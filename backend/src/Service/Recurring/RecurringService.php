<?php

declare(strict_types=1);

namespace App\Service\Recurring;

use App\Service\AppointmentException;
use App\Service\AppointmentService;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

/**
 * Citas recurrentes (doc 13): plantilla de repetición de un cliente y la
 * generación periódica de la próxima cita. La generación reutiliza
 * AppointmentService (misma validación de hueco y anti-solape que la reserva).
 */
final class RecurringService
{
    /** Horizonte: materializa la ocurrencia cuando cae dentro de estos días. */
    private const HORIZON_DAYS = 35;

    public function __construct(
        private readonly Connection $db,
        private readonly AppointmentService $appointments,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Crea una recurrencia para un cliente (alta desde el panel).
     *
     * @return array{id: int}
     *
     * @throws RecurringException
     */
    public function create(
        int $locationId,
        int $serviceId,
        ?int $staffId,
        string $name,
        string $phone,
        int $weekday,
        string $timeLocal,
        int $intervalWeeks,
    ): array {
        $name = trim($name);
        $phone = trim($phone);
        if ($name === '' || $phone === '') {
            throw new RecurringException('VALIDATION', 'Se requieren nombre y teléfono.');
        }
        if ($weekday < 0 || $weekday > 6) {
            throw new RecurringException('VALIDATION', 'weekday debe estar entre 0 (lun) y 6 (dom).');
        }
        if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $timeLocal) !== 1) {
            throw new RecurringException('VALIDATION', 'Hora inválida (HH:MM).');
        }
        if ($intervalWeeks < 1 || $intervalWeeks > 52) {
            throw new RecurringException('VALIDATION', 'El intervalo debe estar entre 1 y 52 semanas.');
        }
        if ($this->db->fetchOne('SELECT 1 FROM service_location WHERE service_id = ? AND location_id = ?', [$serviceId, $locationId]) === false) {
            throw new RecurringException('VALIDATION', 'Ese servicio no se ofrece en esa sede.');
        }

        $id = $this->db->transactional(function (Connection $tx) use (
            $locationId, $serviceId, $staffId, $name, $phone, $weekday, $timeLocal, $intervalWeeks
        ): int {
            $customerId = (int) $tx->fetchOne(
                'INSERT INTO customer (name, phone) VALUES (?, ?)
                 ON CONFLICT (account_id, phone) DO UPDATE SET name = EXCLUDED.name RETURNING id',
                [$name, $phone]
            );

            return (int) $tx->fetchOne(
                'INSERT INTO recurring_appointment (customer_id, location_id, service_id, staff_id, weekday, time_local, interval_weeks)
                 VALUES (?, ?, ?, ?, ?, ?, ?) RETURNING id',
                [$customerId, $locationId, $serviceId, $staffId, $weekday, $timeLocal, $intervalWeeks]
            );
        });

        return ['id' => $id];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listForLocation(?int $locationId, int $accountId): array
    {
        $where = 'r.active AND r.location_id IN (SELECT id FROM location WHERE account_id = ?)';
        $params = [$accountId];
        if ($locationId !== null) {
            $where .= ' AND r.location_id = ?';
            $params[] = $locationId;
        }

        $rows = $this->db->fetchAllAssociative(
            "SELECT r.id, r.weekday, r.time_local, r.interval_weeks, r.last_generated_date,
                    s.name AS service_name, st.name AS staff_name, c.name AS customer_name, c.phone AS customer_phone
               FROM recurring_appointment r
               JOIN service s ON s.id = r.service_id
               LEFT JOIN staff st ON st.id = r.staff_id
               JOIN customer c ON c.id = r.customer_id
              WHERE $where ORDER BY r.created_at DESC",
            $params
        );

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'weekday' => (int) $r['weekday'],
            'time' => substr((string) $r['time_local'], 0, 5),
            'interval_weeks' => (int) $r['interval_weeks'],
            'last_generated_date' => $r['last_generated_date'] !== null ? (string) $r['last_generated_date'] : null,
            'service_name' => (string) $r['service_name'],
            'staff_name' => $r['staff_name'] !== null ? (string) $r['staff_name'] : null,
            'customer' => ['name' => (string) $r['customer_name'], 'phone' => (string) $r['customer_phone']],
        ], $rows);
    }

    public function locationOf(int $id): ?int
    {
        $loc = $this->db->fetchOne('SELECT location_id FROM recurring_appointment WHERE id = ?', [$id]);

        return $loc === false ? null : (int) $loc;
    }

    public function deactivate(int $id): void
    {
        $this->db->executeStatement('UPDATE recurring_appointment SET active = FALSE WHERE id = ?', [$id]);
    }

    /**
     * Genera la próxima cita de cada recurrencia activa cuya ocurrencia caiga
     * dentro del horizonte. Idempotente vía last_generated_date.
     *
     * @return array{created: int, skipped: int}
     */
    public function generateDue(bool $dryRun = false): array
    {
        $rows = $this->db->fetchAllAssociative(
            "SELECT r.id, r.location_id, r.service_id, r.staff_id, r.weekday, r.time_local,
                    r.interval_weeks, r.last_generated_date, l.timezone,
                    c.name AS customer_name, c.phone AS customer_phone
               FROM recurring_appointment r
               JOIN location l ON l.id = r.location_id
               JOIN customer c ON c.id = r.customer_id
              WHERE r.active"
        );

        $created = 0;
        $skipped = 0;
        foreach ($rows as $r) {
            $tz = new \DateTimeZone((string) $r['timezone']);
            $candidate = $this->nextOccurrence($r, $tz);
            $horizon = (new \DateTimeImmutable('today', $tz))->modify('+' . self::HORIZON_DAYS . ' days');
            if ($candidate > $horizon) {
                ++$skipped; // aún no toca

                continue;
            }

            $startUtc = $candidate->setTime(
                (int) substr((string) $r['time_local'], 0, 2),
                (int) substr((string) $r['time_local'], 3, 2)
            )->setTimezone(new \DateTimeZone('UTC'));

            if ($dryRun) {
                ++$created;

                continue;
            }

            try {
                $this->appointments->create([
                    'location_id' => (int) $r['location_id'],
                    'service_id' => (int) $r['service_id'],
                    'staff_id' => $r['staff_id'] !== null ? (int) $r['staff_id'] : null,
                    'start' => $startUtc->format('c'),
                    'customer' => ['name' => (string) $r['customer_name'], 'phone' => (string) $r['customer_phone']],
                    'channel' => 'manual',
                ]);
                $this->db->executeStatement(
                    'UPDATE recurring_appointment SET last_generated_date = ? WHERE id = ?',
                    [$candidate->format('Y-m-d'), (int) $r['id']]
                );
                ++$created;
            } catch (AppointmentException $e) {
                // Hueco no disponible/ocupado: se reintenta en la próxima ejecución.
                $this->logger->info('[Recurring] #{id} sin hueco el {date}: {msg}', [
                    'id' => (int) $r['id'], 'date' => $candidate->format('Y-m-d'), 'msg' => $e->errorCode,
                ]);
                ++$skipped;
            }
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    /**
     * Próxima ocurrencia (>= hoy) según el día de la semana y la última generada.
     *
     * @param array<string, mixed> $r
     */
    private function nextOccurrence(array $r, \DateTimeZone $tz): \DateTimeImmutable
    {
        $today = new \DateTimeImmutable('today', $tz);
        $base = $today;
        if ($r['last_generated_date'] !== null) {
            $next = (new \DateTimeImmutable((string) $r['last_generated_date'], $tz))
                ->modify('+' . (int) $r['interval_weeks'] . ' weeks');
            $base = $next > $today ? $next : $today;
        }

        // Avanza desde $base hasta el día de la semana objetivo (0=lun..6=dom).
        $baseDow = ((int) $base->format('N')) - 1;
        $add = (((int) $r['weekday'] - $baseDow) + 7) % 7;

        return $base->modify("+{$add} days");
    }
}
