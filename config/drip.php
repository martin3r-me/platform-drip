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
];
