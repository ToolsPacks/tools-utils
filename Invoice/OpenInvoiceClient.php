<?php
declare(strict_types=1);

namespace app\utils\Invoice;

final class OpenInvoiceClient
{
    private OpenInvoiceConfig $config;
    private $transport;

    public function __construct(OpenInvoiceConfig $config, ?callable $transport = null)
    {
        $this->config = $config;
        $this->transport = $transport;
    }

    public function capabilities(): array
    {
        return $this->requestJson('GET', $this->openApiBasePath() . '/capabilities');
    }

    public function account(): array
    {
        return $this->requestJson('GET', $this->openApiBasePath() . '/account');
    }

    public function preflight($request): array
    {
        return $this->requestJson('POST', $this->config->invoicePath() . '/preflight', $this->payload($request));
    }

    public function create($request, string $idempotencyKey): array
    {
        $idempotencyKey = trim($idempotencyKey);
        if ($idempotencyKey === '') {
            throw new \InvalidArgumentException('idempotencyKey is required');
        }
        return $this->requestJson('POST', $this->config->invoicePath(), $this->payload($request), [
            'Idempotency-Key' => $idempotencyKey,
        ]);
    }

    public function get(string $invoiceId): array
    {
        return $this->requestJson('GET', $this->config->invoicePath() . '/' . $this->segment($invoiceId));
    }

    public function getByExternalId(string $externalId): array
    {
        return $this->requestJson('GET', $this->config->invoicePath() . '/by-external-id/' . $this->segment($externalId));
    }

    public function download(string $invoiceId, string $format = 'pdf'): OpenInvoiceDownload
    {
        $format = strtolower(trim($format));
        if (!in_array($format, ['pdf', 'xml', 'zip'], true)) {
            throw new \InvalidArgumentException('format must be pdf, xml or zip');
        }
        $path = $this->config->invoicePath() . '/' . $this->segment($invoiceId) . '/download?format=' . $format;
        $response = $this->send('GET', $path, '');
        $status = $response['status'];
        if ($status < 200 || $status >= 300) {
            $this->throwResponse($response);
        }
        return new OpenInvoiceDownload(
            $response['body'],
            $this->header($response['headers'], 'Content-Type') ?: 'application/octet-stream',
            $this->fileName($this->header($response['headers'], 'Content-Disposition'))
        );
    }

    private function requestJson(string $method, string $path, ?array $payload = null, array $headers = []): array
    {
        $body = $payload === null ? '' : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $response = $this->send($method, $path, $body, $headers);
        $status = $response['status'];
        $decoded = $this->decodeJson($response);
        if ($status < 200 || $status >= 300 || ($decoded['success'] ?? false) !== true) {
            $this->throwResponse($response, $decoded);
        }
        return $decoded;
    }

    private function send(string $method, string $pathWithQuery, string $body, array $headers = []): array
    {
        $timestamp = (string)time();
        $nonce = bin2hex(random_bytes(16));
        $canonical = strtoupper($method) . "\n" . $pathWithQuery . "\n" . $timestamp . "\n" . $nonce . "\n" . hash('sha256', $body);
        $headers = array_merge([
            'X-Key-Id' => $this->config->keyId(),
            'X-CFDI-Timestamp' => $timestamp,
            'X-CFDI-Nonce' => $nonce,
            'X-CFDI-Signature' => hash_hmac('sha256', $canonical, $this->config->signingSecret()),
            'Accept' => 'application/json',
        ], $headers);
        if ($body !== '') {
            $headers['Content-Type'] = 'application/json';
        }
        if ($this->transport !== null) {
            $response = ($this->transport)($method, $this->config->baseUrl() . $pathWithQuery, $headers, $body, $this->config->timeoutSeconds());
            if (!is_array($response) || !isset($response['status'], $response['headers'], $response['body'])) {
                throw new OpenInvoiceTransportException('Custom transport must return status, headers and body');
            }
            return $response;
        }
        return $this->nativeRequest($method, $pathWithQuery, $headers, $body);
    }

    private function nativeRequest(string $method, string $pathWithQuery, array $headers, string $body): array
    {
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        $context = stream_context_create(['http' => [
            'method' => $method,
            'header' => implode("\r\n", $lines),
            'content' => $body,
            'timeout' => $this->config->timeoutSeconds(),
            'ignore_errors' => true,
            'follow_location' => 0,
            'max_redirects' => 0,
        ]]);
        $content = @file_get_contents($this->config->baseUrl() . $pathWithQuery, false, $context);
        $responseHeaders = isset($http_response_header) && is_array($http_response_header) ? $http_response_header : [];
        if ($content === false && $responseHeaders === []) {
            $error = error_get_last();
            throw new OpenInvoiceTransportException('Open invoice request failed: ' . (string)($error['message'] ?? 'unknown transport error'));
        }
        $status = 0;
        if (isset($responseHeaders[0]) && preg_match('#\s(\d{3})(?:\s|$)#', $responseHeaders[0], $matches) === 1) {
            $status = (int)$matches[1];
        }
        return ['status' => $status, 'headers' => $responseHeaders, 'body' => $content === false ? '' : $content];
    }

    private function payload($request): array
    {
        if ($request instanceof OpenInvoiceRequest) {
            return $request->toArray();
        }
        if (is_array($request)) {
            return (new OpenInvoiceRequest($request))->toArray();
        }
        throw new \InvalidArgumentException('request must be an array or OpenInvoiceRequest');
    }

    private function decodeJson(array $response): array
    {
        $decoded = json_decode($response['body'], true);
        if (!is_array($decoded)) {
            throw new OpenInvoiceResponseException('Open invoice service returned invalid JSON', $response['status']);
        }
        return $decoded;
    }

    private function throwResponse(array $response, ?array $decoded = null): void
    {
        if ($decoded === null) {
            $decodedBody = json_decode($response['body'], true);
            $decoded = is_array($decodedBody) ? $decodedBody : null;
        }
        $message = $decoded !== null && isset($decoded['message'])
            ? (string)$decoded['message']
            : 'Open invoice service returned HTTP ' . $response['status'];
        throw new OpenInvoiceResponseException($message, $response['status'], $decoded);
    }

    private function header(array $headers, string $name): string
    {
        foreach ($headers as $key => $value) {
            if (is_string($key) && strcasecmp($key, $name) === 0) {
                return is_array($value) ? (string)reset($value) : (string)$value;
            }
            if (is_int($key) && stripos((string)$value, $name . ':') === 0) {
                return trim(substr((string)$value, strlen($name) + 1));
            }
        }
        return '';
    }

    private function openApiBasePath(): string
    {
        $path = rtrim($this->config->invoicePath(), '/');
        return str_ends_with($path, '/invoices') ? substr($path, 0, -9) : $path;
    }

    private function segment(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            throw new \InvalidArgumentException('identifier is required');
        }
        return rawurlencode($value);
    }

    private function fileName(string $disposition): ?string
    {
        if (preg_match('/filename\*=UTF-8\'\'([^;]+)/i', $disposition, $matches) === 1) {
            return rawurldecode(trim($matches[1], " \t\n\r\0\x0B\""));
        }
        if (preg_match('/filename="?([^";]+)"?/i', $disposition, $matches) === 1) {
            return trim($matches[1]);
        }
        return null;
    }
}
