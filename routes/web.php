<?php

use Illuminate\Support\Facades\Route;
use Platform\Drip\Services\GoCardlessService;

Route::get('/', Platform\Drip\Livewire\Dashboard::class)->name('drip.dashboard');
Route::get('/inbox', Platform\Drip\Livewire\Posteingang::class)->name('drip.inbox');
Route::get('/banks', Platform\Drip\Livewire\Banks::class)->name('drip.banks');
Route::get('/groups/{group}', Platform\Drip\Livewire\GroupTransactions::class)->name('drip.groups.show');
Route::get('/categories', Platform\Drip\Livewire\Categories::class)->name('drip.categories');
Route::get('/rules', Platform\Drip\Livewire\Rules::class)->name('drip.rules');
Route::get('/invoices', Platform\Drip\Livewire\Invoices::class)->name('drip.invoices');
Route::get('/transactions/{transaction}', Platform\Drip\Livewire\TransactionDetail::class)->name('drip.transactions.show');

// MOSS-Beleg (PDF/Bild) einer Ausgaben-Transaktion streamen.
Route::get('/transactions/{transaction}/receipt', function (
    Platform\Drip\Models\BankTransaction $transaction,
    Platform\Drip\Services\MossReceiptService $receipts
) {
    abort_unless($transaction->team_id === auth()->user()->current_team_id, 403);

    $file = $receipts->firstFileContent($transaction);
    abort_if(!$file, 404, 'Kein Beleg gefunden.');

    // Content-Type auf erwartete Belegtypen begrenzen (PDF/Bild), sonst neutral.
    $mime = $file['mime'] ?? 'application/octet-stream';
    if ($mime !== 'application/pdf' && !str_starts_with($mime, 'image/')) {
        $mime = 'application/octet-stream';
    }

    // Dateiname für den Header sanitizen (kein CRLF/Quote → keine Header-Injection).
    $safeName = preg_replace('/[^\w.\- ]+/u', '_', $file['filename'] ?? 'beleg') ?: 'beleg';

    return response(base64_decode($file['data_base64']))
        ->header('Content-Type', $mime)
        ->header('Content-Disposition', 'inline; filename="' . $safeName . '"');
})->name('drip.transactions.receipt');

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



