# Tasmota Device V2
Bindet ein Tasmota-Gerät über MQTT ein. Die Instanz liest die Discovery-Nachricht des Geräts (`tasmota/discovery/<MAC>/config`) und legt daraus Relais, Licht, Rollläden, Taster und Schalter an. Sensoren und Energiewerte entstehen automatisch aus der Telemetrie. Normalerweise wird die Instanz über den Tasmota Konfigurator V2 angelegt.

Voraussetzung am Gerät: MQTT ist eingerichtet und die Discovery ist aktiv (`SetOption19 0`, Standard). Weitere Einstellungen am Gerät sind nicht nötig. Nur wenn Taster oder Schalter am Gerät in IP-Symcon als Auslöser dienen sollen, gibt es dafür zwei optionale Einstellungen (siehe [2.4](#24-taster-und-schalter)).

## Inhaltsverzeichnis
- [Tasmota V2](#tasmota-v2)
  - [Inhaltsverzeichnis](#inhaltsverzeichnis)
  - [1. Konfiguration](#1-konfiguration)
  - [2. Variablen](#2-variablen)
    - [2.1 Allgemein](#21-allgemein)
    - [2.2 Relais und Licht](#22-relais-und-licht)
    - [2.3 Rollläden](#23-rollläden)
    - [2.4 Taster und Schalter](#24-taster-und-schalter)
    - [2.5 Energie und Sensoren](#25-energie-und-sensoren)
    - [2.6 Systemvariablen](#26-systemvariablen)
    - [2.7 Spezialfunktionen (Funktionskatalog)](#27-spezialfunktionen-funktionskatalog)
  - [3. Funktionen](#3-funktionen)
  - [4. Spenden](#4-spenden)
  - [5. Lizenz](#5-lizenz)

## 1. Konfiguration

Feld | Beschreibung
------------ | ----------------
Topic | Topic des Geräts (Tasmota-Befehl `Topic`). Pflichtfeld.
Full-Topic | Full-Topic-Muster des Geräts, Standard `%prefix%/%topic%/`. Der Konfigurator übernimmt es vom Gerät.
Discovery-Präfix | Präfix der Discovery-Nachrichten, Standard `tasmota/discovery`.
Sensorvariablen automatisch anlegen | Legt für jeden Wert in der Telemetrie (`SENSOR`) eine Variable an. Standard: an.
Sensoren ignorieren | Kommagetrennte Sensornamen, die keine Variablen bekommen, zum Beispiel `ANALOG`.
Systemvariablen | Zusätzlich Laufzeit, freier Speicher, WLAN-Details und Version. Standard: aus.
Befehle mit Retain-Flag senden | Setzt beim Senden das Retain-Flag. Achtung: Ein retained Schaltbefehl am Broker überschreibt `PowerOnState` des Geräts. Standard: aus.
Fingerabdruck-Namen | Liste mit ID (1 bis 128) und Name. Die Variable `FingerprintName` zeigt den Namen des zuletzt erkannten Fingers, bei unbenannten IDs "Unbekannt (ID n)". Namen lassen sich jederzeit ergänzen, die Variable folgt nach dem Speichern. Die Liste erscheint nur, wenn die Instanz den Fingerabdrucksensor erkannt hat oder er unter "Zusätzliche Funktionen" steht. Die Zeile "Discovery" zeigt, ob die Discovery-Nachricht des Geräts angekommen ist (mit Modell und Firmware), und die Zeile "Erkannte Funktionen", was die Instanz am Gerät gefunden hat.
Zusätzliche Funktionen | Kommagetrennte Kennungen aus dem Funktionskatalog (zum Beispiel `fingerprint`), die zusätzlich zu den erkannten angelegt werden. Nur nötig, wenn die Erkennung über die GPIO-Belegung nicht greift.

## 2. Variablen
Variablen entstehen, sobald das Gerät sie ankündigt oder ein Telegramm sie enthält. Die Idents bleiben bei einer Umbenennung gleich.

### 2.1 Allgemein

Variable | Ident | Typ | Beschreibung
------------ | ------------ | ------------ | ----------------
Erreichbar | `Online` | Ja/Nein | Ob das Gerät erreichbar ist, aus dem Last-Will des Geräts (`LWT`). Telemetrie und Antworten auf Status-Abfragen setzen die Variable ebenfalls auf erreichbar. Der Ident `Online` bleibt aus technischen Gründen bestehen.
WLAN-Qualität | `WifiRSSI` | Ganzzahl (%) | Qualität der WLAN-Verbindung.
WLAN-Signal | `WifiSignal` | Ganzzahl (dBm) | Signalstärke.
Zustand beim Einschalten | `PowerOnState` | Ganzzahl | Steuerbar: Relaiszustand nach dem Einschalten der Versorgung. 0 bleibt aus, 1 schaltet ein, 2 wechselt den gespeicherten Zustand, 3 stellt ihn wieder her (Standard von Tasmota), 4 schaltet ein und sperrt die weitere Bedienung der Relais, 5 schaltet nach Ablauf von `PulseTime` ein (umgekehrte Pulszeit). Achtung: Laut Tasmota-Doku sperrt 4 die weitere Bedienung der Relais. Ob sich die Relais und dieser Wert danach noch über IP-Symcon ändern lassen, ist nicht geprüft. Sicher zurücksetzen lässt sich der Wert im Webinterface des Geräts.

### 2.2 Relais und Licht

Variable | Ident | Typ | Beschreibung
------------ | ------------ | ------------ | ----------------
Schalter n | `Power<n>` | Ja/Nein | Relais n oder Licht, steuerbar. Ein einzelnes Relais ist `Power1`.
Dimmer | `Dimmer` | Ganzzahl (%) | Helligkeit, steuerbar.
Farbe | `Color` | Ganzzahl (RGB) | Farbe, steuerbar. Die Helligkeit bleibt beim Ändern der Farbe erhalten.
Farbtemperatur | `CT` | Ganzzahl (mired) | 153 bis 500, steuerbar.
Weiß | `White` | Ganzzahl (%) | Weißkanal, steuerbar.
Überblenden | `Fade` | Ja/Nein | steuerbar.
Überblendgeschwindigkeit | `Speed` | Ganzzahl | 1 bis 40, steuerbar.
Schema | `Scheme` | Ganzzahl | steuerbar.
Kanal n | `Channel<n>` | Ganzzahl (%) | Einzelne PWM-Kanäle (`SetOption68`), steuerbar.

### 2.3 Rollläden

Variable | Ident | Typ | Beschreibung
------------ | ------------ | ------------ | ----------------
Rollladen n Position | `Shutter<n>Position` | Ganzzahl (%) | 100 = offen, 0 = geschlossen (ohne `ShutterInvert`), steuerbar.
Rollladen n Richtung | `Shutter<n>Direction` | Ganzzahl | -1 schließt, 0 steht, 1 öffnet.
Rollladen n Lamellenwinkel | `Shutter<n>Tilt` | Ganzzahl | Nur bei Geräten mit Lamellen, steuerbar.
Rollladen n Sperre | `Shutter<n>Lock` | Ja/Nein | Steuerbar: gesperrt, dann bewegen Taster und Befehle den Rollladen nicht (`ShutterLock<n>`).
Rollladen n Steuerung | `Shutter<n>Control` | Ganzzahl | Auf, Stopp, Zu. Steht im Ruhezustand auf Stopp.

### 2.4 Taster und Schalter

**Optional, nur wenn man es braucht.** Ein Taster oder Schalter am Gerät schaltet normalerweise direkt das Relais, und das Modul zeigt nur den neuen Zustand (`Power<n>`). Das genügt für die meisten Geräte, es muss nichts eingestellt werden.

Soll IP-Symcon dagegen auf Taster oder Schalter am Gerät reagieren, zum Beispiel mit einer Szene bei Doppelklick oder einem Befehl bei langem Drücken, gibt es am Gerät zwei Einstellungen:

Einstellung am Gerät | Wirkung | Variable
------------ | ------------ | ----------------
`SetOption73 1` | Taster melden jede Aktion einzeln (`SINGLE`, `DOUBLE`, `TRIPLE`, `QUAD`, `PENTA`, `HOLD`, `CLEAR`) | `Button<n>`
`SetOption114 1` | Schalter melden ihre Aktion nur über MQTT (`ON`, `OFF`, `TOGGLE`, `HOLD`) | `Switch<n>`

Achtung: Mit diesen Einstellungen sind Taster oder Schalter vom Relais getrennt und schalten es nicht mehr selbst. Das Relais schaltet dann nur noch, wenn IP-Symcon (oder eine Regel im Gerät) darauf reagiert. Fällt IP-Symcon oder der MQTT-Broker aus, bleibt der Taster am Gerät wirkungslos. Ohne die Einstellungen legt das Modul keine Taster- und Schaltervariablen an.

Variable | Ident | Typ | Beschreibung
------------ | ------------ | ------------ | ----------------
Taster n | `Button<n>` | Text | Letzte Aktion: `SINGLE`, `DOUBLE`, `TRIPLE`, `QUAD`, `PENTA`, `HOLD`, `CLEAR`. Nur mit `SetOption73 1`.
Schalter-Eingang n | `Switch<n>` | Text | Letzte Aktion oder Zustand (`ON`, `OFF`, `TOGGLE`, `HOLD`). Nur mit `SetOption114 1` oder `SwitchMode 15/16`.

### 2.5 Energie und Sensoren

Variable | Ident | Typ | Beschreibung
------------ | ------------ | ------------ | ----------------
Energiewerte | `Energy_<Wert>` | Kommazahl | Leistung, Strom, Spannung, Zähler und weitere Werte aus `ENERGY`. Mehrphasige Geräte liefern `Energy_<Wert>_1`, `_2` und so weiter.
Sensorwerte | `Sensor_<Sensor>_<Wert>` (die Variablen entstehen schon aus der Discovery-Nachricht `sensors`, ihre Werte erst aus der Telemetrie) | Kommazahl, Text | Jeder Wert aus der Telemetrie, zum Beispiel `Sensor_BME280_Temperature`. Einheit und Nachkommastellen kommen aus einer Tabelle, unbekannte Werte erscheinen ohne Einheit. Pro Instanz werden höchstens 400 Variablen angelegt.

### 2.6 Systemvariablen
Nur mit der Option "Systemvariablen".

Variable | Ident | Typ | Beschreibung
------------ | ------------ | ------------ | ----------------
Laufzeit | `System_Uptime`, `System_UptimeSec` | Text, Ganzzahl | Laufzeit des Geräts.
Freier Speicher, Auslastung, Versorgungsspannung | `System_Heap`, `System_LoadAvg`, `System_Vcc` | Zahl | Zustand des Geräts.
Version, Hostname, IP-Adresse | `System_Version`, `System_Hostname`, `System_IPAddress` | Text | Geräteinformationen.
Modul | `System_Module` | Text | Name des Moduls oder Templates (zum Beispiel "Sonoff Basic"), aus der Discovery.
Group-Topic | `System_GroupTopic` | Text | Gruppen-Topic des Geräts, aus der Antwort auf `Status 0`.
WLAN | `System_WifiSSId`, `System_WifiBSSId`, `System_WifiMode`, `System_WifiChannel` | Text, Ganzzahl | Verbindungsdaten.

### 2.7 Spezialfunktionen (Funktionskatalog)
Hardware, die die Discovery nicht beschreibt, erkennt die Instanz an der GPIO-Belegung des Geräts oder, beim Sonoff iFan, an einem Kennzeichen der Discovery (Befehl `Gpio`, wird beim Start und bei "Zustand abfragen" gesendet). Für jede erkannte Funktion legt sie Variablen an, wertet deren Ergebnisse aus und ordnet bedienbaren Variablen den Befehl zu. Der Katalog liegt in `libs/data/features.json`.

Unterstützte Spezialhardware:
* Fingerabdrucksensor AS608
* Lüfter Sonoff iFan02 und iFan03
* LED-Streifen WS2812 (adressierbare LEDs)
* IR-Empfänger
* Geräte mit Energiemessung (Leistungsgrenzen)
* Port-Erweiterungen MCP230xx und PCF8574
* PWM-Treiber PCA9685

Funktion | Kennung | Erkennung | Voraussetzung am Gerät
------------ | ------------ | ---------------- | ----------------
Zustand beim Einschalten | `poweronstate` | für jedes Gerät | keine
Fingerabdrucksensor AS608 | `fingerprint` | GPIO `AS608 Rx` oder `AS608 Tx` | Firmware mit AS608-Treiber, GPIOs belegt
Lüfter Sonoff iFan02/iFan03 | `ifan` | Kennzeichen `if` in der Discovery | Gerät mit dem iFan-Modul
IR-Empfänger | `irreceive` | GPIO `IRrecv` | GPIO `IRrecv` ist belegt
LED-Streifen WS2812 | `ws2812` | GPIO `WS2812` | GPIO `WS2812` ist belegt
Port-Erweiterung MCP230xx | `mcp230xx` | Daten `MCP230XX`, `MCP230XX_INT`, `MCP230_OUT` oder `S29cmnd_D<n>` | Eigene Firmware mit `USE_MCP230xx`
Port-Erweiterung PCF8574 | `pcf8574` | Daten `PCF8574-<n>` oder `PCF8574-<n>_INP` | Eigene Firmware mit `USE_PCF8574`, für die Pinwerte `USE_PCF8574_SENSOR`, für Ereignisse `USE_PCF8574_MQTTINPUT`
PWM-Treiber PCA9685 | `pca9685` | Daten `PCA9685` | Eigene Firmware mit `USE_PCA9685`
Leistungsgrenzen | `powerlimit` | Das Gerät liefert Energiewerte (`ENERGY`) in der Telemetrie oder in der Discovery-Nachricht `sensors`, wird dann dauerhaft gemerkt | Energiemessung am Gerät

Die Treiber der Port-Erweiterungen und des PCA9685 sind laut Tasmota-Doku nicht in den vorkompilierten Firmwares enthalten.

Variable | Ident | Typ | Beschreibung
------------ | ------------ | ------------ | ----------------
MCP230XX Pin D<n> | `MCP230XX_D<n>` | Ja/Nein | Zustand des Pins (0 bis 15), aus der Telemetrie und aus Interrupt-Meldungen. Die Variable entsteht, sobald das Gerät den Pin meldet.
MCP230XX Zeit seit dem letzten Interrupt | `MCP230XXIntervalMs` | Ganzzahl (ms) | Aus `MCP230XX_INT`.
MCP230XX Ausgang D<n> | `MCP230XX_Out<n>` | Ja/Nein | Ausgangspin, steuerbar (`Sensor29 <n>,ON|OFF`). Der Zustand kommt aus der Antwort `S29cmnd_D<n>` und aus `MCP230_OUT`.
PCF8574-<c> Pin D<n> | `PCF8574_<c>_D<n>` | Ja/Nein | Pinzustand des Chips c (1 bis 4), nur lesbar. Die Ausgänge der Chips sind zusätzlich Power-Schalter (`Power<n>`).
PCA9685 PWM Pin <n> | `PCA9685_PWM<n>` | Ganzzahl | PWM-Wert (0 bis 4096) des Pins n (0 bis 15), steuerbar (`driver15 pwm,<n>,<Wert>`). Entsteht, sobald das Gerät den Pin meldet.
Pixel | `Pixels` | Ganzzahl | Nur mit LED-Streifen: Anzahl der Pixel (1 bis 512), steuerbar.
Überblendgeschwindigkeit | `Speed` | Ganzzahl | Nur mit LED-Streifen im Voraus, sonst sobald das Gerät sie meldet: 1 (schnell) bis 40 (langsam), steuerbar.
Überblenden | `Fade` | Ja/Nein | steuerbar.
Schema | `Scheme` | Ganzzahl | Auswahl des Lichtprogramms, steuerbar: 0 einfarbig, 1 Aufwachen, 2 und 3 Farbwechsel, 4 Zufall; mit LED-Streifen zusätzlich 5 Uhr, 6 Kerze, 7 RGB-Muster, 8 Weihnachten, 9 Chanukka, 10 Kwanzaa, 11 Regenbogen, 12 Feuer, 13 Treppe, 14 Löschen. Achtung: Nur eine eigene Firmware mit Netzwerk-Lichtprogrammen (DDP, kompiliert mit `USE_NETWORK_LIGHT_SCHEMES`) verschiebt die Nummern, dort passen die Namen der Auswahl nicht mehr (laut Tasmota-Doku werden die Pixel-Effekte zu 6 bis 15). In der Standard-Firmware gelten die hier genannten Nummern.
IR-Protokoll | `IRProtocol` | Text | Nur mit IR-Empfänger: Protokoll des zuletzt empfangenen Codes (zum Beispiel `NEC`).
IR-Bits | `IRBits` | Ganzzahl | Nur mit IR-Empfänger: Länge des Codes.
IR-Daten | `IRData` | Text | Nur mit IR-Empfänger: Code, zum Beispiel `0x00FF00FF`.
IR-Hash | `IRHash` | Text | Nur mit IR-Empfänger, falls das Gerät einen Hash mitsendet.
Leistungsgrenze | `MaxPower` | Ganzzahl (W) | Nur bei Energiemessung, steuerbar (`MaxPower`): Leistungsgrenze in Watt. Was das Gerät bei Überschreitung tut, steht in der Tasmota-Doku zu `MaxPower`.
Haltezeit der Leistungsgrenze | `MaxPowerHold` | Ganzzahl (s) | Nur bei Energiemessung, steuerbar (`MaxPowerHold`).
Zeitfenster der Leistungsgrenze | `MaxPowerWindow` | Ganzzahl (s) | Nur bei Energiemessung, steuerbar (`MaxPowerWindow`).
Lüfterstufe | `FanSpeed` | Ganzzahl | Nur iFan: 0 (aus) bis 3, steuerbar. Die Lampe des iFan ist `Power1`, die Lüfterrelais erscheinen nicht als Schalter.
Letzte Fingerabdruck-ID | `FingerprintId` | Ganzzahl | ID des zuletzt erkannten Fingerabdrucks.
Name des letzten Fingerabdrucks | `FingerprintName` | Text | Name zur ID aus der Liste "Fingerabdruck-Namen".
Treffergenauigkeit des Fingerabdrucks | `FingerprintConfidence` | Ganzzahl | Genauigkeit des letzten Treffers.
Gespeicherte Fingerabdrücke | `FingerprintCount` | Ganzzahl | Anzahl im Sensor, wird beim Start abgefragt (`FpCount`).
Fingerabdruck-Status | `FingerprintStatus` | Text | Antwort auf `FpEnroll` und `FpDelete`; `NOMATCH`, wenn ein Finger nicht erkannt wurde.
Fingerabdruck anlernen (ID) | `FingerprintEnroll` | Ganzzahl | Steuerbar: sendet `FpEnroll <ID>`, erlaubt sind 0 (Anlernen abbrechen) bis 128.
Fingerabdruck löschen (ID) | `FingerprintDelete` | Ganzzahl | Steuerbar: sendet `FpDelete <ID>`, erlaubt sind 1 bis 128 (`FpDelete 0` würde alle Abdrücke löschen und wird nicht gesendet).

## 3. Funktionen

`boolean TASV2_SendCommand(integer $InstanzID, string $Command, string $Payload);`
Sendet einen beliebigen Tasmota-Befehl an `cmnd/<Topic>/<Command>`, zum Beispiel `TASV2_SendCommand($id, 'Backlog', 'Power1 ON; Delay 20; Power1 OFF');`. Die Antwort kommt asynchron und wird, soweit bekannt, in die Variablen übernommen.

Die Treiber der Port-Erweiterungen sind in den vorkompilierten Tasmota-Firmwares nicht enthalten, die Variablen entstehen deshalb nur bei einer eigenen Firmware. Eingänge werden gemeldet, wenn sich ein Pin ändert (Interrupt), oder mit der Telemetrie. Die Einstellung der Pins ist am Gerät nötig, zum Beispiel:

```php
TASV2_SendCommand($id, 'Sensor29', '4,5');                // MCP230xx: Pin 4 als Ausgang
TASV2_SendCommand($id, 'driver15', 'pwmf,200');           // PCA9685: PWM-Frequenz
```

Beispiel für einzelne Pixel eines LED-Streifens (`Led<x>`, Farbe als `#RRGGBB`), Syntax laut Tasmota-Doku:

```php
TASV2_SendCommand($id, 'Led3', '#FF0000');           // Pixel 3 rot
TASV2_SendCommand($id, 'Led1', '#00FF00 #0000FF');   // Pixel 1 grün, Pixel 2 blau
```

Beispiele für IR-Sender (GPIO `IRsend`), Syntax laut Tasmota-Doku:

```php
// Code senden (Standard-Firmware: NEC, RC5, RC6), Schreibweise wie in der Tasmota-Doku
TASV2_SendCommand($id, 'IRSend', '{"Protocol":"NEC","Bits":32,"Data":0x8166817E}');

// Klimaanlage (Tasmota-IR-Build)
TASV2_SendCommand($id, 'IRHVAC', '{"Vendor":"Mitsubishi_Heavy_152","Power":"On","Mode":"Cool","FanSpeed":3,"Temp":22}');
```
`string TASV2_SendCommandWait(integer $InstanzID, string $Command, string $Payload, integer $Timeout);`
Sendet einen Tasmota-Befehl wie `TASV2_SendCommand` und **wartet auf die Antwort des Geräts**. Die Antwort kommt als Text (JSON) zurück. Ohne Antwort innerhalb von `$Timeout` Millisekunden (100 bis 30000), bei unbekanntem Befehl oder wenn die MQTT-Verbindung nicht aktiv ist, ist der Rückgabewert ein leerer Text. Den Timeout muss man bei dieser Funktion immer angeben, empfohlen sind 3000.

Wofür sie gedacht ist:
- **Einstellungen lesen**, bevor ein Skript sie ändert, zum Beispiel `PowerOnState`, `TelePeriod` oder ein `SetOption`.
- **Abfragen ohne Variable**, zum Beispiel Firmware (`Status 2`), Netzwerk (`Status 5`), `Template` oder `Gpio`.
- **Befehle prüfen**: ob das Gerät den Befehl angenommen hat und welchen Wert es jetzt hat.
- **Konfigurationsskripte**, die mehrere Befehle nacheinander senden und jeweils das Ergebnis brauchen.

Für das einfache Schalten und Bedienen genügt `TASV2_SendCommand` oder die Bedienung der Variablen. `TASV2_SendCommandWait` blockiert das aufrufende Skript, bis die Antwort da ist, und sollte deshalb nicht in Ereignissen verwendet werden, die oft auslösen. Befehle, auf die das Gerät nicht antwortet (zum Beispiel `ShutterOpen1`), laufen bis zum Timeout. Es zählt nur eine Antwort, die zum Befehl passt: Das ist eine Nachricht `stat/<Topic>/RESULT` mit dem Befehlsnamen als Schlüssel (oder `STATUS<n>` bei `Status`).

```php
$antwort = TASV2_SendCommandWait($id, 'PowerOnState', '', 3000);
if ($antwort !== '') {
    $wert = json_decode($antwort, true)['PowerOnState'] ?? null; // zum Beispiel 3
}

// Firmware des Geräts
$firmware = json_decode(TASV2_SendCommandWait($id, 'Status', '2', 3000), true)['StatusFWR']['Version'] ?? '';
```

`boolean TASV2_RequestState(integer $InstanzID);`
Fragt den Zustand des Geräts ab (`Status 0`), die Hardware (`Gpio`) und die Startwerte der erkannten Funktionen. Läuft automatisch beim Start, beim Verbinden der Instanz mit dem MQTT-Parent (auch beim Anlegen über den Konfigurator) und wenn die MQTT-Verbindung aktiv wird. Fehlt noch die Discovery-Nachricht des Geräts und sendet das Gerät sie (`SetOption19 0`), fordert die Instanz sie dabei selbst an.

`void TASV2_RequestInitialState(integer $InstanzID);`
Wird vom internen Timer einmal 8 Sekunden nach dem Start oder Neuladen aufgerufen und fragt den Zustand ab, falls der MQTT-Parent inzwischen aktiv ist. Der Aufruf von Hand ist nicht nötig.

`boolean TASV2_RequestDiscovery(integer $InstanzID);`
Veranlasst das Gerät, seine Discovery-Nachricht erneut zu senden (Befehl `Discover 1`). Das ist nötig, wenn die Instanz neu angelegt wurde und die einmalig gesendete Nachricht verpasst hat.

## 4. Spenden
Dieses Modul ist für die nicht kommerzielle Nutzung kostenlos, Schenkungen als Unterstützung für den Autor werden hier akzeptiert:

[![PayPal](https://img.shields.io/badge/PayPal-Spenden-00457C?logo=paypal&logoColor=white&style=for-the-badge)](https://www.paypal.com/donate?hosted_button_id=EK4JRP87XLSHW) [![Amazon Wunschzettel](https://img.shields.io/badge/Amazon-Wunschzettel-FF9900?logo=amazon&logoColor=white&style=for-the-badge)](https://www.amazon.de/hz/wishlist/ls/3JVWED9SZMDPK?ref_=wl_share)

## 5. Lizenz

[CC BY-NC-SA 4.0](https://creativecommons.org/licenses/by-nc-sa/4.0/)
