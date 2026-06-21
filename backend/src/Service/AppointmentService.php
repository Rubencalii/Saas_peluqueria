<?php

declare(strict_types=1);

namespace App\Service;

use App\Service\Notification\NotificationService;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Creación atómica de reservas (docs/06-especificacion-api.md §2).
 *
 * La fuente de verdad anti-doble-reserva es la BD: el trigger
 * sync_appointment_busy_blocks materializa los tramos ocupados y la
 * restricción EXCLUDE (0002) rechaza cualquier solape para el mismo
 * profesional. Aquí validamos antes (hueco real dentro del horario y la
 * antelación mínima) y dejamos que la BD resuelva las condiciones de
 * carrera devolviendo 409.
 */
final class AppointmentService
{
    /** SQLSTATE de violación de restricción de exclusión en PostgreSQL. */
    private const SQLSTATE_EXCLUSION_VIOLATION = '23P01';

    /** @var list<string> */
    private const CHANNELS = ['web', 'whatsapp', 'manual'];

    /** Estados en los que la cita ocupa agenda y puede gestionarse (doc 05). */
    private const ACTIVE_STATUSES = ['pendiente', 'confirmada'];

    /**
     * Ventana mínima para gestión self-service (doc 02 §7): por debajo de esto
     * el cliente no puede cancelar/reprogramar solo y se le deriva a la sede.
     */
    private const MIN_MANAGE_ADVANCE_MINUTES = 120;

    public function __construct(
        private readonly Connection $db,
        private readonly AvailabilityService $availability,
        private readonly NotificationService $notifications,
    ) {
    }

    /**
     * @param array<string, mixed> $input  Cuerpo de la petición ya decodificado.
     *
     * @return array{appointment_id: int, status: string, staff_id: int, start: string, end: string, idempotent_replay?: bool}
     *
     * @throws AppointmentException
     */
    public function create(array $input, ?string $idempotencyKey = null): array
    {
        // Reproducción idempotente: si la clave ya creó una cita, la devolvemos.
        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $existing = $this->db->fetchOne(
                'SELECT appointment_id FROM idempotency_key WHERE key = ?',
                [$idempotencyKey]
            );
            if ($existing !== false) {
                return $this->present((int) $existing, true);
            }
        }

        $locationId = $this->intField($input, 'location_id');
        $serviceId = $this->intField($input, 'service_id');
        $requestedStaffId = isset($input['staff_id']) && $input['staff_id'] !== null
            ? (int) $input['staff_id']
            : null;

        $channel = is_string($input['channel'] ?? null) ? $input['channel'] : 'web';
        if (!in_array($channel, self::CHANNELS, true)) {
            throw new AppointmentException('VALIDATION', 'Canal inválido (web|whatsapp|manual).');
        }

        $startRaw = is_string($input['start'] ?? null) ? trim($input['start']) : '';
        if ($startRaw === '') {
            throw new AppointmentException('VALIDATION', 'Falta la hora de inicio (start).');
        }
        try {
            $start = (new \DateTimeImmutable($startRaw))->setTimezone(new \DateTimeZone('UTC'));
        } catch (\Exception) {
            throw new AppointmentException('VALIDATION', 'Hora de inicio inválida (ISO 8601).');
        }

        $customer = is_array($input['customer'] ?? null) ? $input['customer'] : [];
        $name = is_string($customer['name'] ?? null) ? trim($customer['name']) : '';
        $phone = is_string($customer['phone'] ?? null) ? trim($customer['phone']) : '';
        if ($name === '' || $phone === '') {
            throw new AppointmentException('VALIDATION', 'El cliente necesita nombre y teléfono.');
        }
        $email = is_string($customer['email'] ?? null) && trim($customer['email']) !== ''
            ? trim($customer['email'])
            : null;
        $waConsent = (bool) ($input['wa_consent'] ?? false);

        // Validar que el hueco existe de verdad y resolver el profesional.
        $staffId = $this->resolveSlot($locationId, $serviceId, $requestedStaffId, $start);

        $span = $this->availability->spanMinutes($serviceId);
        $end = $start->modify('+' . $span . ' minutes');

        $publicCode = bin2hex(random_bytes(8));

        return $this->db->transactional(function (Connection $tx) use (
            $locationId, $serviceId, $staffId, $start, $end,
            $name, $phone, $email, $waConsent, $channel, $idempotencyKey, $publicCode
        ): array {
            $customerId = $this->upsertCustomer($tx, $name, $phone, $email, $waConsent);

            try {
                $appointmentId = (int) $tx->fetchOne(
                    'INSERT INTO appointment
                        (customer_id, staff_id, service_id, location_id, start_at, end_at, status, channel, public_code)
                     VALUES (?, ?, ?, ?, ?, ?, \'confirmada\', ?, ?)
                     RETURNING id',
                    [
                        $customerId, $staffId, $serviceId, $locationId,
                        $start->format('c'), $end->format('c'), $channel, $publicCode,
                    ]
                );
            } catch (\Doctrine\DBAL\Exception $e) {
                if ($this->isExclusionViolation($e)) {
                    throw new AppointmentException('SLOT_TAKEN', 'Ese hueco ya está ocupado.', 409);
                }
                throw $e;
            }

            if ($idempotencyKey !== null && $idempotencyKey !== '') {
                $tx->executeStatement(
                    'INSERT INTO idempotency_key (key, appointment_id) VALUES (?, ?)',
                    [$idempotencyKey, $appointmentId]
                );
            }

            $this->notifications->onAppointmentCreated($tx, $appointmentId);

            return $this->present($appointmentId, false);
        });
    }

    /**
     * Comprueba que $start es un hueco ofertado y devuelve el profesional asignado.
     *
     * @throws AppointmentException
     */
    private function resolveSlot(int $locationId, int $serviceId, ?int $staffId, \DateTimeImmutable $start): int
    {
        $tz = $this->db->fetchOne('SELECT timezone FROM location WHERE id = ? AND active', [$locationId]);
        if ($tz === false) {
            throw new AppointmentException('VALIDATION', 'Sede no encontrada o inactiva.');
        }

        $localDate = $start->setTimezone(new \DateTimeZone((string) $tz))->format('Y-m-d');

        try {
            $offer = $this->availability->find($locationId, $serviceId, $staffId, $localDate);
        } catch (\InvalidArgumentException $e) {
            throw new AppointmentException('VALIDATION', $e->getMessage());
        }

        $startTs = $start->getTimestamp();
        foreach ($offer['slots'] as $slot) {
            if ((new \DateTimeImmutable($slot['start']))->getTimestamp() === $startTs) {
                return $slot['staff_id'];
            }
        }

        throw new AppointmentException('SLOT_TAKEN', 'Ese hueco ya no está disponible.', 409);
    }

    private function upsertCustomer(
        Connection $tx,
        string $name,
        string $phone,
        ?string $email,
        bool $waConsent
    ): int {
        return (int) $tx->fetchOne(
            'INSERT INTO customer (name, phone, email, wa_consent, consent_at)
             VALUES (?, ?, ?, ?, CASE WHEN ? THEN now() END)
             ON CONFLICT (phone) DO UPDATE SET
                 name       = EXCLUDED.name,
                 email      = COALESCE(EXCLUDED.email, customer.email),
                 wa_consent = EXCLUDED.wa_consent,
                 consent_at = CASE
                     WHEN EXCLUDED.wa_consent AND NOT customer.wa_consent THEN now()
                     ELSE customer.consent_at
                 END
             RETURNING id',
            [$name, $phone, $email, $waConsent, $waConsent],
            [3 => ParameterType::BOOLEAN, 4 => ParameterType::BOOLEAN]
        );
    }

    /**
     * Consulta las próximas citas de un cliente (doc 06 §2).
     * Verificación ligera: teléfono + código de una de sus citas.
     *
     * @return array{customer: array{name: string, phone: string}, appointments: list<array<string, mixed>>}
     *
     * @throws AppointmentException
     */
    public function lookup(string $phone, string $code): array
    {
        $phone = trim($phone);
        $code = trim($code);
        if ($phone === '' || $code === '') {
            throw new AppointmentException('VALIDATION', 'Se requieren teléfono y código.');
        }

        $owner = $this->db->fetchAssociative(
            'SELECT c.id, c.name, c.phone
               FROM appointment a
               JOIN customer c ON c.id = a.customer_id
              WHERE a.public_code = ? AND c.phone = ?',
            [$code, $phone]
        );
        if ($owner === false) {
            throw new AppointmentException('NOT_FOUND', 'No encontramos ninguna cita con esos datos.', 404);
        }

        $rows = $this->db->fetchAllAssociative(
            $this->detailSelect() . '
              WHERE a.customer_id = ?
                AND a.status IN (\'pendiente\', \'confirmada\')
                AND a.end_at >= now()
              ORDER BY a.start_at',
            [(int) $owner['id']]
        );

        return [
            'customer' => ['name' => (string) $owner['name'], 'phone' => (string) $owner['phone']],
            'appointments' => array_map($this->presentDetailed(...), $rows),
        ];
    }

    /**
     * Reprograma una cita a una nueva hora, de forma atómica (doc 02 §7, §1).
     *
     * Estrategia: dentro de una transacción se cancela la cita (el trigger
     * libera su hueco actual), se valida el nuevo hueco con la lógica de
     * disponibilidad (horario, antelación, conflictos con OTRAS citas) y se
     * reactiva en la nueva hora. Si el nuevo hueco choca con otra reserva, la
     * restricción de exclusión revierte todo y el hueco original se conserva.
     *
     * @return array<string, mixed>
     *
     * @throws AppointmentException
     */
    public function reschedule(int $id, string $code, string $newStartRaw): array
    {
        $appt = $this->loadAuthorized($id, $code);
        $this->assertActive($appt);
        $this->assertWithinManageWindow($appt);

        $newStart = $this->parseStart($newStartRaw);
        $locationId = (int) $appt['location_id'];
        $serviceId = (int) $appt['service_id'];
        $currentStaffId = $appt['staff_id'] !== null ? (int) $appt['staff_id'] : null;

        return $this->db->transactional(function (Connection $tx) use (
            $id, $newStart, $locationId, $serviceId, $currentStaffId
        ): array {
            // Liberar el hueco actual para que la validación no choque consigo misma.
            $tx->executeStatement(
                "UPDATE appointment SET status = 'cancelada' WHERE id = ?",
                [$id]
            );

            $staffId = $this->resolveSlot($locationId, $serviceId, $currentStaffId, $newStart);
            $span = $this->availability->spanMinutes($serviceId);
            $end = $newStart->modify('+' . $span . ' minutes');

            try {
                $tx->executeStatement(
                    "UPDATE appointment
                        SET start_at = ?, end_at = ?, staff_id = ?, status = 'confirmada'
                      WHERE id = ?",
                    [$newStart->format('c'), $end->format('c'), $staffId, $id]
                );
            } catch (\Doctrine\DBAL\Exception $e) {
                if ($this->isExclusionViolation($e)) {
                    throw new AppointmentException('SLOT_TAKEN', 'Ese hueco ya está ocupado.', 409);
                }
                throw $e;
            }

            $this->notifications->onAppointmentRescheduled($tx, $id);

            return $this->present($id, false);
        });
    }

    /**
     * Cancela una cita (doc 02 §7). Idempotente: cancelar una cita ya
     * cancelada devuelve 200. El hueco se libera vía trigger al instante.
     *
     * @return array<string, mixed>
     *
     * @throws AppointmentException
     */
    public function cancel(int $id, string $code): array
    {
        $appt = $this->loadAuthorized($id, $code);

        if ($appt['status'] === 'cancelada') {
            return $this->present($id, false); // ya cancelada: idempotente
        }
        $this->assertActive($appt);
        $this->assertWithinManageWindow($appt);

        return $this->db->transactional(function (Connection $tx) use ($id): array {
            $tx->executeStatement(
                "UPDATE appointment SET status = 'cancelada' WHERE id = ?",
                [$id]
            );
            $this->notifications->onAppointmentCancelled($tx, $id);

            return $this->present($id, false);
        });
    }

    /**
     * Carga una cita verificando el código de acceso. Para no filtrar la
     * existencia de ids, código ausente o que no casa => 404 (salvo vacío => 400).
     *
     * @return array<string, mixed>
     *
     * @throws AppointmentException
     */
    private function loadAuthorized(int $id, string $code): array
    {
        $code = trim($code);
        if ($code === '') {
            throw new AppointmentException('VALIDATION', 'Falta el código de la cita.');
        }

        $row = $this->db->fetchAssociative(
            'SELECT id, customer_id, staff_id, service_id, location_id, start_at, end_at, status
               FROM appointment WHERE id = ? AND public_code = ?',
            [$id, $code]
        );
        if ($row === false) {
            throw new AppointmentException('NOT_FOUND', 'Cita no encontrada.', 404);
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $appt
     *
     * @throws AppointmentException
     */
    private function assertActive(array $appt): void
    {
        if (!in_array($appt['status'], self::ACTIVE_STATUSES, true)) {
            throw new AppointmentException('INVALID_STATE', 'La cita no se puede gestionar en su estado actual.', 409);
        }
    }

    /**
     * @param array<string, mixed> $appt
     *
     * @throws AppointmentException
     */
    private function assertWithinManageWindow(array $appt): void
    {
        $start = new \DateTimeImmutable($appt['start_at']);
        $limit = (new \DateTimeImmutable('now'))->modify('+' . self::MIN_MANAGE_ADVANCE_MINUTES . ' minutes');
        if ($start < $limit) {
            throw new AppointmentException(
                'TOO_LATE',
                'Falta muy poco para la cita; contacta con la sede para cambiarla o cancelarla.',
                409
            );
        }
    }

    /**
     * @throws AppointmentException
     */
    private function parseStart(string $raw): \DateTimeImmutable
    {
        $raw = trim($raw);
        if ($raw === '') {
            throw new AppointmentException('VALIDATION', 'Falta la nueva hora de inicio (start).');
        }
        try {
            return (new \DateTimeImmutable($raw))->setTimezone(new \DateTimeZone('UTC'));
        } catch (\Exception) {
            throw new AppointmentException('VALIDATION', 'Hora de inicio inválida (ISO 8601).');
        }
    }

    /**
     * SELECT base con los datos legibles de una cita (servicio, profesional, sede).
     */
    private function detailSelect(): string
    {
        return 'SELECT a.id, a.status, a.start_at, a.end_at, a.public_code,
                       a.service_id, s.name AS service_name,
                       a.staff_id, st.name AS staff_name,
                       a.location_id, l.name AS location_name, l.slug AS location_slug, l.timezone
                  FROM appointment a
                  JOIN service  s  ON s.id  = a.service_id
                  LEFT JOIN staff st ON st.id = a.staff_id
                  JOIN location l  ON l.id  = a.location_id';
    }

    /**
     * @param array<string, mixed> $r
     *
     * @return array<string, mixed>
     */
    private function presentDetailed(array $r): array
    {
        return [
            'appointment_id' => (int) $r['id'],
            'status' => (string) $r['status'],
            'start' => (new \DateTimeImmutable($r['start_at']))->format('c'),
            'end' => (new \DateTimeImmutable($r['end_at']))->format('c'),
            'service' => ['id' => (int) $r['service_id'], 'name' => (string) $r['service_name']],
            'staff' => $r['staff_id'] !== null
                ? ['id' => (int) $r['staff_id'], 'name' => (string) $r['staff_name']]
                : null,
            'location' => [
                'id' => (int) $r['location_id'],
                'name' => (string) $r['location_name'],
                'slug' => (string) $r['location_slug'],
                'timezone' => (string) $r['timezone'],
            ],
            'public_code' => (string) $r['public_code'],
        ];
    }

    /**
     * @return array{appointment_id: int, status: string, staff_id: int, start: string, end: string, public_code: string, idempotent_replay?: bool}
     */
    private function present(int $appointmentId, bool $replay): array
    {
        $row = $this->db->fetchAssociative(
            'SELECT id, staff_id, status, start_at, end_at, public_code FROM appointment WHERE id = ?',
            [$appointmentId]
        );
        if ($row === false) {
            throw new AppointmentException('NOT_FOUND', 'Cita no encontrada.', 404);
        }

        $out = [
            'appointment_id' => (int) $row['id'],
            'status' => (string) $row['status'],
            'staff_id' => (int) $row['staff_id'],
            'start' => (new \DateTimeImmutable($row['start_at']))->format('c'),
            'end' => (new \DateTimeImmutable($row['end_at']))->format('c'),
            'public_code' => (string) $row['public_code'],
        ];
        if ($replay) {
            $out['idempotent_replay'] = true;
        }

        return $out;
    }

    private function isExclusionViolation(\Doctrine\DBAL\Exception $e): bool
    {
        $prev = $e->getPrevious();
        if ($prev instanceof \Doctrine\DBAL\Driver\Exception) {
            return $prev->getSQLState() === self::SQLSTATE_EXCLUSION_VIOLATION;
        }

        return str_contains($e->getMessage(), self::SQLSTATE_EXCLUSION_VIOLATION)
            || str_contains($e->getMessage(), 'no_overlap_per_staff');
    }

    /**
     * @param array<string, mixed> $input
     *
     * @throws AppointmentException
     */
    private function intField(array $input, string $key): int
    {
        $value = isset($input[$key]) ? (int) $input[$key] : 0;
        if ($value <= 0) {
            throw new AppointmentException('VALIDATION', "Falta o es inválido el campo: {$key}.");
        }

        return $value;
    }
}
