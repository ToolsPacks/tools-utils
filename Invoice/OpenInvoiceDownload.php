<?php
declare(strict_types=1);

namespace app\utils\Invoice;

final class OpenInvoiceDownload
{
    private string $content;
    private string $contentType;
    private ?string $fileName;

    public function __construct(string $content, string $contentType, ?string $fileName = null)
    {
        $this->content = $content;
        $this->contentType = $contentType;
        $this->fileName = $fileName;
    }

    public function content(): string { return $this->content; }
    public function contentType(): string { return $this->contentType; }
    public function fileName(): ?string { return $this->fileName; }
}
