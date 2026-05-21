# BUS Transport — Système de Gestion des Transports Universitaires

Application web complète de gestion des transports universitaires : suivi GPS en temps réel, réservation de places, gestion des lignes, chauffeurs et bus.

---

## Fonctionnalités

| Rôle | Fonctionnalités |
|------|----------------|
| **Étudiant** | Carte interactive, suivi des bus en temps réel, réservation + QR code, consultation des horaires |
| **Chauffeur** | Dashboard de service (démarrage/arrêt), statistiques du jour, numéros d'urgence |
| **Admin** | Gestion des lignes/arrêts/bus/chauffeurs, validation des comptes, supervision globale |

---

## Prérequis

- **XAMPP** (Apache + PHP 7.4+ + MySQL 5.7+)
- **Navigateur moderne** (Chrome, Firefox, Edge)
- Connexion internet pour la carte (OpenFreeMap / MapLibre GL JS via CDN)

---

## Installation locale (XAMPP)

### 1. Placer le dossier dans XAMPP

```
C:\xampp\htdocs\bus_modified\
```

### 2. Démarrer XAMPP

Lancer **Apache** et **MySQL** depuis le panneau XAMPP.

### 3. Configurer la base de données

Ouvrir dans le navigateur :

```
http://localhost/bus_modified/backend/init_db.php
```

Ce script crée automatiquement :
- La base `bus_transport`
- Toutes les tables
- Le compte admin par défaut
- 3 lignes de démonstration (L1, L2, L3)

### 4. Vérifier la configuration

Le fichier `config/db_access.json` doit contenir :

```json
{
  "host": "127.0.0.1",
  "port": 3306,
  "database": "bus_transport",
  "username": "root",
  "password": ""
}
```

> Par défaut, XAMPP utilise `root` sans mot de passe. Si tu as changé le mot de passe MySQL, mets-le ici.

### 5. Accéder à l'application

```
http://localhost/bus_modified/index.html
http://localhost/bus_modified/pages/auth/login.html
```

---

## Déploiement en hébergement web (cPanel)

### Étape 1 — Préparer les fichiers

Exclure ces dossiers avant de zipper :
- `node_modules/`
- `archive/`
- `backend/st/` (ESP32 local uniquement)
- `.git/`

### Étape 2 — Créer la base de données sur cPanel

1. cPanel → **Bases de données MySQL**
2. Créer une base : `monsite_bus_transport`
3. Créer un utilisateur avec mot de passe fort
4. Attribuer **tous les privilèges** à cet utilisateur sur cette base

### Étape 3 — Modifier config/db_access.json

```json
{
  "host": "localhost",
  "port": 3306,
  "database": "monsite_bus_transport",
  "username": "monsite_dbuser",
  "password": "motDePasseDB"
}
```

> Sur les hébergements mutualisés, `host` est presque toujours `localhost`.

### Étape 4 — Uploader les fichiers

Via **Gestionnaire de fichiers** cPanel ou FTP :
- Déposer tout le contenu dans `public_html/bus_modified/` (ou `public_html/` directement)

### Étape 5 — Initialiser la base

Ouvrir dans le navigateur :
```
https://tondomaine.com/bus_modified/backend/init_db.php
```

### Étape 6 — Vérifier les permissions

Les dossiers `backend/st_data/` et `assets/` doivent avoir les permissions **755** (dossiers) et **644** (fichiers).

```bash
# Via SSH ou terminal cPanel :
chmod -R 755 backend/st_data/
chmod -R 644 assets/
```

### Points importants pour l'hébergement

- **Carte** : fonctionne sans API key (OpenFreeMap / MapLibre GL JS via CDN)
- **QR code** : utilise `api.qrserver.com` (requiert connexion internet côté navigateur)
- **GPS L1** : l'ESP32 envoie les données directement au serveur via HTTP. Fonctionne en hébergement. Voir section ESP32 ci-dessous.
- **Email** : configurer dans `backend/mail.php` avec les identifiants SMTP du fournisseur d'hébergement

---

## Comptes par défaut

### Administrateur

```
Email    : admin@cous.dz
Mot de passe : buscous1
```
URL : `/pages/admin/dashboard.html`

### Chauffeur

Les chauffeurs sont créés par l'admin depuis le dashboard. À la création, l'admin saisit :
- Prénom, Nom, Email, Téléphone
- Ligne assignée
- Immatriculation, Marque, Modèle, Places du bus

Le chauffeur se connecte et voit son dashboard avec les données de sa ligne.

### Étudiant

Les étudiants s'inscrivent eux-mêmes via `/pages/auth/register.html`. L'admin valide ensuite leur compte.

---

## Architecture technique

```
bus_modified/
├── index.html                       # Page d'accueil
├── demarrer.bat                     # Lancer l'ESP32 (PC admin Windows)
├── config/
│   ├── db_access.json               # Config DB (modifier pour l'hébergement)
│   └── db_access.example.json       # Modèle de config
├── backend/
│   ├── db.php                       # Connexion PDO (lit config/db_access.json)
│   ├── api.php                      # API publique (lignes, bus, positions, reserve)
│   ├── admin_api.php                # API admin
│   ├── chauffeur_api.php            # API chauffeur
│   ├── login.php                    # Authentification
│   ├── register.php                 # Inscription
│   ├── init_db.php                  # Création des tables (lancer 1 seule fois)
│   ├── mail.php                     # Envoi email (confirmation compte)
│   ├── st/
│   │   ├── lire_serial.py           # Lit l'ESP32 USB → POST vers gps_receiver.php
│   │   ├── gps_receiver.php         # Reçoit les données GPS de l'ESP32 via HTTP
│   │   ├── import_sensor_data.php   # Import depuis fichier texte (mode local)
│   │   └── config.php               # Config token + port série
│   └── st_data/                     # Données brutes ESP32 (mode fichier)
├── pages/
│   ├── auth/                        # Login / Register
│   ├── admin/dashboard.html         # Dashboard admin (carte + vue détail ligne)
│   ├── chauffeur/dashboard.html     # Dashboard chauffeur
│   └── etudiant/
│       ├── dashboard.html           # Dashboard étudiant
│       └── bus.html                 # Carte + réservation + QR code
├── assets/
│   └── js/popup.js                  # Popups partagés + dark mode
└── data/
    └── database.sql                 # Schéma SQL de référence
```

---

## Base de données — Tables principales

| Table | Rôle |
|-------|------|
| `users` | Tous les utilisateurs (admin, chauffeur, étudiant) |
| `lignes` | Lignes de bus (numéro, trajet, horaires) |
| `arrets` | Arrêts par ligne (avec coordonnées GPS optionnelles) |
| `bus` | Bus physiques — liés à une ligne et un chauffeur |
| `bus_positions` | Position GPS + données temps réel par bus (1 ligne par bus) |
| `reservations` | Réservations étudiants (avec contrainte 1/ligne/jour) |
| `historique_positions` | Historique des positions GPS |
| `notifications` | Alertes utilisateurs |
| `incidents` | Signalements de problèmes |

**Architecture clé** : Un bus appartient à une ligne (`bus.ligne_id`) et a un chauffeur assigné (`bus.chauffeur_id`). Créer un compte chauffeur depuis l'admin crée automatiquement le bus associé.

---

## Intégration ESP32 (Ligne L1 — GPS réel)

L'ESP32 envoie les données GPS directement au serveur via **WiFi + HTTP**. Fonctionne **en local ET en hébergement distant**.

### Architecture WiFi (recommandée — locale et hébergée)

```
Module GPS (NMEA)
    ↓ Serial UART (GPIO 16/17)
ESP32 (WiFi)
    ↓ HTTP POST JSON
backend/st/gps_receiver.php
    ↓ PDO
table bus_positions → carte temps réel
```

### Fonctionnement

L'ESP32 est branché **par USB** au PC de l'admin. Le script Python lit le port série et envoie les données GPS au serveur via HTTP. Fonctionne en local et en hébergement distant.

### Lancer la lecture ESP32

**Double-cliquer sur `demarrer.bat`** (Windows) — ou en ligne de commande :

```bash
# Local
python backend/st/lire_serial.py --port COM6 --server http://localhost/bus_modified/backend/st/gps_receiver.php --bus-id 1

# Hébergement
python backend/st/lire_serial.py --port COM6 --server https://tondomaine.com/bus_modified/backend/st/gps_receiver.php --bus-id 1
```

Modifier `COM6` selon le port visible dans le Gestionnaire de périphériques Windows, et `--bus-id` selon l'ID du bus L1 dans la base.

### Prérequis Python

```
pip install pyserial
```

### Sécurité — Token

Le token `BUS_ESP32_SECRET_2026` est dans `backend/st/config.php`. Le script Python utilise ce même token par défaut. Pour le changer :

```bash
python lire_serial.py --port COM6 --server URL --token MON_TOKEN_SECRET
```

Et modifier `config.php` :
```php
'import_token' => 'MON_TOKEN_SECRET',
```

### Formats de données acceptés

Le script détecte automatiquement le format émis par l'ESP32 :
- **JSON** : `{"lat":36.75,"lng":3.05,"speed":45.0,"accel":0.1,"temp":22.3}`
- **NMEA** : trames `$GPGGA` standard ou `$BUS,...`
- **Clé:Valeur** : `LAT:36.75,LON:3.05,VIT:45,ACC:0.1,TEMP:22.3`

---

## Stack technique

| Composant | Technologie |
|-----------|------------|
| Frontend | HTML5 + Tailwind CSS (CDN) + JavaScript ES6 |
| Carte | MapLibre GL JS + OpenFreeMap (sans clé API) |
| QR Code | api.qrserver.com (API externe gratuite) |
| Backend | PHP 7.4+ avec PDO |
| Base de données | MySQL 5.7+ / MariaDB |
| Auth | Sessions PHP + bcrypt |
| Arrêts carte | Overpass API (OpenStreetMap) + Nominatim (géocodage inversé) |

---

## Résolution de problèmes courants

### ❌ Erreur Apache : "AH00124: Request exceeded the limit"

C'est l'erreur la plus courante à la première installation. Apache refuse les requêtes jugées trop longues.

**Solution rapide (Windows) :**
1. Double-cliquer sur `fix_apache.bat` (dans le dossier du projet)
2. Suivre les instructions à l'écran pour modifier `httpd.conf`
3. Redémarrer Apache dans XAMPP

**Solution manuelle :**

Ouvrir `C:\xampp\apache\conf\httpd.conf` et ajouter à la fin :
```
LimitRequestLine    65536
LimitRequestFieldSize 65536
LimitRequestBody    10485760
```

Aussi dans ce même fichier, trouver le bloc `<Directory "C:/xampp/htdocs">` et changer :
```
AllowOverride None   →   AllowOverride All
```

Redémarrer Apache. L'erreur disparaît.

**Diagnostic :** Ouvrir `http://localhost/bus_modified/check.php` pour voir exactement ce qui bloque.

---

### La carte ne s'affiche pas
- Vérifier la connexion internet (tiles MapLibre chargés via CDN)
- Vérifier la console navigateur pour les erreurs JS

### Erreur de connexion base de données
- Vérifier que MySQL est démarré
- Vérifier les valeurs dans `db_access.json`
- Sur cPanel : s'assurer que l'utilisateur a bien les privilèges sur la base

### Les lignes ne s'affichent pas sur la carte étudiant
- Aller sur `http://localhost/bus_modified/backend/init_db.php` pour créer les données de base
- Créer au moins une ligne depuis le dashboard admin

### Un chauffeur n'a pas de ligne
- Dans le dashboard admin, section "Chauffeurs", utiliser le menu déroulant pour assigner un bus
- Ou recréer le compte chauffeur en sélectionnant une ligne existante

### Les places ne diminuent pas après réservation
- Vérifier que la base a bien la colonne `places_libres` dans `bus_positions`
- Relancer `init_db.php` pour créer les entrées manquantes

### Le QR code ne s'affiche pas
- Vérifier la connexion internet (api.qrserver.com requis)
- Vérifier la console navigateur

---

## Données après installation

Après `init_db.php`, le système contient :
- 1 admin (`admin@cous.dz`)
- 3 lignes de démonstration (L1, L2, L3)
- 3 bus sans chauffeur

L'admin doit ensuite :
1. Créer des comptes chauffeurs (avec bus + ligne)
2. Ajouter des arrêts sur la carte (dashboard admin)
3. Valider les inscriptions étudiants

---

## Version

**2.0** — Mai 2026  
PHP/MySQL + MapLibre GL JS + ESP32 GPS  
Usage interne universitaire
