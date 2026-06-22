<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Service\Auth\AuthException;
use App\Service\Auth\AuthService;
use App\Service\Recurring\RecurringException;
use App\Service\Recurring\RecurringService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Citas recurrentes en el panel (doc 13): alta, listado y baja de las
 * recurrencias de un cliente. Recepción y admin de la sede.
 */
final class AdminRecurringController extends AdminController
{
    private const ROLES = ['recepcion', 'admin_sede', 'admin_cadena'];

    public function __construct(
        private readonly RecurringService $recurring,
        private readonly AuthService $auth,
    ) {
    }

    #[Route('/api/v1/admin/recurring', name: 'admin_recurring_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $user = self::user($request);
        try {
            $this->auth->assertRole($user, self::ROLES);
            $requested = $request->query->get('location_id');
            $locationId = $this->auth->resolveLocation($user, $requested !== null && (int) $requested > 0 ? (int) $requested : null);
        } catch (AuthException $e) {
            return $this->error($e->errorCode, $e->getMessage(), $e->statusCode);
        }

        return $this->json(['recurring' => $this->recurring->listForLocation($locationId, $user['account_id'])]);
    }

    #[Route('/api/v1/admin/recurring', name: 'admin_recurring_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $user = self::user($request);
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->error('VALIDATION', 'El cuerpo debe ser un objeto JSON.', 400);
        }

        $locationId = (int) ($payload['location_id'] ?? 0);
        $customer = is_array($payload['customer'] ?? null) ? $payload['customer'] : [];

        try {
            $this->auth->assertRole($user, self::ROLES);
            $this->auth->assertLocationAccount($user, $locationId);
            $this->auth->assertLocation($user, $locationId);
            $result = $this->recurring->create(
                $locationId,
                (int) ($payload['service_id'] ?? 0),
                isset($payload['staff_id']) ? (int) $payload['staff_id'] : null,
                is_string($customer['name'] ?? null) ? $customer['name'] : '',
                is_string($customer['phone'] ?? null) ? $customer['phone'] : '',
                (int) ($payload['weekday'] ?? -1),
                is_string($payload['time'] ?? null) ? $payload['time'] : '',
                (int) ($payload['interval_weeks'] ?? 4),
            );
        } catch (AuthException|RecurringException $e) {
            return $this->error($e->errorCode, $e->getMessage(), $e->statusCode);
        }

        return $this->json($result, 201);
    }

    #[Route('/api/v1/admin/recurring/{id}', name: 'admin_recurring_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(int $id, Request $request): JsonResponse
    {
        $user = self::user($request);
        try {
            $this->auth->assertRole($user, self::ROLES);
        } catch (AuthException $e) {
            return $this->error($e->errorCode, $e->getMessage(), $e->statusCode);
        }

        $locationId = $this->recurring->locationOf($id);
        if ($locationId === null) {
            return $this->error('NOT_FOUND', 'Recurrencia no encontrada.', 404);
        }
        try {
            $this->auth->assertLocationAccount($user, $locationId);
            $this->auth->assertLocation($user, $locationId);
        } catch (AuthException $e) {
            return $this->error($e->errorCode, $e->getMessage(), $e->statusCode);
        }

        $this->recurring->deactivate($id);

        return $this->json(['ok' => true]);
    }
}
