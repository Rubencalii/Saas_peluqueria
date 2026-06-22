<?php

declare(strict_types=1);

namespace App\Service\Review;

use Doctrine\DBAL\Connection;

/**
 * Valoraciones post-cita (doc 13). El cliente puntúa su cita (verificada por
 * `public_code`) una vez completada; el panel ve la lista y los agregados
 * (nota media global, por profesional y por servicio).
 */
final class ReviewService
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Registra una valoración. Una por cita, solo si está completada.
     *
     * @return array{review_id: int}
     *
     * @throws ReviewException
     */
    public function submit(int $appointmentId, string $code, int $rating, ?string $comment): array
    {
        $code = trim($code);
        if ($code === '') {
            throw new ReviewException('VALIDATION', 'Falta el código de la cita.');
        }
        if ($rating < 1 || $rating > 5) {
            throw new ReviewException('VALIDATION', 'La puntuación debe estar entre 1 y 5.');
        }

        $appt = $this->db->fetchAssociative(
            'SELECT id, status FROM appointment WHERE id = ? AND public_code = ?',
            [$appointmentId, $code]
        );
        if ($appt === false) {
            throw new ReviewException('NOT_FOUND', 'Cita no encontrada.', 404);
        }
        if ($appt['status'] !== 'completada') {
            throw new ReviewException('INVALID_STATE', 'Solo puedes valorar una cita ya realizada.', 409);
        }

        $id = $this->db->fetchOne(
            'INSERT INTO review (appointment_id, rating, comment) VALUES (?, ?, ?)
             ON CONFLICT (appointment_id) DO NOTHING RETURNING id',
            [$appointmentId, $rating, $comment !== null && trim($comment) !== '' ? trim($comment) : null]
        );
        if ($id === false) {
            throw new ReviewException('ALREADY_REVIEWED', 'Esta cita ya tiene una valoración.', 409);
        }

        return ['review_id' => (int) $id];
    }

    public function countForLocation(?int $locationId): int
    {
        [$where, $params] = $this->scope($locationId);

        return (int) $this->db->fetchOne(
            "SELECT COUNT(*) FROM review r JOIN appointment a ON a.id = r.appointment_id WHERE $where",
            $params
        );
    }

    /**
     * Valoraciones legibles para el panel (paginadas).
     *
     * @return list<array<string, mixed>>
     */
    public function listForLocation(?int $locationId, int $limit, int $offset): array
    {
        [$where, $params] = $this->scope($locationId);

        $rows = $this->db->fetchAllAssociative(
            "SELECT r.id, r.rating, r.comment, r.created_at,
                    s.name AS service_name, st.name AS staff_name, c.name AS customer_name
               FROM review r
               JOIN appointment a ON a.id = r.appointment_id
               JOIN service  s ON s.id = a.service_id
               LEFT JOIN staff st ON st.id = a.staff_id
               LEFT JOIN customer c ON c.id = a.customer_id
              WHERE $where
              ORDER BY r.created_at DESC LIMIT ? OFFSET ?",
            [...$params, $limit, $offset]
        );

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'rating' => (int) $r['rating'],
            'comment' => $r['comment'] !== null ? (string) $r['comment'] : null,
            'service_name' => (string) $r['service_name'],
            'staff_name' => $r['staff_name'] !== null ? (string) $r['staff_name'] : null,
            'customer_name' => $r['customer_name'] !== null ? (string) $r['customer_name'] : null,
            'created_at' => (new \DateTimeImmutable($r['created_at']))->format('c'),
        ], $rows);
    }

    /**
     * Agregados: nota media global y por profesional/servicio.
     *
     * @return array<string, mixed>
     */
    public function aggregates(?int $locationId): array
    {
        [$where, $params] = $this->scope($locationId);
        $base = "FROM review r JOIN appointment a ON a.id = r.appointment_id";

        $overall = $this->db->fetchAssociative(
            "SELECT COUNT(*) AS n, COALESCE(AVG(r.rating), 0) AS avg $base WHERE $where",
            $params
        );
        $byStaff = $this->db->fetchAllAssociative(
            "SELECT a.staff_id, st.name AS staff_name, COUNT(*) AS n, ROUND(AVG(r.rating), 2) AS avg
               $base LEFT JOIN staff st ON st.id = a.staff_id
              WHERE $where GROUP BY a.staff_id, st.name ORDER BY avg DESC",
            $params
        );
        $byService = $this->db->fetchAllAssociative(
            "SELECT a.service_id, s.name AS service_name, COUNT(*) AS n, ROUND(AVG(r.rating), 2) AS avg
               $base JOIN service s ON s.id = a.service_id
              WHERE $where GROUP BY a.service_id, s.name ORDER BY avg DESC",
            $params
        );

        return [
            'count' => (int) $overall['n'],
            'average' => round((float) $overall['avg'], 2),
            'by_staff' => array_map(static fn (array $r): array => [
                'staff_id' => $r['staff_id'] !== null ? (int) $r['staff_id'] : null,
                'staff_name' => $r['staff_name'] !== null ? (string) $r['staff_name'] : null,
                'count' => (int) $r['n'],
                'average' => (float) $r['avg'],
            ], $byStaff),
            'by_service' => array_map(static fn (array $r): array => [
                'service_id' => (int) $r['service_id'],
                'service_name' => (string) $r['service_name'],
                'count' => (int) $r['n'],
                'average' => (float) $r['avg'],
            ], $byService),
        ];
    }

    /**
     * @return array{0: string, 1: list<int>}
     */
    private function scope(?int $locationId): array
    {
        if ($locationId !== null) {
            return ['a.location_id = ?', [$locationId]];
        }

        return ['TRUE', []];
    }
}
