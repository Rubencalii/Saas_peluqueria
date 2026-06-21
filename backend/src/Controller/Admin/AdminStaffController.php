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
 * Configuración de personal y horarios (docs/06 §4).
 *
 * Un profesional puede trabajar en varias sedes (staff_location) y ofrecer
 * varios servicios (staff_service). El horario semanal recurrente vive en
 * staff_schedule (weekday 0=lun..6=dom, horas locales de la sede). Sólo
 * admin_sede (su sede) y admin_cadena configuran estos datos.
 */
final class AdminStaffController extends AdminController
{
    private const CONFIG_ROLES = ['admin_sede', 'admin_cadena'];

    public function __construct(
        private readonly Connection $db,
        private readonly AuthService $auth,
    ) {
    }

    #[Route('/api/v1/admin/staff', name: 'admin_staff_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $user = self::user($request);
        try {
            $this->auth->assertRole($user, self::CONFIG_ROLES);
            $locationId = $this->auth->resolveLocation($user, $this->intParam($request, 'location_id'));
        } catch (AuthException $e) {
            return $this->error($e->errorCode, $e->getMessage(), $e->statusCode);
        }

        if ($locationId !== null) {
            $rows = $this->db->fetchAllAssociative(
                'SELECT s.id, s.name, s.email, s.phone, s.active
                   FROM staff s
                   JOIN staff_location sl ON sl.staff_id = s.id AND sl.location_id = ?
                  ORDER BY s.name',
                [$locationId]
            );
        } else {
            $rows = $this->db->fetchAllAssociative(
                'SELECT id, name, email, phone, active FROM staff ORDER BY name'
            );
        }

        return $this->json(['staff' => array_map(function (array $r): array {
            $id = (int) $r['id'];

            return [
                'id' => $id,
                'name' => (string) $r['name'],
                'email' => $r['email'] !== null ? (string) $r['email'] : null,
                'phone' => $r['phone'] !== null ? (string) $r['phone'] : null,
                'active' => (bool) $r['active'],
                'location_ids' => $this->idList('SELECT location_id FROM staff_location WHERE staff_id = ?', $id),
                'service_ids' => $this->idList('SELECT service_id FROM staff_service WHERE staff_id = ?', $id),
            ];
        }, $rows)]);
    }

    #[Route('/api/v1/admin/staff', name: 'admin_staff_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $user = self::user($request);
        try {
            $this->auth->assertRole($user, self::CONFIG_ROLES);
        } catch (AuthException $e) {
            return $this->error($e->errorCode, $e->getMessage(), $e->statusCode);
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->error('VALIDATION', 'El cuerpo debe ser un objeto JSON.', 400);
        }
        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '') {
            return $this->error('VALIDATION', 'El nombre es obligatorio.', 400);
        }

        // Un admin_sede sólo puede asignar a su propia sede.
        $locationIds = $this->normalizeIds($payload['location_ids'] ?? []);
        if ($user['role'] === 'admin_sede') {
            if ($locationIds === [] && $user['location_id'] !== null) {
                $locationIds = [$user['location_id']];
            }
            foreach ($locationIds as $lid) {
                try {
                    $this->auth->assertLocation($user, $lid);
                } catch (AuthException $e) {
                    return $this->error($e->errorCode, $e->getMessage(), $e->statusCode);
                }
            }
        }

        $id = $this->db->transactional(function (Connection $tx) use ($payload, $name, $locationIds): int {
            $sid = (int) $tx->fetchOne(
                'INSERT INTO staff (name, email, phone, active) VALUES (?, ?, ?, COALESCE(?, TRUE)) RETURNING id',
                [
                    $name,
                    isset($payload['email']) && $payload['email'] !== '' ? (string) $payload['email'] : null,
                    isset($payload['phone']) && $payload['phone'] !== '' ? (string) $payload['phone'] : null,
                    isset($payload['active']) ? (bool) $payload['active'] : null,
                ]
            );
            $this->replaceLinks($tx, 'staff_location', 'location_id', $sid, $locationIds);
            $this->replaceLinks($tx, 'staff_service', 'service_id', $sid, $this->normalizeIds($payload['service_ids'] ?? []));

            return $sid;
        });

        return $this->json(['id' => $id], 201);
    }

    #[Route('/api/v1/admin/staff/{id}', name: 'admin_staff_update', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $user = self::user($request);
        try {
            $this->auth->assertRole($user, self::CONFIG_ROLES);
        } catch (AuthException $e) {
            return $this->error($e->errorCode, $e->getMessage(), $e->statusCode);
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->error('VALIDATION', 'El cuerpo debe ser un objeto JSON.', 400);
        }
        if (!$this->staffExists($id)) {
            return $this->error('NOT_FOUND', 'Profesional no encontrado.', 404);
        }
        if (($denied = $this->assertManagesStaff($user, $id)) !== null) {
            return $denied;
        }

        $map = [
            'name' => static fn ($v) => trim((string) $v),
            'email' => static fn ($v) => $v !== null && $v !== '' ? (string) $v : null,
            'phone' => static fn ($v) => $v !== null && $v !== '' ? (string) $v : null,
            'active' => static fn ($v) => (bool) $v,
        ];
        $sets = [];
        $params = [];
        foreach ($map as $key => $cast) {
            if (array_key_exists($key, $payload)) {
                $sets[] = "$key = ?";
                $params[] = $cast($payload[$key]);
            }
        }

        $this->db->transactional(function (Connection $tx) use ($id, $sets, $params, $payload): void {
            if ($sets !== []) {
                $params[] = $id;
                $tx->executeStatement('UPDATE staff SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);
            }
            if (array_key_exists('location_ids', $payload)) {
                $this->replaceLinks($tx, 'staff_location', 'location_id', $id, $this->normalizeIds($payload['location_ids']));
            }
            if (array_key_exists('service_ids', $payload)) {
                $this->replaceLinks($tx, 'staff_service', 'service_id', $id, $this->normalizeIds($payload['service_ids']));
            }
        });

        return $this->json(['ok' => true]);
    }

    #[Route('/api/v1/admin/staff/{id}/schedule', name: 'admin_staff_schedule_get', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function getSchedule(int $id, Request $request): JsonResponse
    {
        $user = self::user($request);
        try {
            $this->auth->assertRole($user, self::CONFIG_ROLES);
        } catch (AuthException $e) {
            return $this->error($e->errorCode, $e->getMessage(), $e->statusCode);
        }
        if (!$this->staffExists($id)) {
            return $this->error('NOT_FOUND', 'Profesional no encontrado.', 404);
        }

        $rows = $this->db->fetchAllAssociative(
            'SELECT id, location_id, weekday, start_time, end_time
               FROM staff_schedule WHERE staff_id = ? ORDER BY weekday, start_time',
            [$id]
        );

        return $this->json(['schedule' => array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'location_id' => (int) $r['location_id'],
            'weekday' => (int) $r['weekday'],
            'start_time' => substr((string) $r['start_time'], 0, 5),
            'end_time' => substr((string) $r['end_time'], 0, 5),
        ], $rows)]);
    }

    /**
     * Reemplaza el horario semanal del profesional en una sede concreta.
     * Body: { location_id, entries: [{weekday, start_time, end_time}, ...] }
     */
    #[Route('/api/v1/admin/staff/{id}/schedule', name: 'admin_staff_schedule_set', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function setSchedule(int $id, Request $request): JsonResponse
    {
        $user = self::user($request);
        try {
            $this->auth->assertRole($user, self::CONFIG_ROLES);
        } catch (AuthException $e) {
            return $this->error($e->errorCode, $e->getMessage(), $e->statusCode);
        }
        if (!$this->staffExists($id)) {
            return $this->error('NOT_FOUND', 'Profesional no encontrado.', 404);
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->error('VALIDATION', 'El cuerpo debe ser un objeto JSON.', 400);
        }
        $locationId = (int) ($payload['location_id'] ?? 0);
        if ($locationId <= 0) {
            return $this->error('VALIDATION', 'location_id es obligatorio.', 400);
        }
        try {
            $this->auth->assertLocation($user, $locationId);
        } catch (AuthException $e) {
            return $this->error($e->errorCode, $e->getMessage(), $e->statusCode);
        }

        $entries = is_array($payload['entries'] ?? null) ? $payload['entries'] : [];
        foreach ($entries as $en) {
            $wd = (int) ($en['weekday'] ?? -1);
            if ($wd < 0 || $wd > 6 || !$this->isTime($en['start_time'] ?? null) || !$this->isTime($en['end_time'] ?? null)) {
                return $this->error('VALIDATION', 'Cada tramo: weekday 0-6 y horas HH:MM válidas.', 400);
            }
            if ((string) $en['start_time'] >= (string) $en['end_time']) {
                return $this->error('VALIDATION', 'start_time debe ser anterior a end_time.', 400);
            }
        }

        $this->db->transactional(function (Connection $tx) use ($id, $locationId, $entries): void {
            $tx->executeStatement(
                'DELETE FROM staff_schedule WHERE staff_id = ? AND location_id = ?',
                [$id, $locationId]
            );
            foreach ($entries as $en) {
                $tx->executeStatement(
                    'INSERT INTO staff_schedule (staff_id, location_id, weekday, start_time, end_time)
                     VALUES (?, ?, ?, ?, ?)',
                    [$id, $locationId, (int) $en['weekday'], (string) $en['start_time'], (string) $en['end_time']]
                );
            }
        });

        return $this->json(['ok' => true]);
    }

    /**
     * Devuelve la URL del feed iCal del profesional (doc 13 §2.6) para que se
     * suscriba en su calendario.
     */
    #[Route('/api/v1/admin/staff/{id}/calendar', name: 'admin_staff_calendar', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function getCalendar(int $id, Request $request): JsonResponse
    {
        $user = self::user($request);
        try {
            $this->auth->assertRole($user, self::CONFIG_ROLES);
        } catch (AuthException $e) {
            return $this->error($e->errorCode, $e->getMessage(), $e->statusCode);
        }
        if (($denied = $this->assertManagesStaff($user, $id)) !== null) {
            return $denied;
        }

        $token = $this->db->fetchOne('SELECT calendar_token FROM staff WHERE id = ?', [$id]);
        if ($token === false) {
            return $this->error('NOT_FOUND', 'Profesional no encontrado.', 404);
        }

        return $this->json(['feed_url' => $this->feedUrl($request, (string) $token)]);
    }

    /**
     * Rota el token del feed iCal (invalida las suscripciones anteriores).
     */
    #[Route('/api/v1/admin/staff/{id}/calendar/rotate', name: 'admin_staff_calendar_rotate', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function rotateCalendar(int $id, Request $request): JsonResponse
    {
        $user = self::user($request);
        try {
            $this->auth->assertRole($user, self::CONFIG_ROLES);
        } catch (AuthException $e) {
            return $this->error($e->errorCode, $e->getMessage(), $e->statusCode);
        }
        if (!$this->staffExists($id)) {
            return $this->error('NOT_FOUND', 'Profesional no encontrado.', 404);
        }
        if (($denied = $this->assertManagesStaff($user, $id)) !== null) {
            return $denied;
        }

        $token = bin2hex(random_bytes(16));
        $this->db->executeStatement('UPDATE staff SET calendar_token = ? WHERE id = ?', [$token, $id]);

        return $this->json(['feed_url' => $this->feedUrl($request, $token)]);
    }

    private function feedUrl(Request $request, string $token): string
    {
        return $request->getSchemeAndHttpHost() . '/api/v1/calendar/' . $token . '.ics';
    }

    /**
     * Un admin_sede sólo gestiona profesionales que trabajan en su sede.
     *
     * @param array{role: string, location_id: int|null} $user
     */
    private function assertManagesStaff(array $user, int $staffId): ?JsonResponse
    {
        if ($user['role'] === 'admin_cadena') {
            return null;
        }
        $inLocation = $this->db->fetchOne(
            'SELECT 1 FROM staff_location WHERE staff_id = ? AND location_id = ?',
            [$staffId, $user['location_id']]
        );
        if ($inLocation === false) {
            return $this->error('FORBIDDEN', 'Ese profesional no pertenece a tu sede.', 403);
        }

        return null;
    }

    private function staffExists(int $id): bool
    {
        return $this->db->fetchOne('SELECT 1 FROM staff WHERE id = ?', [$id]) !== false;
    }

    /**
     * @param list<int> $ids
     */
    private function replaceLinks(Connection $tx, string $table, string $col, int $staffId, array $ids): void
    {
        $tx->executeStatement("DELETE FROM $table WHERE staff_id = ?", [$staffId]);
        foreach (array_unique($ids) as $value) {
            $tx->executeStatement("INSERT INTO $table (staff_id, $col) VALUES (?, ?)", [$staffId, $value]);
        }
    }

    /**
     * @param mixed $raw
     *
     * @return list<int>
     */
    private function normalizeIds($raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        return array_values(array_filter(array_map(static fn ($v): int => (int) $v, $raw), static fn (int $v): bool => $v > 0));
    }

    /**
     * @return list<int>
     */
    private function idList(string $sql, int $id): array
    {
        return array_map(static fn ($v): int => (int) $v, $this->db->fetchFirstColumn($sql, [$id]));
    }

    private function intParam(Request $request, string $key): ?int
    {
        $v = $request->query->get($key);

        return $v === null || (int) $v <= 0 ? null : (int) $v;
    }

    private function isTime(mixed $v): bool
    {
        return is_string($v) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v) === 1;
    }
}
