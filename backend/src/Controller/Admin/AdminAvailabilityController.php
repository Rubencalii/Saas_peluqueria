<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Service\AvailabilityService;
use App\Service\Auth\AuthException;
use App\Service\Auth\AuthService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Disponibilidad para el panel (alta manual de cita). Misma lógica que la web
 * pública pero autenticada y acotada a la cuenta del usuario (no resuelve el
 * tenant por subdominio, que no aplica en el panel).
 */
final class AdminAvailabilityController extends AdminController
{
    public function __construct(
        private readonly AvailabilityService $availability,
        private readonly AuthService $auth,
    ) {
    }

    #[Route('/api/v1/admin/availability', name: 'admin_availability', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $user = self::user($request);
        $locationId = (int) $request->query->get('location_id', 0);
        $serviceId = (int) $request->query->get('service_id', 0);
        $staffParam = $request->query->get('staff_id');
        $staffId = ($staffParam !== null && $staffParam !== '') ? (int) $staffParam : null;
        $date = (string) $request->query->get('date', '');

        if ($locationId <= 0 || $serviceId <= 0 || $date === '') {
            return $this->error('VALIDATION', 'Parámetros requeridos: location_id, service_id, date.', 400);
        }

        try {
            $this->auth->assertLocationAccount($user, $locationId); // la sede debe ser de la cuenta
            $this->auth->assertLocation($user, $locationId);        // y el rol debe poder operarla
        } catch (AuthException $e) {
            return $this->error($e->errorCode, $e->getMessage(), $e->statusCode);
        }

        try {
            $data = $this->availability->find($locationId, $serviceId, $staffId, $date);
        } catch (\InvalidArgumentException $e) {
            return $this->error('VALIDATION', $e->getMessage(), 400);
        }

        return $this->json($data);
    }
}
