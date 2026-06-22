<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Service\WhatsApp\WhatsAppMessenger;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Bandeja de atención humana de WhatsApp (docs/06 §4).
 *
 * Cuando el bot deriva una conversación (BotEngine::toHuman marca
 * needs_human=TRUE), aparece aquí para que el personal responda. Responder
 * envía un mensaje por la Cloud API y permite cerrar la derivación (vuelve el
 * control al bot). Cada conversación se identifica por su wa_id (teléfono).
 */
final class AdminConversationController extends AdminController
{
    public function __construct(
        private readonly Connection $db,
        private readonly WhatsAppMessenger $wa,
    ) {
    }

    #[Route('/api/v1/admin/conversations', name: 'admin_conversation_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $user = self::user($request);

        // Por defecto, solo las que esperan atención humana.
        $onlyPending = $request->query->get('status', 'pendiente') !== 'all';

        $where = [];
        $params = [];
        if ($onlyPending) {
            $where[] = 'c.needs_human';
        }
        // Multi-tenant: solo conversaciones de las sedes de la cuenta (o aún sin
        // sede asignada, que el bot resolverá por línea de WhatsApp en la Fase 3).
        $where[] = '(c.location_id IS NULL OR c.location_id IN (SELECT id FROM location WHERE account_id = ?))';
        $params[] = $user['account_id'];
        // Salvo admin_cadena, solo la sede propia (y las aún sin sede asignada).
        if ($user['role'] !== 'admin_cadena') {
            $where[] = '(c.location_id = ? OR c.location_id IS NULL)';
            $params[] = $user['location_id'];
        }

        // Siempre hay al menos el filtro por cuenta.
        $whereSql = ' WHERE ' . implode(' AND ', $where);
        $pg = self::pagination($request);

        $total = (int) $this->db->fetchOne("SELECT COUNT(*) FROM conversation c$whereSql", $params);

        $sql = 'SELECT c.wa_id, c.state, c.needs_human, c.location_id, c.updated_at,
                       l.name AS location_name, cu.name AS customer_name
                  FROM conversation c
                  LEFT JOIN location l  ON l.id = c.location_id
                  LEFT JOIN customer cu ON cu.phone = (\'+\' || c.wa_id)'
            . $whereSql
            . ' ORDER BY c.updated_at DESC LIMIT ? OFFSET ?';

        $rows = $this->db->fetchAllAssociative($sql, [...$params, $pg['per_page'], $pg['offset']]);

        return $this->json([
            'conversations' => array_map(static fn (array $r): array => [
                'wa_id' => (string) $r['wa_id'],
                'phone' => '+' . $r['wa_id'],
                'customer_name' => $r['customer_name'] !== null ? (string) $r['customer_name'] : null,
                'state' => (string) $r['state'],
                'needs_human' => (bool) $r['needs_human'],
                'location' => $r['location_id'] !== null
                    ? ['id' => (int) $r['location_id'], 'name' => (string) $r['location_name']]
                    : null,
                'updated_at' => (new \DateTimeImmutable($r['updated_at']))->format('c'),
            ], $rows),
            'page' => $pg['page'],
            'per_page' => $pg['per_page'],
            'total' => $total,
        ]);
    }

    #[Route('/api/v1/admin/conversations/{waId}/reply', name: 'admin_conversation_reply', methods: ['POST'], requirements: ['waId' => '\d+'])]
    public function reply(string $waId, Request $request): JsonResponse
    {
        $user = self::user($request);

        $conv = $this->db->fetchAssociative(
            'SELECT wa_id, location_id FROM conversation WHERE wa_id = ?',
            [$waId]
        );
        if ($conv === false) {
            return $this->error('NOT_FOUND', 'Conversación no encontrada.', 404);
        }
        // Multi-tenant: si la conversación tiene sede, debe ser de la cuenta.
        if ($conv['location_id'] !== null
            && $this->db->fetchOne('SELECT 1 FROM location WHERE id = ? AND account_id = ?', [(int) $conv['location_id'], $user['account_id']]) === false) {
            return $this->error('NOT_FOUND', 'Conversación no encontrada.', 404);
        }
        if ($user['role'] !== 'admin_cadena'
            && $conv['location_id'] !== null
            && (int) $conv['location_id'] !== $user['location_id']) {
            return $this->error('FORBIDDEN', 'Esta conversación es de otra sede.', 403);
        }

        $payload = json_decode($request->getContent(), true);
        $message = is_array($payload) && is_string($payload['message'] ?? null) ? trim($payload['message']) : '';
        if ($message === '') {
            return $this->error('VALIDATION', 'El mensaje no puede estar vacío.', 400);
        }

        $this->wa->sendText($waId, $message);

        // Cerrar la derivación (resolve) devuelve el control al bot.
        $resolve = is_array($payload) && (bool) ($payload['resolve'] ?? false);
        if ($resolve) {
            $this->db->executeStatement(
                "UPDATE conversation SET needs_human = FALSE, state = 'menu', updated_at = now() WHERE wa_id = ?",
                [$waId]
            );
        } else {
            $this->db->executeStatement(
                'UPDATE conversation SET updated_at = now() WHERE wa_id = ?',
                [$waId]
            );
        }

        return $this->json(['ok' => true, 'resolved' => $resolve]);
    }
}
