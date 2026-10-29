<?php

declare(strict_types=1);

final class PaymentEvent
{
    public function __construct(
        public readonly string $provider,
        public readonly string $providerEventId,
        public readonly string $destinationAccount,
        public readonly string $amount,
        public readonly string $currency,
        public readonly string $reference,
        public readonly string $direction,
        public readonly ?DateTimeImmutable $occurredAt,
        public readonly array $payload = [],
    ) {
        if (!preg_match('/^[a-z0-9_]{2,30}$/', $provider)) {
            throw new InvalidArgumentException('Invalid payment provider');
        }
        if ($providerEventId === '' || strlen($providerEventId) > 150) {
            throw new InvalidArgumentException('Invalid provider event ID');
        }
        if ($destinationAccount === '' || strlen($destinationAccount) > 190) {
            throw new InvalidArgumentException('Invalid destination account');
        }
        if (!preg_match('/^\d+(?:\.\d{1,8})?$/', $amount) || self::isZero($amount)) {
            throw new InvalidArgumentException('Invalid payment amount');
        }
        if (!preg_match('/^[A-Z0-9]{2,12}$/', $currency)) {
            throw new InvalidArgumentException('Invalid payment currency');
        }
        if (!in_array($direction, ['incoming', 'outgoing'], true)) {
            throw new InvalidArgumentException('Invalid payment direction');
        }
    }

    public function idempotencyKey(): string
    {
        return $this->provider . ':' . $this->destinationAccount . ':' . $this->providerEventId;
    }

    public function payloadJson(): string
    {
        $encoded = json_encode(
            $this->payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );

        if ($encoded === false) {
            throw new RuntimeException('Unable to encode payment payload');
        }

        return $encoded;
    }

    private static function isZero(string $amount): bool
    {
        return preg_match('/^0+(?:\.0+)?$/', $amount) === 1;
    }
}
