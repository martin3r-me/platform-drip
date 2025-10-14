<?php

namespace Platform\Drip\Livewire;

use Livewire\Component;

class Dashboard extends Component
{
    public function render()
    {
        return view('drip::livewire.dashboard')
            ->layout('platform::layouts.app');
    }
}