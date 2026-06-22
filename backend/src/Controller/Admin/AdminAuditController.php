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
 * Consulta del registro de actividad (doc 09 §6). Solo admin_cadena, paginado.
 */
final class AdminAuditController extends AdminController
{
    public function __construct(
        private readonly Connection $db,
        private readonly AuthService $auth,
    ) {
    }

    #[Route('/api/v1/admin/audit', name: 'admin_audit_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        try {
            $this->auth->assertRole(self::user($request), ['admin_cadena']);
        } catch (AuthException $e) {
            return $this->error($e->errorCode, $e->getMessage(), $e->statusCode);
        }

        $pg = self::pagination($request);
        // Multi-tenant: el registro no lleva account_id, así que se acota a las
        // acciones de los usuarios de la cuenta (las anónimas/sistema quedan fuera
        // del panel; atribuirlas requeriría account_id en audit_log — Fase futura).
        $accountId = self::user($request)['account_id'];
        $scope = 'user_id IN (SELECT id FROM app_user WHERE account_id = ?)';
        $total = (int) $this->db->fetchOne("SELECT COUNT(*) FROM audit_log WHERE $scope", [$accountId]);
        $rows = $this->db->fetchAllAssociative(
            "SELECT id, user_id, user_email, method, path, status_code, created_at
               FROM audit_log WHERE $scope ORDER BY created_at DESC LIMIT ? OFFSET ?",
            [$accountId, $pg['per_page'], $pg['offset']]
        );

        return $this->json([
            'audit' => array_map(static fn (array $r): array => [
                'id' => (int) $r['id'],
                'user_id' => $r['user_id'] !== null ? (int) $r['user_id'] : null,
                'user_email' => $r['user_email'] !== null ? (string) $r['user_email'] : null,
                'method' => (string) $r['method'],
                'path' => (string) $r['path'],
                'status_code' => (int) $r['status_code'],
                'created_at' => (new \DateTimeImmutable($r['created_at']))->format('c'),
            ], $rows),
            'page' => $pg['page'],
            'per_page' => $pg['per_page'],
            'total' => $total,
        ]);
    }
}
