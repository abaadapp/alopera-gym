<?php

namespace App\Modules\Messaging\Gateways;

final class GatewayResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly ?string $messageId = null,
        public readonly ?string $errorCode = null,
        public readonly ?string $errorMessage = null,
        public readonly bool $retryable = false,
    ) {
    }

    public static function sent(string $messageId): self
    {
        return new self(true, $messageId);
    }

    public static function failed(string $code, string $message, bool $retryable = false): self
    {
        return new self(false, null, $code, $message, $retryable);
    }
}
