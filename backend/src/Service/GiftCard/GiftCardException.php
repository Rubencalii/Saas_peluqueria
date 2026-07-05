<?php

declare(strict_types=1);

namespace App\Service\GiftCard;

final class GiftCardException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $statusCode = 400,
    ) {
        parent::__construct($message);
    }
}
