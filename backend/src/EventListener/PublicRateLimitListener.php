<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Rate limiting de endpoints públicos (docs/06 §6).
 *
 * - Endpoints de reserva (sin login, expuestos a abuso/scraping): 60/min por IP.
 * - Login del panel: límite estricto (10/min por IP) para frenar la fuerza bruta.
 *
 * El resto del panel queda fuera (va autenticado) y el webhook de WhatsApp
 * también (lo llama Meta y ya valida firma). Al superar el límite: 429 + Retry-After.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 12)]
final class PublicRateLimitListener
{
    /** Prefijo de las rutas de auth (login, forgot, reset) — limitador estricto. */
    private const AUTH_PREFIX = '/api/v1/auth/';

    /** @var list<array{method: string, path: string}> */
    private const PROTECTED = [
        ['method' => 'POST', 'path' => '/api/v1/appointments'],
        ['method' => 'GET', 'path' => '/api/v1/appointments/lookup'],
        ['method' => 'GET', 'path' => '/api/v1/availability'],
        ['method' => 'POST', 'path' => '/api/v1/waitlist'],
    ];

    public function __construct(
        private readonly RateLimiterFactoryInterface $publicApiLimiter,
        private readonly RateLimiterFactoryInterface $authLimiter,
        private readonly RateLimiterFactoryInterface $signupLimiter,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $method = $request->getMethod();
        $path = $request->getPathInfo();
        $ip = $request->getClientIp() ?? 'anon';

        // Alta de salón: límite muy estricto (anti creación masiva de cuentas).
        if ($method === 'POST' && $path === '/api/v1/signup') {
            $this->enforce($event, $this->signupLimiter->create('signup:' . $ip));

            return;
        }

        // Las rutas de auth (login, forgot, reset) usan el limitador estricto.
        if ($method === 'POST' && str_starts_with($path, self::AUTH_PREFIX)) {
            $this->enforce($event, $this->authLimiter->create('auth:' . $ip));

            return;
        }

        if ($this->isProtected($method, $path)) {
            $this->enforce($event, $this->publicApiLimiter->create($ip));
        }
    }

    private function enforce(RequestEvent $event, \Symfony\Component\RateLimiter\LimiterInterface $limiter): void
    {
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
