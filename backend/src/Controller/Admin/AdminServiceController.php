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
 * Configuración del catálogo de servicios (docs/06 §4).
 *
 * El servicio es de cadena (tabla `service`); su oferta y precio por sede van
 * en `service_location`, y el desglose de tramos ocupados/muertos (tintes) en
 * `service_segment`. Por eso el CRUD del catálogo lo gobierna admin_cadena;
 * un admin_sede ajusta la oferta/precio de su sede vía service_location.
 */
final class AdminServiceController extends AdminController
{
    public function __construct(
        private readonly Connection $db,
        private readonly AuthService $auth,
    ) {
    }

    #[Route('/api/v1/admin/services', name: 'admin_service_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        try {
            $this->auth->assertRole(self::user($request), ['admin_sede', 'admin_cadena']);
        } catch (AuthException $e) {
            return $this->error($e->errorCode, $e->getMessage(), $e->statusCode);
        }

        $services = $this->db->fetchAllAssociative(
            'SELECT id, name, duration_min, buffer_min, price, description, active, deposit_amount FROM service ORDER BY name'
        );
        $segments = $this->db->fetchAllAssociative(
            'SELECT service_id, position, minutes, busy FROM service_segment ORDER BY service_id, position'
        );
        $offers = $this->db->fetchAllAssociative(
            'SELECT service_id, location_id, price_override FROM service_location'
        );

        $byService = [];
        foreach ($segments as $s) {
            $byService[(int) $s['service_id']]['segments'][] = [
                'position' => (int) $s['position'],
                'minutes' => (int) $s['minutes'],
                'busy' => (bool) $s['busy'],
            ];
        }
        foreach ($offers as $o) {
            $byService[(int) $o['service_id']]['locations'][] = [
                'location_id' => (int) $o['location_id'],
                'price_override' => $o['price_override'] !== null ? (float) $o['price_override'] : null,
            ];
        }

        return $this->json(['services' => array_map(static function (array $r) use ($byService): array {
            $id = (int) $r['id'];

            return [
                'id' => $id,
                'name' => (string) $r['name'],
                'duration_min' => (int) $r['duration_min'],
                'buffer_min' => (int) $r['buffer_min'],
                'price' => $r['price'] !== null ? (float) $r['price'] : null,
                'deposit_amount' => $r['deposit_amount'] !== null ? (float) $r['deposit_amount'] : null,
                'description' => $r['description'] !== null ? (string) $r['description'] : null,
                'active' => (bool) $r['active'],
                'segments' => $byService[$id]['segments'] ?? [],
                'locations' => $byService[$id]['locations'] ?? [],
            ];
        }, $services)]);
    }

    #[Route('/api/v1/admin/services', name: 'admin_service_create', methods: ['POST'])]
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
        $duration = (int) ($payload['duration_min'] ?? 0);
        if ($name === '' || $duration <= 0) {
            return $this->error('VALIDATION', 'name y duration_min (>0) son obligatorios.', 400);
        }

        $segError = $this->validateSegments($payload['segments'] ?? null, $duration);
        if ($segError !== null) {
            return $this->error('VALIDATION', $segError, 400);
        }

        $id = $this->db->transactional(function (Connection $tx) use ($payload, $name, $duration): int {
            $sid = (int) $tx->fetchOne(
                'INSERT INTO service (name, duration_min, buffer_min, price, deposit_amount, description, active)
                 VALUES (?, ?, ?, ?, ?, ?, COALESCE(?, TRUE)) RETURNING id',
                [
                    $name, $duration, (int) ($payload['buffer_min'] ?? 0),
                    $this->numOrNull($payload['price'] ?? null),
                    $this->numOrNull($payload['deposit_amount'] ?? null),
                    isset($payload['description']) ? (string) $payload['description'] : null,
                    isset($payload['active']) ? (bool) $payload['active'] : null,
                ]
            );
            $this->replaceSegments($tx, $sid, $payload['segments'] ?? null);
            $this->replaceLocations($tx, $sid, $payload['locations'] ?? null);

            return $sid;
        });

        return $this->json(['id' => $id], 201);
    }

    #[Route('/api/v1/admin/services/{id}', name: 'admin_service_update', methods: ['PATCH'], requirements: ['id' => '\d+'])]
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

        $current = $this->db->fetchAssociative('SELECT duration_min FROM service WHERE id = ?', [$id]);
        if ($current === false) {
            return $this->error('NOT_FOUND', 'Servicio no encontrado.', 404);
        }

        $duration = array_key_exists('duration_min', $payload)
            ? (int) $payload['duration_min']
            : (int) $current['duration_min'];
        if ($duration <= 0) {
            return $this->error('VALIDATION', 'duration_min debe ser > 0.', 400);
        }
        if (array_key_exists('segments', $payload)) {
            $segError = $this->validateSegments($payload['segments'], $duration);
            if ($segError !== null) {
                return $this->error('VALIDATION', $segError, 400);
            }
        }

        $map = [
            'name' => static fn ($v) => trim((string) $v),
            'duration_min' => static fn ($v) => (int) $v,
            'buffer_min' => static fn ($v) => (int) $v,
            'price' => fn ($v) => $this->numOrNull($v),
            'deposit_amount' => fn ($v) => $this->numOrNull($v),
            'description' => static fn ($v) => $v !== null ? (string) $v : null,
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
                $tx->executeStatement('UPDATE service SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);
            }
            if (array_key_exists('segments', $payload)) {
                $this->replaceSegments($tx, $id, $payload['segments']);
            }
            if (array_key_exists('locations', $payload)) {
                $this->replaceLocations($tx, $id, $payload['locations']);
            }
        });

        return $this->json(['ok' => true]);
    }

    /**
     * @param mixed $segments
     */
    private function validateSegments($segments, int $duration): ?string
    {
        if ($segments === null) {
            return null;
        }
        if (!is_array($segments) || $segments === []) {
            return 'segments debe ser una lista no vacía (o se omite).';
        }
        $sum = 0;
        foreach ($segments as $seg) {
            if (!is_array($seg) || (int) ($seg['minutes'] ?? 0) <= 0 || !array_key_exists('busy', $seg)) {
                return 'Cada segmento necesita minutes (>0) y busy (bool).';
            }
            $sum += (int) $seg['minutes'];
        }
        if ($sum !== $duration) {
            return "La suma de los segmentos ($sum min) debe igualar duration_min ($duration min).";
        }

        return null;
    }

    /**
     * @param mixed $segments
     */
    private function replaceSegments(Connection $tx, int $serviceId, $segments): void
    {
        if ($segments === null) {
            return;
        }
        $tx->executeStatement('DELETE FROM service_segment WHERE service_id = ?', [$serviceId]);
        $pos = 1;
        foreach ((array) $segments as $seg) {
            $tx->executeStatement(
                'INSERT INTO service_segment (service_id, position, minutes, busy) VALUES (?, ?, ?, ?)',
                [$serviceId, $pos++, (int) $seg['minutes'], (bool) $seg['busy']],
                [3 => \Doctrine\DBAL\ParameterType::BOOLEAN]
            );
        }
    }

    /**
     * @param mixed $locations  lista de {location_id, price_override?}
     */
    private function replaceLocations(Connection $tx, int $serviceId, $locations): void
    {
        if ($locations === null) {
            return;
        }
        $tx->executeStatement('DELETE FROM service_location WHERE service_id = ?', [$serviceId]);
        foreach ((array) $locations as $loc) {
            $tx->executeStatement(
                'INSERT INTO service_location (service_id, location_id, price_override) VALUES (?, ?, ?)',
                [$serviceId, (int) $loc['location_id'], $this->numOrNull($loc['price_override'] ?? null)]
            );
        }
    }

    private function numOrNull(mixed $v): ?float
    {
        return $v === null || $v === '' ? null : (float) $v;
    }
}
