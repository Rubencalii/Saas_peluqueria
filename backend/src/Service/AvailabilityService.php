<?php

declare(strict_types=1);

namespace App\Service;

use Doctrine\DBAL\Connection;

/**
 * Cálculo de huecos disponibles (docs/02-logica-disponibilidad.md).
 *
 * Disponibilidad = horario laboral del profesional
 *                − citas/tramos ocupados
 *                − bloqueos (vacaciones, descansos)
 *                − margen del servicio
 *
 * Los servicios con tiempos muertos (tintes) sólo ocupan al profesional
 * durante sus segmentos activos: durante el reposo el hueco queda libre.
 */
final class AvailabilityService
{
    private const SLOT_MINUTES = 15;
    private const MIN_ADVANCE_MINUTES = 30;

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @return array{date: string, slots: list<array{start: string, staff_id: int}>}
     */
    public function find(int $locationId, int $serviceId, ?int $staffId, string $date): array
    {
        $service = $this->db->fetchAssociative(
            'SELECT id, duration_min, buffer_min FROM service WHERE id = ? AND active',
            [$serviceId]
        );
        if ($service === false) {
            throw new \InvalidArgumentException('Servicio no encontrado o inactivo.');
        }

        $tz = $this->db->fetchOne('SELECT timezone FROM location WHERE id = ? AND active', [$locationId]);
        if ($tz === false) {
            throw new \InvalidArgumentException('Sede no encontrada o inactiva.');
        }

        $zone = new \DateTimeZone((string) $tz);
        $utc = new \DateTimeZone('UTC');

        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, $zone);
        if ($day === false) {
            throw new \InvalidArgumentException('Fecha inválida (formato esperado: YYYY-MM-DD).');
        }

        $occupancy = $this->occupancy($service);
        $spanMin = $occupancy['span'];
        $busyOffsets = $occupancy['busy'];

        $weekday = ((int) $day->format('N')) - 1; // PHP: 1=lun..7=dom → 0=lun..6=dom

        // Profesionales que pueden hacer el servicio en esa sede
        $staffIds = $this->db->fetchFirstColumn(
            'SELECT s.id
               FROM staff s
               JOIN staff_service  ss ON ss.staff_id = s.id AND ss.service_id = :svc
               JOIN staff_location sl ON sl.staff_id = s.id AND sl.location_id = :loc
              WHERE s.active AND (:staff::bigint IS NULL OR s.id = :staff)
              ORDER BY s.id',
            ['svc' => $serviceId, 'loc' => $locationId, 'staff' => $staffId]
        );

        $now = new \DateTimeImmutable('now', $utc);
        $minStart = $now->modify('+' . self::MIN_ADVANCE_MINUTES . ' minutes');

        $dayStartUtc = $day->setTime(0, 0)->setTimezone($utc);
        $dayEndUtc = $day->modify('+1 day')->setTime(0, 0)->setTimezone($utc);

        /** @var array<string, list<int>> $slots */
        $slots = [];

        foreach ($staffIds as $sid) {
            $sid = (int) $sid;

            $shifts = $this->db->fetchAllAssociative(
                'SELECT start_time, end_time FROM staff_schedule
                  WHERE staff_id = ? AND location_id = ? AND weekday = ?
                  ORDER BY start_time',
                [$sid, $locationId, $weekday]
            );
            if ($shifts === []) {
                continue;
            }

            // Tramos ocupados del profesional ese día (citas activas + ausencias)
            $busyRows = $this->db->fetchAllAssociative(
                'SELECT start_at, end_at FROM appointment_busy_block
                  WHERE staff_id = :s AND start_at < :end AND end_at > :start
                 UNION ALL
                 SELECT start_at, end_at FROM time_block
                  WHERE staff_id = :s AND start_at < :end AND end_at > :start',
                ['s' => $sid, 'start' => $dayStartUtc->format('c'), 'end' => $dayEndUtc->format('c')]
            );
            $busyRanges = array_map(
                static fn (array $b): array => [
                    new \DateTimeImmutable($b['start_at'], $utc),
                    new \DateTimeImmutable($b['end_at'], $utc),
                ],
                $busyRows
            );

            foreach ($shifts as $shift) {
                $shiftStart = $day->setTime(
                    (int) substr((string) $shift['start_time'], 0, 2),
                    (int) substr((string) $shift['start_time'], 3, 2)
                );
                $shiftEnd = $day->setTime(
                    (int) substr((string) $shift['end_time'], 0, 2),
                    (int) substr((string) $shift['end_time'], 3, 2)
                );

                $cursor = $shiftStart;
                // La cita completa (incluido reposo y margen) debe caber en el turno
                while ($cursor->modify('+' . $spanMin . ' minutes') <= $shiftEnd) {
                    $startUtc = $cursor->setTimezone($utc);
                    if ($startUtc >= $minStart && !$this->conflicts($cursor, $busyOffsets, $busyRanges, $utc)) {
                        $slots[$startUtc->format('c')][] = $sid;
                    }
                    $cursor = $cursor->modify('+' . self::SLOT_MINUTES . ' minutes');
                }
            }
        }

        ksort($slots);

        $result = [];
        foreach ($slots as $iso => $sids) {
            // "Sin preferencia": se ofrece el hueco una vez; al confirmar se asigna profesional
            $result[] = ['start' => $iso, 'staff_id' => $sids[0]];
        }

        return ['date' => $date, 'slots' => $result];
    }

    /**
     * Próximo hueco libre de un profesional concreto, buscando hacia delante
     * desde $fromDate hasta $maxDays días. null si no hay en la ventana.
     *
     * @return array{date: string, start: string}|null
     */
    public function nextSlotForStaff(int $locationId, int $serviceId, int $staffId, string $fromDate, int $maxDays = 21): ?array
    {
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $fromDate);
        if ($day === false) {
            throw new \InvalidArgumentException('Fecha inválida (formato esperado: YYYY-MM-DD).');
        }

        for ($i = 0; $i < $maxDays; $i++) {
            $date = $day->modify('+' . $i . ' day')->format('Y-m-d');
            $slots = $this->find($locationId, $serviceId, $staffId, $date)['slots'];
            if ($slots !== []) {
                return ['date' => $date, 'start' => $slots[0]['start']];
            }
        }

        return null;
    }

    /**
     * Para cada profesional que puede hacer el servicio en la sede, su próximo
     * hueco libre. Útil en el panel para colocar rápido una cita en el primer
     * profesional disponible.
     *
     * @return list<array{staff_id: int, staff_name: string, next: array{date: string, start: string}|null}>
     */
    public function nextSlotsByStaff(int $locationId, int $serviceId, string $fromDate, int $maxDays = 21): array
    {
        $staff = $this->db->fetchAllAssociative(
            'SELECT s.id, s.name
               FROM staff s
               JOIN staff_service  ss ON ss.staff_id = s.id AND ss.service_id = ?
               JOIN staff_location sl ON sl.staff_id = s.id AND sl.location_id = ?
              WHERE s.active
              ORDER BY s.name',
            [$serviceId, $locationId]
        );

        $out = [];
        foreach ($staff as $row) {
            $sid = (int) $row['id'];
            $out[] = [
                'staff_id' => $sid,
                'staff_name' => (string) $row['name'],
                'next' => $this->nextSlotForStaff($locationId, $serviceId, $sid, $fromDate, $maxDays),
            ];
        }

        return $out;
    }

    /**
     * Minutos totales que el servicio ocupa en la agenda (incluye reposos y margen).
     * Sirve para calcular end_at al crear/reprogramar una cita.
     */
    public function spanMinutes(int $serviceId): int
    {
        $service = $this->db->fetchAssociative(
            'SELECT id, duration_min, buffer_min FROM service WHERE id = ? AND active',
            [$serviceId]
        );
        if ($service === false) {
            throw new \InvalidArgumentException('Servicio no encontrado o inactivo.');
        }

        return $this->occupancy($service)['span'];
    }

    /**
     * ¿Algún tramo ocupado del servicio (a partir de $startLocal) solapa con algo ya reservado?
     *
     * @param list<array{0: int, 1: int}>                          $busyOffsets  [offset, duración] de cada tramo activo
     * @param list<array{0: \DateTimeImmutable, 1: \DateTimeImmutable}> $busyRanges
     */
    private function conflicts(
        \DateTimeImmutable $startLocal,
        array $busyOffsets,
        array $busyRanges,
        \DateTimeZone $utc
    ): bool {
        foreach ($busyOffsets as [$off, $len]) {
            $bStart = $startLocal->modify('+' . $off . ' minutes')->setTimezone($utc);
            $bEnd = $startLocal->modify('+' . ($off + $len) . ' minutes')->setTimezone($utc);
            foreach ($busyRanges as [$rStart, $rEnd]) {
                if ($bStart < $rEnd && $bEnd > $rStart) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Calcula la huella temporal del servicio:
     *  - span: minutos totales que ocupa en la agenda (incluye reposos y margen)
     *  - busy: tramos [offset, duración] en los que el profesional está realmente ocupado
     *
     * @param array{id: mixed, duration_min: mixed, buffer_min: mixed} $service
     *
     * @return array{span: int, busy: list<array{0: int, 1: int}>}
     */
    private function occupancy(array $service): array
    {
        $segments = $this->db->fetchAllAssociative(
            'SELECT minutes, busy FROM service_segment WHERE service_id = ? ORDER BY position',
            [$service['id']]
        );
        $buffer = (int) $service['buffer_min'];

        if ($segments === []) {
            $total = (int) $service['duration_min'] + $buffer;

            return ['span' => $total, 'busy' => [[0, $total]]];
        }

        $busy = [];
        $offset = 0;
        foreach ($segments as $seg) {
            $minutes = (int) $seg['minutes'];
            if ($this->asBool($seg['busy'])) {
                $busy[] = [$offset, $minutes];
            }
            $offset += $minutes;
        }
        if ($buffer > 0) {
            $busy[] = [$offset, $buffer];
            $offset += $buffer;
        }

        return ['span' => $offset, 'busy' => $busy];
    }

    private function asBool(mixed $value): bool
    {
        return $value === true || $value === 't' || $value === 1 || $value === '1';
    }
}
