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

    public function __construct(private readonly Connection $db)
    {
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

        return $this->json([
            'accounts' => [
                'total' => (int) ($row['total'] ?? 0),
                'active' => (int) ($row['active'] ?? 0),
                'trial' => (int) ($row['trial'] ?? 0),
                'suspended' => (int) ($row['suspended'] ?? 0),
                'cancelled' => (int) ($row['cancelled'] ?? 0),
            ],
            'appointments_total' => $appointments,
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
                'counts' => [
                    'locations' => (int) $r['locations'],
                    'users' => (int) $r['users'],
                    'customers' => (int) $r['customers'],
                    'appointments' => (int) $r['appointments'],
                ],
            ], $rows),
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
