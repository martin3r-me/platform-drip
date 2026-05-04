<?php

namespace Platform\Drip\Tools\Concerns;

use Platform\Core\Contracts\ToolContext;
use Platform\Core\Contracts\ToolResult;
use Platform\Core\Models\Team;

trait ResolvesDripTeam
{
    protected function resolveTeam(array $arguments, ToolContext $context): array
    {
        $teamId = $arguments['team_id'] ?? $context->team?->id;
        if ($teamId === 0 || $teamId === '0') {
            $teamId = null;
        }

        if (!$teamId) {
            return [
                'team_id' => null,
                'team' => null,
                'error' => ToolResult::error('MISSING_TEAM', 'Kein Team angegeben und kein Team im Kontext gefunden.'),
            ];
        }

        $team = Team::find((int)$teamId);
        if (!$team) {
            return [
                'team_id' => (int)$teamId,
                'team' => null,
                'error' => ToolResult::error('TEAM_NOT_FOUND', 'Team nicht gefunden.'),
            ];
        }

        if (!$context->user) {
            return [
                'team_id' => (int)$teamId,
                'team' => $team,
                'error' => ToolResult::error('AUTH_ERROR', 'Kein User im Kontext.'),
            ];
        }

        $userHasAccess = $context->user->teams()->where('teams.id', $team->id)->exists();
        if (!$userHasAccess) {
            return [
                'team_id' => (int)$teamId,
                'team' => $team,
                'error' => ToolResult::error('ACCESS_DENIED', 'Kein Zugriff auf dieses Team.'),
            ];
        }

        return ['team_id' => (int)$teamId, 'team' => $team, 'error' => null];
    }
}
