<?php

declare(strict_types=1);

namespace app\utils\Invoice;

final class InvoiceClient
{
    private readonly InvoiceSigner $signer;
    private readonly ?\Closure $logger;

    public function __construct(private readonly InvoiceConfig $config, ?callable $logger = null)
    {
        $this->signer = new InvoiceSigner($config);
        $this->logger = $logger === null ? null : \Closure::fromCallable($logger);
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function preflight(array $payload): array
    {
        return $this->requestJson('POST', $this->config->path('preflight'), $payload);
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    public function create(array $payload, string $idempotencyKey): array
    {
        $idempotencyKey = trim($idempotencyKey);
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 255 || preg_match('/[^\x21-\x7E]/', $idempotencyKey) === 1) {
            throw new ValidationException('Idempotency key must contain 1-255 visible ASCII characters.');
        }

        return $this->requestJson('POST', $this->config->path('create'), $payload, [
            'Idempotency-Key' => $idempotencyKey,
        ]);
    }

    /** @return array<string, mixed> */
    public function status(string $requestNo): array
    {
        return $this->requestJson('GET', $this->config->path('status', $requestNo));
    }

    public function download(string $requestNo, string $format = 'pdf'): DownloadResponse
    {
        if (!in_array($format, ['pdf', 'xml', 'zip'], true)) {
            throw new ValidationException('Download format must be pdf, xml, or zip.');
        }
        $path = $this->config->path('download', $requestNo) . '?format=' . $format;
        $accept = match ($format) {
            'pdf' => 'application/pdf',
            'xml' => 'application/xml, text/xml',
            'zip' => 'application/zip',
        };
        [$status, $headers, $body] = $this->request('GET', $path, '', [
            'Accept' => $accept . ', application/octet-stream',
        ]);
        $this->throwForStatus($status, $body);

        $contentType = $headers['content-type'] ?? 'application/octet-stream';
        $filename = null;
        if (isset($headers['content-disposition']) && preg_match('/filename\*?=(?:UTF-8\'\')?["\']?([^"\';]+)/i', $headers['content-disposition'], $matches) === 1) {
            $filename = rawurldecode(trim($matches[1]));
        }

        return new DownloadResponse($body, $contentType, $filename, $headers, $status);
    }

    /** @param array<string, mixed>|null $payload @param array<string, string> $extraHeaders @return array<string, mixed> */
    private function requestJson(string $method, string $path, ?array $payload = null, array $extraHeaders = []): array
    {
        try {
            $body = $payload === null ? '' : json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (\JsonException $exception) {
            throw new ValidationException('Invoice request payload is not valid JSON data.', null, [], $exception);
        }

        [$status, , $responseBody] = $this->request($method, $path, $body, $extraHeaders);
        $this->throwForStatus($status, $responseBody);
        if ($responseBody === '') {
            return [];
        }

        try {
            $decoded = json_decode($responseBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new ResponseException('Invoice API returned invalid JSON.', $status, [], $exception);
        }
        if (!is_array($decoded)) {
            throw new ResponseException('Invoice API returned an unexpected JSON value.', $status);
        }

        return $decoded;
    }

    /** @param array<string, string> $extraHeaders @return array{0:int,1:array<string,string>,2:string} */
    private function request(string $method, string $pathWithQuery, string $body, array $extraHeaders = []): array
    {
        $url = $this->config->baseUrl() . $pathWithQuery;
        $headers = array_merge([
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ], $this->signer->headers($method, $pathWithQuery, $body), $extraHeaders);
        $headerLines = [];
        foreach ($headers as $name => $value) {
            if (preg_match('/^[A-Za-z0-9-]+$/', $name) !== 1 || str_contains($value, "\r") || str_contains($value, "\n")) {
                throw new ConfigurationException('Invoice API request contains an invalid header.');
            }
            $headerLines[] = $name . ': ' . $value;
        }

        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headerLines),
                'content' => $body,
                'timeout' => $this->config->timeoutSeconds(),
                'ignore_errors' => true,
                'follow_location' => 0,
                'protocol_version' => 1.1,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $this->log('invoice_api.request', ['method' => $method, 'path' => $pathWithQuery]);
        $lastError = null;
        set_error_handler(static function (int $_severity, string $message) use (&$lastError): bool {
            $lastError = $message;
            return true;
        });
        try {
            $responseBody = file_get_contents($url, false, $context);
        } finally {
            restore_error_handler();
        }
        if ($responseBody === false) {
            throw new TransportException('Invoice API request failed.', null, $lastError === null ? [] : ['reason' => $this->config->redact($lastError)]);
        }

        /** @var list<string> $http_response_header */
        $responseHeaders = $http_response_header ?? [];
        [$status, $parsedHeaders] = $this->parseResponseHeaders($responseHeaders);
        $this->log('invoice_api.response', ['method' => $method, 'path' => $pathWithQuery, 'status' => $status]);

        return [$status, $parsedHeaders, $responseBody];
    }

    /** @param list<string> $lines @return array{0:int,1:array<string,string>} */
    private function parseResponseHeaders(array $lines): array
    {
        $status = 0;
        $headers = [];
        foreach ($lines as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $matches) === 1) {
                $status = (int) $matches[1];
                $headers = [];
                continue;
            }
            $separator = strpos($line, ':');
            if ($separator !== false) {
                $headers[strtolower(trim(substr($line, 0, $separator)))] = trim(substr($line, $separator + 1));
            }
        }
        if ($status === 0) {
            throw new ResponseException('Invoice API returned an invalid HTTP response.');
        }
        return [$status, $headers];
    }

    private function throwForStatus(int $status, string $body): void
    {
        if ($status >= 200 && $status < 300) {
            return;
        }
        $details = [];
        try {
            $decoded = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
            if (is_array($decoded)) {
                $details = $this->sanitize($decoded);
            }
        } catch (\JsonException) {
        }
        $message = isset($details['message']) && is_string($details['message']) ? $details['message'] : sprintf('Invoice API returned HTTP %d.', $status);
        $message = $this->config->redact($message);
        $exception = match (true) {
            $status === 401 || $status === 403 => new AuthenticationException($message, $status, $details),
            $status === 409 => new ConflictException($message, $status, $details),
            $status === 422 || $status === 400 => new ValidationException($message, $status, $details),
            $status === 429 => new RateLimitException($message, $status, $details),
            $status >= 500 => new ServerException($message, $status, $details),
            default => new ResponseException($message, $status, $details),
        };
        throw $exception;
    }

    /** @param array<string, mixed> $context */
    private function log(string $event, array $context): void
    {
        if ($this->logger !== null) {
            ($this->logger)($event, $this->sanitize($context));
        }
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function sanitize(array $data): array
    {
        foreach ($data as $key => $value) {
            if (preg_match('/secret|token|signature|authorization|api.?key/i', (string) $key) === 1) {
                $data[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $data[$key] = $this->sanitize($value);
            }
        }
        return $data;
    }
}
