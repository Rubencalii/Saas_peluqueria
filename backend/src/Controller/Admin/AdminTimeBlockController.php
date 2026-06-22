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
 * Bloqueos de agenda (ausencias, vacaciones, descansos) — docs/06 §4.
 *
 * Un time_block reserva tiempo de un profesional para que la disponibilidad no
 * lo ofrezca (lo aplica AvailabilityService). Las horas se manejan en UTC.
 */
final class AdminTimeBlockController extends AdminController
{
    private const CONFIG_ROLES = ['recepcion', 'admin_sede', 'admin_cadena'];

    public function __construct(
        private readonly Connection $db,
        private readonly AuthService $auth,
    ) {
    }

    #[Route('/api/v1/admin/time-blocks', name: 'admin_timeblock_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $user = self::user($request);
        try {
            $this->auth->assertRole($user, self::CONFIG_ROLES);
            $locationId = $this->auth->resolveLocation($user, $this->intParam($request, 'location_id'));
        } catch (AuthException $e) {
            return $this->error($e->errorCode, $e->getMessage(), $e->statusCode);
        }

        $from = $this->parseDate($request->query->get('from')) ?? new \DateTimeImmutable('today', new \DateTimeZone('UTC'));
        $to = $this->parseDate($request->query->get('to')) ?? $from->modify('+30 days');

        $sql = 'SELECT tb.id, tb.staff_id, tb.location_id, tb.start_at, tb.end_at, tb.reason, s.name AS staff_name
                  FROM time_block tb
                  JOIN staff s ON s.id = tb.staff_id
                 WHERE tb.start_at < ? AND tb.end_at > ?';
        $params = [$to->format('c'), $from->format('c')];
        if ($locationId !== null) {
            $sql .= ' AND (tb.location_id = ? OR tb.location_id IS NULL)';
            $params[] = $locationId;
        }
        $sql .= ' ORDER BY tb.start_at';

        $rows = $this->db->fetchAllAssociative($sql, $params);

        return $this->json(['time_blocks' => array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'staff' => ['id' => (int) $r['staff_id'], 'name' => (string) $r['staff_name']],
            'location_id' => $r['location_id'] !== null ? (int) $r['location_id'] : null,
            'start' => (new \DateTimeImmutable($r['start_at']))->format('c'),
            'end' => (new \DateTimeImmutable($r['end_at']))->format('c'),
            'reason' => $r['reason'] !== null ? (string) $r['reason'] : null,
        ], $rows)]);
    }

    #[Route('/api/v1/admin/time-blocks', name: 'admin_timeblock_create', methods: ['POST'])]
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

        $staffId = (int) ($payload['staff_id'] ?? 0);
        if ($staffId <= 0) {
            return $this->error('VALIDATION', 'staff_id es obligatorio.', 400);
        }
        $start = $this->parseInstant($payload['start'] ?? null);
        $end = $this->parseInstant($payload['end'] ?? null);
        if ($start === null || $end === null || $end <= $start) {
            return $this->error('VALIDATION', 'start y end deben ser ISO 8601 con end > start.', 400);
        }

        $locationId = isset($payload['location_id'])
            ? (int) $payload['location_id']
            : null;
        if ($locationId !== null) {
            try {
                $this->auth->assertLocation($user, $locationId);
            } catch (AuthException $e) {
                return $this->error($e->errorCode, $e->getMessage(), $e->statusCode);
            }
        } elseif ($user['role'] !== 'admin_cadena') {
            $locationId = $user['location_id']; // por defecto, la sede del usuario
        }

        $id = (int) $this->db->fetchOne(
            'INSERT INTO time_block (staff_id, location_id, start_at, end_at, reason)
             VALUES (?, ?, ?, ?, ?) RETURNING id',
            [
                $staffId, $locationId, $start->format('c'), $end->format('c'),
                isset($payload['reason']) && $payload['reason'] !== '' ? (string) $payload['reason'] : null,
            ]
        );

        return $this->json(['id' => $id], 201);
    }

    #[Route('/api/v1/admin/time-blocks/{id}', name: 'admin_timeblock_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(int $id, Request $request): JsonResponse
    {
        $user = self::user($request);
        try {
            $this->auth->assertRole($user, self::CONFIG_ROLES);
        } catch (AuthException $e) {
            return $this->error($e->errorCode, $e->getMessage(), $e->statusCode);
        }

        $block = $this->db->fetchAssociative('SELECT location_id FROM time_block WHERE id = ?', [$id]);
        if ($block === false) {
            return $this->error('NOT_FOUND', 'Bloqueo no encontrado.', 404);
        }
        if ($block['location_id'] !== null) {
            try {
                $this->auth->assertLocation($user, (int) $block['location_id']);
            } catch (AuthException $e) {
                return $this->error($e->errorCode, $e->getMessage(), $e->statusCode);
            }
        }

        $this->db->executeStatement('DELETE FROM time_block WHERE id = ?', [$id]);

        return $this->json(['ok' => true]);
    }

    private function parseInstant(mixed $raw): ?\DateTimeImmutable
    {
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable($raw))->setTimezone(new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
    }

    private function parseDate(mixed $raw): ?\DateTimeImmutable
    {
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw, new \DateTimeZone('UTC'));

        return $d !== false && $d->format('Y-m-d') === $raw ? $d : null;
    }

    private function intParam(Request $request, string $key): ?int
    {
        $v = $request->query->get($key);

        return $v === null || (int) $v <= 0 ? null : (int) $v;
    }
}
