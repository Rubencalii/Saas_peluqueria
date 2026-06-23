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
     * @return array{id: int, name: string, email: string, role: string, location_id: int|null, account_id: int, is_superadmin: bool}
     */
    public static function user(Request $request): array
    {
        /** @var array{id: int, name: string, email: string, role: string, location_id: int|null, account_id: int, is_superadmin: bool} $user */
        $user = $request->attributes->get(AdminAuthListener::ATTR, []);

        return $user;
    }

    protected function error(string $code, string $message, int $status): JsonResponse
    {
        return $this->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }

    /**
     * Parámetros de paginación de la query (`page` desde 1, `per_page` 1–100).
     *
     * @return array{page: int, per_page: int, offset: int}
     */
    protected static function pagination(Request $request, int $perPageDefault = 20): array
    {
        $page = max(1, (int) $request->query->get('page', 1));
        $perPage = (int) $request->query->get('per_page', $perPageDefault);
        $perPage = max(1, min(100, $perPage));

        return ['page' => $page, 'per_page' => $perPage, 'offset' => ($page - 1) * $perPage];
    }
}
