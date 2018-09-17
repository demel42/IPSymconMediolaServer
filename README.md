# IPSymconMediolaServer

[![IPS-Version](https://img.shields.io/badge/Symcon_Version-5.0-red.svg)](https://www.symcon.de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/)
![Module-Version](https://img.shields.io/badge/Modul_Version-0.9-blue.svg)
![Code](https://img.shields.io/badge/Code-PHP-blue.svg)
[![License](https://img.shields.io/badge/License-CC%20BY--NC--SA%204.0-green.svg)](https://creativecommons.org/licenses/by-nc-sa/4.0/)
[![StyleCI](https://github.styleci.io/repos/126683101/shield?branch=master)](https://github.styleci.io/repos/149141172)

## Dokumentation

**Inhaltsverzeichnis**

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Installation](#3-installation)
4. [Funktionsreferenz](#4-funktionsreferenz)
5. [Konfiguration](#5-konfiguration)
6. [Anhang](#6-anhang)
7. [Versions-Historie](#7-versions-historie)

## 1. Funktionsumfang

## 2. Voraussetzungen

 - IP-Symcon ab Version 5

## 3. Installation

Die Konsole von IP-Symcon öffnen. Im Objektbaum unter Kerninstanzen die Instanz __*Modules*__ durch einen doppelten Mausklick öffnen.

In der _Modules_ Instanz rechts oben auf den Button __*Hinzufügen*__ drücken.

In dem sich öffnenden Fenster folgende URL hinzufügen:

`https://github.com/demel42/IPSymconMediolaGateway.git`

und mit _OK_ bestätigen.

Anschließend erscheint ein Eintrag für das Modul in der Liste der Instanz _Modules_

### Einrichtung in IPS

In IP-Symcon nun _Instanz hinzufügen_ (_CTRL+1_) auswählen unter der Kategorie, unter der man die Instanz hinzufügen will, und Hersteller _Mediola_ und als Gerät _Gateway V5/V5+/NEO Server_ auswählen.

## 4. Funktionsreferenz

### zentrale Funktion

`boolean xxxxx(integer $InstanzID)`<br>

## 5. Konfiguration:

### Variablen

| Eigenschaft                          | Typ      | Standardwert    | Beschreibung |
| :----------------------------------: | :-----:  | :-------------: | :----------------------------------------------------------------------------------------------------------: |
| Hostname                             | string   |                 | Namen oder IP des Gateway / NEO-Server |
| Port                                 | integer  | 80              | Http-Port, für den Gateway ist das 80, für den NEO Server typischerweise 8088 |
| Accesstoken                          | string   |                 | Accesstoken des Gateway |
| Passwort                             | string   |                 | alternativ zum _Accesstoken_ |

#### Schaltflächen

| Bezeichnung                  | Beschreibung |
| :--------------------------: | :-------------------------------------------------------------: |
| Prüfen Konfiguration         | Zugriff prüfen und Informationen vom Gateway / NEO Server holen |

## 6. Anhang

GUIDs

- Modul: `{C0BD3A9B-D600-4B78-B9CC-173AC2819CE5}`
- Instanzen:
  - MediolaServer: `{3525077B-2902-459F-BFA9-E9F4F18B4C0B}`

## 7. Versions-Historie

- 0.9 @ 17.09.2018 16:58<br>
  Initiale Version
