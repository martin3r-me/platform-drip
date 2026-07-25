<?php

namespace Platform\Drip\Services;

/**
 * Fachliche Integritätsverletzung beim Anlegen/Ändern einer Kategorie.
 * Trägt das betroffene Feld, damit Konsumenten (Livewire-Formular, MCP-Tool)
 * die Meldung gezielt zuordnen können.
 */
class CategoryException extends \RuntimeException
{
    public function __construct(public readonly string $field, string $message)
    {
        parent::__construct($message);
    }
}
