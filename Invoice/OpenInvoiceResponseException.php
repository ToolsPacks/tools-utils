<?php
declare(strict_types=1);

namespace app\utils\Invoice;

final class OpenInvoiceResponseException extends OpenInvoiceException
{
    private int $statusCode;
    private ?array $response;

    public function __construct(string $message, int $statusCode, ?array $response = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, $statusCode, $previous);
        $this->statusCode = $statusCode;
        $this->response = $response;
    }

    public function statusCode(): int { return $this->statusCode; }
    public function response(): ?array { return $this->response; }
    public function errorCode(): ?string
    {
        return isset($this->response['code']) ? (string)$this->response['code'] : null;
    }
    public function requestId(): ?string
    {
        return isset($this->response['request_id']) ? (string)$this->response['request_id'] : null;
    }
}
