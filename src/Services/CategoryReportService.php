<?php

namespace Platform\Drip\Services;

use Illuminate\Support\Carbon;
use Platform\Drip\Models\BankTransaction;
use Platform\Drip\Models\BankTransactionCategory;

/**
 * Auswertung der Kategorien: Baum (nach Richtung gruppiert, mit gerollten
 * €-Volumen), Deckungsgrad und die Root-Liste. Trennt das Reporting von der
 * Mutations-Logik ({@see CategoryService}) und hält den Livewire-Layer dünn.
 *
 * Wichtig: `amount` ist verschlüsselt → Volumen-Aggregation erfolgt in PHP,
 * Counts laufen über unverschlüsselte Spalten via SQL.
 */
class CategoryReportService
{
    /**
     * @return array{groups:array<string,array>,coverage:array,roots:\Illuminate\Support\Collection}
     */
    public function overview(int $teamId, string $period = 'total'): array
    {
        $range = $this->periodRange($period);
        $volumes = $this->volumesByCategory($teamId, $range);

        $roots = BankTransactionCategory::forTeam($teamId)
            ->roots()
            ->with(['children' => fn ($q) => $q->orderBy('name')])
            ->orderBy('name')
            ->get();

        $groups = [
            BankTransactionCategory::DIRECTION_CREDIT => [],
            BankTransactionCategory::DIRECTION_DEBIT => [],
            BankTransactionCategory::DIRECTION_BOTH => [],
        ];

        foreach ($roots as $root) {
            $children = $root->children->map(fn ($c) => [
                'cat' => $c,
                'vol' => $volumes[$c->id]['vol'] ?? 0.0,
                'cnt' => $volumes[$c->id]['cnt'] ?? 0,
            ]);

            $ownVol = $volumes[$root->id]['vol'] ?? 0.0;
            $ownCnt = $volumes[$root->id]['cnt'] ?? 0;

            $node = [
                'cat' => $root,
                'own_vol' => $ownVol,
                'own_cnt' => $ownCnt,
                'total_vol' => $ownVol + $children->sum('vol'),
                'total_cnt' => $ownCnt + $children->sum('cnt'),
                'children' => $children,
            ];

            $dir = in_array($root->direction, BankTransactionCategory::DIRECTIONS, true)
                ? $root->direction
                : BankTransactionCategory::DIRECTION_DEBIT;

            $groups[$dir][] = $node;
        }

        return [
            'groups' => $groups,
            'coverage' => $this->coverage($teamId, $range),
            'roots' => $roots,
        ];
    }

    /** Volumen (Σ|amount|) + Count je Kategorie – PHP-Aggregation (amount encrypted). */
    protected function volumesByCategory(int $teamId, ?array $range): array
    {
        $txs = $this->applyPeriod(
            BankTransaction::where('team_id', $teamId)->whereNotNull('category_id'),
            $range
        )->get(['category_id', 'amount']);

        $volumes = [];
        foreach ($txs as $t) {
            $cid = (int) $t->category_id;
            $volumes[$cid] ??= ['vol' => 0.0, 'cnt' => 0];
            $volumes[$cid]['vol'] += abs((float) $t->amount);
            $volumes[$cid]['cnt']++;
        }

        return $volumes;
    }

    protected function coverage(int $teamId, ?array $range): array
    {
        $total = $this->applyPeriod(BankTransaction::where('team_id', $teamId), $range)->count();
        $categorized = $this->applyPeriod(
            BankTransaction::where('team_id', $teamId)->whereNotNull('category_id'),
            $range
        )->count();

        return [
            'total' => $total,
            'categorized' => $categorized,
            'uncategorized' => max(0, $total - $categorized),
            'pct' => $total > 0 ? (int) round($categorized / $total * 100) : 0,
        ];
    }

    /** [von, bis] oder null für "total". */
    public function periodRange(string $period): ?array
    {
        return match ($period) {
            'month' => [now()->startOfMonth(), now()->endOfMonth()],
            'year' => [now()->startOfYear(), now()->endOfYear()],
            default => null,
        };
    }

    protected function applyPeriod($query, ?array $range)
    {
        if (!$range) {
            return $query;
        }
        [$from, $to] = $range;

        return $query->where(function ($w) use ($from, $to) {
            $w->where(function ($i) use ($from, $to) {
                $i->whereNotNull('booked_at')->whereBetween('booked_at', [$from, $to]);
            })->orWhere(function ($o) use ($from, $to) {
                $o->whereNull('booked_at')->whereBetween('created_at', [$from, $to]);
            });
        });
    }
}
