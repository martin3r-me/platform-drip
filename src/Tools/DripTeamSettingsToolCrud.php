<?php

namespace Platform\Drip\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;
use Platform\Drip\Models\DripTeamSettings;
use Platform\Drip\Tools\Concerns\ResolvesDripTeam;

class DripTeamSettingsToolCrud implements ToolContract, ToolMetadataContract
{
    use ResolvesDripTeam;

    public function getName(): string
    {
        return 'drip.team_settings.CRUD';
    }

    public function getDescription(): string
    {
        return 'Drip Team-Einstellungen (USt-Konfiguration). action=get (aktuelle Settings laden), action=update (Settings aktualisieren: vat_enabled, vat_filing_frequency, vat_due_day, default_tax_rate).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => [
                    'type' => 'string',
                    'enum' => ['get', 'update'],
                    'description' => 'Aktion: get (default) oder update.',
                ],
                'vat_enabled' => [
                    'type' => 'boolean',
                    'description' => 'USt-Berechnung aktiviert (true/false).',
                ],
                'vat_filing_frequency' => [
                    'type' => 'string',
                    'enum' => ['monthly', 'quarterly'],
                    'description' => 'Abgaberhythmus: monthly oder quarterly.',
                ],
                'vat_due_day' => [
                    'type' => 'integer',
                    'description' => 'Faelligkeitstag nach Periodenende (z.B. 10 = 10. des Folgemonats).',
                ],
                'default_tax_rate' => [
                    'type' => 'number',
                    'description' => 'Standard-USt-Satz in % (z.B. 19.00).',
                ],
                'team_id' => [
                    'type' => 'integer',
                    'description' => 'Optional: Team-ID.',
                ],
            ],
            'required' => [],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        try {
            $resolved = $this->resolveTeam($arguments, $context);
            if ($resolved['error']) {
                return $resolved['error'];
            }
            $teamId = (int) $resolved['team_id'];
            $action = $arguments['action'] ?? 'get';

            return match ($action) {
                'update' => $this->update($arguments, $teamId),
                default => $this->get($teamId),
            };
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', $e->getMessage());
        }
    }

    protected function get(int $teamId): ToolResult
    {
        $settings = DripTeamSettings::getOrCreateForTeam($teamId);

        return ToolResult::success([
            'data' => array_merge(DripTeamSettings::DEFAULT_SETTINGS, $settings->settings ?? []),
            'team_id' => $teamId,
        ]);
    }

    protected function update(array $arguments, int $teamId): ToolResult
    {
        $settings = DripTeamSettings::getOrCreateForTeam($teamId);

        $allowedKeys = ['vat_enabled', 'vat_filing_frequency', 'vat_due_day', 'default_tax_rate'];

        $updated = [];
        foreach ($allowedKeys as $key) {
            if (array_key_exists($key, $arguments)) {
                $value = $arguments[$key];

                // Validate specific fields
                if ($key === 'vat_filing_frequency' && !in_array($value, ['monthly', 'quarterly'])) {
                    return ToolResult::error('VALIDATION_ERROR', 'vat_filing_frequency muss "monthly" oder "quarterly" sein.');
                }
                if ($key === 'vat_due_day' && ($value < 1 || $value > 28)) {
                    return ToolResult::error('VALIDATION_ERROR', 'vat_due_day muss zwischen 1 und 28 liegen.');
                }
                if ($key === 'default_tax_rate' && ($value < 0 || $value > 100)) {
                    return ToolResult::error('VALIDATION_ERROR', 'default_tax_rate muss zwischen 0 und 100 liegen.');
                }

                $settings->setSetting($key, $value);
                $updated[] = $key;
            }
        }

        if (empty($updated)) {
            return ToolResult::error('VALIDATION_ERROR', 'Keine gültigen Settings zum Aktualisieren angegeben.');
        }

        $settings->save();

        return ToolResult::success([
            'message' => 'Settings aktualisiert: ' . implode(', ', $updated),
            'data' => array_merge(DripTeamSettings::DEFAULT_SETTINGS, $settings->settings ?? []),
            'team_id' => $teamId,
        ]);
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'crud',
            'tags' => ['drip', 'settings', 'vat'],
            'read_only' => false,
            'requires_auth' => true,
            'requires_team' => true,
            'risk_level' => 'low',
            'idempotent' => false,
        ];
    }
}
