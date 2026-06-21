<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Service\AppointmentException;
use App\Service\Auth\AuthException;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Convierte cualquier excepción no capturada bajo `/api` en la respuesta de
 * error uniforme de la API (docs/06 §6): { "error": { "code", "message" } }.
 *
 * Mapea las excepciones de negocio (AppointmentException, AuthException) a su
 * código/estado, respeta los errores HTTP de Symfony (404, 405, 415…) y para
 * el resto devuelve 500 sin filtrar detalles internos en producción.
 */
#[AsEventListener(event: KernelEvents::EXCEPTION)]
final class ApiExceptionListener
{
    public function __construct(private readonly bool $debug)
    {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        if (!str_starts_with($event->getRequest()->getPathInfo(), '/api/')) {
            return;
        }

        $e = $event->getThrowable();

        [$status, $code, $message] = match (true) {
            $e instanceof AppointmentException => [$e->statusCode, $e->errorCode, $e->getMessage()],
            $e instanceof AuthException => [$e->statusCode, $e->errorCode, $e->getMessage()],
            $e instanceof HttpExceptionInterface => [
                $e->getStatusCode(),
                $this->httpCode($e->getStatusCode()),
                $e->getMessage() !== '' ? $e->getMessage() : 'Error de la petición.',
            ],
            default => [500, 'INTERNAL_ERROR', 'Error interno del servidor.'],
        };

        $payload = ['error' => ['code' => $code, 'message' => $message]];
        // En desarrollo, añade detalles para depurar; en producción no se filtran.
        if ($this->debug && $status >= 500) {
            $payload['error']['exception'] = $e::class;
            $payload['error']['detail'] = $e->getMessage();
        }

        $event->setResponse(new JsonResponse($payload, $status));
    }

    private function httpCode(int $status): string
    {
        return match ($status) {
            400 => 'BAD_REQUEST',
            401 => 'UNAUTHORIZED',
            403 => 'FORBIDDEN',
            404 => 'NOT_FOUND',
            405 => 'METHOD_NOT_ALLOWED',
            415 => 'UNSUPPORTED_MEDIA_TYPE',
            429 => 'RATE_LIMITED',
            default => 'HTTP_ERROR',
        };
    }
}
