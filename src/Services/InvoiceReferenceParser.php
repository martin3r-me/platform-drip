<?php

namespace Platform\Drip\Services;

/**
 * Zerlegt einen Bank-Verwendungszweck in Rechnungsnummer-Kandidaten.
 *
 * Der Marker („RG.", „RNr.", „Rechnung-Nr") ist bewusst NICHT das Filterkriterium.
 * Eine Auswertung der echten Eingänge zeigt beides: die Mehrheit der Zahlungen
 * trägt nur die nackte Nummer im Verwendungszweck („4100104", „4100073,4100074,…"),
 * während derselbe Marker vor allem auf der Kreditorenseite auftaucht (Vodafone
 * „RNR 3790828707", 1+1 „RG-Nr. 151166821104"). Ein markerbasierter Filter würde
 * also die Mehrheit der echten Treffer verlieren und dafür Lieferantenrechnungen
 * einsammeln. Der Marker zählt hier nur als Confidence-Signal — entschieden wird
 * über den Abgleich der Kandidaten gegen das echte Belegbuch.
 *
 * Drei Eigenheiten des Bankformats, die hier abgefangen werden:
 *   1. Zeilenumbrüche zerreißen Nummern mitten in der Ziffernfolge („410 0079").
 *   2. Kunden-/Kontonummern stehen direkt daneben („// KD. 6010200 / RG. 4100067 //").
 *   3. Fremde Belegnummern tragen denselben Marker („RG. FDEUR388613" der Freshworks Inc.)
 *      — sie fallen raus, weil sie nicht im Belegbuch stehen.
 *
 * WICHTIG: Hier steht bewusst KEIN Nummernkreis. Der Parser kennt „4100…" nicht
 * und darf ihn nicht kennen — sonst bricht die Erkennung in dem Moment, in dem
 * der Kreis wechselt (Jahreswechsel, zweiter Kreis, neues Rechnungstool). Er
 * liefert nur Kandidaten; ob eine Zahl eine Belegnummer IST, entscheidet allein
 * der Abgleich gegen das echte Belegbuch im InvoiceMatchService. Für Nummern,
 * die gar nicht ins Ziffernschema passen (z.B. „RE-2027-0001"), gibt es dort
 * zusätzlich den umgekehrten Weg über containsNumber().
 */
final class InvoiceReferenceParser
{
    /**
     * Längenfenster für freistehende Ziffernfolgen. Bewusst weit — es geht nur
     * darum, Beträge, Datumsangaben und IBAN-Fragmente draußen zu lassen, nicht
     * darum, ein bestimmtes Nummernschema zu treffen.
     */
    private const MIN_DIGITS = 5;
    private const MAX_DIGITS = 12;

    /** Marker, die auf eine Belegnummer hindeuten — nur Confidence, kein Filter. */
    private const MARKER = '(?:RG|RNR|RENR|RECHNUNG|RECHNUNGSNR|INVOICE|INV)';

    /**
     * Segmente, die eine Nummer tragen, aber nie eine Belegnummer sind:
     * Kunden-, Konto-, Bankleitzahl-Angaben. Müssen vor der Nummernsuche raus,
     * sonst wandert die Kundennummer als Kandidat weiter.
     */
    private const NOISE_SEGMENT = '/\b(?:KD|KND|KNR|KTO|KONTO|BLZ|KUNDE|KUNDEN|MANDAT|IBAN|BIC)\b[\s.:\-\/]*(?:NR\b\.?)?[\s.:\-\/]*\d[\d\s]{0,24}/u';

    /**
     * Zerlegt einen Verwendungszweck.
     *
     * @return array{numbers:list<string>, marked:list<string>, has_marker:bool}
     *   numbers    — Ziffern-Kandidaten (gegen das Belegbuch zu validieren)
     *   marked     — Tokens direkt hinter einem Marker, auch nicht-numerische
     *                (z.B. „FDEUR388613", „RE-25-0093") — für die Kreditorenseite
     *   has_marker — stand irgendwo ein RG./RNr.-Marker?
     */
    public function parse(?string $reference): array
    {
        $empty = ['numbers' => [], 'marked' => [], 'has_marker' => false];

        if ($reference === null || trim($reference) === '') {
            return $empty;
        }

        $raw = $this->compactDigitRuns(mb_strtoupper($reference));

        return [
            'numbers' => $this->numberCandidates($this->stripNoiseSegments($raw)),
            'marked' => $this->markedTokens($raw),
            'has_marker' => (bool) preg_match('/\b' . self::MARKER . '\b/u', $raw),
        ];
    }

    /** Nur die Ziffern-Kandidaten — der übliche Einstieg. */
    public function numbers(?string $reference): array
    {
        return $this->parse($reference)['numbers'];
    }

    /**
     * Steht eine konkrete Belegnummer im Verwendungszweck? Toleriert die vom
     * Bankformat eingestreuten Leerzeichen („410 0079" findet „4100079").
     */
    public function containsNumber(?string $reference, ?string $number): bool
    {
        if ($reference === null || $number === null || trim($number) === '') {
            return false;
        }

        $needle = preg_replace('/\s+/u', '', mb_strtoupper($number));
        if ($needle === '') {
            return false;
        }

        $haystack = $this->compactDigitRuns(mb_strtoupper($reference));

        return (bool) preg_match(
            '/(?<![0-9A-Z])' . preg_quote((string) $needle, '/') . '(?![0-9A-Z])/u',
            $haystack
        );
    }

    /**
     * „410 0079" → „4100079". Das Bankformat bricht lange Verwendungszwecke hart
     * um, wodurch Leerzeichen mitten in der Ziffernfolge landen. Zusammengezogen
     * wird nur zwischen Ziffern — Trennzeichen wie Komma oder „/" bleiben.
     */
    private function compactDigitRuns(string $s): string
    {
        return (string) preg_replace('/(?<=\d)[ \t]+(?=\d)/u', '', $s);
    }

    private function stripNoiseSegments(string $s): string
    {
        return (string) preg_replace(self::NOISE_SEGMENT, ' ', $s);
    }

    /**
     * Freistehende Ziffernfolgen. Die Lookarounds schließen bewusst auch
     * Buchstaben-Nachbarn aus, damit Fremdformate wie „RE1260010" oder
     * „K316129362" gar nicht erst als Kandidat auftauchen.
     *
     * @return list<string>
     */
    private function numberCandidates(string $s): array
    {
        $pattern = '/(?<![0-9A-Z])(\d{' . self::MIN_DIGITS . ',' . self::MAX_DIGITS . '})(?![0-9A-Z])/u';

        if (!preg_match_all($pattern, $s, $m)) {
            return [];
        }

        return array_values(array_unique($m[1]));
    }

    /**
     * Tokens direkt hinter einem Marker — inklusive alphanumerischer Fremdformate.
     * Für die Debitorenseite uninteressant, für den späteren Abgleich der
     * Eingangsrechnungen (Vodafone, solcentrix, 1+1) die eigentliche Nutzlast.
     *
     * @return list<string>
     */
    private function markedTokens(string $s): array
    {
        $pattern = '/\b' . self::MARKER . '\b[\s.:\-]*(?:NR\b\.?)?[\s.:\-]*([A-Z0-9][A-Z0-9\-\/]{2,})/u';

        if (!preg_match_all($pattern, $s, $m)) {
            return [];
        }

        return array_values(array_unique($m[1]));
    }
}
