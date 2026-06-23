<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Tenant\BrandingService;
use App\Service\Tenant\TenantResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Marca pública de la cuenta (white-label): la web de reserva la pide al
 * resolver el salón por subdominio para aplicar nombre/colores/logo (doc 08).
 */
final class BrandingController extends AbstractController
{
    public function __construct(
        private readonly BrandingService $branding,
        private readonly TenantResolver $tenant,
    ) {
    }

    #[Route('/api/v1/branding', name: 'public_branding', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $data = $this->branding->get($this->tenant->accountId($request));

        return $this->json(['branding' => $data]);
    }
}
