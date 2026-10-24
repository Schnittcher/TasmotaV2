# Changelog

## 25.10.2026 - Version 1.0.1

### Neu
- Das Anwesenheitsradar HLK-LD2410 wird erkannt und bekommt Entfernungen, Energien, Haltezeit und Empfindlichkeit als Variablen. Die Werte für Haltezeit und Empfindlichkeit sind steuerbar, ungültige Eingaben werden nicht gesendet. Es ist noch nicht an einem echten Gerät geprüft.

### Fixes
- Variablen aus Listen von Sensorwerten tragen jetzt eine Nummer in der Beschriftung (zum Beispiel "Distance 1" bis "Distance 3") und heißen nicht mehr alle gleich.

## 16.10.2026 - Version 1.0 (Beta Version)

### Neu
- Erste Fassung: Relais, Licht, Rollläden, Taster, Schalter, Sensoren, Energie und Erreichbarkeit, gesteuert durch die Discovery des Geräts.
- Sensoren und Energiewerte entstehen automatisch aus der Telemetrie, auch für Sensoren ohne eigene Unterstützung.
- Spezialhardware wird an der GPIO-Belegung erkannt und bekommt ihre Variablen und Befehle automatisch. Im Funktionskatalog stehen der Lüfter Sonoff iFan02/iFan03 (Lüfterstufe 0 bis 3) und der Fingerabdrucksensor AS608 mit ID, Name zur ID (Liste in der Instanz), Treffergenauigkeit, Anzahl sowie Anlernen und Löschen.
- Neue Instanzen fragen beim Anlegen sofort den kompletten Zustand und die Hardware des Geräts ab, auch wenn sie über den Konfigurator angelegt werden.
- Mit der Option "Systemvariablen" zeigt die Instanz auch Modul und Group-Topic des Geräts.
- Port-Erweiterungen MCP230xx und PCF8574 sowie der PWM-Treiber PCA9685 werden an ihren Meldungen erkannt. Je Pin entsteht eine Variable, Ausgänge und PWM-Kanäle sind steuerbar.
- LED-Streifen (WS2812) werden an der GPIO-Belegung erkannt und bekommen Pixelanzahl, Überblendung, Geschwindigkeit und eine Auswahl der Lichtprogramme (Schema). Das Schema ist bei allen Lichtern eine Auswahl statt einer Zahl.
- Neue Funktion `TASV2_SendCommandWait`: sendet einen Befehl und wartet auf die Antwort des Geräts, für Skripte, die das Ergebnis sofort brauchen.
- Geräte mit IR-Empfänger bekommen automatisch Variablen für Protokoll, Bits, Daten und Hash des zuletzt empfangenen IR-Codes.
- Geräte mit Energiemessung bekommen automatisch die steuerbaren Variablen für die Leistungsgrenzen (MaxPower, MaxPowerHold, MaxPowerWindow).
- Rollläden haben eine Variable "Sperre" (ShutterLock), steuerbar.
- Neue Variable "Zustand beim Einschalten" (PowerOnState) für jedes Gerät, steuerbar mit den Werten 0 bis 5. Achtung: Wert 4 sperrt das Schalten der Relais.
- Der Konfigurator findet Geräte über MQTT, ohne einen IP-Bereich zu durchsuchen.
