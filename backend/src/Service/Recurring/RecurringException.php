<?php

declare(strict_types=1);

namespace App\Service\Recurring;

/**
 * Error de negocio de las citas recurrentes (docs/06 §6).
 */
final class RecurringException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $statusCode = 400,
    ) {
        parent::__construct($message);
    }
}
