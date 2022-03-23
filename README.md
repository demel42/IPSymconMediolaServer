# IPSymconMediolaServer

[![IPS-Version](https://img.shields.io/badge/Symcon_Version-5.3+-red.svg)](https://www.symcon.de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/)
![Code](https://img.shields.io/badge/Code-PHP-blue.svg)
[![License](https://img.shields.io/badge/License-CC%20BY--NC--SA%204.0-green.svg)](https://creativecommons.org/licenses/by-nc-sa/4.0/)

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

Diese Modul stellt einige Hilfsfunktionen zum Umgang mit dem Mediola Gateway oder dem NEO Server zur Verfügung (im folgenden _MediolaServer_ genannt).

### CallTask

Aufruf von beliebigen Tasks auf dem _MediolaServer_.

### ExecuteCommand / ExecuteMakro / GetState

Hiermit können alle Geräteaktionen, die auf den _MediolaServer_ verfügbar sind, aufgerufen werden, ebenso die definierten Makros sowie jeder verfügbare Gerätstatus angerufen werden.

Wichtig: das macht überhaupt keinen Sinn für Geräte, die nativ im IPS eingebunden werden können (wie z.B. HomeMatic), für viele Geräte, die am Gateway angelernt sind, gibt es direkte Aufrufe, die in dem Modul [Wolbolar/IPSymconAIOGateway](https://github.com/Wolbolar/IPSymconAIOGateway) angedeckt sind. Es gibt aber Geräte, die werde so noch so zu erreichen sind, aber von Mediola angebunden wurden. z.B. der Warema-Gateway (bei mir mit einer Markise) ist in Mediola angebunden, die API ist nicht öffentlich und zudem verschlüsselt.
Man könnte natürlich für jede Funktion ein eigenen Task machen, aber das ist nicht wirklich umsetzbar bei Aufrufen mit variablem Anteil (Markise auf Postion 50% fahren).

Daher habe ich ein Interface geschaffen, das über einen generellen Task auf dem _MediolaServer_ jede dort verfügbaren Geräte-Aktion aufrufen, jeden Gerätes-Status abrufen und auch jedes Makro auslösen kann.

Der Ablauf ist wie folgt
 - Aufruf des o.g. generellen Task auf dem _MediolaServer_
 - dieser ruft ein WebHook auf dem IPS auf (_query_), als Antwort auf diese Query liefert IPS die Steuerinformationen
 - je mach Funktion wird dann 
   - die Geräteaktion / das Makro aufgerufen und nach Abschluss dann wieder der WebHook mit Status-Information aufgerufen (_status_)
   - der Gerätestatus angefragt und der WebHook mit dem Wert des Gerätestatus aufgerufen (_value_)

Der Ablauf ist leider asynchron, das bedeutet, das nach dem Auslösen des Aufrufs (z.B. _ExecuteCommand_) mit direkt ein Ergebnis bekommt. Braucht man das Ergebinis, kann man mit _GetActionStatus_ darauf warten.
Bei der Abfrage eines Gerätestatus wird bei Eingang der Antwort vom _MediolaServer_ eine Variable gesetzt oder ein Script aufgerufen.

In einer Variable _Queue_ werden die anstehenden bzw. abgelaufenen Aktionen für eine gewisse Zeit aufbewahrt (_max. Alter der Queue_) und dann gelöscht.

Bei den Funktionen gibt es den Parameter _wait4reply_, ist er _true_, wird die nächste Aktion in der Queue erst aufgerufen, wenn es eine Rückmeldung des Status (_status_) oder Wertes (_value_) vom _MediolaServer_ gegeben hat. Meiner Beobachtung nach kann man aber im Regelfall _wait4reply_ = _false_ verwenden.

Ein Aufruf eines Tasks, der nicht innerhalb einer bsetimmten Zeit (_max. Wartezeit_) abgesickelt ist, wird aus _überfällig_ markiert und nicht mehr behandelt.

### SetValue/GetValue

Umgang mit Variablen auf dem Gateway / NEO Server

## 2. Voraussetzungen

 - IP-Symcon ab Version 5.3
 - Mediola Gateway V5/V5+ oder NEO Server (auf beliebiger Plattform)

## 3. Installation

Die Konsole von IP-Symcon öffnen. Im Objektbaum unter Kerninstanzen die Instanz __*Modules*__ durch einen doppelten Mausklick öffnen.

In der _Modules_ Instanz rechts oben auf den Button __*Hinzufügen*__ drücken.

In dem sich öffnenden Fenster folgende URL hinzufügen:

`https://github.com/demel42/IPSymconMediolaGateway.git`

und mit _OK_ bestätigen.

Anschließend erscheint ein Eintrag für das Modul in der Liste der Instanz _Modules_

### Einrichtung in IPS

In IP-Symcon nun _Instanz hinzufügen_ (_CTRL+1_) auswählen unter der Kategorie, unter der man die Instanz hinzufügen will, und Hersteller _Mediola_ und als Gerät _Gateway V5/V5+/NEO Server_ auswählen.

Nun die Zugangsdaten ausfüllen, wenn der _MediolaServer_ mit einem Passwort geschützt ist entweder der Accesstoken oder das Passwort angegeben werden. Der Accesstoken ist der Parameter, der z.B. im Blockeditor bei _at=_ angezeigt wird (siehe Snap).

Die Portnummer ist _80_ für die Gateways und typischerweise _8088_ für den NEO Server.

Im Mediola Gateway bzw. dem NEO-Server muss ein Script angelegt werden; siehe [docs/ips-callback.js](https://github.com/demel42/IPSymconMediolaServer/blob/master/docs/ips-callback.js), hier nur bitte die Zugangsdaten des IPS-Systems anpassen.

Weiterhin muss ein Task angelegt werden, das als Auslöser _HTTP_ vorsieht und das zuvor angelegte Script aufruft (siehe unten).

## 4. Funktionsreferenz

`bool function MediolaServer_CallTask(int $InstanzID, string $args)`<br>
Aufruf eines Tasks auf dem _MediolaServer_. _args_ ist die json-kodierte Liste des (bzw. der) Schlüssel mit dem Wert aus dem Blockeditor - Format so:
```
$args = ['test' => '1'];

```
Rückgabewert ist _false_, wenn dieser Task nicht existiert bzw. nicht aufgerufen werden konnte.

`int MediolaServer_ExecuteCommand(int $InstanzID, string $room, string $device, string $action, string $value, bool $wait4reply)`<br>
Aufrufe einer beliebigen Geräteaktion über den o.g. Task.
Rückgabewert ist die ID der Aktion.

`int MediolaServer_ExecuteMakro(int $InstanzID, string $group, string $macro, bool $wait4reply)`<br>
Aufrufe eines Makros über den o.g. Task.
Rückgabewert ist die ID der Aktion.

`int MediolaServer_GetState(int $InstanzID, string $room, string $device, string $variable, int $objID, bool $wait4reply)`<br>
Abfrage eines Gerätestats über den o.g. Task. _objID_ ist entweder die ID einer Variablen (mit zum Mediola-Gerätestatus passenden Typ) oder ein Script, dem das Ergebnis übergeben wird.

```
<?php

$status = $_IPS['status'];
$value = $_IPS['value'];

IPS_LogMessage(IPS_GetName($_IPS['SELF']) . '(' . $_IPS['SELF'] . ')', '_IPS=' . print_r($_IPS, true));
```
Rückgabewert ist die ID der Aktion.

`string MediolaServer_GetActionStatus(int $id, int $max_wait)`<br>
Ergebnis eines vorherigen Task-Aufrufs. Die Funktion wartet maximal bis _max_wait_ Sekunden; ist das Ergebnis ein leerer String, ist die Funktion noch nicht abgewickelt.

`bool MediolaServer_SetValueString(int $InstanzID, string $adr, string $sval)`<br>
`bool MediolaServer_SetValueBoolean(int $InstanzID, string $adr, bool $bval)`<br>
`bool MediolaServer_SetValueInteger(int $InstanzID, string $adr, int $ival)`<br>
`bool MediolaServer_SetValueFloat(int $InstanzID, string $adr, float $fval)`<br>
Setzen von Variablenwerten auf dem _MediolaServer_, die _adr_ ist die im Gerätemanager angegebene Adresse der Variablen.

`string MediolaServer_GetValueString(int $InstanzID, string $adr)`<br>
`bool MediolaServer_GetValueBoolean(int $InstanzID, string $adr)`<br>
`int MediolaServer_GetValueInteger(int $InstanzID, string $adr)`<br>
`float MediolaServer_GetValueFloat(int $InstanzID, string $adr)`<br>
Abfrage von Variablenwerten des _MediolaServer_, die _adr_ ist die im Gerätemanager angegebene Adresse der Variablen.

## 5. Konfiguration:

### Variablen

| Eigenschaft                 | Typ     | Standardwert   | Beschreibung |
| :-------------------------- | :------ | :------------- | :----------- |
| Instanz deaktivieren        | boolean | false          | Instanz temporär deaktivieren |
|                             |         |                | |
| Hostname                    | string  |                | Namen oder IP des Gateway / NEO-Server |
| Port                        | integer | 80             | Http-Port, für den Gateway ist das 80, für den NEO Server typischerweise 8088 |
| Accesstoken                 | string  |                | Accesstoken des Gateway |
| Passwort                    | string  |                | alternativ zum _Accesstoken_ |
|                             |         |                | |
| Schlüssel des Mediola-Tasks | string  | ips-callback=1 | Schlüssel der HTTP-Aufrufs des Tasks im Blockeditor |
| max. Alter der Queue        | integer | 3600           | maximales Alter eines Queue-Eintrags (in Sekunden) |
| max. Wartezeit              | integer | 10             | maximal Wartezeit nach Aufruf des Tasks vom IPS bis zur Antwort vom Mediola-Server (in Sekunden) |
|                             |         |                | |
| Update-Intervall            | integer | 5              | Abfrage des Status alle X Minuten |

#### Schaltflächen

| Bezeichnung          | Beschreibung |
| :------------------- | :----------- |
| Prüfen Konfiguration | Zugriff prüfen und Informationen vom _MediolaServer_ holen |
| Queue anzeigen       | Status der Queue der Aktionen anzeigen |

## 6. Anhang

### GUIDs

- Modul: `{C0BD3A9B-D600-4B78-B9CC-173AC2819CE5}`
- Instanzen:
  - MediolaServer: `{3525077B-2902-459F-BFA9-E9F4F18B4C0B}`

### Beispiele

#### Callback-Task

![Mediola-Task](docs/ips-callback-task.png?raw=true "Task im Blockeditor")

#### Geräteaktion

![Mediola-Task](docs/Geraeteaktion_1a.png?raw=true "Geräteaktion 1a") ![Mediola-Task](docs/Geraeteaktion_1b.png?raw=true "Geräteaktion 1b")

`MediolaServer_ExecuteCommand(4711, 'Gerät', 'Terrasse', 'up', '', false);`

#### Geräteaktion mit variablem Parameter

![Mediola-Task](docs/Geraeteaktion_2a.png?raw=true "Geräteaktion 2a") ![Mediola-Task](docs/Geraeteaktion_2b.png?raw=true "Geräteaktion 2b")

`MediolaServer_ExecuteCommand(4711, 'Gerät', 'Terrasse', 'moveTo', '50', false);`

#### Gerätestatus

![Mediola-Task](docs/Geraetestatus_1a.png?raw=true "Gerätestatus 1a") ![Mediola-Task](docs/Geraetestatus_1b.png?raw=true "Gerätestatus 1b")

`MediolaServer_GetState(4711, 'Gerät', 'Terrasse', 'position', false);`

##### Makro

![Mediola-Task](docs/Makro_1a.png?raw=true "Makro 1a") ![Mediola-Task](docs/Makro_1b.png?raw=true "Makro 1b")

`MediolaServer_ExecuteMakro(4711, 'Aussenleuchten', 'Ausschalten', false);`

## 7. Versions-Historie

- 1.17 @ 23.03.2022 10:14 
  - libs/common.php -> CommonStubs
  - Anzeige der Modul/Bibliotheks-Informationen
  - Möglichkeit der Anzeige der Instanz-Referenzen

- 1.16 @ 13.08.2021 18:00
  - Anpassungen für IPS 6
    - IPS_LogMessage(...) ersetzt durch $this->LogMessage(..., KL_MESSAGE);

- 1.15 @ 14.07.2021 18:44
  - PHP_CS_FIXER_IGNORE_ENV=1 in github/workflows/style.yml eingefügt
  - Schalter "Instanz ist deaktiviert" umbenannt in "Instanz deaktivieren"

- 1.14 @ 23.07.2020 15:21
  - LICENSE.md hinzugefügt
  - define's durch statische Klassen-Variablen ersetzt
  - library.php in local.php umbenannt
  - lokale Funktionen aus common.php in locale.php verlagert

- 1.13 @ 18.01.2020 10:46
  - Anpassungen an IPS 5.3
    - auch in der Dokumentation das 'PHP Long Tag' verwenden

- 1.12 @ 01.01.2020 18:52
  - Anpassungen an IPS 5.3
    - Formular-Elemente: 'label' in 'caption' geändert
  - Fix in CreateVarProfile()
  - Schreibfehler korrigiert

- 1.11 @ 13.10.2019 13:18
  - Anpassungen an IPS 5.2
    - IPS_SetVariableProfileValues(), IPS_SetVariableProfileDigits() nur bei INTEGER, FLOAT
    - Dokumentation-URL in module.json
  - Umstellung auf strict_types=1
  - Umstellung von StyleCI auf php-cs-fixer

- 1.10 @ 19.08.2019 21:02
  - Fehler in ips-callback.js korrgiert

- 1.9 @ 09.08.2019 14:32
  - Schreibfehler korrigiert

- 1.8 @ 26.04.2019 16:50
  - Übersetzung ergänzt
  - locale.json korrigiert

- 1.7 @ 17.04.2019 08:18
  - Anpassung IPS 5.1: neue Standard-Funktion GetStatus(), daher lokale Funktion GetStatus() umbenannt in GetState()

- 1.6 @ 29.03.2019 16:19
  - SetValue() abgesichert

- 1.5 @ 21.03.2019 17:04
  - Schalter, um eine Instanz (temporär) zu deaktivieren
  - Anpassung IPS 5

- 1.4 @ 23.01.2019 18:18
  - curl_errno() abfragen

- 1.3 @ 21.12.2018 13:10
  - Standard-Konstanten verwenden

- 1.2 @ 18.11.2018 11:40
  - Implementierung negativer _INT_ und _FLOAT_-Werte war fehlerhaft
  - Prüfung auf Verletzung von Wertegrenzen (INT: -2147483648..2147483647, FLOAT: -21474836.48..21474836.47)

- 1.1 @ 15.10.2018 12:08
  - Schreibfehler korrigiert
  - zyklische Status-Abfrage

- 1.0 @ 17.09.2018 16:58
  - Initiale Version
