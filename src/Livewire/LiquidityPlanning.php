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
        $monthlyDetail = $service->getMonthlyDetail($teamId, $this->monthsAhead);

        return view('drip::livewire.liquidity-planning', [
            'plan' => $plan,
            'monthlyDetail' => $monthlyDetail,
        ])->layout('platform::layouts.app');
    }
}
