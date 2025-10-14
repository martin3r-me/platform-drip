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
    
    'navigation' => [
        'main' => [
            'drip' => [
                'title' => 'Drip',
                'icon' => 'heroicon-o-drop',
                'route' => 'drip.dashboard',
            ],
        ],
    ],
    
    'sidebar' => [
        'drip' => [
            'title' => 'Drip',
            'icon' => 'heroicon-o-drop',
            'items' => [
                'dashboard' => [
                    'title' => 'Dashboard',
                    'route' => 'drip.dashboard',
                    'icon' => 'heroicon-o-home',
                ],
                'banks' => [
                    'title' => 'Banken',
                    'route' => 'drip.banks',
                    'icon' => 'heroicon-o-building-library',
                ],
            ],
        ],
    ],
];
