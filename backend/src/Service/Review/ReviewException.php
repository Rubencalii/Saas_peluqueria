<?php

declare(strict_types=1);

namespace App\Service\Review;

/**
 * Error de negocio de las valoraciones, con código y estado HTTP (docs/06 §6).
 */
final class ReviewException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $statusCode = 400,
    ) {
        parent::__construct($message);
    }
}
