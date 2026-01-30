<?php

declare(strict_types=1);

namespace App\Exception;

class EsiApiException extends \Exception
{
    public function __construct(
        string $message,
        public readonly int $statusCode = 500,
        public readonly ?string $endpoint = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }

    public static function fromResponse(int $statusCode, string $message, ?string $endpoint = null): self
    {
        return new self($message, $statusCode, $endpoint);
    }

    public static function unauthorized(string $message = 'ESI authentication failed', ?string $endpoint = null): self
    {
        return new self($message, 401, $endpoint);
    }

    public static function forbidden(string $message = 'Access forbidden', ?string $endpoint = null): self
    {
        return new self($message, 403, $endpoint);
    }

    public static function notFound(string $message = 'Resource not found', ?string $endpoint = null): self
    {
        return new self($message, 404, $endpoint);
    }

    public static function rateLimited(string $message = 'Rate limit exceeded', ?string $endpoint = null): self
    {
        return new self($message, 429, $endpoint);
    }
}
