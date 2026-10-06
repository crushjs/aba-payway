<?php

namespace Crushjs\AbaPayway;

/**
 * A decoded PayWay API response.
 *
 * PayWay endpoints report their outcome in two shapes:
 *   - {"status": {"code": "00", "message": "Success!"}, "data": {...}}  ("0" for the QR API)
 *   - {"status": 0, "description": "..."}
 */
final class Response
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        private array $data,
        private int $httpStatus = 200,
    ) {
    }

    /**
     * Read a value using "dot" notation, e.g. get('data.payment_status').
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->data;

        foreach (explode('.', $key) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    public function successful(): bool
    {
        if ($this->httpStatus >= 400) {
            return false;
        }

        $status = $this->data['status'] ?? null;

        if (is_array($status)) {
            // Most endpoints use "00"; the QR API uses "0".
            return in_array((string) ($status['code'] ?? ''), ['00', '0'], true);
        }

        return $status !== null && (string) $status === '0';
    }

    public function failed(): bool
    {
        return ! $this->successful();
    }

    public function message(): ?string
    {
        $status = $this->data['status'] ?? null;

        if (is_array($status)) {
            return $status['message'] ?? null;
        }

        return $this->data['description'] ?? $this->data['message'] ?? null;
    }

    /**
     * The transaction's payment status (APPROVED, PENDING, DECLINED, REFUNDED, ...).
     */
    public function paymentStatus(): ?string
    {
        $status = $this->get('data.payment_status');

        return $status === null ? null : strtoupper((string) $status);
    }

    public function isPaid(): bool
    {
        return $this->successful() && $this->paymentStatus() === 'APPROVED';
    }

    public function httpStatus(): int
    {
        return $this->httpStatus;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }
}
