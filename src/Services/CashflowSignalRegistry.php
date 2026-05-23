<?php

namespace Platform\Drip\Services;

use Illuminate\Support\Collection;
use Platform\Core\Contracts\CashflowSignalProviderInterface;

class CashflowSignalRegistry
{
    /** @var CashflowSignalProviderInterface[] */
    protected array $providers = [];

    public function register(CashflowSignalProviderInterface $provider): void
    {
        $this->providers[$provider->key()] = $provider;
    }

    public function get(string $key): ?CashflowSignalProviderInterface
    {
        return $this->providers[$key] ?? null;
    }

    /**
     * All registered providers, sorted by priority (ascending).
     *
     * @return Collection<CashflowSignalProviderInterface>
     */
    public function all(): Collection
    {
        return collect($this->providers)
            ->sortBy(fn (CashflowSignalProviderInterface $p) => $p->priority())
            ->values();
    }

    public function keys(): array
    {
        return array_keys($this->providers);
    }

    public function has(string $key): bool
    {
        return isset($this->providers[$key]);
    }
}
