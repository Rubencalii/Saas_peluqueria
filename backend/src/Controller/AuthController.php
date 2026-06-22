<?php

declare(strict_types=1);

namespace App\Controller;

use App\Controller\Admin\AdminController;
use App\Service\Auth\AuthException;
use App\Service\Auth\AuthService;
use App\Service\Auth\PasswordResetService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Autenticación del panel (docs/06 §1). Emite el token que el resto de
 * endpoints `/api/v1/admin/*` exige, y gestiona el reset de contraseña.
 */
final class AuthController extends AbstractController
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly PasswordResetService $passwordReset,
    ) {
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
     * Renueva el token a partir de uno válido (Bearer). Útil para extender la
     * sesión sin pedir credenciales otra vez.
     */
    #[Route('/api/v1/auth/refresh', name: 'auth_refresh', methods: ['POST'])]
    public function refresh(Request $request): JsonResponse
    {
        $header = (string) $request->headers->get('Authorization', '');
        if (!str_starts_with($header, 'Bearer ')) {
            return $this->json(['error' => ['code' => 'UNAUTHORIZED', 'message' => 'Falta el token de acceso.']], 401);
        }

        try {
            $result = $this->auth->refresh(substr($header, 7));
        } catch (AuthException $e) {
            return $this->json(['error' => ['code' => $e->errorCode, 'message' => $e->getMessage()]], $e->statusCode);
        }

        return $this->json($result);
    }

    /**
     * Cierra la sesión en todos los dispositivos: revoca los tokens emitidos
     * hasta ahora (incluido el actual). Ruta admin → token ya validado.
     */
    #[Route('/api/v1/admin/auth/logout', name: 'auth_logout', methods: ['POST'])]
    public function logout(Request $request): JsonResponse
    {
        $this->auth->revokeSessions(AdminController::user($request)['id']);

        return $this->json(['ok' => true]);
    }

    /**
     * Solicita un enlace de reset. Responde siempre 200 (no revela si el email
     * existe). El enlace se envía por email / se registra en el log.
     */
    #[Route('/api/v1/auth/password/forgot', name: 'auth_password_forgot', methods: ['POST'])]
    public function forgot(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        $email = is_array($payload) && is_string($payload['email'] ?? null) ? $payload['email'] : '';

        $this->passwordReset->request($email);

        return $this->json(['ok' => true]); // respuesta genérica
    }

    /**
     * Restablece la contraseña con un token válido.
     */
    #[Route('/api/v1/auth/password/reset', name: 'auth_password_reset', methods: ['POST'])]
    public function reset(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['error' => ['code' => 'VALIDATION', 'message' => 'El cuerpo debe ser un objeto JSON.']], 400);
        }

        try {
            $this->passwordReset->reset(
                is_string($payload['token'] ?? null) ? $payload['token'] : '',
                is_string($payload['password'] ?? null) ? $payload['password'] : '',
            );
        } catch (AuthException $e) {
            return $this->json(['error' => ['code' => $e->errorCode, 'message' => $e->getMessage()]], $e->statusCode);
        }

        return $this->json(['ok' => true]);
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
