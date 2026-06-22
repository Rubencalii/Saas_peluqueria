<?php

declare(strict_types=1);

namespace App\Service\Loyalty;

use Doctrine\DBAL\Connection;

/**
 * Fidelización por puntos (doc 13). Abona puntos al completarse una cita
 * (1 punto por € del servicio) y mantiene el saldo e historial del cliente.
 */
final class LoyaltyService
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Abona los puntos de una cita completada. Idempotente: no abona dos veces
     * la misma cita (índice único por appointment_id con reason='cita_completada').
     */
    public function awardForCompletedAppointment(int $appointmentId): void
    {
        $row = $this->db->fetchAssociative(
            'SELECT a.customer_id, COALESCE(sl.price_override, s.price) AS price
               FROM appointment a
               JOIN service s ON s.id = a.service_id
               LEFT JOIN service_location sl ON sl.service_id = a.service_id AND sl.location_id = a.location_id
              WHERE a.id = ? AND a.status = \'completada\'',
            [$appointmentId]
        );
        if ($row === false || $row['customer_id'] === null) {
            return;
        }

        $points = (int) round((float) ($row['price'] ?? 0));
        if ($points <= 0) {
            return;
        }
        $customerId = (int) $row['customer_id'];

        $this->db->transactional(function (Connection $tx) use ($appointmentId, $customerId, $points): void {
            $inserted = $tx->executeStatement(
                "INSERT INTO loyalty_transaction (customer_id, appointment_id, points, reason)
                 VALUES (?, ?, ?, 'cita_completada')
                 ON CONFLICT (appointment_id) WHERE reason = 'cita_completada' DO NOTHING",
                [$customerId, $appointmentId, $points]
            );
            if ($inserted > 0) {
                $tx->executeStatement(
                    'UPDATE customer SET loyalty_points = loyalty_points + ? WHERE id = ?',
                    [$points, $customerId]
                );
            }
        });
    }

    /**
     * Saldo e historial reciente de puntos de un cliente.
     *
     * @return array{points: int, history: list<array<string, mixed>>}
     */
    public function summary(int $customerId, int $limit = 20): array
    {
        $points = (int) ($this->db->fetchOne('SELECT loyalty_points FROM customer WHERE id = ?', [$customerId]) ?: 0);
        $rows = $this->db->fetchAllAssociative(
            'SELECT points, reason, appointment_id, created_at
               FROM loyalty_transaction WHERE customer_id = ? ORDER BY created_at DESC LIMIT ?',
            [$customerId, $limit]
        );

        return [
            'points' => $points,
            'history' => array_map(static fn (array $r): array => [
                'points' => (int) $r['points'],
                'reason' => (string) $r['reason'],
                'appointment_id' => $r['appointment_id'] !== null ? (int) $r['appointment_id'] : null,
                'created_at' => (new \DateTimeImmutable($r['created_at']))->format('c'),
            ], $rows),
        ];
    }
}
