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
 * Agenda del salón (docs/06 §4). Lista las citas de una sede en un día o una
 * semana. Las horas se devuelven en UTC (ISO 8601); el panel las pinta en la
 * zona local de la sede, que se incluye en la respuesta.
 */
final class AdminAgendaController extends AdminController
{
    public function __construct(
        private readonly Connection $db,
        private readonly AuthService $auth,
    ) {
    }

    #[Route('/api/v1/admin/agenda', name: 'admin_agenda', methods: ['GET'])]
    public function agenda(Request $request): JsonResponse
    {
        $user = self::user($request);

        $locationId = $request->query->get('location_id');
        if ($locationId === null || (int) $locationId <= 0) {
            // admin_cadena no tiene sede fija; el resto cae en la suya.
            $locationId = $user['location_id'];
        } else {
            $locationId = (int) $locationId;
        }
        if ($locationId === null) {
            return $this->error('VALIDATION', 'Indica location_id para ver la agenda.', 400);
        }

        try {
            $this->auth->assertLocation($user, $locationId);
        } catch (AuthException $e) {
            return $this->error($e->errorCode, $e->getMessage(), $e->statusCode);
        }

        $location = $this->db->fetchAssociative(
            'SELECT id, name, slug, timezone FROM location WHERE id = ?',
            [$locationId]
        );
        if ($location === false) {
            return $this->error('NOT_FOUND', 'Sede no encontrada.', 404);
        }

        $tz = new \DateTimeZone((string) $location['timezone']);
        $view = $request->query->get('view') === 'week' ? 'week' : 'day';

        $dateRaw = (string) $request->query->get('date', '');
        $anchor = \DateTimeImmutable::createFromFormat('!Y-m-d', $dateRaw, $tz);
        if ($anchor === false || $anchor->format('Y-m-d') !== $dateRaw) {
            $anchor = new \DateTimeImmutable('now', $tz);
        }

        if ($view === 'week') {
            // Lunes 00:00 local de la semana del anchor → +7 días.
            $dow = (int) $anchor->format('N'); // 1 (lun) .. 7 (dom)
            $from = $anchor->modify('-' . ($dow - 1) . ' days')->setTime(0, 0);
            $to = $from->modify('+7 days');
        } else {
            $from = $anchor->setTime(0, 0);
            $to = $from->modify('+1 day');
        }

        $rows = $this->db->fetchAllAssociative(
            'SELECT a.id, a.status, a.channel, a.start_at, a.end_at, a.notes, a.public_code,
                    a.service_id, s.name AS service_name, s.duration_min,
                    a.staff_id, st.name AS staff_name,
                    a.customer_id, c.name AS customer_name, c.phone AS customer_phone
               FROM appointment a
               JOIN service  s  ON s.id  = a.service_id
               LEFT JOIN staff st ON st.id = a.staff_id
               LEFT JOIN customer c ON c.id = a.customer_id
              WHERE a.location_id = ?
                AND a.start_at >= ? AND a.start_at < ?
                AND a.status <> \'cancelada\'
              ORDER BY a.start_at, st.name',
            [$locationId, $from->setTimezone(new \DateTimeZone('UTC'))->format('c'),
             $to->setTimezone(new \DateTimeZone('UTC'))->format('c')]
        );

        return $this->json([
            'location' => [
                'id' => (int) $location['id'],
                'name' => (string) $location['name'],
                'slug' => (string) $location['slug'],
                'timezone' => (string) $location['timezone'],
            ],
            'view' => $view,
            'from' => $from->setTimezone(new \DateTimeZone('UTC'))->format('c'),
            'to' => $to->setTimezone(new \DateTimeZone('UTC'))->format('c'),
            'appointments' => array_map($this->present(...), $rows),
        ]);
    }

    /**
     * @param array<string, mixed> $r
     *
     * @return array<string, mixed>
     */
    private function present(array $r): array
    {
        return [
            'appointment_id' => (int) $r['id'],
            'status' => (string) $r['status'],
            'channel' => (string) $r['channel'],
            'start' => (new \DateTimeImmutable($r['start_at']))->format('c'),
            'end' => (new \DateTimeImmutable($r['end_at']))->format('c'),
            'notes' => $r['notes'] !== null ? (string) $r['notes'] : null,
            'public_code' => $r['public_code'] !== null ? (string) $r['public_code'] : null,
            'service' => [
                'id' => (int) $r['service_id'],
                'name' => (string) $r['service_name'],
                'duration_min' => (int) $r['duration_min'],
            ],
            'staff' => $r['staff_id'] !== null
                ? ['id' => (int) $r['staff_id'], 'name' => (string) $r['staff_name']]
                : null,
            'customer' => $r['customer_id'] !== null ? [
                'id' => (int) $r['customer_id'],
                'name' => (string) $r['customer_name'],
                'phone' => (string) $r['customer_phone'],
            ] : null,
        ];
    }
}
