<?php
declare(strict_types=1);

namespace app\utils\Invoice;

final class OpenInvoiceConfig
{
    private string $baseUrl;
    private string $keyId;
    private string $signingSecret;
    private string $invoicePath;
    private string $keyVersion;
    private int $timeoutSeconds;
    private int $clockSkewSeconds;

    public function __construct(
        string $baseUrl,
        string $keyId,
        string $signingSecret,
        string $invoicePath = '/api/open/v1/invoices',
        string $keyVersion = '1',
        int $timeoutSeconds = 30,
        int $clockSkewSeconds = 300
    ) {
        $baseUrl = rtrim(trim($baseUrl), '/');
        $keyId = trim($keyId);
        $invoicePath = '/' . trim($invoicePath, '/');
        $keyVersion = trim($keyVersion);
        if ($baseUrl === '' || filter_var($baseUrl, FILTER_VALIDATE_URL) === false) {
            throw new OpenInvoiceConfigurationException('baseUrl must be a valid absolute URL');
        }
        if ($keyId === '' || $signingSecret === '' || $keyVersion === '') {
            throw new OpenInvoiceConfigurationException('keyId, signingSecret and keyVersion are required');
        }
        if ($timeoutSeconds < 1 || $clockSkewSeconds < 1) {
            throw new OpenInvoiceConfigurationException('timeoutSeconds and clockSkewSeconds must be positive');
        }
        $this->baseUrl = $baseUrl;
        $this->keyId = $keyId;
        $this->signingSecret = $signingSecret;
        $this->invoicePath = $invoicePath;
        $this->keyVersion = $keyVersion;
        $this->timeoutSeconds = $timeoutSeconds;
        $this->clockSkewSeconds = $clockSkewSeconds;
    }

    public function baseUrl(): string { return $this->baseUrl; }
    public function keyId(): string { return $this->keyId; }
    public function signingSecret(): string { return $this->signingSecret; }
    public function invoicePath(): string { return $this->invoicePath; }
    public function keyVersion(): string { return $this->keyVersion; }
    public function timeoutSeconds(): int { return $this->timeoutSeconds; }
    public function clockSkewSeconds(): int { return $this->clockSkewSeconds; }
}
