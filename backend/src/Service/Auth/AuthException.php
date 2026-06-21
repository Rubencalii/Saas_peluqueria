<?php

declare(strict_types=1);

namespace App\Service\Auth;

/**
 * Error de autenticación/autorización del panel, con código y estado HTTP
 * según la convención de errores de la API (docs/06 §6).
 */
final class AuthException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $statusCode = 401,
    ) {
        parent::__construct($message);
    }
}
