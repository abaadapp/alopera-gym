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
        public readonly bool $uncertain = false,
    ) {}

    public static function sent(string $messageId): self
    {
        return new self(true, $messageId);
    }

    public static function failed(string $code, string $message, bool $retryable = false): self
    {
        return new self(false, null, $code, $message, $retryable);
    }

    /**
     * The request may have reached WhatsApp and nothing says what became of it (#203): never retried on its own, since
     * a second request would be a second message. `retryable` is kept for failures known to be before WhatsApp.
     */
    public static function uncertain(string $message): self
    {
        return new self(false, null, 'outcome_unknown', $message, false, true);
    }
}
