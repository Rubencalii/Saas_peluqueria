<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Review\ReviewException;
use App\Service\Review\ReviewService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Valoración pública post-cita (doc 13). El cliente puntúa su cita verificada
 * por `public_code`.
 */
final class ReviewController extends AbstractController
{
    public function __construct(private readonly ReviewService $reviews)
    {
    }

    /** Contexto para la página pública /valorar (verificado por código). */
    #[Route('/api/v1/appointments/{id}/review', name: 'review_context', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function context(int $id, Request $request): JsonResponse
    {
        $ctx = $this->reviews->context($id, (string) $request->query->get('code', ''));
        if ($ctx === null) {
            return $this->json(['error' => ['code' => 'NOT_FOUND', 'message' => 'Cita no encontrada.']], 404);
        }

        return $this->json(['appointment' => $ctx]);
    }

    #[Route('/api/v1/appointments/{id}/review', name: 'review_submit', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function submit(int $id, Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        $payload = is_array($payload) ? $payload : [];

        $code = $request->headers->get('X-Appointment-Code')
            ?: $request->query->get('code')
            ?: (is_string($payload['code'] ?? null) ? $payload['code'] : '');

        try {
            $result = $this->reviews->submit(
                $id,
                (string) $code,
                (int) ($payload['rating'] ?? 0),
                is_string($payload['comment'] ?? null) ? $payload['comment'] : null,
            );
        } catch (ReviewException $e) {
            return $this->json(['error' => ['code' => $e->errorCode, 'message' => $e->getMessage()]], $e->statusCode);
        }

        return $this->json($result, 201);
    }
}
