<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Payment\PaymentException;
use App\Service\Payment\PaymentService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Depósito / pago online (doc 13 §2.5).
 *
 * - El cliente inicia el pago del depósito de su cita (verificado por código)
 *   y recibe el client_secret para completarlo con Stripe en la web.
 * - El webhook de Stripe confirma el cobro (responde 200 siempre que la firma
 *   sea válida, para que Stripe no reintente sin parar).
 */
final class PaymentController extends AbstractController
{
    public function __construct(private readonly PaymentService $payments)
    {
    }

    #[Route('/api/v1/appointments/{id}/deposit', name: 'payment_deposit', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function deposit(int $id, Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        $payload = is_array($payload) ? $payload : [];

        try {
            $result = $this->payments->createDepositIntent($id, $this->code($request, $payload));
        } catch (PaymentException $e) {
            return $this->json(['error' => ['code' => $e->errorCode, 'message' => $e->getMessage()]], $e->statusCode);
        }

        return $this->json($result);
    }

    #[Route('/api/v1/webhooks/stripe', name: 'payment_webhook', methods: ['POST'])]
    public function webhook(Request $request): JsonResponse
    {
        try {
            $this->payments->handleWebhook(
                $request->getContent(),
                (string) $request->headers->get('Stripe-Signature', '')
            );
        } catch (PaymentException $e) {
            return $this->json(['error' => ['code' => $e->errorCode, 'message' => $e->getMessage()]], $e->statusCode);
        }

        return $this->json(['received' => true]);
    }

    /**
     * Código de la cita: cabecera `X-Appointment-Code`, query `?code=` o campo
     * `code` del cuerpo (igual que en la gestión de citas).
     *
     * @param array<string, mixed> $payload
     */
    private function code(Request $request, array $payload): string
    {
        $header = $request->headers->get('X-Appointment-Code');
        if (is_string($header) && $header !== '') {
            return $header;
        }
        $query = $request->query->get('code');
        if (is_string($query) && $query !== '') {
            return $query;
        }

        return is_string($payload['code'] ?? null) ? $payload['code'] : '';
    }
}
