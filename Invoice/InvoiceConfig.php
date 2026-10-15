<?php

declare(strict_types=1);

namespace app\utils\Invoice;

final class InvoiceConfig
{
    private const DEFAULT_PATHS = [
        'preflight' => '/api/internal/erp/purchase-invoices/preflight',
        'create' => '/api/internal/erp/purchase-invoices',
        'status' => '/api/internal/erp/purchase-invoices/{requestNo}',
        'download' => '/api/internal/erp/purchase-invoices/{requestNo}/download',
    ];

    private string $baseUrl;
    private string $clientId;
    private string $secret;
    private int $timeoutSeconds;
    private int $clockSkewSeconds;
    /** @var array<string, string> */
    private array $paths;

    /** @param array<string, string> $paths */
    public function __construct(
        string $baseUrl,
        string $clientId,
        string $secret,
        int $timeoutSeconds = 15,
        int $clockSkewSeconds = 300,
        array $paths = []
    ) {
        $baseUrl = rtrim(trim($baseUrl), '/');
        if ($baseUrl === '' || filter_var($baseUrl, FILTER_VALIDATE_URL) === false) {
            throw new ConfigurationException('Invoice API base URL is invalid.');
        }
        if (trim($clientId) === '') {
            throw new ConfigurationException('Invoice API client ID must not be empty.');
        }
        if ($secret === '') {
            throw new ConfigurationException('Invoice API secret must not be empty.');
        }
        if ($timeoutSeconds < 1) {
            throw new ConfigurationException('Invoice API timeout must be at least one second.');
        }
        if ($clockSkewSeconds < 0) {
            throw new ConfigurationException('Invoice API clock skew must not be negative.');
        }

        $mergedPaths = array_replace(self::DEFAULT_PATHS, $paths);
        foreach (self::DEFAULT_PATHS as $name => $_) {
            if (!isset($mergedPaths[$name]) || !is_string($mergedPaths[$name]) || !str_starts_with($mergedPaths[$name], '/')) {
                throw new ConfigurationException(sprintf('Invoice API path "%s" must start with /.', $name));
            }
        }

        $this->baseUrl = $baseUrl;
        $this->clientId = trim($clientId);
        $this->secret = $secret;
        $this->timeoutSeconds = $timeoutSeconds;
        $this->clockSkewSeconds = $clockSkewSeconds;
        $this->paths = $mergedPaths;
    }

    public function baseUrl(): string { return $this->baseUrl; }
    public function clientId(): string { return $this->clientId; }
    public function timeoutSeconds(): int { return $this->timeoutSeconds; }
    public function clockSkewSeconds(): int { return $this->clockSkewSeconds; }

    public function path(string $operation, ?string $invoiceId = null): string
    {
        if (!isset($this->paths[$operation])) {
            throw new ConfigurationException(sprintf('Unknown invoice API operation "%s".', $operation));
        }

        $path = $this->paths[$operation];
        foreach (['{requestNo}', '{invoiceId}'] as $placeholder) {
            if (str_contains($path, $placeholder)) {
                if ($invoiceId === null || $invoiceId === '') {
                    throw new ConfigurationException('Invoice request number must not be empty.');
                }
                $path = str_replace($placeholder, rawurlencode($invoiceId), $path);
            }
        }

        return $path;
    }

    public function sign(string $canonicalRequest): string
    {
        return hash_hmac('sha256', $canonicalRequest, $this->secret);
    }

    public function redact(string $value): string
    {
        return str_replace($this->secret, '[REDACTED]', $value);
    }
}
