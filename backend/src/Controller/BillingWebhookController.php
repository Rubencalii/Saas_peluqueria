<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Billing\BillingException;
use App\Service\Billing\BillingService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Webhook de Stripe Billing (suscripción del SaaS, multi-tenant Fase 5).
 * Separado del webhook de depósitos (/webhooks/stripe) y con su propio secreto.
 */
final class BillingWebhookController extends AbstractController
{
    public function __construct(private readonly BillingService $billing)
    {
    }

    #[Route('/api/v1/webhooks/stripe/billing', name: 'billing_webhook', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        try {
            $this->billing->handleWebhook(
                $request->getContent(),
                (string) $request->headers->get('Stripe-Signature', '')
            );
        } catch (BillingException $e) {
            return $this->json(['error' => ['code' => $e->errorCode, 'message' => $e->getMessage()]], $e->statusCode);
        }

        return $this->json(['received' => true]);
    }
}
