@echo off
title BUS Transport - ESP32 GPS
color 0A

echo ================================================
echo   BUS TRANSPORT - DEMARRAGE ESP32
echo ================================================
echo.
echo  Ce script lit l'ESP32 via USB et envoie les
echo  donnees GPS au serveur (local ou heberge).
echo.
echo ------------------------------------------------
echo  CONFIGURATION  (modifier les lignes ci-dessous)
echo ------------------------------------------------

REM Port serie de l'ESP32 (verifier Gestionnaire de periph.)
set ESP32_PORT=COM6

REM URL du serveur gps_receiver.php :
REM   Local   : http://localhost/bus_modified/backend/st/gps_receiver.php
REM   En ligne : https://tondomaine.com/bus_modified/backend/st/gps_receiver.php
set SERVER_URL=http://localhost/bus_modified/backend/st/gps_receiver.php

REM ID du bus L1 dans la base de donnees (table bus, colonne id)
set BUS_ID=1

echo.
echo  Port    : %ESP32_PORT%
echo  Serveur : %SERVER_URL%
echo  Bus ID  : %BUS_ID%
echo.
echo  Ctrl+C pour arreter
echo ================================================
echo.

python backend\st\lire_serial.py --port %ESP32_PORT% --server %SERVER_URL% --bus-id %BUS_ID%

echo.
echo  Programme arrete.
pause
