<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Tenant\SignupException;
use App\Service\Tenant\SignupService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Alta pública de un salón en el SaaS (multi-tenant Fase 6, doc 15).
 */
final class SignupController extends AbstractController
{
    public function __construct(private readonly SignupService $signup)
    {
    }

    #[Route('/api/v1/signup', name: 'signup', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['error' => ['code' => 'VALIDATION', 'message' => 'El cuerpo debe ser un objeto JSON.']], 400);
        }

        try {
            $result = $this->signup->signup($payload);
        } catch (SignupException $e) {
            return $this->json(['error' => ['code' => $e->errorCode, 'message' => $e->getMessage()]], $e->statusCode);
        }

        return $this->json($result, 201);
    }
}
