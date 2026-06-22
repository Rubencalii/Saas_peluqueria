<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Service\Auth\AuthException;
use App\Service\Auth\AuthService;
use App\Service\Billing\BillingException;
use App\Service\Billing\BillingService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Facturación de la suscripción del salón (multi-tenant Fase 5, doc 15).
 * Solo admin_cadena. Estas rutas se eximen del bloqueo por cuenta suspendida
 * (un salón impago debe poder pagar) — ver AdminAuthListener.
 */
final class AdminBillingController extends AdminController
{
    public function __construct(
        private readonly BillingService $billing,
        private readonly AuthService $auth,
    ) {
    }

    #[Route('/api/v1/admin/billing/checkout', name: 'admin_billing_checkout', methods: ['POST'])]
    public function checkout(Request $request): JsonResponse
    {
        $user = self::user($request);
        try {
            $this->auth->assertRole($user, ['admin_cadena']);
        } catch (AuthException $e) {
            return $this->error($e->errorCode, $e->getMessage(), $e->statusCode);
        }

        $payload = json_decode($request->getContent(), true);
        $planCode = is_array($payload) && is_string($payload['plan_code'] ?? null) ? $payload['plan_code'] : '';

        try {
            return $this->json($this->billing->startCheckout($user['account_id'], $planCode));
        } catch (BillingException $e) {
            return $this->error($e->errorCode, $e->getMessage(), $e->statusCode);
        }
    }

    #[Route('/api/v1/admin/billing/portal', name: 'admin_billing_portal', methods: ['POST'])]
    public function portal(Request $request): JsonResponse
    {
        $user = self::user($request);
        try {
            $this->auth->assertRole($user, ['admin_cadena']);
        } catch (AuthException $e) {
            return $this->error($e->errorCode, $e->getMessage(), $e->statusCode);
        }

        try {
            return $this->json($this->billing->portal($user['account_id']));
        } catch (BillingException $e) {
            return $this->error($e->errorCode, $e->getMessage(), $e->statusCode);
        }
    }
}
