<?php

namespace Platform\Drip\Livewire;

use Livewire\Component;
use Platform\Drip\Models\BudgetItem;
use Platform\Drip\Models\CashflowSignal;
use Platform\Drip\Services\BudgetPeriodService;
use Platform\Drip\Services\LiquidityPlanningService;

class LiquidityPlanning extends Component
{
    public int $monthsAhead = 6;
    public array $signals = [];

    public function setMonthsAhead(int $months): void
    {
        $this->monthsAhead = max(1, min($months, 24));
    }

    public function dismissSignal(int $signalId): void
    {
        $teamId = (int) auth()->user()?->current_team_id;
        $signal = CashflowSignal::where('team_id', $teamId)->findOrFail($signalId);
        $signal->dismiss();
    }

    public function overrideSignalAmount(int $signalId, string $amount): void
    {
        $teamId = (int) auth()->user()?->current_team_id;
        $signal = CashflowSignal::where('team_id', $teamId)->findOrFail($signalId);
        $signal->update(['override_amount' => (float) $amount]);
    }

    public function overrideSignalDate(int $signalId, string $date): void
    {
        $teamId = (int) auth()->user()?->current_team_id;
        $signal = CashflowSignal::where('team_id', $teamId)->findOrFail($signalId);
        $signal->update(['override_date' => $date]);
    }

    public function pinSignalToBudget(int $signalId): void
    {
        $teamId = (int) auth()->user()?->current_team_id;
        $signal = CashflowSignal::where('team_id', $teamId)->findOrFail($signalId);

        // Create BudgetItem from signal
        $budgetItem = BudgetItem::create([
            'team_id' => $teamId,
            'name' => $signal->label,
            'direction' => $signal->direction,
            'amount' => $signal->effectiveAmount(),
            'frequency' => 'once',
            'planned_date' => $signal->effectiveDate(),
            'status' => 'active',
            'is_active' => true,
            'source_type' => 'signal:' . $signal->provider_key,
            'notes' => $signal->counterparty ? 'Von: ' . $signal->counterparty : null,
        ]);

        // Generate period
        app(BudgetPeriodService::class)->generatePeriodsForItem($budgetItem);

        // Pin the signal
        $signal->pinToBudget($budgetItem->id);
    }

    public function render()
    {
        $teamId = (int) auth()->user()?->current_team_id;

        $service = app(LiquidityPlanningService::class);
        $plan = $service->getPlan($teamId, $this->monthsAhead);

        // Load active signals for the signals panel
        $this->signals = CashflowSignal::where('team_id', $teamId)
            ->whereIn('status', ['active'])
            ->orderBy('expected_date')
            ->limit(50)
            ->get()
            ->map(fn (CashflowSignal $s) => [
                'id' => $s->id,
                'label' => $s->label,
                'provider_key' => $s->provider_key,
                'direction' => $s->direction,
                'amount' => $s->effectiveAmount(),
                'original_amount' => (float) $s->amount,
                'date' => $s->effectiveDate()->format('Y-m-d'),
                'date_formatted' => $s->effectiveDate()->format('d.m.Y'),
                'original_date' => $s->expected_date->format('Y-m-d'),
                'confidence' => (float) $s->confidence,
                'confidence_level' => $s->confidence_level,
                'counterparty' => $s->counterparty,
                'category' => $s->category,
                'url' => $s->url,
                'has_override' => $s->override_amount !== null || $s->override_date !== null,
            ])
            ->toArray();

        return view('drip::livewire.liquidity-planning', [
            'plan' => $plan,
        ])->layout('platform::layouts.app');
    }
}
