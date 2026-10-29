<?php

declare(strict_types=1);

final class SupplierRegistry
{
    /** @var array<string, SupplierInterface> */
    private array $providers = [];

    public function __construct(array $config)
    {
        $this->register(new SandromaniaSupplier((array) ($config['sandromania'] ?? [])));
        $this->register(new CupponHubSupplier((array) ($config['cupponhub'] ?? [])));
    }

    public function get(string $key): ?SupplierInterface
    {
        return $this->providers[$key] ?? null;
    }

    /** @return array<string, SupplierInterface> */
    public function all(): array
    {
        return $this->providers;
    }

    /** @return array<string, string> */
    public function names(): array
    {
        $names = [];
        foreach ($this->providers as $key => $provider) {
            $names[$key] = $provider->name();
        }
        return $names;
    }

    private function register(SupplierInterface $provider): void
    {
        $this->providers[$provider->key()] = $provider;
    }
}
