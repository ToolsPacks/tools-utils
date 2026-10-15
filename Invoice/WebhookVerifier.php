<?php
declare(strict_types=1);

namespace app\utils\Invoice;

final class WebhookVerifier
{
    private string $signingSecret;
    private int $clockSkewSeconds;

    public function __construct(string $signingSecret, int $clockSkewSeconds = 300)
    {
        if ($signingSecret === '' || $clockSkewSeconds < 1) {
            throw new \InvalidArgumentException('signingSecret and positive clockSkewSeconds are required');
        }
        $this->signingSecret = $signingSecret;
        $this->clockSkewSeconds = $clockSkewSeconds;
    }

    public function verify(string $rawBody, array $headers, ?int $now = null): bool
    {
        $headers = $this->normalizeHeaders($headers);
        $deliveryId = trim($headers['x-cfdi-webhook-id'] ?? '');
        $timestamp = trim($headers['x-cfdi-webhook-timestamp'] ?? '');
        $signature = strtolower(trim($headers['x-cfdi-webhook-signature'] ?? ''));
        if ($deliveryId === '' || !ctype_digit($timestamp) || preg_match('/^[a-f0-9]{64}$/', $signature) !== 1) {
            return false;
        }
        if (abs(($now ?? time()) - (int)$timestamp) > $this->clockSkewSeconds) {
            return false;
        }
        $expected = hash_hmac('sha256', $deliveryId . '.' . $timestamp . '.' . $rawBody, $this->signingSecret);
        return hash_equals($expected, $signature);
    }

    public function assertValid(string $rawBody, array $headers, ?int $now = null): void
    {
        if (!$this->verify($rawBody, $headers, $now)) {
            throw new OpenInvoiceWebhookException('Invalid or expired webhook signature');
        }
    }

    private function normalizeHeaders(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $name => $value) {
            $normalized[strtolower((string)$name)] = is_array($value) ? (string)reset($value) : (string)$value;
        }
        return $normalized;
    }
}
