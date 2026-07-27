<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Service\Auth\AuthException;
use App\Service\Auth\AuthService;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Caja del día (migración 0029): qué se ha cobrado en una sede y cómo, y
 * arqueo al cerrar. Es trabajo de mostrador, así que también lo usa recepción.
 *
 * El efectivo esperado sale SOLO de las citas cobradas en efectivo: los bonos
 * y las tarjetas regalo se cobraron el día que se vendieron y no se guarda con
 * qué forma de pago, así que se listan aparte como información, sin sumarlos.
 */
final class AdminCashController extends AdminController
{
    private const ROLES = ['recepcion', 'admin_sede', 'admin_cadena'];

    /** Formas de pago de la enum payment_method, en el orden en que se muestran. */
    public const METHODS = ['efectivo', 'tarjeta', 'bono', 'regalo', 'online'];

    public function __construct(
        private readonly Connection $db,
        private readonly AuthService $auth,
    ) {
    }

    #[Route('/api/v1/admin/cash/day', name: 'admin_cash_day', methods: ['GET'])]
    public function day(Request $request): JsonResponse
    {
        $ctx = $this->context($request);
        if ($ctx instanceof JsonResponse) {
            return $ctx;
        }
        [$locationId, $date, $tz] = $ctx;
        [$fromUtc, $toUtc] = $this->dayBounds($date, $tz);

        $lines = $this->db->fetchAllAssociative(
            "SELECT a.id, a.start_at, a.payment_method,
                    s.name AS service_name, st.name AS staff_name, c.name AS customer_name,
                    COALESCE(sl.price_override, s.price, 0) AS amount
               FROM appointment a
               JOIN service s ON s.id = a.service_id
               LEFT JOIN service_location sl ON sl.service_id = a.service_id AND sl.location_id = a.location_id
               LEFT JOIN staff st ON st.id = a.staff_id
               LEFT JOIN customer c ON c.id = a.customer_id
              WHERE a.location_id = ? AND a.start_at >= ? AND a.start_at < ?
                AND a.status = 'completada'
              ORDER BY a.start_at",
            [$locationId, $fromUtc, $toUtc]
        );

        $byMethod = array_fill_keys([...self::METHODS, 'sin_registrar'], ['count' => 0, 'amount' => 0.0]);
        $total = 0.0;
        $rows = [];
        foreach ($lines as $l) {
            $amount = round((float) $l['amount'], 2);
            $method = $l['payment_method'] !== null ? (string) $l['payment_method'] : 'sin_registrar';
            ++$byMethod[$method]['count'];
            $byMethod[$method]['amount'] = round($byMethod[$method]['amount'] + $amount, 2);
            $total = round($total + $amount, 2);

            $rows[] = [
                'appointment_id' => (int) $l['id'],
                'start' => (new \DateTimeImmutable((string) $l['start_at']))->format('c'),
                'customer_name' => $l['customer_name'] !== null ? (string) $l['customer_name'] : null,
                'service_name' => (string) $l['service_name'],
                'staff_name' => $l['staff_name'] !== null ? (string) $l['staff_name'] : null,
                'amount' => $amount,
                'payment_method' => $l['payment_method'] !== null ? (string) $l['payment_method'] : null,
            ];
        }

        // Prepagos vendidos hoy (informativos: entraron por caja, pero no se
        // guarda con qué forma de pago se cobraron).
        $giftCards = $this->db->fetchAllAssociative(
            'SELECT code, initial_amount FROM gift_card
              WHERE account_id = ? AND created_at >= ? AND created_at < ? ORDER BY created_at',
            [$this->accountId($request), $fromUtc, $toUtc]
        );
        $packs = $this->db->fetchAllAssociative(
            'SELECT p.name, p.price FROM customer_pack cp
               JOIN pack p ON p.id = cp.pack_id
              WHERE p.account_id = ? AND cp.sold_at >= ? AND cp.sold_at < ? ORDER BY cp.sold_at',
            [$this->accountId($request), $fromUtc, $toUtc]
        );

        return $this->json([
            'location_id' => $locationId,
            'date' => $date,
            'total' => $total,
            'expected_cash' => $byMethod['efectivo']['amount'],
            'by_method' => $byMethod,
            'appointments' => $rows,
            'gift_cards_sold' => array_map(static fn (array $g): array => [
                'code' => (string) $g['code'],
                'amount' => round((float) $g['initial_amount'], 2),
            ], $giftCards),
            'packs_sold' => array_map(static fn (array $p): array => [
                'name' => (string) $p['name'],
                'amount' => round((float) $p['price'], 2),
            ], $packs),
            'close' => $this->closeOf($locationId, $date),
        ]);
    }

    /**
     * Cierra (o vuelve a cerrar) el día: guarda lo contado frente a lo esperado.
     * El esperado NO viene del cliente, se recalcula aquí.
     */
    #[Route('/api/v1/admin/cash/close', name: 'admin_cash_close', methods: ['POST'])]
    public function close(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->error('VALIDATION', 'El cuerpo debe ser un objeto JSON.', 400);
        }

        $ctx = $this->context($request, $payload);
        if ($ctx instanceof JsonResponse) {
            return $ctx;
        }
        [$locationId, $date, $tz] = $ctx;

        $counted = $payload['counted_cash'] ?? null;
        if (!is_numeric($counted) || (float) $counted < 0) {
            return $this->error('VALIDATION', 'Indica el efectivo contado (0 o más).', 400);
        }

        [$fromUtc, $toUtc] = $this->dayBounds($date, $tz);
        $expected = (float) $this->db->fetchOne(
            "SELECT COALESCE(SUM(COALESCE(sl.price_override, s.price, 0)), 0)
               FROM appointment a
               JOIN service s ON s.id = a.service_id
               LEFT JOIN service_location sl ON sl.service_id = a.service_id AND sl.location_id = a.location_id
              WHERE a.location_id = ? AND a.start_at >= ? AND a.start_at < ?
                AND a.status = 'completada' AND a.payment_method = 'efectivo'",
            [$locationId, $fromUtc, $toUtc]
        );

        $user = self::user($request);
        $this->db->executeStatement(
            'INSERT INTO cash_close (account_id, location_id, business_date, expected_cash, counted_cash, notes, closed_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON CONFLICT (location_id, business_date) DO UPDATE
                SET expected_cash = EXCLUDED.expected_cash,
                    counted_cash  = EXCLUDED.counted_cash,
                    notes         = EXCLUDED.notes,
                    closed_by     = EXCLUDED.closed_by,
                    closed_at     = now()',
            [
                $user['account_id'], $locationId, $date,
                round($expected, 2), round((float) $counted, 2),
                isset($payload['notes']) && $payload['notes'] !== '' ? (string) $payload['notes'] : null,
                $user['id'],
            ]
        );

        return $this->json(['close' => $this->closeOf($locationId, $date)]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function closeOf(int $locationId, string $date): ?array
    {
        $r = $this->db->fetchAssociative(
            'SELECT c.expected_cash, c.counted_cash, c.notes, c.closed_at, u.name AS closed_by_name
               FROM cash_close c LEFT JOIN app_user u ON u.id = c.closed_by
              WHERE c.location_id = ? AND c.business_date = ?',
            [$locationId, $date]
        );
        if ($r === false) {
            return null;
        }
        $expected = round((float) $r['expected_cash'], 2);
        $counted = round((float) $r['counted_cash'], 2);

        return [
            'expected_cash' => $expected,
            'counted_cash' => $counted,
            'difference' => round($counted - $expected, 2),
            'notes' => $r['notes'] !== null ? (string) $r['notes'] : null,
            'closed_at' => (new \DateTimeImmutable((string) $r['closed_at']))->format('c'),
            'closed_by_name' => $r['closed_by_name'] !== null ? (string) $r['closed_by_name'] : null,
        ];
    }

    /**
     * Rol, sede (de la cuenta) y fecha del día pedido.
     *
     * @param array<string, mixed>|null $body para el POST, donde llegan en el cuerpo
     *
     * @return array{0: int, 1: string, 2: \DateTimeZone}|JsonResponse
     */
    private function context(Request $request, ?array $body = null): array|JsonResponse
    {
        $user = self::user($request);
        $rawLocation = $body !== null ? ($body['location_id'] ?? null) : $request->query->get('location_id');
        $rawDate = $body !== null ? ($body['date'] ?? null) : $request->query->get('date');

        try {
            $this->auth->assertRole($user, self::ROLES);
            $requested = $rawLocation !== null && (int) $rawLocation > 0 ? (int) $rawLocation : null;
            $locationId = $this->auth->resolveLocation($user, $requested);
        } catch (AuthException $e) {
            return $this->error($e->errorCode, $e->getMessage(), $e->statusCode);
        }

        if ($locationId === null) {
            return $this->error('VALIDATION', 'Indica la sede (location_id): la caja es de una sede concreta.', 400);
        }

        $tzName = $this->db->fetchOne('SELECT timezone FROM location WHERE id = ? AND account_id = ?', [$locationId, $user['account_id']]);
        if ($tzName === false) {
            return $this->error('NOT_FOUND', 'Sede no encontrada.', 404);
        }
        $tz = new \DateTimeZone((string) $tzName);

        $date = is_string($rawDate) && $rawDate !== '' ? $rawDate : (new \DateTimeImmutable('now', $tz))->format('Y-m-d');
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, $tz);
        if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
            return $this->error('VALIDATION', 'La fecha debe tener el formato AAAA-MM-DD.', 400);
        }

        return [$locationId, $date, $tz];
    }

    /**
     * Límites UTC del día local de la sede.
     *
     * @return array{0: string, 1: string}
     */
    private function dayBounds(string $date, \DateTimeZone $tz): array
    {
        $from = new \DateTimeImmutable($date . ' 00:00:00', $tz);
        $utc = new \DateTimeZone('UTC');

        return [
            $from->setTimezone($utc)->format('c'),
            $from->modify('+1 day')->setTimezone($utc)->format('c'),
        ];
    }

    private function accountId(Request $request): int
    {
        return self::user($request)['account_id'];
    }
}
