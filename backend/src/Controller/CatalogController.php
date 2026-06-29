<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Tenant\TenantResolver;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Endpoints públicos de catálogo (docs/06-especificacion-api.md §2).
 *
 * Multi-tenant (doc 15, Fase 3): la cuenta se resuelve por el subdominio de la
 * petición; el `slug` de sede es único POR cuenta, así que el catálogo se acota
 * siempre a la cuenta resuelta.
 */
final class CatalogController extends AbstractController
{
    public function __construct(
        private readonly Connection $db,
        private readonly TenantResolver $tenant,
    ) {
    }

    #[Route('/api/v1/locations', name: 'locations', methods: ['GET'])]
    public function locations(Request $request): JsonResponse
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT id, name, slug, timezone FROM location WHERE active AND account_id = ? ORDER BY name',
            [$this->tenant->accountId($request)]
        );

        return $this->json(array_map($this->castLocation(...), $rows));
    }

    #[Route('/api/v1/locations/{slug}/services', name: 'location_services', methods: ['GET'])]
    public function services(string $slug, Request $request): JsonResponse
    {
        $accountId = $this->tenant->accountId($request);
        $locationId = $this->db->fetchOne(
            'SELECT id FROM location WHERE slug = ? AND account_id = ? AND active',
            [$slug, $accountId]
        );
        if ($locationId === false) {
            return $this->json(['error' => ['code' => 'NOT_FOUND', 'message' => 'Sede no encontrada.']], 404);
        }

        $rows = $this->db->fetchAllAssociative(
            'SELECT s.id, s.name, s.duration_min, s.buffer_min,
                    COALESCE(sl.price_override, s.price) AS price, s.description, s.deposit_amount
               FROM service s
               JOIN service_location sl ON sl.service_id = s.id AND sl.location_id = :loc
              WHERE s.active AND s.account_id = :acc
              ORDER BY s.name',
            ['loc' => $locationId, 'acc' => $accountId]
        );

        return $this->json([
            'location_id' => (int) $locationId,
            'services' => array_map($this->castService(...), $rows),
        ]);
    }

    /**
     * Profesionales que ofrecen un servicio en una sede (para que el cliente
     * pueda elegir con quién). Acotado a la cuenta del subdominio.
     */
    #[Route('/api/v1/staff', name: 'public_staff', methods: ['GET'])]
    public function staff(Request $request): JsonResponse
    {
        $locationId = (int) $request->query->get('location_id', 0);
        $serviceId = (int) $request->query->get('service_id', 0);
        if ($locationId <= 0 || $serviceId <= 0) {
            return $this->json(['error' => ['code' => 'VALIDATION', 'message' => 'location_id y service_id son obligatorios.']], 400);
        }
        if (!$this->tenant->locationInAccount($request, $locationId)) {
            return $this->json(['staff' => []]);
        }

        $rows = $this->db->fetchAllAssociative(
            'SELECT s.id, s.name
               FROM staff s
               JOIN staff_service  ss ON ss.staff_id = s.id AND ss.service_id = ?
               JOIN staff_location sl ON sl.staff_id = s.id AND sl.location_id = ?
              WHERE s.active AND s.account_id = ?
              ORDER BY s.name',
            [$serviceId, $locationId, $this->tenant->accountId($request)]
        );

        return $this->json([
            'staff' => array_map(static fn (array $r): array => [
                'id' => (int) $r['id'],
                'name' => (string) $r['name'],
            ], $rows),
        ]);
    }

    /**
     * @param array<string, mixed> $r
     *
     * @return array<string, mixed>
     */
    private function castLocation(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'name' => $r['name'],
            'slug' => $r['slug'],
            'timezone' => $r['timezone'],
        ];
    }

    /**
     * @param array<string, mixed> $r
     *
     * @return array<string, mixed>
     */
    private function castService(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'name' => $r['name'],
            'duration_min' => (int) $r['duration_min'],
            'buffer_min' => (int) $r['buffer_min'],
            'price' => $r['price'] !== null ? (float) $r['price'] : null,
            'deposit_amount' => $r['deposit_amount'] !== null ? (float) $r['deposit_amount'] : null,
            'description' => $r['description'],
        ];
    }
}
