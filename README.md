[![Version](https://img.shields.io/badge/Symcon-PHPModul-red.svg)](https://www.symcon.de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/)
![Version](https://img.shields.io/badge/Symcon%20Version-8.1%20%3E-blue.svg)
[![License](https://img.shields.io/badge/License-CC%20BY--NC--SA%204.0-green.svg)](https://creativecommons.org/licenses/by-nc-sa/4.0/)
[![Check Style](https://github.com/Schnittcher/TasmotaV2/actions/workflows/style.yml/badge.svg)](https://github.com/Schnittcher/TasmotaV2/actions/workflows/style.yml)
[![Tests](https://github.com/Schnittcher/TasmotaV2/actions/workflows/tests.yml/badge.svg)](https://github.com/Schnittcher/TasmotaV2/actions/workflows/tests.yml)

# Tasmota V2
Bindet Geräte mit Tasmota-Firmware über MQTT ein. Das Gerät beschreibt sich über die Discovery selbst, Sensoren und Energiewerte entstehen automatisch aus der Telemetrie.

## Inhaltsverzeichnis
- [Tasmota V2](#tasmota-v2)
  - [Inhaltsverzeichnis](#inhaltsverzeichnis)
  - [1. Voraussetzungen](#1-voraussetzungen)
  - [2. Funktionsumfang](#2-funktionsumfang)
  - [3. Enthaltene Module](#3-enthaltene-module)
  - [4. Installation](#4-installation)
  - [5. Konfiguration in IP-Symcon](#5-konfiguration-in-ip-symcon)
  - [6. Spenden](#6-spenden)
  - [7. Lizenz](#7-lizenz)

## 1. Voraussetzungen

* mindestens IPS Version 8.1
* MQTT-Server oder MQTT-Client in IP-Symcon
* Tasmota-Geräte mit eingerichtetem MQTT und aktiver Discovery (`SetOption19 0`, Standard)

## 2. Funktionsumfang
* Relais, Licht (Dimmer, Farbe, Farbtemperatur, Weiß), Rollläden, Taster und Schalter
* Sensoren und Energiewerte, auch für Sensoren ohne eigene Unterstützung
* Spezialhardware, die das Modul an der Selbstauskunft des Geräts erkennt, erweiterbar über den [Funktionskatalog](libs/data/features.json) (Beschreibung und Voraussetzungen in der [README des Moduls](TasmotaV2/README.md#27-spezialfunktionen-funktionskatalog)):
  * Fingerabdrucksensor AS608 mit Namen zu den IDs, Anlernen und Löschen
  * Lüfter Sonoff iFan02 und iFan03 mit Lüfterstufen
  * LED-Streifen WS2812 mit Pixelanzahl, Überblendung, Geschwindigkeit und Lichtprogrammen
  * IR-Empfänger mit Protokoll, Bits, Daten und Hash des zuletzt empfangenen Codes
  * Geräte mit Energiemessung, mit den Leistungsgrenzen (MaxPower)
  * Port-Erweiterungen MCP230xx und PCF8574 sowie PWM-Treiber PCA9685 (nur mit einer Firmware, die die Treiber enthält)
* Erreichbarkeit des Geräts und WLAN-Qualität
* Konfigurator, der Geräte über MQTT findet

Die einzelnen Funktionen stehen bei den Instanzen unter "Enthaltene Module" und in der README des jeweiligen Moduls.

## 3. Enthaltene Module

* [Tasmota V2](TasmotaV2/README.md)
  * Bindet ein Tasmota-Gerät ein und legt Variablen für Relais, Licht, Rollläden, Taster, Schalter, Sensoren und Energie an.
  * Erkennt Spezialhardware an der GPIO-Belegung und legt deren Variablen und Befehle an (Funktionskatalog).
  * Befehle für Skripte: beliebiger Tasmota-Befehl, Zustand abfragen, Discovery anfordern.
* [Tasmota Konfigurator V2](TasmotaConfiguratorV2/README.md)
  * Listet die Geräte, die ihre Discovery-Nachricht gesendet haben, und legt dafür Tasmota-V2-Instanzen an.

## 4. Installation
Die Installation erfolgt über den IP-Symcon Module Store.

## 5. Konfiguration in IP-Symcon
Zuerst den MQTT-Server oder den MQTT-Client einrichten. Danach den Tasmota Konfigurator V2 anlegen, ihn mit dem MQTT-Parent verbinden und mit "Geräte suchen" die Discovery der Geräte anfordern. Aus der Liste lassen sich Instanzen anlegen. Alternativ legt man eine Tasmota-V2-Instanz von Hand an und trägt das Topic ein.

Taster und Schalter am Gerät brauchen keine Einstellung. Nur wenn sie in IP-Symcon als Auslöser dienen sollen, schaltet man am Gerät `SetOption73` (Taster) und `SetOption114` (Schalter) ein. Dann schalten sie das Relais nicht mehr selbst, Einzelheiten stehen in der README des Moduls.

Die Module laufen unabhängig von der Bibliothek `IPS-Tasmota` und können parallel zu ihr betrieben werden.

## 6. Spenden
Dieses Modul ist für die nicht kommerzielle Nutzung kostenlos, Schenkungen als Unterstützung für den Autor werden hier akzeptiert:

[![PayPal](https://img.shields.io/badge/PayPal-Spenden-00457C?logo=paypal&logoColor=white&style=for-the-badge)](https://www.paypal.com/donate?hosted_button_id=EK4JRP87XLSHW) [![Amazon Wunschzettel](https://img.shields.io/badge/Amazon-Wunschzettel-FF9900?logo=amazon&logoColor=white&style=for-the-badge)](https://www.amazon.de/hz/wishlist/ls/3JVWED9SZMDPK?ref_=wl_share)

## 7. Lizenz

[CC BY-NC-SA 4.0](https://creativecommons.org/licenses/by-nc-sa/4.0/)
