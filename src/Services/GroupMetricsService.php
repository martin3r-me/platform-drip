<?php

namespace Platform\Drip\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GroupMetricsService
{
    public function buildForTeam(int $teamId, ?Carbon $since = null, ?Carbon $until = null): int
    {
        $since = $since ?: now()->startOfMonth()->subMonths(2);
        $until = $until ?: now();

        // Tagesweise aggregieren pro Gruppe
        $rows = DB::table('drip_bank_transactions as t')
            ->selectRaw('t.team_id, ba.group_id, DATE(COALESCE(t.booked_at, t.created_at)) as d,
                SUM(CASE WHEN t.direction = "credit" THEN t.amount ELSE 0 END) as inflow,
                SUM(CASE WHEN t.direction = "debit" THEN ABS(t.amount) ELSE 0 END) as outflow')
            ->join('drip_bank_accounts as ba', 'ba.id', '=', 't.bank_account_id')
            ->where('t.team_id', $teamId)
            ->whereNull('t.deleted_at')
            ->whereNull('ba.deleted_at')
            ->where(function ($q) use ($since, $until) {
                $q->whereNotNull('t.booked_at')->whereBetween('t.booked_at', [$since, $until])
                  ->orWhere(function ($q2) use ($since, $until) {
                      $q2->whereNull('t.booked_at')->whereBetween('t.created_at', [$since, $until]);
                  });
            })
            ->where(function ($q) {
                $q->whereNull('t.is_internal_transfer')->orWhere('t.is_internal_transfer', false);
            })
            ->groupBy('t.team_id', 'ba.group_id', 'd')
            ->get();

        $upserts = 0;

        foreach ($rows as $r) {
            $net = (float) $r->inflow - (float) $r->outflow;

            // Burn Rate 30d grob via Gleitfenster auf Tagesbasis (vereinfachtes Näherungsverfahren)
            $burn30 = $this->calcBurnRate30d($teamId, (int) $r->group_id, Carbon::parse($r->d));
            $runwayDays = $this->calcRunwayDays($teamId, (int) $r->group_id, $burn30);

            DB::table('drip_group_metrics')->updateOrInsert(
                ['team_id' => $teamId, 'group_id' => $r->group_id, 'date' => $r->d],
                [
                    'uuid' => DB::raw('UUID()'),
                    'inflow' => $r->inflow,
                    'outflow' => $r->outflow,
                    'net' => $net,
                    'burn_rate_30d' => $burn30,
                    'runway_days' => $runwayDays,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );

            $upserts++;
        }

        Log::info('GroupMetricsService: built metrics', [
            'teamId' => $teamId,
            'rows' => $upserts,
            'since' => $since->toDateString(),
            'until' => $until->toDateString(),
        ]);

        return $upserts;
    }

    private function calcBurnRate30d(int $teamId, int $groupId, Carbon $asOf): float
    {
        $from = $asOf->copy()->subDays(29)->startOfDay();
        $to = $asOf->copy()->endOfDay();

        $sumOut = DB::table('drip_bank_transactions as t')
            ->join('drip_bank_accounts as ba', 'ba.id', '=', 't.bank_account_id')
            ->where('t.team_id', $teamId)
            ->where('ba.group_id', $groupId)
            ->where(function ($q) use ($from, $to) {
                $q->whereNotNull('t.booked_at')->whereBetween('t.booked_at', [$from, $to])
                  ->orWhere(function ($q2) use ($from, $to) {
                      $q2->whereNull('t.booked_at')->whereBetween('t.created_at', [$from, $to]);
                  });
            })
            ->where(function ($q) {
                $q->whereNull('t.is_internal_transfer')->orWhere('t.is_internal_transfer', false);
            })
            ->sum(DB::raw('CASE WHEN t.direction = "debit" THEN ABS(t.amount) ELSE 0 END'));

        return round(((float) $sumOut) / 30.0, 2);
    }

    private function calcRunwayDays(int $teamId, int $groupId, float $burnPerDay): int
    {
        if ($burnPerDay <= 0) {
            return 0; // kein Verbrauch -> keine Aussage
        }

        $balance = DB::table('drip_bank_account_balances as b')
            ->join('drip_bank_accounts as a', 'a.id', '=', 'b.bank_account_id')
            ->where('a.team_id', $teamId)
            ->where('a.group_id', $groupId)
            ->orderByDesc('b.as_of_date')
            ->value('b.balance');

        if ($balance === null) return 0;
        return (int) floor(((float) $balance) / $burnPerDay);
    }
}


