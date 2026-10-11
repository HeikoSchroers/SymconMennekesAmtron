<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/VerbindungKette.php';

/**
 * Mennekes AMTRON Professional (Ladecontroller ECU) ueber das ModBus Gateway
 * von IP-Symcon.
 *
 * Die Instanz haengt unter einem ModBus Gateway mit Client Socket. Adresse,
 * Port und Unit-ID stehen in dieser Instanz; sie traegt sie selbst in Gateway
 * und Socket ein (libs/VerbindungKette.php).
 *
 * Grundlage: MENNEKES "ECU-BRx and ECU-BBx Modbus TCP Server Specification",
 * Dok.-Revision 1.07 vom 10.06.2022. Jedes gelesene Register ist am echten
 * Geraet geprueft (Modell CC612_2S0R_CC, Firmware 5.22.5, Protokoll 0.12).
 *
 * Eigenheiten des Geraets:
 * 1. Hoechstens 20 Register je Anfrage, und nur Bloecke innerhalb seiner
 *    internen Gruppen. 100..112 wird abgelehnt, 100..101 und 104..112 gehen.
 * 2. Es nimmt genau EINE Modbus-TCP-Verbindung an. Jede neue wirft die
 *    vorige hinaus. Neben dieser Instanz darf kein anderer Leser laufen.
 * 3. 32-Bit-Werte: hohes Wort zuerst, 0xFFFFFFFF heisst "kein Messwert".
 * 4. Stroeme stehen in Milliampere.
 */
class MennekesAMTRON extends IPSModule
{
    use \SymconMennekesAmtron\VerbindungKette;

    private const VERBINDUNG = ['Port' => 502, 'GatewayMode' => 0, 'LoggerSerial' => false];
    private const MODBUS_TX = '{E310B701-4AE7-458E-B618-EC13A1A6F6A8}';

    // Darstellungen von IP-Symcon (/usr/share/symcon/presentations/)
    private const P_WERT = '{3319437D-7CDE-699D-750A-3C6A3841FA75}';
    private const P_ZEIT = '{497C4845-27FA-6E4F-AE37-5D951D3BDBF9}';
    private const P_DAUER = '{08A6AF76-394E-D354-48D5-BFC690488E4E}';

    /** Einzeln gegen das Geraet geprueft (Eigenheit 1). */
    private const BLOECKE = [
        [100, 2], [104, 9], [120, 5], [131, 4], [142, 10], [152, 4],
        [200, 16], [216, 12],
        [600, 1], [610, 6], [620, 2], [630, 6],
        [705, 15], [720, 10], [740, 7], [1000, 1],
    ];

    /** Ab dieser Leistung (W) gilt es als Laden. */
    private const SCHWELLE = 100.0;
    /** So lange (s) ohne Leistung, dann ist ein Ladevorgang beendet. */
    private const KARENZ = 900;

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyInteger('Interval', 30);
        $this->RegisterPropertyBoolean('AllowWrite', false);
        $this->RegisterAttributeString('Session', '');
        $this->RegisterTimer('Update', 0, 'AMTRON_Update($_IPS[\'TARGET\']);');
        $this->registriereVerbindung();

        $this->RequireParent('{A5F663AB-C400-4FE5-B207-4D67CC030564}');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->registerVariables();
        if ($this->ReadPropertyBoolean('AllowWrite')) {
            $this->EnableAction('I_HEMS');
        } else {
            $this->DisableAction('I_HEMS');
        }
        $this->SetTimerInterval('Update', max(5, $this->ReadPropertyInteger('Interval')) * 1000);
        $this->wendeVerbindungAn();
    }

    public function GetConfigurationForm()
    {
        return $this->formularMitVerbindung(__DIR__ . '/form.json');
    }

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
            case 'I_HEMS':
                if ($this->SetCurrentLimit((int) $Value)) {
                    $this->SetValue('I_HEMS', (float) (int) $Value);
                }
                break;
            default:
                throw new Exception('Unbekannter Ident ' . $Ident);
        }
    }

    // ===================== Oeffentliche Funktionen =====================

    /** Liest alle Register und schreibt die Variablen. */
    public function Update(): bool
    {
        $r = [];
        $fehlt = [];
        foreach (self::BLOECKE as [$start, $anzahl]) {
            $teil = $this->readRegisters($start, $anzahl);
            if ($teil === null) {
                $fehlt[] = $start;
                continue;
            }
            $r += $teil;
        }

        if ($r === []) {
            $this->SetValue('VERBINDUNG', false);
            $this->SendDebug('Update', 'Kein Register lesbar', 0);
            return false;
        }
        if ($fehlt !== []) {
            $this->SendDebug('Update', 'Bloecke ohne Antwort: ' . implode(', ', $fehlt), 0);
        }

        $this->writeValues($r);
        $this->SetValue('STAND', time());
        $this->SetValue('VERBINDUNG', true);
        return true;
    }

    /**
     * HEMS-Stromgrenze in A (Register 1000), z. B. fuer PV-Ueberschussladen.
     * Laut Hersteller-Doku faellt die Wallbox auf den Sicherheitsstrom
     * zurueck, wenn innerhalb des Kommunikations-Zeitlimits (Register 132)
     * nichts mehr kommt. Am Geraet noch nicht erprobt.
     */
    public function SetCurrentLimit(int $Ampere): bool
    {
        if (!$this->ReadPropertyBoolean('AllowWrite')) {
            throw new Exception('Schreiben ist in der Instanz nicht freigegeben (Schreibzugriff erlauben)');
        }
        if ($Ampere < 0 || $Ampere > 32) {
            throw new Exception('Stromgrenze muss zwischen 0 und 32 A liegen');
        }
        $this->SendDebug('Schreiben', '1000 = ' . $Ampere . ' A', 0);
        return $this->sendModbus(16, 1000, 1, pack('n', $Ampere)) !== null;
    }

    /** Fuer "Verbindung testen" (libs/VerbindungKette.php). */
    private function hinweisOhneAntwort(): string
    {
        return 'Die Wallbox nimmt nur eine Modbus-Verbindung an. Läuft noch ein anderer Leser (Skript, zweite Instanz, Werkzeug)?';
    }

    // ===================== Modbus ueber das Gateway =====================

    /** Liest Holding-Register (Funktion 3). Gibt [Adresse => Wert] oder null. */
    private function readRegisters(int $start, int $anzahl): ?array
    {
        $daten = $this->sendModbus(3, $start, $anzahl, '');
        if ($daten === null) {
            return null;
        }
        $reg = [];
        for ($i = 0; $i + 1 < strlen($daten) && intdiv($i, 2) < $anzahl; $i += 2) {
            $reg[$start + intdiv($i, 2)] = (ord($daten[$i]) << 8) | ord($daten[$i + 1]);
        }
        return $reg;
    }

    /**
     * Eine Anfrage ans ModBus Gateway. Gibt die Registerdaten ohne
     * Funktionscode und Byteanzahl zurueck oder null bei Fehler.
     */
    private function sendModbus(int $funktion, int $adresse, int $anzahl, string $daten): ?string
    {
        if (!$this->HasActiveParent()) {
            return null;
        }
        // Schreibdaten sind rohe Bytes; damit json_encode sie annimmt, werden
        // sie als Latin-1 nach UTF-8 gewandelt (so erwartet es das Gateway).
        $fehler = '';
        set_error_handler(static function (int $nr, string $text) use (&$fehler): bool {
            $fehler = $text;
            return true;
        });
        try {
            $antwort = $this->SendDataToParent(json_encode([
                'DataID'   => self::MODBUS_TX,
                'Function' => $funktion,
                'Address'  => $adresse,
                'Quantity' => $anzahl,
                'Data'     => mb_convert_encoding($daten, 'UTF-8', 'ISO-8859-1'),
            ]));
        } catch (Throwable $e) {
            $fehler = $e->getMessage();
            $antwort = false;
        } finally {
            restore_error_handler();
        }
        if (!is_string($antwort) || strlen($antwort) < 2) {
            $this->SendDebug('Modbus', sprintf('F%d %d/%d: %s', $funktion, $adresse, $anzahl, $fehler ?: 'keine Antwort'), 0);
            return null;
        }
        // Ausnahme-Antwort: Bit 0x80 im Funktionscode, danach der Fehlercode.
        if (ord($antwort[0]) & 0x80) {
            $this->SendDebug('Modbus', sprintf('F%d %d/%d: Ausnahme %d', $funktion, $adresse, $anzahl, ord($antwort[1])), 0);
            return null;
        }
        // Die Antwort ist binaer: Funktionscode, Byteanzahl, dann die Daten.
        return $funktion <= 4 ? substr($antwort, 2) : $antwort;
    }

    // ===================== Auswertung =====================

    private function writeValues(array $r): void
    {
        $reg = static fn (int $a): ?int => $r[$a] ?? null;

        // --- Status ---
        $this->SetValue('FZ', $reg(122) ?? 0);
        $this->SetValue('OCPP', $reg(104) ?? 0);
        $this->SetValue('VERRIEGELT', ($reg(152) ?? 0) === 1);
        // Register 124: Laut Doku hat sich die Bedeutung mit Firmware 5.22
        // gedreht. Das gepruefte Geraet (5.22.5) liest aber im Leerlauf und
        // beim Laden 0, folgt also der alten Auslegung (0 = verfuegbar).
        $this->SetValue('FREIGABE', $reg(124) ?? 0);
        $fehler = self::fehler($r);
        $this->SetValue('STOERUNG', $fehler !== []);
        $this->SetValue('FEHLER', $fehler === [] ? 'keine' : implode("\n", $fehler));

        // --- Messwerte ---
        // Den Zaehlerstand nie auf 0 setzen - eine 0 ist hier immer ein Lesefehler.
        $eGesamt = self::u32($r, 218);
        if ($eGesamt !== null && $eGesamt > 0) {
            $this->SetValue('E_GESAMT', $eGesamt / 1000.0);
        }
        $this->setIf('P_GESAMT', self::u32($r, 220));
        $this->setIf('U_L1', self::u32($r, 222));
        $this->setIf('U_L2', self::u32($r, 224));
        $this->setIf('U_L3', self::u32($r, 226));
        $summe = 0.0;
        $gemessen = false;
        foreach ([['I_L1', 212], ['I_L2', 214], ['I_L3', 216]] as [$ident, $a]) {
            $mA = self::u32($r, $a);
            if ($mA !== null) {
                $this->SetValue($ident, round($mA / 1000.0, 3));
                $summe += $mA / 1000.0;
                $gemessen = true;
            }
        }
        // Summe der drei Phasen, nur zur Anzeige - Grenzen gelten je Phase.
        if ($gemessen) {
            $this->SetValue('I_SUMME', round($summe, 3));
        }

        // --- Ladevorgang ---
        // 716/718 sind Sitzungswerte des Geraets; 718 zaehlt die STECKDAUER,
        // auch wenn laengst nichts mehr fliesst.
        $sE = self::u32($r, 716);
        if ($sE !== null) {
            $this->SetValue('S_ENERGIE', $sE / 1000.0);
        }
        $this->setIf('S_DAUER', self::u32($r, 718));
        $this->session(
            (float) (self::u32($r, 220) ?? 0),
            ($eGesamt !== null && $eGesamt > 0) ? $eGesamt / 1000.0 : null,
            $reg(122) ?? 0
        );
        $strich = static fn (string $t): string => $t === '' ? '-' : $t;
        $this->SetValue('S_START', $strich(self::bcd(self::u32($r, 707), 'zeit')));
        $this->SetValue('S_ENDE', $strich(self::bcd(self::u32($r, 710), 'zeit')));
        $abZeit = self::bcd(self::u32($r, 701), 'zeit');
        $abDatum = self::bcd(self::u32($r, 703), 'datum');
        $this->SetValue('S_ABFAHRT', $strich(trim($abDatum . ' ' . $abZeit)));
        $bedarf = self::u32($r, 713);
        if ($bedarf !== null) {
            $this->SetValue('S_BEDARF', $bedarf / 1000.0);
        }
        // Register 740 steht in keiner Hersteller-Tabelle; die Bedeutung
        // passt zu den EVCCID-Registern direkt dahinter.
        $this->SetValue('SMART', ($reg(740) ?? 0) === 1);
        $this->SetValue('EVCCID', $strich(self::text($r, 741, 6)));
        $this->SetValue('IDTAG', $strich(self::text($r, 720, 10)));

        // --- Stromgrenzen (A) ---
        foreach ([['I_SIGNAL', 706], ['I_MAX_EV', 715], ['I_MIN', 712], ['I_HEMS', 1000],
                  ['I_SAFE', 131], ['I_OP', 134]] as [$ident, $a]) {
            if ($reg($a) !== null) {
                $this->SetValue($ident, (float) $reg($a));
            }
        }
        $this->setIf('COMM_TO', $reg(132));

        // --- Lastmanagement ---
        $this->setIf('DLM_MODUS', $reg(600));
        $this->setIf('DLM_SLAVES', $reg(621));
        foreach ([['DLM_A_L1', 630], ['DLM_A_L2', 631], ['DLM_A_L3', 632],
                  ['DLM_V_L1', 633], ['DLM_V_L2', 634], ['DLM_V_L3', 635]] as [$ident, $a]) {
            if ($reg($a) !== null) {
                $this->SetValue($ident, (float) $reg($a));
            }
        }

        // --- Geraet ---
        $modell = self::text($r, 142, 10);
        if ($modell !== '') {
            $this->SetValue('MODELL', $modell);
        }
        if ($reg(153) !== null) {
            $this->SetValue('FIRMWARE', sprintf('%d.%d.%d', $reg(153), $reg(154) ?? 0, $reg(155) ?? 0));
        }
        $protokoll = self::text($r, 120, 2);
        if ($protokoll !== '') {
            $this->SetValue('PROTOKOLL', $protokoll);
        }
    }

    /**
     * Ladevorgang selbst messen. Register 718 ist die Steckdauer, nicht die
     * Ladezeit, und die Wallbox setzt es nach dem Abstecken auf 0. Gezaehlt
     * wird deshalb nur die Zeit, in der Leistung fliesst; die Energie kommt
     * aus dem monoton laufenden Zaehlerstand.
     */
    private function session(float $p, ?float $zaehler, int $fz): void
    {
        $st = json_decode($this->ReadAttributeString('Session'), true);
        if (!is_array($st)) {
            $st = ['lauf' => 0, 'aktiv' => false, 'zstart' => 0.0, 'sek' => 0, 'pmax' => 0.0, 'leer' => 0, 'beginn' => 0];
        }
        $jetzt = time();
        // Eine Luecke (Neustart, Netzausfall) zaehlt nicht als Ladezeit.
        $takt = max(5, $this->ReadPropertyInteger('Interval'));
        $dt = ($st['lauf'] > 0 && $jetzt > $st['lauf']) ? min($jetzt - $st['lauf'], 5 * $takt) : 0;
        $st['lauf'] = $jetzt;
        $laedt = $p > self::SCHWELLE;

        if ($laedt) {
            if (!$st['aktiv'] && $zaehler !== null) {
                $st = ['lauf' => $jetzt, 'aktiv' => true, 'zstart' => $zaehler, 'sek' => 0, 'pmax' => 0.0, 'leer' => 0, 'beginn' => $jetzt];
            }
            $st['leer'] = 0;
            if ($st['aktiv']) {
                // Beginn schon waehrend des Vorgangs zeigen, nicht erst am Ende.
                $this->SetValue('L_BEGINN', $st['beginn']);
                $st['sek'] += $dt;
                $st['pmax'] = max($st['pmax'], $p);
            }
        } elseif ($st['aktiv']) {
            $st['leer'] += $dt;
            // Zustand A (kein Fahrzeug) beendet den Vorgang sofort.
            if ($st['leer'] >= self::KARENZ || $fz <= 1) {
                $this->SetValue('LETZT_D', $st['sek']);
                if ($zaehler !== null) {
                    $this->SetValue('LETZT_E', round($zaehler - $st['zstart'], 3));
                }
                $this->SetValue('P_MAX', $st['pmax']);
                $this->SetValue('L_BEGINN', $st['beginn']);
                $this->SetValue('LETZT_ST', $jetzt - $st['leer']);
                $st['aktiv'] = false;
            }
        }
        $this->WriteAttributeString('Session', json_encode($st));

        $this->SetValue('LAEDT', $laedt);
        $this->SetValue('LZ_AKTUELL', $st['aktiv'] ? $st['sek'] : 0);
        $this->SetValue('LE_AKTUELL', ($st['aktiv'] && $zaehler !== null) ? round($zaehler - $st['zstart'], 3) : 0.0);
    }

    // ===================== Variablen =====================

    private function registerVariables(): void
    {
        $pos = 0;
        $v = function (string $ident, string $name, int $typ, array $praes = []) use (&$pos): void {
            $pos += 10;
            $this->registerVariable($ident, $name, $typ, $praes, $pos);
        };

        // Status
        $v('VERBINDUNG', 'Verbindung', 0, self::pBool('getrennt', 'verbunden', 'Network'));
        $v('STAND', 'Letzte Aktualisierung', 1, self::pZeit());
        $v('FZ', 'Fahrzeugstatus', 1, self::pAufz([0 => 'unbekannt', 1 => 'A - kein Fahrzeug', 2 => 'B - verbunden, lädt nicht',
            3 => 'C - lädt', 4 => 'D - lädt mit Belüftung', 5 => 'E - Fehler'], 'Car'));
        $v('OCPP', 'Ladepunkt-Status', 1, self::pAufz([0 => 'Verfügbar', 1 => 'Belegt', 2 => 'Reserviert', 3 => 'Nicht verfügbar',
            4 => 'Störung', 5 => 'Vorbereitung', 6 => 'Lädt', 7 => 'Unterbrochen (Ladepunkt)',
            8 => 'Unterbrochen (Fahrzeug)', 9 => 'Beendet'], 'Information'));
        $v('VERRIEGELT', 'Stecker verriegelt', 0, self::pBool('offen', 'verriegelt', 'Lock'));
        $v('FREIGABE', 'Ladepunkt verfügbar', 1, self::pAufz([0 => 'verfügbar', 1 => 'nicht verfügbar'], 'Ok'));
        $v('STOERUNG', 'Störung', 0, self::pBool('keine', 'Störung', 'Warning'));
        $v('FEHLER', 'Fehlermeldungen', 3, ['ICON' => 'Alert']);

        // Messwerte
        $v('E_GESAMT', 'Energie gesamt', 2, self::pWert(' kWh', 2, 'Gauge'));
        $v('P_GESAMT', 'Leistung gesamt', 2, self::pWert(' W', 1, 'Lightning', 0, 14490));
        for ($n = 1; $n <= 3; $n++) {
            $v("U_L$n", "Spannung L$n", 2, self::pWert(' V', 1, 'Power', 0, 280));
        }
        for ($n = 1; $n <= 3; $n++) {
            $v("I_L$n", "Strom L$n", 2, self::pWert(' A', 2, 'Electricity', 0, 32));
        }
        $v('I_SUMME', 'Ladestrom gesamt', 2, self::pWert(' A', 2, 'Electricity', 0, 96));

        // Ladevorgang
        $v('S_ENERGIE', 'Sitzungsenergie (Gerät)', 2, self::pWert(' kWh', 2, 'Battery'));
        $v('S_DAUER', 'Steckdauer (Gerät)', 1, self::pDauer(3));
        $v('LAEDT', 'Lädt gerade', 0, self::pBool('nein', 'ja', 'Lightning'));
        $v('LZ_AKTUELL', 'Ladezeit laufender Vorgang', 1, self::pDauer(3));
        $v('LE_AKTUELL', 'Geladene Energie laufend', 2, self::pWert(' kWh', 2, 'Battery'));
        $v('L_BEGINN', 'Letzter Ladevorgang Beginn', 1, self::pZeit());
        $v('P_MAX', 'Höchste Leistung letzter Vorgang', 2, self::pWert(' W', 1, 'Lightning', 0, 14490));
        $v('LETZT_E', 'Letztes Ladevolumen', 2, self::pWert(' kWh', 2, 'Battery'));
        $v('LETZT_D', 'Letzte Ladezeit', 1, self::pDauer(3));
        $v('LETZT_ST', 'Letzter Ladevorgang Ende', 1, self::pZeit());
        $v('S_START', 'Ladebeginn', 3, ['ICON' => 'Clock']);
        $v('S_ENDE', 'Ladeende', 3, ['ICON' => 'Clock']);
        $v('S_ABFAHRT', 'Geplante Abfahrt', 3, ['ICON' => 'Clock']);
        $v('S_BEDARF', 'Energiebedarf Fahrzeug', 2, self::pWert(' kWh', 2, 'Battery'));
        $v('SMART', 'Smart-Fahrzeug erkannt', 0, self::pBool('nein', 'ja', 'Car'));
        $v('EVCCID', 'Fahrzeugkennung (EVCCID)', 3, ['ICON' => 'Car']);
        $v('IDTAG', 'Ladekarte (IdTag)', 3, ['ICON' => 'Notebook']);

        // Stromgrenzen
        $v('I_SIGNAL', 'Signalisierter Strom', 2, self::pWert(' A', 1, 'Speedo', 0, 32));
        $v('I_MAX_EV', 'Maximalstrom Fahrzeug', 2, self::pWert(' A', 1, 'Car', 0, 32));
        $v('I_MIN', 'Mindeststrom', 2, self::pWert(' A', 1, 'Speedo', 0, 32));
        $v('I_HEMS', 'HEMS-Stromgrenze', 2, self::pWert(' A', 1, 'Gauge', 0, 32));
        $v('I_SAFE', 'Sicherheitsstrom bei Ausfall', 2, self::pWert(' A', 1, 'Lock', 0, 32));
        $v('I_OP', 'Betreiber-Stromgrenze', 2, self::pWert(' A', 1, 'Gauge', 0, 32));
        $v('COMM_TO', 'Kommunikations-Zeitlimit', 1, self::pDauer());

        // Lastmanagement
        $v('DLM_MODUS', 'Betriebsart Lastmanagement', 1, self::pAufz([0 => 'aus', 1 => 'Master mit internem Slave', 2 => 'Master allein',
            3 => 'Slave (Master-Suche)', 4 => 'Slave (feste IP)'], 'Network'));
        $v('DLM_SLAVES', 'Verbundene Slaves', 1, self::pWert('', 0, 'Network', 0, 16));
        for ($n = 1; $n <= 3; $n++) {
            $v("DLM_V_L$n", "Verfügbar L$n", 2, self::pWert(' A', 1, 'Gauge', 0, 32));
        }
        for ($n = 1; $n <= 3; $n++) {
            $v("DLM_A_L$n", "Zugeteilt L$n", 2, self::pWert(' A', 1, 'Electricity', 0, 32));
        }

        // Geraet
        $v('MODELL', 'Modellkennung', 3, ['ICON' => 'Information']);
        $v('FIRMWARE', 'Firmware', 3, ['ICON' => 'Information']);
        $v('PROTOKOLL', 'Modbus-Protokollversion', 3, ['ICON' => 'Information']);
    }

    private function registerVariable(string $ident, string $name, int $typ, array $praes, int $pos): void
    {
        $mitDarstellung = isset($praes['PRESENTATION']);
        switch ($typ) {
            case 0:
                $this->RegisterVariableBoolean($ident, $name, $mitDarstellung ? $praes : '', $pos);
                break;
            case 1:
                $this->RegisterVariableInteger($ident, $name, $mitDarstellung ? $praes : '', $pos);
                break;
            case 2:
                $this->RegisterVariableFloat($ident, $name, $mitDarstellung ? $praes : '', $pos);
                break;
            default:
                $this->RegisterVariableString($ident, $name, $mitDarstellung ? $praes : '', $pos);
        }
        $icon = $praes['ICON'] ?? '';
        $id = $this->GetIDForIdent($ident);
        if ($icon !== '' && IPS_GetObject($id)['ObjectIcon'] !== $icon) {
            IPS_SetIcon($id, $icon);
        }
    }

    private function setIf(string $ident, $wert): void
    {
        if ($wert !== null) {
            $this->SetValue($ident, $wert);
        }
    }

    private static function pWert(string $suffix, int $digits, string $icon, float $min = 0.0, float $max = 0.0): array
    {
        return ['PRESENTATION' => self::P_WERT, 'SUFFIX' => $suffix, 'DIGITS' => $digits,
                'ICON' => $icon, 'MIN' => $min, 'MAX' => $max];
    }

    /**
     * Feste Texte fuer Ganzzahlen. Nicht die Darstellung "Aufzaehlung": Die
     * verlangt eine Aktion an der Variable, und ohne Aktion zeigt die
     * Kachel-Visualisierung nur die rohe Zahl. Die Darstellung "Wert" kennt
     * fuer Ganzzahlen Intervalle mit festem Text.
     */
    private static function pAufz(array $optionen, string $icon): array
    {
        $iv = [];
        foreach ($optionen as $wert => $text) {
            $iv[] = ['IntervalMinValue' => $wert, 'IntervalMaxValue' => $wert, 'ConstantActive' => true, 'ConstantValue' => $text,
                     'ConversionFactor' => 1, 'PrefixActive' => false, 'PrefixValue' => '', 'IconActive' => false, 'IconValue' => '',
                     'ColorActive' => false, 'ColorValue' => -1, 'ContentColorActive' => false, 'ContentColorValue' => -1,
                     'SuffixActive' => false, 'SuffixValue' => '', 'DigitsActive' => false, 'DigitsValue' => 0];
        }
        return ['PRESENTATION' => self::P_WERT, 'ICON' => $icon, 'DIGITS' => 0, 'INTERVALS_ACTIVE' => true, 'INTERVALS' => json_encode($iv)];
    }

    /**
     * Feste Texte fuer Ja/Nein-Werte, aus demselben Grund wie pAufz. Ohne
     * Aktion zeigte die Kachel-Visualisierung sonst ein rotes "Aus".
     */
    private static function pBool(string $aus, string $an, string $icon): array
    {
        $o = [];
        foreach ([[false, $aus], [true, $an]] as [$wert, $text]) {
            $o[] = ['Value' => $wert, 'Caption' => $text, 'IconActive' => false, 'IconValue' => '',
                    'ColorActive' => false, 'ColorValue' => -1, 'ContentColorActive' => false, 'ContentColorValue' => -1];
        }
        return ['PRESENTATION' => self::P_WERT, 'ICON' => $icon, 'OPTIONS' => json_encode($o)];
    }

    private static function pZeit(string $icon = 'Clock'): array
    {
        return ['PRESENTATION' => self::P_ZEIT, 'DATE' => 2, 'TIME' => 1, 'MONTH_TEXT' => false, 'ICON' => $icon];
    }

    /**
     * Format 1 = Minuten und Sekunden, 3 = Stunden und Minuten. Ladezeiten
     * brauchen Stunden: 9750 s erschienen mit Format 1 als "162:30".
     */
    private static function pDauer(int $format = 1, string $icon = 'Clock'): array
    {
        return ['PRESENTATION' => self::P_DAUER, 'FORMAT' => $format, 'MILLISECONDS' => false, 'ICON' => $icon];
    }

    // ===================== Umrechnungen =====================

    /** 32-Bit-Wert, hohes Wort zuerst; 0xFFFFFFFF heisst "kein Messwert". */
    private static function u32(array $r, int $a): ?int
    {
        if (!isset($r[$a], $r[$a + 1])) {
            return null;
        }
        $w = ($r[$a] << 16) | $r[$a + 1];
        return $w === 0xFFFFFFFF ? null : $w;
    }

    /** Registerfolge als ASCII-Text. */
    private static function text(array $r, int $a, int $anzahl): string
    {
        $s = '';
        for ($i = 0; $i < $anzahl; $i++) {
            if (!isset($r[$a + $i])) {
                return '';
            }
            $s .= chr($r[$a + $i] >> 8) . chr($r[$a + $i] & 0xFF);
        }
        return trim(str_replace("\0", '', $s));
    }

    /** Gepacktes BCD: hhmmss bzw. ddmmyy (laut Doku, nicht yymmdd). */
    private static function bcd(?int $w, string $art): string
    {
        if ($w === null || $w === 0) {
            return '';
        }
        $h = sprintf('%06X', $w & 0xFFFFFF);
        return $art === 'zeit'
            ? substr($h, 0, 2) . ':' . substr($h, 2, 2) . ':' . substr($h, 4, 2)
            : substr($h, 0, 2) . '.' . substr($h, 2, 2) . '.20' . substr($h, 4, 2);
    }

    /** Fehlerregister 105..112 in Klartext. Wortfolge und Bytes sind gedreht. */
    private static function fehler(array $r): array
    {
        $masken = [
            0 => 'Fehlerstrom erkannt (RCMB)',
            1 => 'Fahrzeug meldet Fehler',
            2 => 'Diodenprüfung fehlgeschlagen (Manipulation)',
            3 => 'Leitungsschutzschalter Typ-2-Dose ausgelöst',
            4 => 'Leitungsschutzschalter Schuko ausgelöst',
            5 => 'Fehlerstromschutzschalter ausgelöst',
            6 => 'Schütz verschweißt',
            7 => 'Backend getrennt',
            8 => 'Verriegelung fehlgeschlagen',
            9 => 'Verriegelung ohne Stecker fehlgeschlagen',
            10 => 'Verriegelung klemmt, entriegelt nicht',
            11 => 'Verriegelungserkennung fehlgeschlagen',
            12 => 'Firmware-Aktualisierung läuft',
            13 => 'Ladepunkt steht schief',
            14 => 'Verdrahtungsfehler CP/PR',
            15 => 'Fahrzeug überlastet, Ladung gestoppt',
            16 => 'Während des Ladens entriegelt',
            17 => 'Schieflage - Neustart nötig',
            18 => 'PIC24-Fehler',
            19 => 'USB-Stick wird verarbeitet',
            20 => 'Falsche Phasenfolge',
            21 => 'Keine Netzspannung',
        ];
        $dreh = static fn (int $w): int => (($w & 0xFF) << 8) | ($w >> 8);
        // Je Registerpaar erst die Woerter tauschen, dann je Wort die Bytes.
        // Belegt sind nur die Bits 0..21, also reicht das erste Paar.
        $gesamt = 0;
        if (isset($r[105], $r[106])) {
            $gesamt = ($dreh($r[106]) << 16) | $dreh($r[105]);
        }
        $liste = [];
        foreach ($masken as $bit => $text) {
            if ($gesamt & (1 << $bit)) {
                $liste[] = $text;
            }
        }
        return $liste;
    }
}
