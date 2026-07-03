<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Service\Auth\AuthException;
use App\Service\Auth\AuthService;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Usuarios del panel (el equipo con acceso): alta, cambio de rol/sede y
 * activación. Solo admin_cadena. Distinto de "Personal" (staff): un
 * profesional puede no tener acceso al panel, y recepción no es staff.
 */
final class AdminUserController extends AdminController
{
    private const ROLES = ['recepcion', 'profesional', 'admin_sede', 'admin_cadena'];

    public function __construct(
        private readonly Connection $db,
        private readonly AuthService $auth,
    ) {
    }

    #[Route('/api/v1/admin/users', name: 'admin_user_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $user = self::user($request);
        try {
            $this->auth->assertRole($user, ['admin_cadena']);
        } catch (AuthException $e) {
            return $this->error($e->errorCode, $e->getMessage(), $e->statusCode);
        }

        $rows = $this->db->fetchAllAssociative(
            'SELECT u.id, u.name, u.email, u.role, u.location_id, u.active, l.name AS location_name
               FROM app_user u
               LEFT JOIN location l ON l.id = u.location_id
              WHERE u.account_id = ?
              ORDER BY u.active DESC, u.name',
            [$user['account_id']]
        );

        return $this->json(['users' => array_map($this->present(...), $rows)]);
    }

    #[Route('/api/v1/admin/users', name: 'admin_user_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $user = self::user($request);
        try {
            $this->auth->assertRole($user, ['admin_cadena']);
        } catch (AuthException $e) {
            return $this->error($e->errorCode, $e->getMessage(), $e->statusCode);
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->error('VALIDATION', 'El cuerpo debe ser un objeto JSON.', 400);
        }

        $name = trim((string) ($payload['name'] ?? ''));
        $email = mb_strtolower(trim((string) ($payload['email'] ?? '')));
        $password = (string) ($payload['password'] ?? '');
        $role = (string) ($payload['role'] ?? '');
        $locationId = isset($payload['location_id']) && (int) $payload['location_id'] > 0
            ? (int) $payload['location_id']
            : null;

        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->error('VALIDATION', 'Nombre y email válidos son obligatorios.', 400);
        }
        if (strlen($password) < 8) {
            return $this->error('VALIDATION', 'La contraseña debe tener al menos 8 caracteres.', 400);
        }
        if (!in_array($role, self::ROLES, true)) {
            return $this->error('VALIDATION', 'Rol inválido.', 400);
        }

        $check = $this->checkRoleLocation($role, $locationId, (int) $user['account_id']);
        if ($check instanceof JsonResponse) {
            return $check;
        }
        $locationId = $check;

        // El email es único global (misma regla que el alta del SaaS).
        if ($this->db->fetchOne('SELECT 1 FROM app_user WHERE email = ?', [$email]) !== false) {
            return $this->error('EMAIL_TAKEN', 'Ya existe un usuario con ese email.', 409);
        }

        $id = (int) $this->db->fetchOne(
            'INSERT INTO app_user (account_id, name, email, password_hash, role, location_id, active)
             VALUES (?, ?, ?, ?, ?, ?, TRUE) RETURNING id',
            [$user['account_id'], $name, $email, password_hash($password, PASSWORD_BCRYPT), $role, $locationId]
        );

        return $this->json(['id' => $id], 201);
    }

    #[Route('/api/v1/admin/users/{id}', name: 'admin_user_update', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $user = self::user($request);
        try {
            $this->auth->assertRole($user, ['admin_cadena']);
        } catch (AuthException $e) {
            return $this->error($e->errorCode, $e->getMessage(), $e->statusCode);
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->error('VALIDATION', 'El cuerpo debe ser un objeto JSON.', 400);
        }

        $target = $this->db->fetchAssociative(
            'SELECT id, role, location_id, active FROM app_user WHERE id = ? AND account_id = ?',
            [$id, $user['account_id']]
        );
        if ($target === false) {
            return $this->error('NOT_FOUND', 'Usuario no encontrado.', 404);
        }

        $isSelf = $id === (int) $user['id'];

        // Estado final tras aplicar los cambios pedidos.
        $role = array_key_exists('role', $payload) ? (string) $payload['role'] : (string) $target['role'];
        $active = array_key_exists('active', $payload) ? (bool) $payload['active'] : (bool) $target['active'];
        $locationId = array_key_exists('location_id', $payload)
            ? ((int) $payload['location_id'] > 0 ? (int) $payload['location_id'] : null)
            : ($target['location_id'] !== null ? (int) $target['location_id'] : null);
        $name = array_key_exists('name', $payload) ? trim((string) $payload['name']) : null;

        if (!in_array($role, self::ROLES, true)) {
            return $this->error('VALIDATION', 'Rol inválido.', 400);
        }
        if ($name !== null && $name === '') {
            return $this->error('VALIDATION', 'El nombre no puede estar vacío.', 400);
        }
        // Autoprotección: no puedes desactivarte ni dejar de ser admin_cadena
        // (evita dejar la cuenta sin ningún administrador por accidente).
        if ($isSelf && (!$active || $role !== 'admin_cadena')) {
            return $this->error('VALIDATION', 'No puedes desactivarte ni cambiar tu propio rol.', 400);
        }

        $check = $this->checkRoleLocation($role, $locationId, (int) $user['account_id']);
        if ($check instanceof JsonResponse) {
            return $check;
        }
        $locationId = $check;

        $deactivating = (bool) $target['active'] && !$active;

        $sets = ['role = ?', 'location_id = ?', 'active = ?'];
        $params = [$role, $locationId, $active];
        $types = [ParameterType::STRING, ParameterType::INTEGER, ParameterType::BOOLEAN];
        if ($name !== null) {
            $sets[] = 'name = ?';
            $params[] = $name;
            $types[] = ParameterType::STRING;
        }
        if ($deactivating) {
            // Desactivar corta las sesiones ya abiertas (revocación por versión).
            $sets[] = 'token_version = token_version + 1';
        }
        $params[] = $id;
        $types[] = ParameterType::INTEGER;

        $this->db->executeStatement(
            'UPDATE app_user SET ' . implode(', ', $sets) . ' WHERE id = ?',
            $params,
            $types
        );

        $row = $this->db->fetchAssociative(
            'SELECT u.id, u.name, u.email, u.role, u.location_id, u.active, l.name AS location_name
               FROM app_user u LEFT JOIN location l ON l.id = u.location_id WHERE u.id = ?',
            [$id]
        );
        \assert($row !== false);

        return $this->json(['user' => $this->present($row)]);
    }

    /**
     * Coherencia rol/sede: los roles de sede exigen una sede de la cuenta;
     * admin_cadena opera sin sede. Devuelve la sede normalizada o el error.
     */
    private function checkRoleLocation(string $role, ?int $locationId, int $accountId): int|JsonResponse|null
    {
        if ($role === 'admin_cadena') {
            return null;
        }
        if ($locationId === null) {
            return $this->error('VALIDATION', 'Este rol necesita una sede asignada.', 400);
        }
        $owned = $this->db->fetchOne(
            'SELECT 1 FROM location WHERE id = ? AND account_id = ?',
            [$locationId, $accountId]
        );
        if ($owned === false) {
            return $this->error('NOT_FOUND', 'Sede no encontrada.', 404);
        }

        return $locationId;
    }

    /**
     * @param array<string, mixed> $r
     *
     * @return array<string, mixed>
     */
    private function present(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'name' => (string) $r['name'],
            'email' => (string) $r['email'],
            'role' => (string) $r['role'],
            'location' => $r['location_id'] !== null
                ? ['id' => (int) $r['location_id'], 'name' => (string) $r['location_name']]
                : null,
            'active' => (bool) $r['active'],
        ];
    }
}
