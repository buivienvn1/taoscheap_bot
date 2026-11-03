<?php

declare(strict_types=1);

final class BinancePayClient
{
    public function __construct(private array $config)
    {
    }

    public function fetchPage(): array
    {
        return (new PaymentFeedClient($this->config))->fetchPage();
    }
}
