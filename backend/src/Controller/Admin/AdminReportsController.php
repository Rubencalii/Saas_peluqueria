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
 * Informes del panel (docs/06 §4): ocupación, ausencias (no-show) y reservas
 * por canal. Disponibles para admin_sede (su sede) y admin_cadena.
 */
final class AdminReportsController extends AdminController
{
    private const REPORT_ROLES = ['admin_sede', 'admin_cadena'];

    public function __construct(
        private readonly Connection $db,
        private readonly AuthService $auth,
    ) {
    }

    /**
     * Ocupación: minutos reservados frente a la capacidad (horarios) de la
     * sede en el rango. Requiere location_id (la capacidad depende de la sede).
     */
    #[Route('/api/v1/admin/reports/occupancy', name: 'admin_report_occupancy', methods: ['GET'])]
    public function occupancy(Request $request): JsonResponse
    {
        $ctx = $this->context($request, requireLocation: true);
        if ($ctx instanceof JsonResponse) {
            return $ctx;
        }
        [$locationId, $from, $to, $tz] = $ctx;

        $fromUtc = $from->setTimezone(new \DateTimeZone('UTC'));
        $toUtc = $to->setTimezone(new \DateTimeZone('UTC'));

        // Minutos reservados (citas que ocupan agenda) en el rango.
        $booked = (int) $this->db->fetchOne(
            "SELECT COALESCE(SUM(EXTRACT(EPOCH FROM (end_at - start_at)) / 60), 0)
               FROM appointment
              WHERE location_id = ? AND start_at >= ? AND start_at < ?
                AND status IN ('pendiente','confirmada','completada')",
            [$locationId, $fromUtc->format('c'), $toUtc->format('c')]
        );

        // Capacidad: suma de minutos de horario de los profesionales de la sede,
        // por cada día del rango según su weekday (0=lun..6=dom).
        $schedules = $this->db->fetchAllAssociative(
            'SELECT weekday, EXTRACT(EPOCH FROM (end_time - start_time)) / 60 AS minutes
               FROM staff_schedule WHERE location_id = ?',
            [$locationId]
        );
        $minutesByWeekday = array_fill(0, 7, 0.0);
        foreach ($schedules as $s) {
            $minutesByWeekday[(int) $s['weekday']] += (float) $s['minutes'];
        }
        $capacity = 0.0;
        for ($day = $from; $day < $to; $day = $day->modify('+1 day')) {
            $capacity += $minutesByWeekday[((int) $day->format('N')) - 1];
        }

        $byStaff = $this->db->fetchAllAssociative(
            "SELECT a.staff_id, st.name AS staff_name,
                    COALESCE(SUM(EXTRACT(EPOCH FROM (a.end_at - a.start_at)) / 60), 0) AS minutes,
                    COUNT(*) AS appointments
               FROM appointment a
               LEFT JOIN staff st ON st.id = a.staff_id
              WHERE a.location_id = ? AND a.start_at >= ? AND a.start_at < ?
                AND a.status IN ('pendiente','confirmada','completada')
              GROUP BY a.staff_id, st.name ORDER BY minutes DESC",
            [$locationId, $fromUtc->format('c'), $toUtc->format('c')]
        );

        return $this->json([
            'location_id' => $locationId,
            'timezone' => $tz->getName(),
            'from' => $from->format('Y-m-d'),
            'to' => $to->modify('-1 day')->format('Y-m-d'),
            'booked_minutes' => $booked,
            'capacity_minutes' => (int) $capacity,
            'occupancy_rate' => $capacity > 0 ? round($booked / $capacity, 4) : null,
            'by_staff' => array_map(static fn (array $r): array => [
                'staff_id' => $r['staff_id'] !== null ? (int) $r['staff_id'] : null,
                'staff_name' => $r['staff_name'] !== null ? (string) $r['staff_name'] : null,
                'booked_minutes' => (int) $r['minutes'],
                'appointments' => (int) $r['appointments'],
            ], $byStaff),
        ]);
    }

    /**
     * Ausencias: ratio de citas marcadas no_show sobre el total terminado.
     */
    #[Route('/api/v1/admin/reports/no-shows', name: 'admin_report_noshows', methods: ['GET'])]
    public function noShows(Request $request): JsonResponse
    {
        $ctx = $this->context($request, requireLocation: false);
        if ($ctx instanceof JsonResponse) {
            return $ctx;
        }
        [$locationId, $from, $to] = $ctx;
        [$where, $params] = $this->scope($locationId, $from, $to);

        $totals = $this->db->fetchAssociative(
            "SELECT COUNT(*) FILTER (WHERE status = 'no_show')    AS no_shows,
                    COUNT(*) FILTER (WHERE status = 'completada') AS completed,
                    COUNT(*) FILTER (WHERE status IN ('no_show','completada')) AS finished
               FROM appointment WHERE $where",
            $params
        );
        $finished = (int) $totals['finished'];

        return $this->json([
            'location_id' => $locationId,
            'from' => $from->format('Y-m-d'),
            'to' => $to->modify('-1 day')->format('Y-m-d'),
            'no_shows' => (int) $totals['no_shows'],
            'completed' => (int) $totals['completed'],
            'no_show_rate' => $finished > 0 ? round((int) $totals['no_shows'] / $finished, 4) : null,
        ]);
    }

    /**
     * Reservas por canal (web/whatsapp/manual) en el rango.
     */
    #[Route('/api/v1/admin/reports/bookings-by-channel', name: 'admin_report_channels', methods: ['GET'])]
    public function bookingsByChannel(Request $request): JsonResponse
    {
        $ctx = $this->context($request, requireLocation: false);
        if ($ctx instanceof JsonResponse) {
            return $ctx;
        }
        [$locationId, $from, $to] = $ctx;
        [$where, $params] = $this->scope($locationId, $from, $to);

        $rows = $this->db->fetchAllAssociative(
            "SELECT channel, COUNT(*) AS total FROM appointment WHERE $where GROUP BY channel",
            $params
        );

        $byChannel = ['web' => 0, 'whatsapp' => 0, 'manual' => 0];
        foreach ($rows as $r) {
            $byChannel[(string) $r['channel']] = (int) $r['total'];
        }

        return $this->json([
            'location_id' => $locationId,
            'from' => $from->format('Y-m-d'),
            'to' => $to->modify('-1 day')->format('Y-m-d'),
            'by_channel' => $byChannel,
            'total' => array_sum($byChannel),
        ]);
    }

    /**
     * Filtro común para no-shows/canales: por rango (UTC) y, si procede, sede.
     *
     * @return array{0: string, 1: list<string|int>}
     */
    private function scope(?int $locationId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $where = 'start_at >= ? AND start_at < ?';
        $params = [
            $from->setTimezone(new \DateTimeZone('UTC'))->format('c'),
            $to->setTimezone(new \DateTimeZone('UTC'))->format('c'),
        ];
        if ($locationId !== null) {
            $where .= ' AND location_id = ?';
            $params[] = $locationId;
        }

        return [$where, $params];
    }

    /**
     * Resuelve usuario, sede y rango [from, to) de fechas locales.
     *
     * @return array{0: int|null, 1: \DateTimeImmutable, 2: \DateTimeImmutable, 3: \DateTimeZone}|JsonResponse
     */
    private function context(Request $request, bool $requireLocation): array|JsonResponse
    {
        $user = self::user($request);
        try {
            $this->auth->assertRole($user, self::REPORT_ROLES);
            $requested = $request->query->get('location_id');
            $locationId = $this->auth->resolveLocation($user, $requested !== null && (int) $requested > 0 ? (int) $requested : null);
        } catch (AuthException $e) {
            return $this->error($e->errorCode, $e->getMessage(), $e->statusCode);
        }

        if ($requireLocation && $locationId === null) {
            return $this->error('VALIDATION', 'Indica location_id para este informe.', 400);
        }

        $tz = new \DateTimeZone('Europe/Madrid');
        if ($locationId !== null) {
            $tzName = $this->db->fetchOne('SELECT timezone FROM location WHERE id = ?', [$locationId]);
            if ($tzName === false) {
                return $this->error('NOT_FOUND', 'Sede no encontrada.', 404);
            }
            $tz = new \DateTimeZone((string) $tzName);
        }

        $from = $this->parseDate($request->query->get('from'), $tz)
            ?? new \DateTimeImmutable('first day of this month 00:00', $tz);
        // 'to' es inclusivo en la petición; internamente usamos [from, to+1día).
        $toInclusive = $this->parseDate($request->query->get('to'), $tz)
            ?? new \DateTimeImmutable('now', $tz);
        $to = $toInclusive->setTime(0, 0)->modify('+1 day');

        if ($to <= $from) {
            return $this->error('VALIDATION', 'El rango de fechas es inválido (to debe ser >= from).', 400);
        }

        return [$locationId, $from->setTime(0, 0), $to, $tz];
    }

    private function parseDate(mixed $raw, \DateTimeZone $tz): ?\DateTimeImmutable
    {
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw, $tz);

        return $d !== false && $d->format('Y-m-d') === $raw ? $d : null;
    }
}
