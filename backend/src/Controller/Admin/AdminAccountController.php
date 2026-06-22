<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Datos de la cuenta (tenant) del usuario y su suscripción (doc 15, Fase 1).
 * El `account_id` viaja en el JWT; aquí se muestra la cuenta y su plan.
 */
final class AdminAccountController extends AdminController
{
    public function __construct(private readonly Connection $db)
    {
    }

    #[Route('/api/v1/admin/account', name: 'admin_account', methods: ['GET'])]
    public function show(Request $request): JsonResponse
    {
        $accountId = self::user($request)['account_id'];

        $row = $this->db->fetchAssociative(
            'SELECT a.id, a.name, a.slug, a.status, a.created_at,
                    sub.plan_code, p.name AS plan_name, sub.status AS subscription_status,
                    sub.current_period_end,
                    p.max_locations, p.max_staff, p.max_appointments_month
               FROM account a
               LEFT JOIN subscription sub ON sub.account_id = a.id
               LEFT JOIN plan p ON p.code = sub.plan_code
              WHERE a.id = ?',
            [$accountId]
        );
        if ($row === false) {
            return $this->error('NOT_FOUND', 'Cuenta no encontrada.', 404);
        }

        return $this->json([
            'account' => [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'slug' => (string) $row['slug'],
                'status' => (string) $row['status'],
                'created_at' => (new \DateTimeImmutable($row['created_at']))->format('c'),
            ],
            'subscription' => $row['plan_code'] !== null ? [
                'plan_code' => (string) $row['plan_code'],
                'plan_name' => (string) $row['plan_name'],
                'status' => (string) $row['subscription_status'],
                'current_period_end' => $row['current_period_end'] !== null
                    ? (new \DateTimeImmutable($row['current_period_end']))->format('c')
                    : null,
                'limits' => [
                    'max_locations' => $row['max_locations'] !== null ? (int) $row['max_locations'] : null,
                    'max_staff' => $row['max_staff'] !== null ? (int) $row['max_staff'] : null,
                    'max_appointments_month' => $row['max_appointments_month'] !== null ? (int) $row['max_appointments_month'] : null,
                ],
            ] : null,
        ]);
    }
}
