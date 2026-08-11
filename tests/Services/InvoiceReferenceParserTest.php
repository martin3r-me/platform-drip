<?php

namespace Platform\Drip\Tests\Services;

use Platform\Drip\Services\InvoiceReferenceParser;
use Tests\TestCase;

/**
 * Die Fälle stammen 1:1 aus den echten Verwendungszwecken des Kontos
 * „PremiumGeschäftskonto Gründer" (316 Transaktionen, Stand 08/2026). Sie halten
 * fest, was der Parser leisten muss — und was er bewusst NICHT tut: er kennt
 * keinen Nummernkreis. Ob ein Kandidat eine Belegnummer ist, entscheidet erst
 * der Abgleich gegen das Belegbuch im InvoiceMatchService.
 */
class InvoiceReferenceParserTest extends TestCase
{
    private function parser(): InvoiceReferenceParser
    {
        return new InvoiceReferenceParser();
    }

    /** Der Regelfall: nackte Nummer, ganz ohne Marker. */
    public function test_findet_nummer_ohne_marker(): void
    {
        $this->assertSame(['4100104'], $this->parser()->numbers('4100104'));
    }

    /** Sammelzahlung — eine Überweisung über 571,20 € deckt 14 Rechnungen. */
    public function test_findet_alle_nummern_einer_sammelzahlung(): void
    {
        $ref = '4100075,4100076,4100077,4100078,4100079,4100080,4100081';

        $this->assertSame(
            ['4100075', '4100076', '4100077', '4100078', '4100079', '4100080', '4100081'],
            $this->parser()->numbers($ref)
        );
    }

    /**
     * Das Bankformat bricht lange Verwendungszwecke hart um — dabei landen
     * Leerzeichen mitten in der Ziffernfolge.
     */
    public function test_repariert_durch_zeilenumbruch_zerrissene_nummern(): void
    {
        $numbers = $this->parser()->numbers('4100078,410 0079,4100080');

        $this->assertContains('4100079', $numbers);
    }

    /** Kundennummern stehen direkt neben der Rechnungsnummer und dürfen nicht mit. */
    public function test_ignoriert_kunden_und_kontonummern(): void
    {
        $numbers = $this->parser()->numbers('// KD. 6010200 / RG. 4100067 / 9012000 //');

        $this->assertContains('4100067', $numbers);
        $this->assertNotContains('6010200', $numbers, 'Kundennummer darf kein Kandidat sein');
    }

    public function test_ignoriert_bankinterne_konto_und_blz_angaben(): void
    {
        $ref = 'Konto  220381800 EUR BLZ   300 400 00 vom 30.06.2026 bis 31.07.2026';

        $this->assertSame([], $this->parser()->numbers($ref));
    }

    /** „RNr. 4100102 RDat. … KNr. 6010500" — Rheingedeck-Format. */
    public function test_erkennt_rnr_format_und_laesst_knr_draussen(): void
    {
        $numbers = $this->parser()->numbers('RNr. 4100102 RDat. 30.06.2026 KNr. 6010500');

        $this->assertSame(['4100102'], $numbers);
    }

    /**
     * Fremde Belegnummern tragen denselben Marker. Sie dürfen keinen Ziffern-
     * Kandidaten erzeugen — sonst landet eine Freshworks-Rechnung auf einer
     * eigenen Forderung.
     */
    public function test_fremdes_belegformat_erzeugt_keinen_ziffern_kandidaten(): void
    {
        $parsed = $this->parser()->parse('// ERSTATTUNG DER VORAUSLAGE ZUR RG. FDEUR388613 DER FRESHWORKS INC. //');

        $this->assertSame([], $parsed['numbers']);
        $this->assertTrue($parsed['has_marker']);
        $this->assertContains('FDEUR388613', $parsed['marked']);
    }

    /**
     * Alphanumerische Kreditorenformate erzeugen gar keinen Kandidaten …
     */
    public function test_alphanumerische_kreditoren_nummern_sind_keine_kandidaten(): void
    {
        $this->assertSame([], $this->parser()->numbers('RNR RE1260010 Datum 16.07.2026 Betrag 4.057,90 Kto. 700'));
        $this->assertSame([], $this->parser()->numbers('RNR RE-25-0093 Datum 01.07.2026'));
    }

    /**
     * … rein numerische dagegen schon — und das ist Absicht. Der Parser darf
     * nicht anhand der Länge raten, welcher Nummernkreis „unserer" ist, sonst
     * bricht er beim nächsten Kreiswechsel. Aussortiert werden diese Kandidaten
     * erst eine Stufe später: sie stehen in keinem eigenen Beleg.
     *
     * Belegt an den echten Daten: über 261 Ausgänge des Kontos entsteht so kein
     * einziger Fehltreffer, weil keine dieser Nummern im Belegbuch steht.
     */
    public function test_numerische_kreditoren_nummern_werden_erst_am_belegbuch_verworfen(): void
    {
        $vodafone = $this->parser()->numbers('Kto. 70010 07 RNR 3790828707 Datum 30.06.2026');
        $einsUndEins = $this->parser()->numbers('KD-Nr. K316129362/ RG-Nr. 151166821104');

        // Kandidat ja — aber keiner, der im eigenen Belegbuch steht.
        $this->assertSame(['3790828707'], $vodafone);
        $this->assertSame(['151166821104'], $einsUndEins);

        $eigenesBelegbuch = ['4100107', '4100126', '4100129'];
        $this->assertSame([], array_intersect($vodafone, $eigenesBelegbuch));
        $this->assertSame([], array_intersect($einsUndEins, $eigenesBelegbuch));
    }

    /** „Thomasberg", „Königswinter" — enthalten buchstäblich „rg". */
    public function test_wortbestandteile_loesen_keinen_marker_treffer_aus(): void
    {
        $parsed = $this->parser()->parse('Dienstleistung Thomasberg | Meerbusch - Königswinter (Rückfahrt)');

        $this->assertFalse($parsed['has_marker']);
        $this->assertSame([], $parsed['numbers']);
    }

    /**
     * Der Rückwärtsweg für Nummern, die gar nicht ins Ziffernschema passen —
     * damit ein Wechsel des Nummernkreises oder des Rechnungstools die Erkennung
     * nicht bricht.
     */
    public function test_containsNumber_findet_beliebige_belegnummern(): void
    {
        $p = $this->parser();

        $this->assertTrue($p->containsNumber('Zahlung zu RE-2027-0001, danke', 'RE-2027-0001'));
        $this->assertTrue($p->containsNumber('4100078,410 0079', '4100079'), 'muss Umbruch tolerieren');
        $this->assertFalse($p->containsNumber('Rechnung 41001070', '4100107'), 'Teiltreffer darf nicht zählen');
    }

    public function test_leerer_verwendungszweck_ist_unkritisch(): void
    {
        $this->assertSame([], $this->parser()->numbers(null));
        $this->assertSame([], $this->parser()->numbers('   '));
    }
}
