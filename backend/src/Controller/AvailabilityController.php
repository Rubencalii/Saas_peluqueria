<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\AvailabilityService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class AvailabilityController extends AbstractController
{
    public function __construct(private readonly AvailabilityService $availability)
    {
    }

    #[Route('/api/v1/availability', name: 'availability', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $locationId = $request->query->getInt('location_id');
        $serviceId = $request->query->getInt('service_id');
        $staffParam = $request->query->get('staff_id');
        $staffId = ($staffParam !== null && $staffParam !== '') ? (int) $staffParam : null;
        $date = (string) $request->query->get('date', '');

        if ($locationId <= 0 || $serviceId <= 0 || $date === '') {
            return $this->json(
                ['error' => ['code' => 'VALIDATION', 'message' => 'Parámetros requeridos: location_id, service_id, date.']],
                400
            );
        }

        try {
            $data = $this->availability->find($locationId, $serviceId, $staffId, $date);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['error' => ['code' => 'VALIDATION', 'message' => $e->getMessage()]], 400);
        }

        return $this->json($data);
    }
}
