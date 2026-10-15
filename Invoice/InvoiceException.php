<?php

declare(strict_types=1);

namespace app\utils\Invoice;

class InvoiceException extends \RuntimeException
{
    /** @param array<string, mixed> $details */
    public function __construct(string $message, private readonly ?int $statusCode = null, private readonly array $details = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public function statusCode(): ?int { return $this->statusCode; }
    /** @return array<string, mixed> */
    public function details(): array { return $this->details; }
}
