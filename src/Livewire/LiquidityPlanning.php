<?php

namespace Platform\Drip\Livewire;

use Livewire\Component;
use Platform\Drip\Services\LiquidityPlanningService;

class LiquidityPlanning extends Component
{
    public int $monthsAhead = 6;

    public function setMonthsAhead(int $months): void
    {
        $this->monthsAhead = max(1, min($months, 24));
    }

    public function render()
    {
        $teamId = (int) auth()->user()?->current_team_id;

        $service = app(LiquidityPlanningService::class);
        $plan = $service->getPlan($teamId, $this->monthsAhead);

        return view('drip::livewire.liquidity-planning', [
            'plan' => $plan,
        ])->layout('platform::layouts.app');
    }
}
