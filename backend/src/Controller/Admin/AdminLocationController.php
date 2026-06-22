<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Service\Auth\AuthException;
use App\Service\Auth\AuthService;
use App\Service\Tenant\PlanLimitService;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Gestión de sedes y su personalización white-label (docs/06 §4, doc 08).
 *
 * El alta/baja y edición de sedes es exclusivo de admin_cadena. El branding
 * (logo, colores, tipografía, dominio propio) lo puede ajustar también el
 * admin_sede sobre SU sede.
 */
final class AdminLocationController extends AdminController
{
    public function __construct(
        private readonly Connection $db,
        private readonly AuthService $auth,
        private readonly PlanLimitService $planLimit,
    ) {
    }

    #[Route('/api/v1/admin/locations', name: 'admin_location_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        try {
            $this->auth->assertRole(self::user($request), ['admin_sede', 'admin_cadena']);
        } catch (AuthException $e) {
            return $this->error($e->errorCode, $e->getMessage(), $e->statusCode);
        }

        $rows = $this->db->fetchAllAssociative(
            'SELECT id, name, slug, address, phone, timezone, active FROM location WHERE account_id = ? ORDER BY name',
            [self::user($request)['account_id']]
        );

        return $this->json(['locations' => array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'name' => (string) $r['name'],
            'slug' => (string) $r['slug'],
            'address' => $r['address'] !== null ? (string) $r['address'] : null,
            'phone' => $r['phone'] !== null ? (string) $r['phone'] : null,
            'timezone' => (string) $r['timezone'],
            'active' => (bool) $r['active'],
        ], $rows)]);
    }

    #[Route('/api/v1/admin/locations', name: 'admin_location_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        try {
            $this->auth->assertRole(self::user($request), ['admin_cadena']);
        } catch (AuthException $e) {
            return $this->error($e->errorCode, $e->getMessage(), $e->statusCode);
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->error('VALIDATION', 'El cuerpo debe ser un objeto JSON.', 400);
        }
        $name = trim((string) ($payload['name'] ?? ''));
        $slug = trim((string) ($payload['slug'] ?? ''));
        if ($name === '' || $slug === '') {
            return $this->error('VALIDATION', 'name y slug son obligatorios.', 400);
        }
        $accountId = self::user($request)['account_id'];
        if ($this->planLimit->locationLimitReached($accountId)) {
            return $this->error('PLAN_LIMIT', 'Tu plan no permite más sedes. Mejóralo para añadir otra.', 402);
        }
        if ($this->db->fetchOne('SELECT 1 FROM location WHERE slug = ? AND account_id = ?', [$slug, $accountId]) !== false) {
            return $this->error('CONFLICT', 'Ya existe una sede con ese slug.', 409);
        }

        $id = (int) $this->db->fetchOne(
            'INSERT INTO location (account_id, name, slug, address, phone, timezone, active)
             VALUES (?, ?, ?, ?, ?, COALESCE(?, \'Europe/Madrid\'), COALESCE(?, TRUE)) RETURNING id',
            [
                $accountId, $name, $slug,
                isset($payload['address']) && $payload['address'] !== '' ? (string) $payload['address'] : null,
                isset($payload['phone']) && $payload['phone'] !== '' ? (string) $payload['phone'] : null,
                isset($payload['timezone']) && $payload['timezone'] !== '' ? (string) $payload['timezone'] : null,
                isset($payload['active']) ? (bool) $payload['active'] : null,
            ]
        );

        return $this->json(['id' => $id], 201);
    }

    #[Route('/api/v1/admin/locations/{id}', name: 'admin_location_update', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function update(int $id, Request $request): JsonResponse
    {
        try {
            $this->auth->assertRole(self::user($request), ['admin_cadena']);
        } catch (AuthException $e) {
            return $this->error($e->errorCode, $e->getMessage(), $e->statusCode);
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->error('VALIDATION', 'El cuerpo debe ser un objeto JSON.', 400);
        }
        $accountId = self::user($request)['account_id'];
        if ($this->db->fetchOne('SELECT 1 FROM location WHERE id = ? AND account_id = ?', [$id, $accountId]) === false) {
            return $this->error('NOT_FOUND', 'Sede no encontrada.', 404);
        }

        $map = [
            'name' => static fn ($v) => trim((string) $v),
            'slug' => static fn ($v) => trim((string) $v),
            'address' => static fn ($v) => $v !== null && $v !== '' ? (string) $v : null,
            'phone' => static fn ($v) => $v !== null && $v !== '' ? (string) $v : null,
            'timezone' => static fn ($v) => (string) $v,
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
        if ($sets === []) {
            return $this->error('VALIDATION', 'Nada que actualizar.', 400);
        }

        $params[] = $id;
        $params[] = $accountId;
        $this->db->executeStatement('UPDATE location SET ' . implode(', ', $sets) . ' WHERE id = ? AND account_id = ?', $params);

        return $this->json(['ok' => true]);
    }

    #[Route('/api/v1/admin/locations/{id}/branding', name: 'admin_branding_get', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function getBranding(int $id, Request $request): JsonResponse
    {
        $user = self::user($request);
        try {
            $this->auth->assertRole($user, ['admin_sede', 'admin_cadena']);
            $this->auth->assertLocation($user, $id);
        } catch (AuthException $e) {
            return $this->error($e->errorCode, $e->getMessage(), $e->statusCode);
        }
        if ($this->db->fetchOne('SELECT 1 FROM location WHERE id = ? AND account_id = ?', [$id, $user['account_id']]) === false) {
            return $this->error('NOT_FOUND', 'Sede no encontrada.', 404);
        }

        $row = $this->db->fetchAssociative(
            'SELECT location_id, logo_url, color_primary, color_accent, font_family, custom_domain, extra
               FROM branding WHERE location_id = ?',
            [$id]
        );

        return $this->json(['branding' => $row === false ? null : [
            'location_id' => (int) $row['location_id'],
            'logo_url' => $row['logo_url'],
            'color_primary' => $row['color_primary'],
            'color_accent' => $row['color_accent'],
            'font_family' => $row['font_family'],
            'custom_domain' => $row['custom_domain'],
            'extra' => $row['extra'] !== null ? json_decode((string) $row['extra'], true) : null,
        ]]);
    }

    #[Route('/api/v1/admin/locations/{id}/branding', name: 'admin_branding_update', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function updateBranding(int $id, Request $request): JsonResponse
    {
        $user = self::user($request);
        try {
            $this->auth->assertRole($user, ['admin_sede', 'admin_cadena']);
            $this->auth->assertLocation($user, $id);
        } catch (AuthException $e) {
            return $this->error($e->errorCode, $e->getMessage(), $e->statusCode);
        }
        if ($this->db->fetchOne('SELECT 1 FROM location WHERE id = ? AND account_id = ?', [$id, $user['account_id']]) === false) {
            return $this->error('NOT_FOUND', 'Sede no encontrada.', 404);
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->error('VALIDATION', 'El cuerpo debe ser un objeto JSON.', 400);
        }

        // El dominio propio es decisión de cadena (doc 08): sólo admin_cadena.
        if (array_key_exists('custom_domain', $payload) && $user['role'] !== 'admin_cadena') {
            return $this->error('FORBIDDEN', 'Solo admin_cadena puede fijar el dominio propio.', 403);
        }

        // Columnas presentes en el cuerpo → su valor saneado (incluida `extra`).
        $set = [];
        foreach (['logo_url', 'color_primary', 'color_accent', 'font_family', 'custom_domain'] as $c) {
            if (array_key_exists($c, $payload)) {
                $set[$c] = $payload[$c] !== null && $payload[$c] !== '' ? (string) $payload[$c] : null;
            }
        }
        if (array_key_exists('extra', $payload)) {
            $set['extra'] = $payload['extra'] !== null ? json_encode($payload['extra'], JSON_UNESCAPED_UNICODE) : null;
        }
        if ($set === []) {
            return $this->error('VALIDATION', 'Nada que actualizar.', 400);
        }

        // UPSERT: una fila de branding por sede.
        $insertCols = ['location_id', ...array_keys($set)];
        $insertVals = [$id, ...array_values($set)];
        $placeholders = implode(', ', array_map(static fn (string $c): string => $c === 'extra' ? '?::jsonb' : '?', $insertCols));
        $updates = array_map(static fn (string $c): string => "$c = EXCLUDED.$c", array_keys($set));

        $this->db->executeStatement(
            'INSERT INTO branding (' . implode(', ', $insertCols) . ') VALUES (' . $placeholders . ')
             ON CONFLICT (location_id) DO UPDATE SET ' . implode(', ', $updates),
            $insertVals
        );

        return $this->getBranding($id, $request);
    }
}
