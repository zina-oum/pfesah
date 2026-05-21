#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
lire_serial.py - Lit l'ESP32 via port USB série et envoie les données GPS au serveur

USAGE LOCAL (serveur sur le même PC) :
    python lire_serial.py --port COM6 --server http://localhost/bus_modified/backend/st/gps_receiver.php

USAGE HÉBERGEMENT (serveur distant) :
    python lire_serial.py --port COM6 --server https://tondomaine.com/bus_modified/backend/st/gps_receiver.php

Autres options :
    --baud 115200        Vitesse série (défaut: 115200)
    --bus-id 1           ID du bus dans la base de données (défaut: 1)
    --token SECRET       Token de sécurité (défaut: lu dans config.php / BUS_ESP32_SECRET_2026)
    --file chemin.txt    Ecrire aussi dans un fichier (optionnel)
    --port auto          Détecter automatiquement le port ESP32/Arduino
"""

import serial
import serial.tools.list_ports
import sys
import os
import time
import json
import re
import argparse
import urllib.request
import urllib.error
from datetime import datetime

DEFAULT_PORT  = "COM6"
DEFAULT_BAUD  = 115200
DEFAULT_TOKEN = "BUS_ESP32_SECRET_2026"
DEFAULT_BUS   = 1


def log(level, msg):
    ts = datetime.now().strftime("%H:%M:%S")
    print(f"[{ts}] [{level:5}] {msg}", flush=True)


def detect_port():
    """Détecte automatiquement le port USB de l'ESP32 ou Arduino."""
    ports = list(serial.tools.list_ports.comports())
    for p in ports:
        desc = (p.description or "").lower()
        vid  = p.vid or 0
        # ESP32 (CP210x, CH340, FTDI, STLink)
        if vid in (0x10C4, 0x1A86, 0x0403, 0x0483) or \
           any(k in desc for k in ("cp210", "ch340", "ftdi", "esp32", "arduino", "stm", "usb serial")):
            log("DÉTECT", f"Port trouvé: {p.device} ({p.description})")
            return p.device
    return None


def parse_line(raw_line, bus_id, token):
    """
    Convertit une ligne série en dict JSON prêt à poster.
    Formats supportés :
      - JSON  : {"lat":36.75,"lng":3.05,"speed":45.0,"accel":0.1,"temp":22.3,"engine":1}
      - NMEA  : $GPGGA,... ou $BUS,1,36.75,3.05,45,0.1,22.3,1,1*XX
      - KV    : LAT:36.75,LON:3.05,VIT:45,ACC:0.1,TEMP:22.3
    """
    line = raw_line.strip()
    if not line or line.startswith('#') or line.startswith('//'):
        return None

    data = {"token": token, "bus_id": bus_id}

    # --- JSON ---
    if line.startswith('{'):
        try:
            j = json.loads(line)
            data.update({
                "lat":    float(j.get("lat", j.get("latitude", 0))),
                "lng":    float(j.get("lng", j.get("longitude", 0))),
                "speed":  float(j.get("speed", j.get("vitesse", 0))),
                "accel":  float(j.get("accel", j.get("acceleration", 0))),
                "engine": int(j.get("engine", j.get("moteur", 1))),
                "service":int(j.get("service", j.get("en_service", 1))),
            })
            if "temp" in j or "temperature" in j:
                data["temp"] = float(j.get("temp", j.get("temperature", 0)))
            if data["lat"] == 0 or data["lng"] == 0:
                return None
            return data
        except (json.JSONDecodeError, ValueError):
            pass

    # --- $BUS NMEA-like ---
    if line.startswith("$BUS"):
        parts = re.split(r'[,*]', line)
        try:
            data.update({
                "lat":    float(parts[2]),
                "lng":    float(parts[3]),
                "speed":  float(parts[4]),
                "accel":  float(parts[5]),
                "temp":   float(parts[6]),
                "engine": int(parts[7]),
                "service":int(parts[8]) if len(parts) > 8 else 1,
            })
            if data["lat"] == 0 or data["lng"] == 0:
                return None
            return data
        except (IndexError, ValueError):
            pass

    # --- GPGGA NMEA standard ---
    if line.startswith("$GPGGA") or line.startswith("$GNGGA"):
        parts = line.split(',')
        try:
            if parts[2] and parts[4]:
                raw_lat = float(parts[2])
                lat_deg = int(raw_lat / 100) + (raw_lat % 100) / 60
                if parts[3] == 'S': lat_deg = -lat_deg
                raw_lng = float(parts[4])
                lng_deg = int(raw_lng / 100) + (raw_lng % 100) / 60
                if parts[5] == 'W': lng_deg = -lng_deg
                data.update({"lat": lat_deg, "lng": lng_deg, "engine": 1, "service": 1})
                return data
        except (IndexError, ValueError):
            pass

    # --- Clé:Valeur (LAT:36.75,LON:3.05,...) ---
    kv = dict(re.findall(r'(\w+)\s*[:=]\s*([+-]?\d+\.?\d*)', line, re.IGNORECASE))
    if kv:
        lat = float(kv.get('lat', kv.get('latitude', 0)))
        lng = float(kv.get('lng', kv.get('lon', kv.get('longitude', 0))))
        if lat != 0 and lng != 0:
            data.update({
                "lat":    lat,
                "lng":    lng,
                "speed":  float(kv.get('speed', kv.get('vit', kv.get('vitesse', 0)))),
                "accel":  float(kv.get('acc', kv.get('accel', kv.get('acceleration', 0)))),
                "engine": int(float(kv.get('eng', kv.get('engine', kv.get('moteur', 1))))),
                "service": 1,
            })
            if 'temp' in kv or 'temperature' in kv:
                data["temp"] = float(kv.get('temp', kv.get('temperature', 0)))
            return data

    return None


def post_to_server(server_url, payload, timeout=5):
    """Envoie le payload JSON au serveur. Retourne (True, réponse) ou (False, erreur)."""
    body = json.dumps(payload).encode('utf-8')
    req  = urllib.request.Request(
        server_url,
        data=body,
        headers={"Content-Type": "application/json", "User-Agent": "ESP32-BUS/1.0"},
        method="POST"
    )
    try:
        with urllib.request.urlopen(req, timeout=timeout) as resp:
            return True, resp.read().decode('utf-8', errors='replace')[:120]
    except urllib.error.HTTPError as e:
        return False, f"HTTP {e.code}: {e.read().decode('utf-8', errors='replace')[:80]}"
    except urllib.error.URLError as e:
        return False, str(e.reason)
    except Exception as e:
        return False, str(e)


def main():
    parser = argparse.ArgumentParser(description="Lecture série ESP32 → Serveur BUS")
    parser.add_argument("--port",   default=DEFAULT_PORT,
                        help=f"Port série (défaut: {DEFAULT_PORT}), ou 'auto' pour détecter")
    parser.add_argument("--baud",   type=int, default=DEFAULT_BAUD,
                        help=f"Vitesse série (défaut: {DEFAULT_BAUD})")
    parser.add_argument("--server", default=None,
                        help="URL du gps_receiver.php (ex: http://localhost/bus_modified/backend/st/gps_receiver.php)")
    parser.add_argument("--bus-id", type=int, default=DEFAULT_BUS,
                        help=f"ID du bus dans la base (défaut: {DEFAULT_BUS})")
    parser.add_argument("--token",  default=DEFAULT_TOKEN,
                        help="Token de sécurité (doit correspondre à config.php)")
    parser.add_argument("--file",   default=None,
                        help="Aussi écrire dans un fichier texte (optionnel)")
    args = parser.parse_args()

    # Détection automatique du port
    if args.port.lower() == 'auto':
        detected = detect_port()
        if detected:
            args.port = detected
        else:
            log("WARN", "Aucun port détecté automatiquement, utilisation de COM6")
            args.port = "COM6"

    if not args.server:
        log("WARN", "Aucun --server fourni. Les données ne seront pas envoyées.")
        log("WARN", "Usage: python lire_serial.py --port COM6 --server http://localhost/bus_modified/backend/st/gps_receiver.php")

    log("INFO", f"Port: {args.port} | Baud: {args.baud} | Bus ID: {args.bus_id}")
    if args.server:
        log("INFO", f"Serveur: {args.server}")
    if args.file:
        log("INFO", f"Fichier: {args.file}")

    sent_ok    = 0
    sent_err   = 0
    skipped    = 0
    file_handle = None

    if args.file:
        os.makedirs(os.path.dirname(os.path.abspath(args.file)), exist_ok=True)
        file_handle = open(args.file, 'a', encoding='utf-8')

    try:
        with serial.Serial(args.port, args.baud, timeout=1) as ser:
            log("OK", f"Connecté à {args.port}")
            print("-" * 60, flush=True)

            while True:
                try:
                    raw = ser.readline()
                    if not raw:
                        continue

                    line = raw.decode('utf-8', errors='replace').strip()
                    if not line:
                        continue

                    # Écrire dans le fichier si demandé
                    if file_handle:
                        file_handle.write(line + "\n")
                        file_handle.flush()

                    # Parser la ligne
                    payload = parse_line(line, args.bus_id, args.token)

                    if payload is None:
                        log("SKIP", f"Non reconnu: {line[:60]}")
                        skipped += 1
                        continue

                    lat_str = f"{payload['lat']:.5f}"
                    lng_str = f"{payload['lng']:.5f}"
                    spd_str = f"{payload.get('speed', 0):.1f} km/h"

                    if args.server:
                        ok, resp = post_to_server(args.server, payload)
                        if ok:
                            sent_ok += 1
                            log("OK", f"#{sent_ok} pos={lat_str},{lng_str} speed={spd_str} | {resp}")
                        else:
                            sent_err += 1
                            log("ERR", f"#{sent_err} Échec envoi: {resp}")
                    else:
                        log("DATA", f"pos={lat_str},{lng_str} speed={spd_str} (pas de serveur configuré)")

                except serial.SerialException as e:
                    log("ERR", f"Erreur série: {e}")
                    time.sleep(2)
                except KeyboardInterrupt:
                    raise

    except serial.SerialException as e:
        log("ERR", f"Impossible d'ouvrir {args.port}: {e}")
        log("INFO", "Ports disponibles:")
        for p in serial.tools.list_ports.comports():
            print(f"  {p.device}: {p.description}")
        sys.exit(1)
    except KeyboardInterrupt:
        print()
        log("INFO", f"Arrêt. Envoyés: {sent_ok} OK, {sent_err} erreurs, {skipped} ignorés")
    finally:
        if file_handle:
            file_handle.close()


if __name__ == "__main__":
    main()
