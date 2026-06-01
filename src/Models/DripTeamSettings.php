<?php

namespace Platform\Drip\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DripTeamSettings extends Model
{
    protected $table = 'drip_team_settings';

    protected $fillable = [
        'team_id',
        'settings',
    ];

    protected $casts = [
        'settings' => 'array',
    ];

    const DEFAULT_SETTINGS = [
        'vat_enabled' => true,
        'vat_filing_frequency' => 'monthly',   // 'monthly' | 'quarterly'
        'vat_due_day' => 10,                     // Fälligkeitstag nach Periodenende
        'default_tax_rate' => 19.00,             // Standard-USt-Satz
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(\Platform\Core\Models\Team::class, 'team_id');
    }

    public static function getOrCreateForTeam(int $teamId): self
    {
        return static::firstOrCreate(
            ['team_id' => $teamId],
            ['settings' => self::DEFAULT_SETTINGS]
        );
    }

    public function getSetting(string $key, $default = null)
    {
        $settings = $this->settings ?? self::DEFAULT_SETTINGS;

        return $settings[$key] ?? $default ?? (self::DEFAULT_SETTINGS[$key] ?? null);
    }

    public function setSetting(string $key, $value): void
    {
        $settings = $this->settings ?? self::DEFAULT_SETTINGS;
        $settings[$key] = $value;
        $this->settings = $settings;
    }
}
