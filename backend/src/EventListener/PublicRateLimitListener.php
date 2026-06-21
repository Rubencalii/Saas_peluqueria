<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Rate limiting de los endpoints públicos de reserva (docs/06 §6).
 *
 * Limita por IP el alta de citas, la consulta de disponibilidad y el lookup,
 * que no requieren login y son los expuestos a abuso/scraping. Los endpoints
 * del panel quedan fuera (van autenticados) y el webhook de WhatsApp también
 * (lo llama Meta). Al superar el límite responde 429 con cabecera Retry-After.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 12)]
final class PublicRateLimitListener
{
    /** @var list<array{method: string, path: string}> */
    private const PROTECTED = [
        ['method' => 'POST', 'path' => '/api/v1/appointments'],
        ['method' => 'GET', 'path' => '/api/v1/appointments/lookup'],
        ['method' => 'GET', 'path' => '/api/v1/availability'],
    ];

    public function __construct(private readonly RateLimiterFactoryInterface $publicApiLimiter)
    {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!$this->isProtected($request->getMethod(), $request->getPathInfo())) {
            return;
        }

        $limiter = $this->publicApiLimiter->create($request->getClientIp() ?? 'anon');
        $limit = $limiter->consume(1);

        if (!$limit->isAccepted()) {
            $retryAfter = max(1, $limit->getRetryAfter()->getTimestamp() - time());
            $event->setResponse(new JsonResponse(
                ['error' => ['code' => 'RATE_LIMITED', 'message' => 'Demasiadas peticiones, inténtalo en unos segundos.']],
                429,
                ['Retry-After' => (string) $retryAfter]
            ));
        }
    }

    private function isProtected(string $method, string $path): bool
    {
        foreach (self::PROTECTED as $rule) {
            if ($rule['method'] === $method && $path === $rule['path']) {
                return true;
            }
        }

        return false;
    }
}
