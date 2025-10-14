<?php

use Illuminate\Support\Facades\Route;
use Platform\Drip\Services\GoCardlessService;

Route::get('/', Platform\Drip\Livewire\Dashboard::class)->name('drip.dashboard');
Route::get('/banks', Platform\Drip\Livewire\Banks::class)->name('drip.banks');

// GoCardless Callback
Route::get('/banks/callback', function () {
    $reference = request('ref');
    
    if (!$reference) {
        return redirect()->route('drip.banks')->with('error', 'Keine Referenz erhalten.');
    }

    $user = auth()->user();
    $gc = new GoCardlessService($user->current_team_id);
    
    try {
        $accounts = $gc->getAccountsFromRequisitionByRef($reference);
        return redirect()->route('drip.banks')->with('success', 'Bank erfolgreich verbunden!');
    } catch (\Exception $e) {
        return redirect()->route('drip.banks')->with('error', 'Fehler beim Verbinden der Bank: ' . $e->getMessage());
    }
})->name('drip.banks.callback');



