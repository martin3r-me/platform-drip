<?php

namespace Platform\Drip\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinanceMetric extends Model
{
    protected $table = 'drip_finance_metrics';

    protected $fillable = [
        'uuid', 'team_id', 'date', 'income', 'expenses', 'savings', 'balance', 'extras',
    ];

    protected $casts = [
        'date' => 'date',
        'income' => 'decimal:4',
        'expenses' => 'decimal:4',
        'savings' => 'decimal:4',
        'balance' => 'decimal:4',
        'extras' => 'array',
    ];
}


