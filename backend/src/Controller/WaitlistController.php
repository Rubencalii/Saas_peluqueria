<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Waitlist\WaitlistException;
use App\Service\Waitlist\WaitlistService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Lista de espera pública (doc 13 §2.4): el cliente se apunta cuando no hay
 * hueco a su gusto y el sistema le avisa al liberarse uno.
 */
final class WaitlistController extends AbstractController
{
    public function __construct(private readonly WaitlistService $waitlist)
    {
    }

    #[Route('/api/v1/waitlist', name: 'waitlist_join', methods: ['POST'])]
    public function join(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->error('VALIDATION', 'El cuerpo debe ser un objeto JSON.', 400);
        }

        $customer = is_array($payload['customer'] ?? null) ? $payload['customer'] : [];

        try {
            $result = $this->waitlist->join(
                (int) ($payload['location_id'] ?? 0),
                (int) ($payload['service_id'] ?? 0),
                isset($payload['staff_id']) && $payload['staff_id'] !== null ? (int) $payload['staff_id'] : null,
                is_string($customer['name'] ?? null) ? $customer['name'] : '',
                is_string($customer['phone'] ?? null) ? $customer['phone'] : '',
                (bool) ($payload['wa_consent'] ?? false),
                is_string($payload['date'] ?? null) ? $payload['date'] : null,
            );
        } catch (WaitlistException $e) {
            return $this->error($e->errorCode, $e->getMessage(), $e->statusCode);
        }

        return $this->json($result, ($result['already'] ?? false) ? 200 : 201);
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return $this->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
