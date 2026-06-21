<?php

declare(strict_types=1);

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Endpoints públicos de catálogo (docs/06-especificacion-api.md §2).
 */
final class CatalogController extends AbstractController
{
    public function __construct(private readonly Connection $db)
    {
    }

    #[Route('/api/v1/locations', name: 'locations', methods: ['GET'])]
    public function locations(): JsonResponse
    {
        $rows = $this->db->fetchAllAssociative(
            'SELECT id, name, slug, timezone FROM location WHERE active ORDER BY name'
        );

        return $this->json(array_map($this->castLocation(...), $rows));
    }

    #[Route('/api/v1/locations/{slug}/services', name: 'location_services', methods: ['GET'])]
    public function services(string $slug): JsonResponse
    {
        $locationId = $this->db->fetchOne('SELECT id FROM location WHERE slug = ? AND active', [$slug]);
        if ($locationId === false) {
            return $this->json(['error' => ['code' => 'NOT_FOUND', 'message' => 'Sede no encontrada.']], 404);
        }

        $rows = $this->db->fetchAllAssociative(
            'SELECT s.id, s.name, s.duration_min, s.buffer_min,
                    COALESCE(sl.price_override, s.price) AS price, s.description
               FROM service s
               JOIN service_location sl ON sl.service_id = s.id AND sl.location_id = :loc
              WHERE s.active
              ORDER BY s.name',
            ['loc' => $locationId]
        );

        return $this->json([
            'location_id' => (int) $locationId,
            'services' => array_map($this->castService(...), $rows),
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
            'description' => $r['description'],
        ];
    }
}
