[![Version](https://img.shields.io/badge/Symcon-PHPModul-red.svg)](https://www.symcon.de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/)
[![Version](https://img.shields.io/badge/Modul%20Version-1.00-blue.svg)]()
[![License](https://img.shields.io/badge/License-CC%20BY--NC--SA%204.0-green.svg)](https://creativecommons.org/licenses/by-nc-sa/4.0/)  
[![Version](https://img.shields.io/badge/Symcon%20Version-8.1%20%3E-green.svg)](https://www.symcon.de/forum/threads/30857-IP-Symcon-5-1-%28Stable%29-Changelog)


# MrWash Flatrate (IP-Symcon)

IP-Symcon Modul zur automatischen Auswertung, ob sich die **Mr. Wash Flatrate** für dich lohnt – inklusive Webhook-Anbindung (z. B. Geofency), automatischer Programmerkennung (Außen vs. Innen+Außen), Break-Even-Berechnung, Fair-Use-Warnungen und Historie.

> Unterstützt IP-Symcon **8.1**.

---

## Features

- ✅ Webhook-Empfang über eigenes I/O-Modul (Geofency kompatibel)
- ✅ Splitter-Modul zur Datenverteilung an mehrere Device-Instanzen
- ✅ Device-Modul mit:
  - Auswertung "lohnt sich / lohnt sich nicht"
  - Break-Even: **wie viele Wäschen fehlen** + **empfohlenes Intervall**
  - Letzter Besuch inkl. Programm / Dauer / Location / Device
  - Fair-Use/Nutzungsbedingungen: Warnung bei "übertriebener" Nutzung (konfigurierbar)
- ✅ **HistoryJSON** als editierbares Archiv (JSON) + zusätzlich internes Rolling-Window (400 Tage) für die Berechnung
- ✅ Debugging getrennt pro I/O, Splitter und Device

---

## Modulstruktur

Dieses Repository enthält drei Module:

- **MrWashWebhookIO** (I/O): Webhook-Endpunkt, Token-Validierung, Payload-Aufbereitung
- **MrWashSplitter** (Splitter): verteilt eingehende Webhook-Daten an Device-Instanzen
- **MrWashFlatrate** (Device): speichert Visits, berechnet Kennzahlen, liefert Dashboard

---

## Voraussetzungen

- IP-Symcon **8.1**
- Optional: Geofency (iOS) oder ein anderer Webhook-Sender
- WebHook Control muss erreichbar sein (Port/Firewall/Reverse Proxy beachten)

---

## Installation

### Installation via Module Control (empfohlen)
1. IP-Symcon Konsole öffnen
2. **Module Control** → **Modules** → **Add**
3. Repository-URL eintragen:
   - `https://github.com/TheEldar/MrWashFlatrate.git`
4. **Install** klicken

### Manuelle Installation
1. Repository nach `/var/lib/symcon/modules/` klonen:
   ```bash
   cd /var/lib/symcon/modules
   git clone https://github.com/TheEldar/MrWashFlatrate.git MrWashFlatrate
2. IP-Symcon Dienst neu starten:
   ```bash
   sudo systemctl restart symcon
