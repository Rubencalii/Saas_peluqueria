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
        [$locationId, $from, $to, , $accountId] = $ctx;
        [$where, $params] = $this->scope($locationId, $accountId, $from, $to);

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
        [$locationId, $from, $to, , $accountId] = $ctx;
        [$where, $params] = $this->scope($locationId, $accountId, $from, $to);

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
     * Ingresos (doc 13 §2.8): por profesional y por servicio en el rango. Solo
     * citas completadas; el precio sale de service_location.price_override o, en
     * su defecto, service.price.
     */
    #[Route('/api/v1/admin/reports/revenue', name: 'admin_report_revenue', methods: ['GET'])]
    public function revenue(Request $request): JsonResponse
    {
        $ctx = $this->context($request, requireLocation: false);
        if ($ctx instanceof JsonResponse) {
            return $ctx;
        }
        [$locationId, $from, $to, , $accountId] = $ctx;
        [$where, $params] = $this->scope($locationId, $accountId, $from, $to, 'a');
        $where .= " AND a.status = 'completada'";

        $price = 'COALESCE(sl.price_override, s.price)';
        $join = 'FROM appointment a
                   JOIN service s ON s.id = a.service_id
                   LEFT JOIN service_location sl ON sl.service_id = a.service_id AND sl.location_id = a.location_id';

        $byStaff = $this->db->fetchAllAssociative(
            "SELECT a.staff_id, st.name AS staff_name, COUNT(*) AS appts,
                    COALESCE(SUM($price), 0) AS revenue
               $join LEFT JOIN staff st ON st.id = a.staff_id
              WHERE $where GROUP BY a.staff_id, st.name ORDER BY revenue DESC",
            $params
        );
        $byService = $this->db->fetchAllAssociative(
            "SELECT a.service_id, s.name AS service_name, COUNT(*) AS appts,
                    COALESCE(SUM($price), 0) AS revenue
               $join WHERE $where GROUP BY a.service_id, s.name ORDER BY revenue DESC",
            $params
        );
        $total = (float) $this->db->fetchOne("SELECT COALESCE(SUM($price), 0) $join WHERE $where", $params);

        return $this->json([
            'location_id' => $locationId,
            'from' => $from->format('Y-m-d'),
            'to' => $to->modify('-1 day')->format('Y-m-d'),
            'total_revenue' => round($total, 2),
            'by_staff' => array_map(static fn (array $r): array => [
                'staff_id' => $r['staff_id'] !== null ? (int) $r['staff_id'] : null,
                'staff_name' => $r['staff_name'] !== null ? (string) $r['staff_name'] : null,
                'appointments' => (int) $r['appts'],
                'revenue' => round((float) $r['revenue'], 2),
            ], $byStaff),
            'by_service' => array_map(static fn (array $r): array => [
                'service_id' => (int) $r['service_id'],
                'service_name' => (string) $r['service_name'],
                'appointments' => (int) $r['appts'],
                'revenue' => round((float) $r['revenue'], 2),
            ], $byService),
        ]);
    }

    /**
     * Horas punta (doc 13 §2.8): nº de citas por día de la semana y por hora
     * local. Útil para dimensionar plantilla.
     */
    #[Route('/api/v1/admin/reports/peak-hours', name: 'admin_report_peak_hours', methods: ['GET'])]
    public function peakHours(Request $request): JsonResponse
    {
        $ctx = $this->context($request, requireLocation: false);
        if ($ctx instanceof JsonResponse) {
            return $ctx;
        }
        [$locationId, $from, $to, $tz, $accountId] = $ctx;
        [$where, $params] = $this->scope($locationId, $accountId, $from, $to, 'a');
        $where .= " AND a.status IN ('confirmada','completada')";

        // weekday 0=lun..6=dom (igual que staff_schedule); hora local de la sede.
        $local = 'a.start_at AT TIME ZONE ?';
        $params2 = array_merge([$tz->getName(), $tz->getName()], $params);
        $rows = $this->db->fetchAllAssociative(
            "SELECT ((EXTRACT(ISODOW FROM ($local))::int + 6) % 7) AS weekday,
                    EXTRACT(HOUR FROM ($local))::int AS hour,
                    COUNT(*) AS total
               FROM appointment a
              WHERE $where
              GROUP BY weekday, hour ORDER BY weekday, hour",
            $params2
        );

        return $this->json([
            'location_id' => $locationId,
            'from' => $from->format('Y-m-d'),
            'to' => $to->modify('-1 day')->format('Y-m-d'),
            'timezone' => $tz->getName(),
            'slots' => array_map(static fn (array $r): array => [
                'weekday' => (int) $r['weekday'],
                'hour' => (int) $r['hour'],
                'appointments' => (int) $r['total'],
            ], $rows),
        ]);
    }

    /**
     * Retención (doc 13 §2.8): de los clientes con cita en el rango, qué
     * proporción ha venido más de una vez (histórico completo).
     */
    #[Route('/api/v1/admin/reports/retention', name: 'admin_report_retention', methods: ['GET'])]
    public function retention(Request $request): JsonResponse
    {
        $ctx = $this->context($request, requireLocation: false);
        if ($ctx instanceof JsonResponse) {
            return $ctx;
        }
        [$locationId, $from, $to, , $accountId] = $ctx;
        [$where, $params] = $this->scope($locationId, $accountId, $from, $to, 'a');
        $where .= " AND a.status IN ('confirmada','completada')";

        // Clientes con actividad en el rango y su nº total de visitas (histórico).
        $row = $this->db->fetchAssociative(
            "WITH activos AS (
                SELECT DISTINCT a.customer_id FROM appointment a WHERE $where
             )
             SELECT COUNT(*) AS total_clientes,
                    COUNT(*) FILTER (WHERE visitas > 1) AS recurrentes
               FROM (
                 SELECT ac.customer_id,
                        (SELECT COUNT(*) FROM appointment h
                          WHERE h.customer_id = ac.customer_id
                            AND h.status IN ('confirmada','completada')) AS visitas
                   FROM activos ac
               ) t",
            $params
        );

        $total = (int) ($row['total_clientes'] ?? 0);
        $recurrentes = (int) ($row['recurrentes'] ?? 0);

        return $this->json([
            'location_id' => $locationId,
            'from' => $from->format('Y-m-d'),
            'to' => $to->modify('-1 day')->format('Y-m-d'),
            'customers' => $total,
            'returning_customers' => $recurrentes,
            'retention_rate' => $total > 0 ? round($recurrentes / $total, 4) : null,
        ]);
    }

    /**
     * Clientes con más ausencias (doc 13 §2.2, anti no-show): ranking por nº de
     * no_show en el rango, para aplicar política (aviso, depósito).
     */
    #[Route('/api/v1/admin/reports/no-show-customers', name: 'admin_report_noshow_customers', methods: ['GET'])]
    public function noShowCustomers(Request $request): JsonResponse
    {
        $ctx = $this->context($request, requireLocation: false);
        if ($ctx instanceof JsonResponse) {
            return $ctx;
        }
        [$locationId, $from, $to, , $accountId] = $ctx;
        [$where, $params] = $this->scope($locationId, $accountId, $from, $to, 'a');
        $where .= " AND a.status = 'no_show'";

        $rows = $this->db->fetchAllAssociative(
            "SELECT c.id, c.name, c.phone, COUNT(*) AS no_shows
               FROM appointment a JOIN customer c ON c.id = a.customer_id
              WHERE $where
              GROUP BY c.id, c.name, c.phone
              ORDER BY no_shows DESC, c.name LIMIT 50",
            $params
        );

        return $this->json([
            'location_id' => $locationId,
            'from' => $from->format('Y-m-d'),
            'to' => $to->modify('-1 day')->format('Y-m-d'),
            'customers' => array_map(static fn (array $r): array => [
                'id' => (int) $r['id'],
                'name' => (string) $r['name'],
                'phone' => (string) $r['phone'],
                'no_shows' => (int) $r['no_shows'],
            ], $rows),
        ]);
    }

    /**
     * Filtro común por rango (UTC) y, si procede, sede. $alias prefija las
     * columnas cuando la consulta tiene JOINs (p. ej. 'a' → a.start_at).
     *
     * @return array{0: string, 1: list<string|int>}
     */
    /**
     * Serie mensual (últimos 12 meses, incluido el actual): citas completadas
     * e ingresos por mes, con los meses sin actividad a cero. Para la gráfica
     * de evolución del panel.
     */
    #[Route('/api/v1/admin/reports/monthly', name: 'admin_report_monthly', methods: ['GET'])]
    public function monthly(Request $request): JsonResponse
    {
        $ctx = $this->context($request, requireLocation: false);
        if ($ctx instanceof JsonResponse) {
            return $ctx;
        }
        [$locationId, , , $tz, $accountId] = $ctx;

        $from = (new \DateTimeImmutable('first day of this month 00:00', $tz))->modify('-11 months');
        $to = new \DateTimeImmutable('first day of next month 00:00', $tz);
        [$where, $params] = $this->scope($locationId, $accountId, $from, $to, 'a');
        $where .= " AND a.status = 'completada'";

        $price = 'COALESCE(sl.price_override, s.price)';
        $rows = $this->db->fetchAllAssociative(
            "SELECT to_char(date_trunc('month', a.start_at AT TIME ZONE ?), 'YYYY-MM') AS month,
                    COUNT(*) AS appts, COALESCE(SUM($price), 0) AS revenue
               FROM appointment a
               JOIN service s ON s.id = a.service_id
               LEFT JOIN service_location sl ON sl.service_id = a.service_id AND sl.location_id = a.location_id
              WHERE $where
              GROUP BY 1 ORDER BY 1",
            array_merge([$tz->getName()], $params)
        );

        // Serie completa: los meses sin datos también cuentan (a cero).
        $series = [];
        for ($m = $from; $m < $to; $m = $m->modify('+1 month')) {
            $series[$m->format('Y-m')] = ['month' => $m->format('Y-m'), 'appointments' => 0, 'revenue' => 0.0];
        }
        foreach ($rows as $r) {
            $key = (string) $r['month'];
            if (isset($series[$key])) {
                $series[$key]['appointments'] = (int) $r['appts'];
                $series[$key]['revenue'] = round((float) $r['revenue'], 2);
            }
        }

        return $this->json(['location_id' => $locationId, 'months' => array_values($series)]);
    }

    private function scope(?int $locationId, int $accountId, \DateTimeImmutable $from, \DateTimeImmutable $to, string $alias = ''): array
    {
        $p = $alias !== '' ? $alias . '.' : '';
        $where = "{$p}start_at >= ? AND {$p}start_at < ?";
        $params = [
            $from->setTimezone(new \DateTimeZone('UTC'))->format('c'),
            $to->setTimezone(new \DateTimeZone('UTC'))->format('c'),
        ];
        if ($locationId !== null) {
            // La sede ya quedó verificada como de la cuenta en context().
            $where .= " AND {$p}location_id = ?";
            $params[] = $locationId;
        } else {
            // admin_cadena sin sede fija: agrega SOLO sobre las sedes de su cuenta.
            $where .= " AND {$p}location_id IN (SELECT id FROM location WHERE account_id = ?)";
            $params[] = $accountId;
        }

        return [$where, $params];
    }

    /**
     * Resuelve usuario, sede y rango [from, to) de fechas locales.
     *
     * @return array{0: int|null, 1: \DateTimeImmutable, 2: \DateTimeImmutable, 3: \DateTimeZone, 4: int}|JsonResponse
     */
    private function context(Request $request, bool $requireLocation): array|JsonResponse
    {
        $user = self::user($request);
        $accountId = $user['account_id'];
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
            // La sede debe ser de la cuenta del usuario (aísla a admin_cadena de otras cuentas).
            $tzName = $this->db->fetchOne('SELECT timezone FROM location WHERE id = ? AND account_id = ?', [$locationId, $accountId]);
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

        return [$locationId, $from->setTime(0, 0), $to, $tz, $accountId];
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
