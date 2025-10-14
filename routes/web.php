<?php

use Illuminate\Support\Facades\Route;

Route::get('/', Platform\Drip\Livewire\Dashboard::class)->name('drip.dashboard');
Route::get('/banks', Platform\Drip\Livewire\Banks::class)->name('drip.banks');



