<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Service\Auth\AuthException;
use App\Service\Auth\AuthService;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Protege los endpoints internos del panel (docs/06 §1, §4).
 *
 * Toda ruta bajo `/api/v1/admin` exige `Authorization: Bearer <jwt>`. Si el
 * token es válido, el contexto del usuario queda en el atributo de petición
 * `auth_user` para que los controladores apliquen autorización por sede/rol.
 * Si falta o no es válido, corta con 401/403 JSON antes del controlador.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 8)]
final class AdminAuthListener
{
    public const ATTR = 'auth_user';

    public function __construct(private readonly AuthService $auth)
    {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!str_starts_with($request->getPathInfo(), '/api/v1/admin')) {
            return;
        }

        $header = (string) $request->headers->get('Authorization', '');
        if (!str_starts_with($header, 'Bearer ')) {
            $event->setResponse($this->deny('UNAUTHORIZED', 'Falta el token de acceso.', 401));

            return;
        }

        try {
            $user = $this->auth->verify(substr($header, 7));
        } catch (AuthException $e) {
            $event->setResponse($this->deny($e->errorCode, $e->getMessage(), $e->statusCode));

            return;
        }

        $request->attributes->set(self::ATTR, $user);
    }

    private function deny(string $code, string $message, int $status): JsonResponse
    {
        return new JsonResponse(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
