# SymconMennekesAmtron

IP-Symcon-Modul für die Wallbox **Mennekes AMTRON Professional** (Ladecontroller
ECU). Es liest die Wallbox über das ModBus Gateway von IP-Symcon aus.

## Voraussetzungen

- IP-Symcon ab 8.0, getestet mit 9.0
- AMTRON im lokalen Netz, Modbus TCP an Port 502 eingeschaltet
- getestet mit Modell `CC612_2S0R_CC`, Firmware 5.22.5, Modbus-Protokoll 0.12

## Einrichtung

1. In der **Modulsteuerung** (Kern-Instanzen → Modules) diese URL hinzufügen:
   `https://github.com/HeikoSchroers/SymconMennekesAmtron`
2. Eine Instanz **Mennekes AMTRON** anlegen. Symcon legt dazu ein
   **ModBus Gateway** und einen **Client Socket** an.
3. In der Instanz eintragen:
   - Adresse der Wallbox
   - Port **502** und Unit-ID **1** (Vorgabe)
   - Abfrageintervall (Vorgabe 30 Sekunden)
4. **Änderungen übernehmen**. Die Instanz stellt das Gateway auf Modbus TCP
   und trägt Adresse und Port in den Client Socket ein.

Der Haken **Verbindung aktiv** öffnet bzw. schließt den Client Socket. Ohne
Haken gibt die Instanz die einzige Modbus-Verbindung der Wallbox frei (s. u.).

Gateway und Client Socket werden **nicht** von Hand eingestellt. Die Instanz
trägt ihre Werte selbst ein, und zwar nur, wenn sie abweichen. Ohne
Abweichung werden Gateway und Socket nicht neu angewendet; die Verbindung
bleibt dann bestehen. Im Gateway sind die Felder gesperrt, die von der
Instanz kommen. Den Socket sperrt Symcon nicht. Was dort von Hand geändert
wird, überschreibt die Instanz beim nächsten Speichern.

**Verbindung testen** (unten im Formular) prüft Gateway und Socket und liest
einmal alle Werte. Darüber steht, wann zuletzt erfolgreich gelesen wurde.

### Umstieg von Version 1.0

Bis 1.0 standen Adresse und Port im Client Socket und die Geräte-ID im
Gateway. Nach dem Update übernimmt jede bestehende Instanz diese Werte
einmalig in ihre eigenen Felder. Instanzen werden dabei nicht neu angelegt,
Variablen und Archiv bleiben. Gateway und Socket werden nur angewendet, wenn
ein Wert abweicht.

> **Wichtig: Die Wallbox nimmt nur EINE Modbus-TCP-Verbindung an.** Jede neue
> Verbindung wirft die vorige hinaus. Neben dieser Instanz darf deshalb kein
> anderer Leser laufen — kein Skript, keine zweite Instanz, kein Werkzeug am
> Rechner. Zwei Leser bedeuten nicht doppelte Daten, sondern dass sich beide
> gegenseitig die Verbindung abschneiden.

## Variablen

| Bereich | Variablen |
|---|---|
| Status | Verbindung, letzte Aktualisierung, Fahrzeugstatus (A–E), Ladepunkt-Status (OCPP), Stecker verriegelt, Ladepunkt verfügbar, Störung, Fehlermeldungen im Klartext |
| Messwerte | Zählerstand, Leistung, Spannung und Strom je Phase, Summenstrom |
| Ladevorgang | Sitzungsenergie und Steckdauer des Geräts, lädt gerade, Ladezeit und Energie des laufenden Vorgangs, Beginn, Ende, Dauer, Energie und Spitzenleistung des letzten Vorgangs, Ladebeginn/-ende/Abfahrt laut Fahrzeug, Energiebedarf, EVCCID, Ladekarte |
| Stromgrenzen | signalisierter Strom, Maximalstrom des Fahrzeugs, Mindeststrom, HEMS-Grenze, Sicherheitsstrom, Betreiber-Grenze, Kommunikations-Zeitlimit |
| Lastmanagement | Betriebsart, verbundene Slaves, verfügbarer und zugeteilter Strom je Phase |
| Gerät | Modellkennung, Firmware, Modbus-Protokollversion |

**Ladezeit selbst gemessen:** Das Geräteregister „Ladedauer" zählt in
Wirklichkeit die Steckdauer — es läuft weiter, solange der Wagen hängt, auch
wenn längst nichts mehr fließt, und steht nach dem Abstecken wieder auf 0. Das
Modul zählt deshalb selbst: Ladezeit ist nur die Zeit mit mehr als 100 W, die
Energie kommt aus dem Zählerstand. Ein Vorgang endet nach 15 Minuten ohne
Leistung oder sobald der Wagen abgesteckt ist.

Der Zählerstand wird nie auf 0 gesetzt. Eine 0 ist immer ein Lesefehler, und
das Archiv würde den ganzen Stand sonst neu zählen.

## Schreiben

Ab Werk liest die Instanz nur. Schreiben ist erst möglich, wenn in der
Instanz **Schreibzugriff erlauben** angehakt ist. Dann lässt sich die
HEMS-Stromgrenze bedienen, und diese Funktion ist freigegeben:

| Funktion | Register | Bedeutung |
|---|---|---|
| `AMTRON_SetCurrentLimit($id, $Ampere)` | 1000 | HEMS-Stromgrenze in A (0–32) |

> **Achtung:** Der Schreibweg ist am Gerät noch nicht erprobt. Laut
> Hersteller fällt die Wallbox auf den Sicherheitsstrom zurück, wenn
> innerhalb des Kommunikations-Zeitlimits kein neuer Wert kommt. Eine
> Überschussregelung muss die Grenze also regelmäßig neu schreiben.

## Eigenheiten des Geräts

- **Höchstens 20 Register je Anfrage.** Ab 25 kommt `illegal data address`,
  ab 32 `illegal function`.
- **Nur die eigenen Gruppengrenzen.** `100..112` wird abgelehnt, `100..101`
  und `104..112` gehen. Das Modul liest deshalb in 16 einzeln geprüften
  Blöcken.
- **Nur eine Verbindung** (siehe oben).
- 32-Bit-Werte stehen mit dem hohen Wort zuerst; `0xFFFFFFFF` heißt „kein
  Messwert". Ströme stehen in Milliampere.
- Register 124 („Ladepunkt verfügbar"): Laut Doku hat sich die Bedeutung mit
  Firmware 5.22 gedreht. Das geprüfte Gerät mit 5.22.5 meldet aber im
  Leerlauf und beim Laden 0 und folgt damit der alten Auslegung
  (0 = verfügbar).
- Die Fehlerregister 105ff. stehen mit getauschten Wörtern und Bytes.

## Quelle

Die Register stammen aus dem Herstellerdokument **MENNEKES „ECU-BRx and
ECU-BBx Modbus TCP Server Specification"**, Revision 1.07 vom 10.06.2022.
Jedes gelesene Register ist am Gerät geprüft. Register 740
(„Smart-Fahrzeug erkannt") steht in keiner Hersteller-Tabelle.

## Lizenz

MIT, siehe [LICENSE](LICENSE).
