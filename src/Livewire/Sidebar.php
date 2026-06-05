<?php

namespace Platform\Drip\Livewire;

use Livewire\Component;
use Platform\Drip\Models\BankAccountGroup;

class Sidebar extends Component
{
    public function render()
    {
        $teamId = (int) auth()->user()?->current_team_id;

        $groups = BankAccountGroup::forTeam($teamId)
            ->orderBy('name')
            ->get(['id', 'name', 'color']);

        return view('drip::livewire.sidebar', [
            'groups' => $groups,
        ])->layout('platform::layouts.app');
    }
}
