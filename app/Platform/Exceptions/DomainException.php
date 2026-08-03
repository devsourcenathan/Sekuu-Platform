<?php

declare(strict_types=1);

namespace App\Platform\Exceptions;

use RuntimeException;

/**
 * Exception métier portant un code du catalogue commun.
 *
 * Chaque module lève des sous-classes de celle-ci plutôt que de construire
 * des réponses d'erreur à la main.
 *
 * @see docs/02-standards/error-codes.md
 */
class DomainException extends RuntimeException
{
    /**
     * @param  array<string, mixed>|null  $details
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 400,
        public readonly ?array $details = null,
    ) {
        parent::__construct($message);
    }

    public static function notFound(string $code = 'RESOURCE_NOT_FOUND', string $message = 'The requested resource does not exist.'): self
    {
        return new self($code, $message, 404);
    }

    public static function forbidden(string $code = 'FORBIDDEN', string $message = 'Access denied.'): self
    {
        return new self($code, $message, 403);
    }

    public static function conflict(string $code, string $message): self
    {
        return new self($code, $message, 409);
    }

    public static function unprocessable(string $code, string $message): self
    {
        return new self($code, $message, 422);
    }
}
