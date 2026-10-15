<?php

declare(strict_types=1);

namespace app\utils\Invoice;

final class InvoiceSigner
{
    public function __construct(private readonly InvoiceConfig $config)
    {
    }

    /** @return array<string, string> */
    public function headers(string $method, string $pathWithQuery, string $body = '', ?int $timestamp = null, ?string $nonce = null): array
    {
        $timestamp ??= time();
        $nonce ??= bin2hex(random_bytes(16));
        $method = strtoupper(trim($method));
        if ($method === '' || preg_match('/^[A-Z]+$/', $method) !== 1) {
            throw new ConfigurationException('Invoice API method is invalid.');
        }
        if ($pathWithQuery === '' || !str_starts_with($pathWithQuery, '/') || str_contains($pathWithQuery, "\r") || str_contains($pathWithQuery, "\n") || preg_match('#^[A-Za-z][A-Za-z0-9+.-]*://#', $pathWithQuery) === 1) {
            throw new ConfigurationException('Invoice API canonical path must be an original relative path with query.');
        }
        if ($nonce === '') {
            throw new ConfigurationException('Invoice API nonce must not be empty.');
        }

        $canonical = implode("\n", [
            strtoupper($method),
            $pathWithQuery,
            (string) $timestamp,
            $nonce,
            hash('sha256', $body),
        ]);

        return [
            'X-Client-Id' => $this->config->clientId(),
            'X-Timestamp' => (string) $timestamp,
            'X-Nonce' => $nonce,
            'X-Signature' => $this->config->sign($canonical),
        ];
    }
}
