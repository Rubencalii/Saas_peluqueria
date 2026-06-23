<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Service\Auth\AuthException;
use App\Service\Auth\AuthService;
use App\Service\Tenant\BrandingService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Apariencia (white-label) de la cuenta: el admin de la cadena personaliza
 * nombre/colores/logo que se aplican a la web de reserva y al panel (doc 08).
 */
final class AdminBrandingController extends AdminController
{
    public function __construct(
        private readonly BrandingService $branding,
        private readonly AuthService $auth,
    ) {
    }

    #[Route('/api/v1/admin/account/branding', name: 'admin_account_branding_get', methods: ['GET'])]
    public function show(Request $request): JsonResponse
    {
        $data = $this->branding->get(self::user($request)['account_id']);
        if ($data === null) {
            return $this->error('NOT_FOUND', 'Cuenta no encontrada.', 404);
        }

        return $this->json(['branding' => $data]);
    }

    #[Route('/api/v1/admin/account/branding', name: 'admin_account_branding_update', methods: ['PATCH'])]
    public function update(Request $request): JsonResponse
    {
        $user = self::user($request);
        try {
            $this->auth->assertRole($user, ['admin_cadena']);
        } catch (AuthException $e) {
            return $this->error($e->errorCode, $e->getMessage(), $e->statusCode);
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->error('VALIDATION', 'El cuerpo debe ser un objeto JSON.', 400);
        }

        try {
            $this->branding->update($user['account_id'], $payload);
        } catch (\InvalidArgumentException $e) {
            return $this->error('VALIDATION', $e->getMessage(), 400);
        }

        return $this->show($request);
    }
}
