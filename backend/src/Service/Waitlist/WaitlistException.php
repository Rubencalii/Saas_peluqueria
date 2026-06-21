<?php

declare(strict_types=1);

namespace App\Service\Waitlist;

/**
 * Error de negocio de la lista de espera, con código y estado HTTP según la
 * convención de errores de la API (docs/06 §6).
 */
final class WaitlistException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $statusCode = 400,
    ) {
        parent::__construct($message);
    }
}
