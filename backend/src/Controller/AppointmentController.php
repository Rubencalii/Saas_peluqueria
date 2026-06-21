<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\AppointmentException;
use App\Service\AppointmentService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Reservas públicas (docs/06-especificacion-api.md §2).
 */
final class AppointmentController extends AbstractController
{
    public function __construct(private readonly AppointmentService $appointments)
    {
    }

    #[Route('/api/v1/appointments', name: 'appointment_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->error('VALIDATION', 'El cuerpo debe ser un objeto JSON.', 400);
        }

        $idempotencyKey = $request->headers->get('Idempotency-Key');

        try {
            $result = $this->appointments->create($payload, $idempotencyKey);
        } catch (AppointmentException $e) {
            return $this->error($e->errorCode, $e->getMessage(), $e->statusCode);
        }

        $status = ($result['idempotent_replay'] ?? false) ? 200 : 201;

        return $this->json($result, $status);
    }

    #[Route('/api/v1/appointments/lookup', name: 'appointment_lookup', methods: ['GET'])]
    public function lookup(Request $request): JsonResponse
    {
        try {
            $result = $this->appointments->lookup(
                (string) $request->query->get('phone', ''),
                (string) $request->query->get('code', ''),
            );
        } catch (AppointmentException $e) {
            return $this->error($e->errorCode, $e->getMessage(), $e->statusCode);
        }

        return $this->json($result);
    }

    #[Route('/api/v1/appointments/{id}/reschedule', name: 'appointment_reschedule', methods: ['PATCH'], requirements: ['id' => '\d+'])]
    public function reschedule(int $id, Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->error('VALIDATION', 'El cuerpo debe ser un objeto JSON.', 400);
        }

        $code = $this->code($request, $payload);
        $start = is_string($payload['start'] ?? null) ? $payload['start'] : '';

        try {
            $result = $this->appointments->reschedule($id, $code, $start);
        } catch (AppointmentException $e) {
            return $this->error($e->errorCode, $e->getMessage(), $e->statusCode);
        }

        return $this->json($result);
    }

    #[Route('/api/v1/appointments/{id}', name: 'appointment_cancel', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function cancel(int $id, Request $request): JsonResponse
    {
        $code = $this->code($request, []);

        try {
            $result = $this->appointments->cancel($id, $code);
        } catch (AppointmentException $e) {
            return $this->error($e->errorCode, $e->getMessage(), $e->statusCode);
        }

        return $this->json($result);
    }

    /**
     * Código de acceso de la cita: cabecera `X-Appointment-Code`, query `?code=`
     * o campo `code` del cuerpo (en ese orden de preferencia).
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

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return $this->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
