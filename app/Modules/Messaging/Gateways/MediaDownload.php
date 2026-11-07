<?php

namespace App\Modules\Messaging\Gateways;

/** A downloaded file, or why it could not be downloaded. */
final class MediaDownload
{
    public function __construct(
        public readonly bool $ok,
        public readonly ?string $bytes = null,
        public readonly ?string $mime = null,
        public readonly ?string $error = null,
        public readonly bool $retryable = false,
    ) {}

    public static function file(string $bytes, ?string $mime): self
    {
        return new self(true, $bytes, $mime);
    }

    public static function failed(string $error, bool $retryable = false): self
    {
        return new self(false, null, null, $error, $retryable);
    }
}
