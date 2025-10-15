<?php

namespace Platform\Drip\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class FinanceMetricsService
{
    // Aggregiert Team-KPIs aus drip_group_metrics in drip_finance_metrics
    public function buildFromGroupMetrics(int $teamId, ?Carbon $since = null, ?Carbon $until = null): int
    {
        $since = $since ?: now()->startOfMonth()->subMonth();
        $until = $until ?: now();

        $rows = DB::table('drip_group_metrics')
            ->selectRaw('date, SUM(inflow) as inflow, SUM(outflow) as outflow, SUM(net) as net')
            ->where('team_id', $teamId)
            ->whereBetween('date', [$since->toDateString(), $until->toDateString()])
            ->groupBy('date')
            ->get();

        $upserts = 0;
        foreach ($rows as $r) {
            DB::table('drip_finance_metrics')->updateOrInsert(
                ['team_id' => $teamId, 'date' => $r->date],
                [
                    'uuid' => DB::raw('UUID()'),
                    'income' => $r->inflow,
                    'expenses' => $r->outflow,
                    'savings' => 0,
                    'balance' => $r->net,
                    'extras' => json_encode([]),
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
            $upserts++;
        }

        return $upserts;
    }
}


