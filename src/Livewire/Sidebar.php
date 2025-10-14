<?php

namespace Platform\Drip\Livewire;

use Livewire\Component;

class Sidebar extends Component
{
    public function render()
    {
        return view('drip::livewire.sidebar')
            ->layout('platform::layouts.app');
    }
}