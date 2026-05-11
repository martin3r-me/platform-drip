<?php

namespace Platform\Drip\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class LiquidityForecast extends Model
{
    public $timestamps = false;

    protected $table = 'drip_liquidity_forecasts';

    protected $fillable = [
        'team_id', 'forecast_date',
        'projected_balance', 'planned_credits', 'planned_debits',
        'computed_at',
    ];

    protected $casts = [
        'forecast_date' => 'date',
        'projected_balance' => 'decimal:2',
        'planned_credits' => 'decimal:2',
        'planned_debits' => 'decimal:2',
        'computed_at' => 'datetime',
    ];

    public function scopeForTeam(Builder $query, int $teamId): Builder
    {
        return $query->where('team_id', $teamId);
    }
}
