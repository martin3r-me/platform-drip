<?php

namespace Platform\Drip\Tools;

use Platform\Core\Contracts\ToolContract;
use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolMetadataContract;
use Platform\Core\Contracts\ToolResult;

class DripOverviewTool implements ToolContract, ToolMetadataContract
{
    public function getName(): string
    {
        return 'drip.overview.GET';
    }

    public function getDescription(): string
    {
        return 'GET /drip/overview - Zeigt Übersicht über das Drip Finance-Modul (Bankkonten, Transaktionen, Requisitions, Gruppen).';
    }

    public function getSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => new \stdClass(),
            'required' => [],
        ];
    }

    public function execute(array $arguments, ToolContext $context): ToolResult
    {
        try {
            return ToolResult::success([
                'module' => 'drip',
                'description' => 'Finance-Modul für Bankkonten-Anbindung via GoCardless, Transaktionen, Salden und Metriken.',
                'concepts' => [
                    'bank_accounts' => 'Bankkonten (IBAN, BIC, Salden) – via GoCardless oder manuell',
                    'bank_transactions' => 'Banktransaktionen (Buchungen, Verwendungszweck, Beträge)',
                    'bank_account_balances' => 'Kontosalden (Snapshot pro Typ)',
                    'bank_account_groups' => 'Gruppen zur Konten-Organisation',
                    'requisitions' => 'GoCardless-Bankverbindungen (OAuth-Flow, Status, Ablauf)',
                    'institutions' => 'Banken/Institute (Name, BIC, Logo)',
                    'recurring_patterns' => 'Wiederkehrende Transaktionsmuster',
                    'finance_metrics' => 'Aggregierte Finanzkennzahlen pro Konto/Periode',
                    'internal_transfers' => 'Umbuchungen zwischen eigenen Konten',
                    'go_cardless_tokens' => 'OAuth-Tokens für GoCardless API',
                ],
                'related_tools' => [
                    'drip.bank_accounts.GET' => 'Bankkonten auflisten',
                    'drip.bank_transactions.GET' => 'Transaktionen auflisten',
                    'drip.requisitions.GET' => 'GoCardless-Verbindungen auflisten',
                    'drip.institutions.GET' => 'Institute/Banken auflisten',
                ],
            ]);
        } catch (\Throwable $e) {
            return ToolResult::error('EXECUTION_ERROR', $e->getMessage());
        }
    }

    public function getMetadata(): array
    {
        return [
            'category' => 'overview',
            'tags' => ['drip', 'finance', 'overview'],
            'read_only' => true,
            'requires_auth' => true,
            'requires_team' => true,
            'risk_level' => 'safe',
            'idempotent' => true,
        ];
    }
}
