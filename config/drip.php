<?php

return [
    'name' => 'Drip',
    'description' => 'Drip Module',
    'version' => '1.0.0',
    
    'routing' => [
        'prefix' => 'drip',
        'middleware' => ['web', 'auth'],
    ],
    
    'guard' => 'web',
    
    // Top-Level Navigation-Eintrag (für Modul-Kachel im Dashboard)
    'navigation' => [
        'route' => 'drip.dashboard',
        'icon'  => 'heroicon-o-cube',
        'order' => 50,
    ],
    // Sidebar wird über die Modul-eigene Sidebar-Komponente gerendert
    'billables' => [
        [
            'model' => \Platform\Drip\Models\BankAccount::class,
            'type' => 'per_item',
            'label' => 'Bankkonto',
            'description' => 'Jedes angebundene Bankkonto verursacht tägliche Kosten nach Nutzung.',
            'pricing' => [
                ['cost_per_day' => 0.01, 'start_date' => '2025-01-01', 'end_date' => null]
            ],
            'free_quota' => null,
            'min_cost' => null,
            'max_cost' => null,
            'billing_period' => 'daily',
            'start_date' => '2026-01-01',
            'end_date' => null,
            'trial_period_days' => 0,
            'discount_percent' => 0,
            'exempt_team_ids' => [],
            'priority' => 100,
            'active' => true,
        ],
        [
            'model' => \Platform\Drip\Models\BankTransaction::class,
            'type' => 'per_item',
            'label' => 'Transaktion',
            'description' => 'Jede importierte Banktransaktion verursacht minimale tägliche Kosten.',
            'pricing' => [
                ['cost_per_day' => 0.0005, 'start_date' => '2025-01-01', 'end_date' => null]
            ],
            'free_quota' => null,
            'min_cost' => null,
            'max_cost' => null,
            'billing_period' => 'daily',
            'start_date' => '2026-01-01',
            'end_date' => null,
            'trial_period_days' => 0,
            'discount_percent' => 0,
            'exempt_team_ids' => [],
            'priority' => 100,
            'active' => true,
        ],
    ],
];
