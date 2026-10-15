<?php
declare(strict_types=1);

namespace app\utils\Invoice;

final class OpenInvoiceRequest
{
    private array $payload;

    public function __construct(array $payload)
    {
        $payload = $this->normalizeLegacyPayload($payload);
        foreach (['external_id', 'tenant_key', 'store_key', 'receiver', 'payment', 'items', 'total'] as $field) {
            if (!array_key_exists($field, $payload)) {
                throw new \InvalidArgumentException("Missing invoice field: {$field}");
            }
        }
        if (!is_array($payload['receiver']) || !is_array($payload['payment']) || !is_array($payload['items']) || $payload['items'] === []) {
            throw new \InvalidArgumentException('receiver, payment and non-empty items are required');
        }
        foreach (['rfc', 'legal_name', 'fiscal_regime', 'zip_code', 'use_cfdi'] as $field) {
            if (!isset($payload['receiver'][$field]) || trim((string)$payload['receiver'][$field]) === '') {
                throw new \InvalidArgumentException("Missing receiver field: {$field}");
            }
        }
        foreach (['method', 'form'] as $field) {
            if (!isset($payload['payment'][$field]) || trim((string)$payload['payment'][$field]) === '') {
                throw new \InvalidArgumentException("Missing payment field: {$field}");
            }
        }
        foreach ($payload['items'] as $index => $item) {
            if (!is_array($item)) {
                throw new \InvalidArgumentException("Item {$index} must be an object");
            }
            foreach (['product_code', 'description', 'unit_code', 'qty', 'price'] as $field) {
                if (!array_key_exists($field, $item)) {
                    throw new \InvalidArgumentException("Missing item {$index} field: {$field}");
                }
            }
        }
        if (isset($payload['retroactive_days']) && ((int)$payload['retroactive_days'] < 0 || (int)$payload['retroactive_days'] > 3)) {
            throw new \InvalidArgumentException('retroactive_days must be between 0 and 3');
        }
        $payload['business_type'] = $payload['business_type'] ?? 'purchase';
        $payload['currency'] = $payload['currency'] ?? 'MXN';
        $payload['payment']['method'] = $payload['payment']['method'] ?? 'PUE';
        $this->payload = $payload;
    }

    public static function fromArray(array $payload): self { return new self($payload); }
    public function toArray(): array { return $this->payload; }

    private function normalizeLegacyPayload(array $payload): array
    {
        $account = isset($payload['account']) && is_array($payload['account']) ? $payload['account'] : [];
        if (!isset($payload['external_id']) && isset($payload['external_request_id'])) {
            $payload['external_id'] = $payload['external_request_id'];
        }
        if (!isset($payload['tenant_key']) && isset($account['account_key'])) {
            $payload['tenant_key'] = $account['account_key'];
        }
        if (!isset($payload['store_key']) && isset($account['merchant_key'])) {
            $payload['store_key'] = $account['merchant_key'];
        }
        return $payload;
    }
}
