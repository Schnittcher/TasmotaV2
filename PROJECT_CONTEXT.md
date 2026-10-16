# Projektkontext

## Zweck und Benutzer

- Projektzweck: Nachfolger der Bibliothek `IPS-Tasmota`. Das Gerät beschreibt sich über die Tasmota-Discovery (`tasmota/discovery/<MAC>/config`) selbst, Sensoren und Energiewerte entstehen generisch aus der Telemetrie.
- Hauptnutzer: Symcon-Anwender mit Tasmota-Geräten an einem MQTT-Server oder -Client.
- Nicht im Umfang: Zigbee, IR, RF, Displays, Tuya-spezifische Datenpunkte, Bluetooth. Die alte Bibliothek `IPS-Tasmota` bleibt eigenständig bestehen.

## Unterstützte Plattform

- IP-Symcon: 9.0 (Module laufen ab 8.1, `IPSModuleStrict`)
- PHP: 8.5
- Parent: MQTT-Server oder MQTT-Client (Datenfluss `{043EA491-0325-4ADD-8FC2-A30C8EEB4D3F}`)

## Einstiegspunkte

| Bereich | Datei oder Verzeichnis | Bedeutung |
| --- | --- | --- |
| Bibliothek | `library.json` | Eigene Bibliothek, Kompatibilität ab 8.1 |
| Gerät | `TasmotaV2/module.php` | Empfang, Variablenpflege, Befehle |
| Konfigurator | `TasmotaConfiguratorV2/module.php` | Geräteliste aus der Discovery |
| Parser | `libs/MessageParser.php` | Nachricht (stat/tele) zu Variablendatensätzen, reine PHP-Logik |
| Topics | `libs/TopicMapper.php` | FullTopic-Muster zu Topics und Empfangsfilter |
| Modell | `libs/DiscoveryModel.php` | Discovery-`config` als Fähigkeitsmodell |
| Befehle | `libs/CommandTable.php` | Ident zu Tasmota-Befehl |
| Darstellung | `libs/Presentation.php` | Presentation-Arrays für Symcon 9 |
| Funktionskatalog | `libs/FeatureCatalog.php`, `libs/data/features.json` | Erkennung von Spezialhardware über GPIO-Komponenten, Variablen, Ergebnisse, Befehle |
| Einheiten | `libs/data/sensor_units.json` | Einheit, Nachkommastellen und Beschriftung je Sensorwert |
| Tests | `tests/` | PHPUnit für `libs/`, `tests/bootstrap.php` definiert die Presentation-Konstanten |

## Architektur und Datenfluss

Das Gerät empfängt `stat/<Topic>/…` und `tele/<Topic>/…` sowie die Discovery. Die Discovery-Konfiguration liegt als Attribut in der Instanz. `MessageParser` verteilt nach den Schlüsseln der JSON-Nutzdaten (nicht nach Teilstrings des Payloads) in Datensätze, das Modul legt Variablen nur bei Bedarf an (Signatur im Attribut `Variables`). Befehle laufen über `CommandTable` zu `cmnd/<Topic>/<Befehl>`, asynchron. Nur `SendCommandWait` wartet: Es legt vor dem Senden einen Eintrag im Instanzpuffer an (Semaphore `TASV2Wait<ID>`), `ReceiveData` trägt die passende Antwort ein. Bei `IPSModuleStrict` ist die Payload im Datenfluss hex-kodiert.

## Funktionskatalog erweitern

Neue Hardware braucht nur einen Eintrag in `libs/data/features.json`, keinen Modulcode:

1. `detect.gpio`: GPIO-Komponentennamen aus der Antwort auf den Befehl `Gpio` (zum Beispiel `AS608 Rx`). Ein Treffer genügt.
2. `variables`: Ident (nur Buchstaben, Ziffern, Unterstrich), `caption` (englisch, Übersetzung in `locale.json` beider Module ergänzen), `type` (boolean, integer, float, string), `presentation.kind` (text, number, input, slider, toggle, color), optional `action` mit `command` und `payload` (Platzhalter `{value}`).
3. `results`: `path` im JSON einer Nachricht (`FPrint.Id`) und die Ziel-`variable`.
   Variablen, die erst mit den Daten entstehen (je Pin), beschreibt `dynamic`: `path` mit Platzhaltern (`*` beliebiger Text, `#` Zahl, zum Beispiel `PCF8574-#.D#`), `ident` und `caption` mit `{1}`, `{2}` für die Treffer, `type`, `presentation` (`kind` auch `status`), optional `action` mit `{1}` und `{value}` im `payload` und `boolean` (Texte für aus und an). Mit `keyField` und `valueField` wird aus einem Objekt wie `{"PIN":3,"PWM":100}` der Pin als `{1}` gelesen.
4. `init`: Abfragebefehle, die beim Start gesendet werden.
   Optional: `results[].scalarOnly` (nur Skalare übernehmen, zum Beispiel `NOMATCH`), `action.min` und `action.max` (Wertebereich einer bedienbaren Variable, schützt vor Werten mit Nebenwirkung wie `FpDelete 0`), `names` (`property`, `idVariable`, `nameVariable`: Namensliste in der Konfiguration, die zu einer erkannten ID den Namen liefert; die Liste braucht ein Element gleichen Namens im Formular).
5. Idents nie umbenennen, das ist ein Vertrag. Neue Einträge in `tests/FeatureCatalogTest.php` und die Modul-README (Abschnitt 2.7) aufnehmen.

Die Befehle und Ergebnisformate stammen aus der Tasmota-Doku zum jeweiligen Treiber und sind am Gerät zu prüfen. Der Katalog erkennt nur, was darin steht.

## Öffentliche und persistente Verträge

- Properties: `Topic`, `FullTopic`, `DiscoveryPrefix`, `AutoSensors`, `ExcludeSensors`, `SystemVariables`, `RetainCommands`, `ExtraFeatures`, `FingerprintNames` (Gerät); `DiscoveryPrefix`, `GroupTopic` (Konfigurator)
- Idents: siehe Modul-README, Abschnitt Variablen
- Öffentliche Methoden: `TASV2_SendCommand`, `TASV2_SendCommandWait` (wartet auf die Antwort; Zuordnung in `libs/ResponseMatcher.php`), `TASV2_RequestState`, `TASV2_RequestInitialState` (Timer), `TASV2_RequestDiscovery`, `TASV2C_Search`
- Attribute: `Model` (Discovery-JSON), `Variables` (Ident zu Signatur), `Features` (erkannte Funktionen aus dem Katalog)

## Prüfung und Veröffentlichung

- Lokale Prüfungen: `symcon-check` im Repo-Ordner
- Notwendige Praxistests: `symcon-dev` mit echten Geräten (Relais, Licht, Rollladen, Energie, Taster, Schalter), Retain und Discovery-Auslieferung des MQTT-Servers
- Branch-Besonderheiten: keine

## Bekannte Risiken und offene Fragen

- Mit echten Geräten geprüft sind nur Relais, WLAN, Energie, Leistungsgrenzen, PowerOnState, Online-Status, Discovery, Fingerabdruck (ohne echten Finger) und `SendCommandWait` (vier Geräte mit Release-Firmware). Alles andere ist nur mit synthetischen Nachrichten geprüft und aus Tasmota-Doku, Quelltext oder Altmodul abgeleitet: Rollläden (Payload `Shutter<n>`, `ShutterLock`, Anzeige 100 = offen), Licht (`lt_st`, Farbe über `HSBColor`, Farbtemperatur), Taster und Schalter, iFan, IR-Empfänger, LED-Streifen, Port-Erweiterungen und PCA9685 (die Meldung `PCA9685.PIN/PWM` stammt aus dem Altmodul).
- Annahmen ohne Beleg in der Doku: Wertebereich der Leistungsgrenzen (0 bis 65535), Verhalten des Geräts bei PowerOnState 4, Format der Antwort auf `ShutterLock<n>` ohne Wert.
- Nachrichten: Retained Discovery erreicht eine neu angelegte Instanz nicht von selbst. Die Instanz fordert sie mit `Discover 1` an, wenn `SetOption19` am Gerät `OFF` ist. Ob jedes Gerät sie beim Verbinden sendet, ist nicht für alle geprüft.
- Eigene Firmware mit Netzwerk-Lichtprogrammen (DDP) verschiebt die Lichtprogramm-Nummern, die Auswahl zeigt dann falsche Namen.
- Die PHPUnit-Tests unter `tests/` decken nur die reine Logik in `libs/` ab (Parser, Topic-Abbildung, Discovery-Modell, Befehlstabelle) mit synthetischen Payloads aus der Tasmota-Doku, nicht die Module selbst und nicht das Verhalten echter Geräte. Aufruf: `phpunit --bootstrap tests/bootstrap.php tests` oder `symcon-check`. Stubs werden nicht gebraucht.
