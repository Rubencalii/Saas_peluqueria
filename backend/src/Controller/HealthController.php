<?php

declare(strict_types=1);

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Health check para balanceadores/monitorización (doc 11). Comprueba que la
 * app responde y que la base de datos está accesible.
 */
final class HealthController extends AbstractController
{
    public function __construct(private readonly Connection $db)
    {
    }

    #[Route('/api/v1/health', name: 'health', methods: ['GET'])]
    public function health(): JsonResponse
    {
        try {
            $this->db->executeQuery('SELECT 1');
            $db = 'up';
        } catch (\Throwable) {
            $db = 'down';
        }

        $ok = $db === 'up';

        return $this->json(
            ['status' => $ok ? 'ok' : 'degraded', 'db' => $db],
            $ok ? 200 : 503
        );
    }
}
