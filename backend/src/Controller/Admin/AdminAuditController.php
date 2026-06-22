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
        $total = (int) $this->db->fetchOne('SELECT COUNT(*) FROM audit_log');
        $rows = $this->db->fetchAllAssociative(
            'SELECT id, user_id, user_email, method, path, status_code, created_at
               FROM audit_log ORDER BY created_at DESC LIMIT ? OFFSET ?',
            [$pg['per_page'], $pg['offset']]
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
