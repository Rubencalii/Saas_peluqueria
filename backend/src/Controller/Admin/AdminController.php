<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\EventListener\AdminAuthListener;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Base de los controladores del panel: acceso al usuario autenticado (que el
 * AdminAuthListener deja en la petición) y respuesta de error uniforme.
 */
abstract class AdminController extends AbstractController
{
    /**
     * Contexto del usuario autenticado, garantizado por el listener en rutas admin.
     *
     * @return array{id: int, name: string, email: string, role: string, location_id: int|null}
     */
    public static function user(Request $request): array
    {
        /** @var array{id: int, name: string, email: string, role: string, location_id: int|null} $user */
        $user = $request->attributes->get(AdminAuthListener::ATTR, []);

        return $user;
    }

    protected function error(string $code, string $message, int $status): JsonResponse
    {
        return $this->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
