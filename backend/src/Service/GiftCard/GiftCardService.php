<?php

declare(strict_types=1);

namespace App\Service\GiftCard;

use Doctrine\DBAL\Connection;

/**
 * Tarjetas regalo (doc 13 §2): saldo prepagado al portador con código
 * legible. La venta genera el código; el canje descuenta importe en caja
 * (transaccional, con libro de movimientos para auditar el saldo).
 */
final class GiftCardService
{
    /** Sin caracteres ambiguos (0/O, 1/I/L) para dictarlo por teléfono. */
    private const CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Vende una tarjeta y devuelve su código.
     *
     * @return array{id: int, code: string}
     *
     * @throws GiftCardException
     */
    public function sell(int $accountId, float $amount, ?string $recipient, ?int $validityDays, ?int $soldBy): array
    {
        if ($amount <= 0 || $amount > 10000) {
            throw new GiftCardException('VALIDATION', 'El importe debe estar entre 0,01 y 10.000 €.');
        }
        if ($validityDays !== null && $validityDays < 1) {
            throw new GiftCardException('VALIDATION', 'La validez debe ser al menos 1 día.');
        }

        $expires = $validityDays !== null
            ? (new \DateTimeImmutable('now'))->modify('+' . $validityDays . ' days')->format('c')
            : null;

        // El código es UNIQUE global: reintenta ante la colisión (improbable).
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $code = $this->generateCode();
            try {
                $id = (int) $this->db->fetchOne(
                    'INSERT INTO gift_card (account_id, code, initial_amount, balance, recipient_name, expires_at, sold_by)
                     VALUES (?, ?, ?, ?, ?, ?, ?) RETURNING id',
                    [$accountId, $code, $amount, $amount, $recipient !== null && trim($recipient) !== '' ? trim($recipient) : null, $expires, $soldBy]
                );

                return ['id' => $id, 'code' => $code];
            } catch (\Doctrine\DBAL\Exception\UniqueConstraintViolationException) {
                continue;
            }
        }

        throw new GiftCardException('CONFLICT', 'No se pudo generar un código único; reintenta.', 409);
    }

    /**
     * Tarjeta por código (de la cuenta) con su historial de canjes.
     *
     * @return array<string, mixed>|null
     */
    public function findByCode(string $code, int $accountId): ?array
    {
        $card = $this->db->fetchAssociative(
            'SELECT id, code, initial_amount, balance, recipient_name, expires_at, created_at
               FROM gift_card WHERE code = ? AND account_id = ?',
            [$this->normalizeCode($code), $accountId]
        );
        if ($card === false) {
            return null;
        }

        $redemptions = $this->db->fetchAllAssociative(
            'SELECT r.amount, r.redeemed_at, u.name AS redeemed_by
               FROM gift_card_redemption r LEFT JOIN app_user u ON u.id = r.redeemed_by
              WHERE r.gift_card_id = ? ORDER BY r.redeemed_at DESC',
            [(int) $card['id']]
        );

        $expiresAt = $card['expires_at'] !== null ? new \DateTimeImmutable($card['expires_at']) : null;

        return [
            'id' => (int) $card['id'],
            'code' => (string) $card['code'],
            'initial_amount' => (float) $card['initial_amount'],
            'balance' => (float) $card['balance'],
            'recipient_name' => $card['recipient_name'] !== null ? (string) $card['recipient_name'] : null,
            'expires_at' => $expiresAt?->format('c'),
            'expired' => $expiresAt !== null && $expiresAt < new \DateTimeImmutable('now'),
            'created_at' => (new \DateTimeImmutable($card['created_at']))->format('c'),
            'redemptions' => array_map(static fn (array $r): array => [
                'amount' => (float) $r['amount'],
                'redeemed_at' => (new \DateTimeImmutable($r['redeemed_at']))->format('c'),
                'redeemed_by' => $r['redeemed_by'] !== null ? (string) $r['redeemed_by'] : null,
            ], $redemptions),
        ];
    }

    /**
     * Canjea un importe contra el saldo (en caja). Devuelve el saldo restante.
     *
     * @throws GiftCardException
     */
    public function redeem(string $code, int $accountId, float $amount, ?int $redeemedBy): float
    {
        if ($amount <= 0) {
            throw new GiftCardException('VALIDATION', 'El importe a canjear debe ser mayor que cero.');
        }
        $normalized = $this->normalizeCode($code);

        return (float) $this->db->transactional(function (Connection $tx) use ($normalized, $accountId, $amount, $redeemedBy): float {
            $card = $tx->fetchAssociative(
                'SELECT id, balance, expires_at FROM gift_card WHERE code = ? AND account_id = ? FOR UPDATE',
                [$normalized, $accountId]
            );
            if ($card === false) {
                throw new GiftCardException('NOT_FOUND', 'Tarjeta no encontrada.', 404);
            }
            if ($card['expires_at'] !== null && new \DateTimeImmutable($card['expires_at']) < new \DateTimeImmutable('now')) {
                throw new GiftCardException('EXPIRED', 'La tarjeta está caducada.', 409);
            }
            $balance = (float) $card['balance'];
            if ($amount > $balance + 0.001) {
                throw new GiftCardException('INSUFFICIENT', sprintf('Saldo insuficiente: quedan %.2f €.', $balance), 409);
            }

            $tx->executeStatement(
                'UPDATE gift_card SET balance = balance - ? WHERE id = ?',
                [$amount, (int) $card['id']]
            );
            $tx->executeStatement(
                'INSERT INTO gift_card_redemption (gift_card_id, amount, redeemed_by) VALUES (?, ?, ?)',
                [(int) $card['id'], $amount, $redeemedBy]
            );

            return round($balance - $amount, 2);
        });
    }

    /**
     * Últimas tarjetas de la cuenta (para el listado del panel).
     *
     * @return list<array<string, mixed>>
     */
    public function recent(int $accountId, int $limit = 50): array
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT code, initial_amount, balance, recipient_name, expires_at, created_at
               FROM gift_card WHERE account_id = ?
              ORDER BY created_at DESC LIMIT ?',
            [$accountId, $limit]
        );

        return array_map(static fn (array $r): array => [
            'code' => (string) $r['code'],
            'initial_amount' => (float) $r['initial_amount'],
            'balance' => (float) $r['balance'],
            'recipient_name' => $r['recipient_name'] !== null ? (string) $r['recipient_name'] : null,
            'expires_at' => $r['expires_at'] !== null ? (new \DateTimeImmutable($r['expires_at']))->format('c') : null,
            'created_at' => (new \DateTimeImmutable($r['created_at']))->format('c'),
        ], $rows);
    }

    /** GIFT-XXXX-XXXX con alfabeto sin ambigüedades. */
    private function generateCode(): string
    {
        $part = function (): string {
            $s = '';
            for ($i = 0; $i < 4; $i++) {
                $s .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
            }

            return $s;
        };

        return 'GIFT-' . $part() . '-' . $part();
    }

    /** Tolera minúsculas, espacios y guiones de más al teclear el código. */
    private function normalizeCode(string $code): string
    {
        $clean = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $code) ?? '');
        if (str_starts_with($clean, 'GIFT')) {
            $clean = substr($clean, 4);
        }

        return strlen($clean) === 8 ? 'GIFT-' . substr($clean, 0, 4) . '-' . substr($clean, 4) : $code;
    }
}
