<?php

declare(strict_types=1);

namespace app\utils\Invoice;

final class DownloadResponse
{
    /** @param array<string, string> $headers */
    public function __construct(
        private readonly string $content,
        private readonly string $contentType,
        private readonly ?string $filename,
        private readonly array $headers,
        private readonly int $statusCode
    ) {
    }

    public function content(): string { return $this->content; }
    public function contentType(): string { return $this->contentType; }
    public function filename(): ?string { return $this->filename; }
    /** @return array<string, string> */
    public function headers(): array { return $this->headers; }
    public function statusCode(): int { return $this->statusCode; }
}
