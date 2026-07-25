<?php

namespace Platform\Drip\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Symfony\Component\Uid\UuidV7;

class BankTransactionCategory extends Model
{
    use SoftDeletes;

    public const DIRECTION_DEBIT = 'debit';    // Ausgabe
    public const DIRECTION_CREDIT = 'credit';  // Einnahme
    public const DIRECTION_BOTH = 'both';       // Beides

    public const DIRECTIONS = [self::DIRECTION_DEBIT, self::DIRECTION_CREDIT, self::DIRECTION_BOTH];

    protected $table = 'drip_bank_transaction_categories';

    protected $fillable = [
        'uuid', 'team_id', 'user_id', 'parent_id',
        'name', 'slug', 'color', 'direction', 'default_tax_rate', 'metadata',
    ];

    protected $casts = [
        'default_tax_rate' => 'decimal:2',
        'metadata' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $model) {
            do {
                $uuid = UuidV7::generate();
            } while (self::where('uuid', $uuid)->exists());

            $model->uuid = $model->uuid ?: $uuid;
            $model->user_id ??= Auth::id();
            $model->team_id ??= Auth::user()?->current_team_id;
            $model->slug = $model->slug ?: Str::slug($model->name);
        });
    }

    // ── Relations ──

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(BankTransaction::class, 'category_id');
    }

    public function budgetItems(): HasMany
    {
        return $this->hasMany(BudgetItem::class, 'category_id');
    }

    /** Regeln, die diese Kategorie via Spalte referenzieren (defaults->category_id zusätzlich in PHP prüfen). */
    public function rules(): HasMany
    {
        return $this->hasMany(RecurringPattern::class, 'bank_transaction_category_id');
    }

    // ── Scopes ──

    public function scopeForTeam(Builder $query, int $teamId): Builder
    {
        return $query->where('team_id', $teamId);
    }

    public function scopeRoots(Builder $query): Builder
    {
        return $query->whereNull('parent_id');
    }

    public function scopeByDirection(Builder $query, string $direction): Builder
    {
        return $query->where('direction', $direction);
    }

    // ── Helpers ──

    public function isRoot(): bool
    {
        return $this->parent_id === null;
    }

    /** 1 = Hauptkategorie, 2 = Unterkategorie. */
    public function depth(): int
    {
        return $this->parent_id === null ? 1 : 2;
    }

    public function isIncome(): bool
    {
        return $this->direction === self::DIRECTION_CREDIT;
    }

    public function isExpense(): bool
    {
        return $this->direction === self::DIRECTION_DEBIT;
    }

    /**
     * CSS-Farbwert für den Farbpunkt: Tone-Name → var(--nx-tone-*),
     * Hex-Altwert unverändert, sonst neutraler Slate-Ton.
     */
    public function colorVar(): string
    {
        $c = $this->color;
        if (!$c) {
            return 'var(--nx-tone-slate)';
        }

        return str_starts_with($c, '#') ? $c : 'var(--nx-tone-' . $c . ')';
    }
}
