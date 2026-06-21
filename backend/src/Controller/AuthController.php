<?php

declare(strict_types=1);

namespace App\Controller;

use App\Controller\Admin\AdminController;
use App\Service\Auth\AuthException;
use App\Service\Auth\AuthService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Autenticación del panel (docs/06 §1). Emite el token que el resto de
 * endpoints `/api/v1/admin/*` exige.
 */
final class AuthController extends AbstractController
{
    public function __construct(private readonly AuthService $auth)
    {
    }

    #[Route('/api/v1/auth/login', name: 'auth_login', methods: ['POST'])]
    public function login(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['error' => ['code' => 'VALIDATION', 'message' => 'El cuerpo debe ser un objeto JSON.']], 400);
        }

        try {
            $result = $this->auth->login(
                is_string($payload['email'] ?? null) ? $payload['email'] : '',
                is_string($payload['password'] ?? null) ? $payload['password'] : '',
            );
        } catch (AuthException $e) {
            return $this->json(['error' => ['code' => $e->errorCode, 'message' => $e->getMessage()]], $e->statusCode);
        }

        return $this->json($result);
    }

    /**
     * Datos del usuario autenticado (útil para que el panel pinte la sesión).
     * Es ruta admin → el listener ya ha validado el token y puesto el contexto.
     */
    #[Route('/api/v1/admin/me', name: 'auth_me', methods: ['GET'])]
    public function me(Request $request): JsonResponse
    {
        return $this->json(['user' => AdminController::user($request)]);
    }
}
