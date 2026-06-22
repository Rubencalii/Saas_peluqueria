<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * CORS para la API (`/api/*`), necesario para que el panel y la web pública
 * (navegador) llamen al backend desde otro origen.
 *
 * Orígenes permitidos vía `CORS_ALLOWED_ORIGINS` (lista separada por comas; `*`
 * permite cualquiera, útil en desarrollo). Responde el preflight `OPTIONS` antes
 * de auth/rate-limit y añade las cabeceras CORS a las respuestas de la API.
 */
final class CorsListener implements EventSubscriberInterface
{
    private const ALLOW_METHODS = 'GET, POST, PATCH, DELETE, OPTIONS';
    private const ALLOW_HEADERS = 'Content-Type, Authorization, X-Appointment-Code, Idempotency-Key';

    /** @var list<string> */
    private array $allowed;

    public function __construct(string $allowedOrigins)
    {
        $this->allowed = array_values(array_filter(array_map('trim', explode(',', $allowedOrigins))));
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // Prioridad alta: contestar el preflight antes que auth/rate-limit.
            KernelEvents::REQUEST => ['onRequest', 250],
            KernelEvents::RESPONSE => ['onResponse', 0],
        ];
    }

    public function onRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        if ($request->getMethod() === 'OPTIONS' && str_starts_with($request->getPathInfo(), '/api/')) {
            $response = new Response('', 204);
            $this->applyHeaders($response, (string) $request->headers->get('Origin', ''));
            $event->setResponse($response);
        }
    }

    public function onResponse(ResponseEvent $event): void
    {
        $request = $event->getRequest();
        $origin = (string) $request->headers->get('Origin', '');
        if ($origin === '' || !str_starts_with($request->getPathInfo(), '/api/')) {
            return;
        }
        $this->applyHeaders($event->getResponse(), $origin);
    }

    private function applyHeaders(Response $response, string $origin): void
    {
        $allow = $this->resolveOrigin($origin);
        if ($allow === null) {
            return; // origen no permitido: sin cabeceras CORS
        }

        $response->headers->set('Access-Control-Allow-Origin', $allow);
        $response->headers->set('Access-Control-Allow-Methods', self::ALLOW_METHODS);
        $response->headers->set('Access-Control-Allow-Headers', self::ALLOW_HEADERS);
        $response->headers->set('Access-Control-Max-Age', '3600');
        if ($allow !== '*') {
            $response->headers->set('Vary', 'Origin');
        }
    }

    /**
     * Devuelve el valor de Access-Control-Allow-Origin: el propio origen si está
     * permitido, `*` si se permite cualquiera, o null si no se permite.
     */
    private function resolveOrigin(string $origin): ?string
    {
        if (in_array('*', $this->allowed, true)) {
            return '*';
        }
        if (in_array($origin, $this->allowed, true)) {
            return $origin;
        }

        return null;
    }
}
