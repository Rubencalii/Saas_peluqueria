<?php

declare(strict_types=1);

namespace App\Service\Waitlist;

use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Connection;

/**
 * Lista de espera (doc 13 §2.4): alta, consulta y baja. El aviso cuando se
 * libera un hueco lo hace el comando `app:waitlist:notify` (cron), que mira la
 * disponibilidad real y reutiliza el mismo algoritmo que la reserva.
 */
final class WaitlistService
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Apunta a un cliente a la lista de espera. Idempotente mientras siga
     * esperando: si ya está apuntado al mismo servicio/sede/día, devuelve su id.
     *
     * @return array{waitlist_id: int, already: bool}
     *
     * @throws WaitlistException
     */
    public function join(
        int $locationId,
        int $serviceId,
        ?int $staffId,
        string $name,
        string $phone,
        bool $waConsent,
        ?string $date,
    ): array {
        $name = trim($name);
        $phone = trim($phone);
        if ($name === '' || $phone === '') {
            throw new WaitlistException('VALIDATION', 'Se requieren nombre y teléfono.');
        }

        $offered = $this->db->fetchOne(
            'SELECT 1 FROM service_location WHERE service_id = ? AND location_id = ?',
            [$serviceId, $locationId]
        );
        if ($offered === false) {
            throw new WaitlistException('VALIDATION', 'Ese servicio no se ofrece en esa sede.');
        }

        $desiredDate = $this->parseDate($date);

        if ($staffId !== null) {
            $valid = $this->db->fetchOne(
                'SELECT 1 FROM staff_service ss
                   JOIN staff_location sl ON sl.staff_id = ss.staff_id AND sl.location_id = ?
                  WHERE ss.staff_id = ? AND ss.service_id = ?',
                [$locationId, $staffId, $serviceId]
            );
            if ($valid === false) {
                throw new WaitlistException('VALIDATION', 'Ese profesional no ofrece el servicio en esa sede.');
            }
        }

        return $this->db->transactional(function (Connection $tx) use (
            $locationId, $serviceId, $staffId, $name, $phone, $waConsent, $desiredDate
        ): array {
            $customerId = $this->upsertCustomer($tx, $name, $phone, $waConsent);

            // El índice único parcial evita duplicados mientras status='esperando'.
            $id = $tx->fetchOne(
                "INSERT INTO waitlist (location_id, service_id, staff_id, customer_id, desired_date)
                 VALUES (?, ?, ?, ?, ?)
                 ON CONFLICT (location_id, service_id, customer_id, COALESCE(desired_date, '0001-01-01'))
                    WHERE status = 'esperando'
                 DO NOTHING
                 RETURNING id",
                [$locationId, $serviceId, $staffId, $customerId, $desiredDate]
            );

            if ($id === false) {
                $existing = (int) $tx->fetchOne(
                    "SELECT id FROM waitlist
                      WHERE location_id = ? AND service_id = ? AND customer_id = ?
                        AND COALESCE(desired_date, '0001-01-01') = COALESCE(?::date, '0001-01-01')
                        AND status = 'esperando'",
                    [$locationId, $serviceId, $customerId, $desiredDate]
                );

                return ['waitlist_id' => $existing, 'already' => true];
            }

            return ['waitlist_id' => (int) $id, 'already' => false];
        });
    }

    /** Total de entradas que casan el filtro (para paginar). */
    public function countForLocation(?int $locationId, string $status): int
    {
        $where = 'status = ?';
        $params = [$status];
        if ($locationId !== null) {
            $where .= ' AND location_id = ?';
            $params[] = $locationId;
        }

        return (int) $this->db->fetchOne("SELECT COUNT(*) FROM waitlist WHERE $where", $params);
    }

    /**
     * Entradas de la lista para el panel (paginadas).
     *
     * @return list<array<string, mixed>>
     */
    public function listForLocation(?int $locationId, string $status, int $limit = 50, int $offset = 0): array
    {
        $where = 'w.status = ?';
        $params = [$status];
        if ($locationId !== null) {
            $where .= ' AND w.location_id = ?';
            $params[] = $locationId;
        }

        $rows = $this->db->fetchAllAssociative(
            "SELECT w.id, w.desired_date, w.status, w.created_at, w.notified_at,
                    w.location_id, l.name AS location_name,
                    w.service_id, s.name AS service_name,
                    w.staff_id, st.name AS staff_name,
                    c.name AS customer_name, c.phone AS customer_phone
               FROM waitlist w
               JOIN location l ON l.id = w.location_id
               JOIN service  s ON s.id = w.service_id
               LEFT JOIN staff st ON st.id = w.staff_id
               JOIN customer c ON c.id = w.customer_id
              WHERE $where
              ORDER BY w.created_at LIMIT ? OFFSET ?",
            [...$params, $limit, $offset]
        );

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'status' => (string) $r['status'],
            'desired_date' => $r['desired_date'] !== null ? (string) $r['desired_date'] : null,
            'created_at' => (new \DateTimeImmutable($r['created_at']))->format('c'),
            'notified_at' => $r['notified_at'] !== null ? (new \DateTimeImmutable($r['notified_at']))->format('c') : null,
            'location' => ['id' => (int) $r['location_id'], 'name' => (string) $r['location_name']],
            'service' => ['id' => (int) $r['service_id'], 'name' => (string) $r['service_name']],
            'staff' => $r['staff_id'] !== null ? ['id' => (int) $r['staff_id'], 'name' => (string) $r['staff_name']] : null,
            'customer' => ['name' => (string) $r['customer_name'], 'phone' => (string) $r['customer_phone']],
        ], $rows);
    }

    /**
     * Sede de una entrada (para autorizar antes de actuar), o null si no existe.
     */
    public function locationOf(int $id): ?int
    {
        $locationId = $this->db->fetchOne('SELECT location_id FROM waitlist WHERE id = ?', [$id]);

        return $locationId === false ? null : (int) $locationId;
    }

    /** Marca una entrada como cancelada (idempotente). */
    public function markCancelled(int $id): void
    {
        $this->db->executeStatement("UPDATE waitlist SET status = 'cancelado' WHERE id = ?", [$id]);
    }

    private function upsertCustomer(Connection $tx, string $name, string $phone, bool $waConsent): int
    {
        return (int) $tx->fetchOne(
            'INSERT INTO customer (name, phone, wa_consent, consent_at)
             VALUES (?, ?, ?, CASE WHEN ? THEN now() END)
             ON CONFLICT (account_id, phone) DO UPDATE SET
                 name       = EXCLUDED.name,
                 wa_consent = customer.wa_consent OR EXCLUDED.wa_consent,
                 consent_at = CASE
                     WHEN EXCLUDED.wa_consent AND NOT customer.wa_consent THEN now()
                     ELSE customer.consent_at
                 END
             RETURNING id',
            [$name, $phone, $waConsent, $waConsent],
            [2 => ParameterType::BOOLEAN, 3 => ParameterType::BOOLEAN]
        );
    }

    /**
     * @throws WaitlistException
     */
    private function parseDate(?string $date): ?string
    {
        if ($date === null || trim($date) === '') {
            return null;
        }
        $date = trim($date);
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($d === false || $d->format('Y-m-d') !== $date) {
            throw new WaitlistException('VALIDATION', 'Fecha inválida (AAAA-MM-DD).');
        }
        if ($d < new \DateTimeImmutable('today')) {
            throw new WaitlistException('VALIDATION', 'La fecha deseada ya pasó.');
        }

        return $date;
    }
}
