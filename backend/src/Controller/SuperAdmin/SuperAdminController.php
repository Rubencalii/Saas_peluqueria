<?php

declare(strict_types=1);

namespace App\Controller\SuperAdmin;

use App\Controller\Admin\AdminController;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Panel de PLATAFORMA (operador del SaaS). Transversal a los tenants: lista y
 * gestiona todas las cuentas. Autorización por el flag `is_superadmin` del JWT
 * (no por `account_id`). El token lo valida el AdminAuthListener (también cubre
 * `/api/v1/superadmin`).
 */
final class SuperAdminController extends AdminController
{
    private const ACCOUNT_STATUSES = ['active', 'trial', 'suspended', 'cancelled'];

    public function __construct(
        private readonly Connection $db,
        private readonly \App\Service\Auth\AuthService $auth,
    ) {
    }

    #[Route('/api/v1/superadmin/stats', name: 'superadmin_stats', methods: ['GET'])]
    public function stats(Request $request): JsonResponse
    {
        if (($deny = $this->guard($request)) !== null) {
            return $deny;
        }

        $row = $this->db->fetchAssociative(
            "SELECT COUNT(*) AS total,
                    COUNT(*) FILTER (WHERE status = 'active')    AS active,
                    COUNT(*) FILTER (WHERE status = 'trial')     AS trial,
                    COUNT(*) FILTER (WHERE status = 'suspended') AS suspended,
                    COUNT(*) FILTER (WHERE status = 'cancelled') AS cancelled
               FROM account"
        ) ?: [];
        $appointments = (int) $this->db->fetchOne('SELECT COUNT(*) FROM appointment');

        // Crecimiento: altas de cuentas por semana (últimas 8, incluida la actual).
        $signups = $this->db->fetchAllAssociative(
            "SELECT to_char(date_trunc('week', created_at), 'YYYY-MM-DD') AS week, COUNT(*) AS n
               FROM account
              WHERE created_at >= date_trunc('week', now()) - interval '7 weeks'
              GROUP BY 1 ORDER BY 1"
        );

        return $this->json([
            'accounts' => [
                'total' => (int) ($row['total'] ?? 0),
                'active' => (int) ($row['active'] ?? 0),
                'trial' => (int) ($row['trial'] ?? 0),
                'suspended' => (int) ($row['suspended'] ?? 0),
                'cancelled' => (int) ($row['cancelled'] ?? 0),
            ],
            'appointments_total' => $appointments,
            'signups_8w' => array_map(static fn (array $s): array => [
                'week' => (string) $s['week'],
                'count' => (int) $s['n'],
            ], $signups),
        ]);
    }

    #[Route('/api/v1/superadmin/accounts', name: 'superadmin_accounts', methods: ['GET'])]
    public function accounts(Request $request): JsonResponse
    {
        if (($deny = $this->guard($request)) !== null) {
            return $deny;
        }

        $rows = $this->db->fetchAllAssociative(
            "SELECT a.id, a.name, a.slug, a.status, a.created_at,
                    s.plan_code, p.name AS plan_name, s.status AS sub_status, s.current_period_end,
                    (s.stripe_subscription_id IS NOT NULL) AS stripe_managed,
                    (SELECT COUNT(*) FROM location l WHERE l.account_id = a.id) AS locations,
                    (SELECT COUNT(*) FROM app_user u WHERE u.account_id = a.id) AS users,
                    (SELECT COUNT(*) FROM customer c WHERE c.account_id = a.id) AS customers,
                    (SELECT COUNT(*) FROM appointment ap
                       JOIN location l2 ON l2.id = ap.location_id WHERE l2.account_id = a.id) AS appointments
               FROM account a
               LEFT JOIN subscription s ON s.account_id = a.id
               LEFT JOIN plan p ON p.code = s.plan_code
              ORDER BY a.id"
        );

        return $this->json([
            'accounts' => array_map(static fn (array $r): array => [
                'id' => (int) $r['id'],
                'name' => (string) $r['name'],
                'slug' => (string) $r['slug'],
                'status' => (string) $r['status'],
                'created_at' => (new \DateTimeImmutable($r['created_at']))->format('c'),
                'plan_code' => $r['plan_code'] !== null ? (string) $r['plan_code'] : null,
                'plan_name' => $r['plan_name'] !== null ? (string) $r['plan_name'] : null,
                'subscription_status' => $r['sub_status'] !== null ? (string) $r['sub_status'] : null,
                'stripe_managed' => (bool) $r['stripe_managed'],
                'counts' => [
                    'locations' => (int) $r['locations'],
                    'users' => (int) $r['users'],
                    'customers' => (int) $r['customers'],
                    'appointments' => (int) $r['appointments'],
                ],
            ], $rows),
        ]);
    }

    /**
     * Ficha completa de una cuenta: quién está detrás (admins con su email),
     * sedes, suscripción (y si la gestiona Stripe) y actividad reciente.
     */
    #[Route('/api/v1/superadmin/accounts/{id}', name: 'superadmin_account_detail', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function detail(int $id, Request $request): JsonResponse
    {
        if (($deny = $this->guard($request)) !== null) {
            return $deny;
        }

        $account = $this->db->fetchAssociative(
            'SELECT id, name, slug, status, created_at FROM account WHERE id = ?',
            [$id]
        );
        if ($account === false) {
            return $this->error('NOT_FOUND', 'Cuenta no encontrada.', 404);
        }

        $sub = $this->db->fetchAssociative(
            'SELECT s.plan_code, p.name AS plan_name, s.status, s.current_period_end,
                    (s.stripe_subscription_id IS NOT NULL) AS stripe_managed
               FROM subscription s LEFT JOIN plan p ON p.code = s.plan_code
              WHERE s.account_id = ?',
            [$id]
        );
        $admins = $this->db->fetchAllAssociative(
            "SELECT id, name, email, active FROM app_user
              WHERE account_id = ? AND role = 'admin_cadena' ORDER BY id",
            [$id]
        );
        $locations = $this->db->fetchAllAssociative(
            'SELECT id, name, slug, active FROM location WHERE account_id = ? ORDER BY name',
            [$id]
        );
        $activity = $this->db->fetchAssociative(
            "SELECT COUNT(*) FILTER (WHERE ap.start_at >= now() - interval '30 days' AND ap.start_at <= now()) AS appointments_30d,
                    MAX(ap.start_at) AS last_appointment_at
               FROM appointment ap JOIN location l ON l.id = ap.location_id
              WHERE l.account_id = ?",
            [$id]
        ) ?: [];

        return $this->json([
            'account' => [
                'id' => (int) $account['id'],
                'name' => (string) $account['name'],
                'slug' => (string) $account['slug'],
                'status' => (string) $account['status'],
                'created_at' => (new \DateTimeImmutable($account['created_at']))->format('c'),
            ],
            'subscription' => $sub !== false ? [
                'plan_code' => (string) $sub['plan_code'],
                'plan_name' => $sub['plan_name'] !== null ? (string) $sub['plan_name'] : null,
                'status' => (string) $sub['status'],
                'current_period_end' => $sub['current_period_end'] !== null
                    ? (new \DateTimeImmutable($sub['current_period_end']))->format('c')
                    : null,
                'stripe_managed' => (bool) $sub['stripe_managed'],
            ] : null,
            'admins' => array_map(static fn (array $u): array => [
                'id' => (int) $u['id'],
                'name' => (string) $u['name'],
                'email' => (string) $u['email'],
                'active' => (bool) $u['active'],
            ], $admins),
            'locations' => array_map(static fn (array $l): array => [
                'id' => (int) $l['id'],
                'name' => (string) $l['name'],
                'slug' => (string) $l['slug'],
                'active' => (bool) $l['active'],
            ], $locations),
            'activity' => [
                'appointments_30d' => (int) ($activity['appointments_30d'] ?? 0),
                'last_appointment_at' => ($activity['last_appointment_at'] ?? null) !== null
                    ? (new \DateTimeImmutable((string) $activity['last_appointment_at']))->format('c')
                    : null,
            ],
        ]);
    }

    /**
     * Impersonación para soporte: emite una sesión del primer admin activo de
     * la cuenta. La petición queda en audit_log a nombre del superadmin.
     */
    #[Route('/api/v1/superadmin/accounts/{id}/impersonate', name: 'superadmin_impersonate', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function impersonate(int $id, Request $request): JsonResponse
    {
        if (($deny = $this->guard($request)) !== null) {
            return $deny;
        }

        $accountName = $this->db->fetchOne('SELECT name FROM account WHERE id = ?', [$id]);
        if ($accountName === false) {
            return $this->error('NOT_FOUND', 'Cuenta no encontrada.', 404);
        }

        $userId = $this->db->fetchOne(
            "SELECT id FROM app_user
              WHERE account_id = ? AND role = 'admin_cadena' AND active AND NOT is_superadmin
              ORDER BY id LIMIT 1",
            [$id]
        );
        if ($userId === false) {
            return $this->error('NO_ADMIN', 'La cuenta no tiene ningún administrador activo.', 409);
        }

        try {
            $session = $this->auth->impersonate((int) $userId);
        } catch (\App\Service\Auth\AuthException $e) {
            return $this->error($e->errorCode, $e->getMessage(), $e->statusCode);
        }

        return $this->json([
            'token' => $session['token'],
            'expires_at' => $session['expires_at'],
            'user' => $session['user'],
            'account' => ['id' => $id, 'name' => (string) $accountName],
        ]);
    }

    #[Route('/api/v1/superadmin/accounts/{id}', name: 'superadmin_account_update', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function update(int $id, Request $request): JsonResponse
    {
        if (($deny = $this->guard($request)) !== null) {
            return $deny;
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->error('VALIDATION', 'El cuerpo debe ser un objeto JSON.', 400);
        }
        if ($this->db->fetchOne('SELECT 1 FROM account WHERE id = ?', [$id]) === false) {
            return $this->error('NOT_FOUND', 'Cuenta no encontrada.', 404);
        }

        if (array_key_exists('status', $payload)) {
            $status = (string) $payload['status'];
            if (!in_array($status, self::ACCOUNT_STATUSES, true)) {
                return $this->error('VALIDATION', 'Estado de cuenta inválido.', 400);
            }
            $this->db->executeStatement('UPDATE account SET status = ? WHERE id = ?', [$status, $id]);
        }

        if (array_key_exists('plan_code', $payload)) {
            $plan = (string) $payload['plan_code'];
            if ($this->db->fetchOne('SELECT 1 FROM plan WHERE code = ?', [$plan]) === false) {
                return $this->error('VALIDATION', 'Plan desconocido.', 400);
            }
            // Crea la suscripción si no existía (cuentas sembradas a mano).
            $this->db->executeStatement(
                "INSERT INTO subscription (account_id, plan_code, status) VALUES (?, ?, 'active')
                 ON CONFLICT (account_id) DO UPDATE SET plan_code = EXCLUDED.plan_code",
                [$id, $plan]
            );
        }

        return $this->json(['ok' => true]);
    }

    private function guard(Request $request): ?JsonResponse
    {
        if (self::user($request)['is_superadmin'] !== true) {
            return $this->error('FORBIDDEN', 'Solo el administrador de plataforma puede acceder.', 403);
        }

        return null;
    }
}
