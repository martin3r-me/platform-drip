<?php

namespace Platform\Drip\Services;

use Illuminate\Support\Carbon;
use Platform\Core\Contracts\CashflowSignalProviderInterface;
use Platform\Drip\Models\CashflowSignal;

class CashflowSignalSyncService
{
    public function __construct(
        protected CashflowSignalRegistry $registry,
    ) {}

    /**
     * Sync all registered providers for a team.
     * Returns total number of upserted signals.
     */
    public function syncAll(int $teamId, int $daysAhead = 180): int
    {
        $from = now()->startOfDay();
        $to = now()->addDays($daysAhead);
        $total = 0;

        foreach ($this->registry->all() as $provider) {
            $total += $this->syncProvider($provider, $teamId, $from, $to);
        }

        return $total;
    }

    /**
     * Sync a single provider.
     */
    public function syncProvider(CashflowSignalProviderInterface $provider, int $teamId, Carbon $from, Carbon $to): int
    {
        $signals = $provider->signals($teamId, $from, $to);
        $count = 0;

        foreach ($signals as $dto) {
            $existing = CashflowSignal::where('team_id', $teamId)
                ->where('provider_key', $dto->providerKey)
                ->where('external_id', $dto->externalId)
                ->first();

            if ($existing) {
                // Don't overwrite user overrides or dismissed/pinned signals
                if (in_array($existing->status, ['dismissed', 'pinned'])) {
                    continue;
                }

                // Update provider fields only, preserve user overrides
                $existing->update([
                    'label' => $dto->label,
                    'direction' => $dto->direction,
                    'amount' => $dto->amount,
                    'expected_date' => $dto->expectedDate,
                    'confidence' => $dto->confidence,
                    'confidence_level' => $dto->confidenceLevel,
                    'counterparty' => $dto->counterparty,
                    'category' => $dto->category,
                    'url' => $dto->url,
                    'meta' => $dto->meta,
                ]);
            } else {
                CashflowSignal::create([
                    'team_id' => $teamId,
                    'provider_key' => $dto->providerKey,
                    'external_id' => $dto->externalId,
                    'label' => $dto->label,
                    'direction' => $dto->direction,
                    'amount' => $dto->amount,
                    'expected_date' => $dto->expectedDate,
                    'confidence' => $dto->confidence,
                    'confidence_level' => $dto->confidenceLevel,
                    'counterparty' => $dto->counterparty,
                    'category' => $dto->category,
                    'url' => $dto->url,
                    'meta' => $dto->meta,
                ]);
            }

            $count++;
        }

        // Check for resolved signals
        $activeSignals = CashflowSignal::where('team_id', $teamId)
            ->where('provider_key', $provider->key())
            ->where('status', 'active')
            ->get();

        foreach ($activeSignals as $signal) {
            $resolved = $provider->isResolved($teamId, $signal->external_id);
            if ($resolved === true) {
                $signal->resolve();
            }
        }

        return $count;
    }
}
