# ST Sensor Import

Ce dossier contient les scripts de la partie ST pour importer les données capteurs dans la base de données.

## Configuration série

Le fichier `config.php` contient les paramètres :
- `serial_port` : port COM (défaut: COM6)
- `serial_baud` : vitesse en bauds (défaut: 115200)

## Structure

- `backend/st/config.php` : configuration (port série, baud rate, token, dossier data)
- `backend/st/import_sensor_data.php` : script PHP d'import — lit le fichier TXT, décode, insère dans `bus_positions` + `historique_positions`
- `backend/st/lire_serial.py` : script Python — lit le port série du STM32 et écrit dans le fichier TXT
- `backend/st_data/` : dossier contenant le fichier texte du capteur

## Flux complet

```
STM32 (COM6, 115200)
    ↓ liaison série
lire_serial.py (Python)
    ↓ écriture dans fichier
st_data/donnees_capteur.txt
    ↓ lecture & décodage
import_sensor_data.php (PHP)
    ↓ insertion BDD
bus_positions + historique_positions
```

## Utilisation

### 1. Lire le port série et écrire dans le fichier TXT

```bash
# Avec Python
python backend/st/lire_serial.py

# Détection automatique du port STM32
python backend/st/lire_serial.py --detect

# Port et baud rate personnalisés
python backend/st/lire_serial.py --port COM6 --baud 115200

# Avec PM2 (pour exécution en continu)
pm2 start backend/st/lire_serial.py --interpreter python3
```

### 2. Importer les données du fichier TXT vers la BDD

```bash
# Import unique
php backend/st/import_sensor_data.php --file=backend/st_data/donnees_capteur.txt

# Mode watch (surveille le fichier en continu, import temps réel)
php backend/st/import_sensor_data.php --file=backend/st_data/donnees_capteur.txt --watch --interval=2
```

### 3. Tester avec le fichier d'exemple
Déposer le fichier `donnees_capteur.txt` dans `backend/st_data/` et exécuter :
```bash
php backend/st/import_sensor_data.php
```

## Formats supportés (auto-détection)

Le script PHP détecte automatiquement ces formats :

| Format | Exemple |
|--------|---------|
| JSON | `{"bus_id":1,"lat":36.7538,"lng":3.0588,"speed":35.0}` |
| CSV | `bus_id;lat;lng;speed;accel;temp;engine;service` puis `1;36.7538;3.0588;35.0;...` |
| Key=Value | `bus=1 lat=36.7538 lng=3.0588 speed=35.0` |
| NMEA-like | `$BUS,1,36.7538,3.0588,35.0,0.5,25.3,1,1*FF` |

## Sécurisation

- Le script web (si activé) vérifie le token `SENSOR_IMPORT_TOKEN`.
- Par défaut, le token est `CHANGEMOI_CAPTEUR_TOKEN` et doit être remplacé en production.
