# Tasmota Konfigurator V2
Listet alle Tasmota-Geräte, die ihre Discovery-Nachricht (`tasmota/discovery/<MAC>/config`) über MQTT gesendet haben, und legt dafür Tasmota-V2-Instanzen an. Topic und Full-Topic übernimmt der Konfigurator vom Gerät. Es werden keine IP-Bereiche durchsucht.

## Inhaltsverzeichnis
- [Tasmota Konfigurator V2](#tasmota-konfigurator-v2)
  - [Inhaltsverzeichnis](#inhaltsverzeichnis)
  - [1. Konfiguration](#1-konfiguration)
  - [2. Funktionen](#2-funktionen)
  - [3. Spenden](#3-spenden)
  - [4. Lizenz](#4-lizenz)

## 1. Konfiguration

Feld | Beschreibung
------------ | ----------------
Discovery-Präfix | Präfix der Discovery-Nachrichten, Standard `tasmota/discovery`.
Group-Topic | Group-Topic, an das "Geräte suchen" den Befehl `Discover` sendet, Standard `tasmotas`.

Die Liste füllt sich, sobald Geräte ihre Discovery-Nachricht senden. Das tun sie beim Start, beim Verbinden mit dem Broker und nach dem Befehl `Discover`. Mit "Geräte suchen" fordert der Konfigurator alle Geräte der Gruppe dazu auf. Geräte mit eigenem Group-Topic lassen sich in der Geräteinstanz über "Discovery anfordern" anstoßen.

## 2. Funktionen

`boolean TASV2C_Search(integer $InstanzID);`
Sendet `Discover 1` an das Group-Topic, damit die Geräte ihre Discovery-Nachricht erneut senden.

## 3. Spenden
Dieses Modul ist für die nicht kommerzielle Nutzung kostenlos, Schenkungen als Unterstützung für den Autor werden hier akzeptiert:

[![PayPal](https://img.shields.io/badge/PayPal-Spenden-00457C?logo=paypal&logoColor=white&style=for-the-badge)](https://www.paypal.com/donate?hosted_button_id=EK4JRP87XLSHW) [![Amazon Wunschzettel](https://img.shields.io/badge/Amazon-Wunschzettel-FF9900?logo=amazon&logoColor=white&style=for-the-badge)](https://www.amazon.de/hz/wishlist/ls/3JVWED9SZMDPK?ref_=wl_share)

## 4. Lizenz

[CC BY-NC-SA 4.0](https://creativecommons.org/licenses/by-nc-sa/4.0/)
