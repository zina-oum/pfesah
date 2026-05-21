<?php
header('Content-Type: text/html; charset=utf-8');

echo "<!DOCTYPE html>
<html><head><title>Créer Base BUS</title>
<link href='https://cdn.tailwindcss.com' rel='stylesheet'>
<style>
body{font-family:system-ui;background:#0a1628;color:#e2e8f0;min-height:100vh;padding:2rem}
.container{max-width:900px;margin:0 auto}
.card{background:#1a3a5c;padding:1.5rem;border-radius:1rem;margin-bottom:1rem}
.success{color:#4ade80;background:#052e16;padding:1rem;border-radius:0.5rem;margin-bottom:0.5rem}
.error{color:#f87171;background:#450a0a;padding:1rem;border-radius:0.5rem;margin-bottom:0.5rem}
.info{color:#60a5fa;background:#172554;padding:1rem;border-radius:0.5rem;margin-bottom:0.5rem}
table{width:100%;border-collapse:collapse;margin-top:1rem}
th,td{padding:0.75rem;border:1px solid #334155;text-align:left}
th{background:#0f2942}
tr:hover{background:#1e3a5c}
</style>
</head><body>
<div class='container'>
<h1 class='text-3xl font-bold mb-2'>🔧 BUS Transport - Setup</h1>
<p class='text-gray-400 mb-6'>Structure complète de la base de données</p>";

$configFile = __DIR__ . '/../config/db_access.json';
if (file_exists($configFile)) {
    $json = json_decode(file_get_contents($configFile), true);
    $host = $json['host'] ?? 'localhost';
    $user = $json['username'] ?? 'root';
    $pass = $json['password'] ?? '';
    $db   = $json['database'] ?? 'bus_transport';
} else {
    $host = getenv('DB_HOST') ?: 'localhost';
    $user = getenv('DB_USER') ?: 'root';
    $pass = getenv('DB_PASS') ?: '';
    $db   = getenv('DB_NAME') ?: 'bus_transport';
}

try {
    $pdo = new PDO("mysql:host=$host;charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "<div class='success'>✓ Connexion MySQL réussie</div>";
} catch (PDOException $e) {
    echo "<div class='error'>✗ Erreur: " . $e->getMessage() . "</div>";
    echo "<div class='card'>
        <h3 class='font-bold'>Solutions:</h3>
        <ol class='list-decimal list-inside mt-2 space-y-1'>
            <li>Démarrez MySQL dans XAMPP</li>
            <li>Vérifiez le port (défaut: 3306)</li>
        </ol>
    </div>";
    exit;
}

// Créer la base
echo "<div class='card'>";
echo "<h2 class='text-xl font-bold mb-4'>Base de données</h2>";
try {
    $pdo->exec("CREATE DATABASE IF NOT EXISTS $db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE $db");
    echo "<div class='success'>✓ Base '$db' créée/Sélectionnée</div>";
} catch (PDOException $e) {
    echo "<div class='error'>✗ Erreur: " . $e->getMessage() . "</div>";
}
echo "</div>";

// Tables à créer
$tables = [
    'users' => [
        'sql' => "CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(100) UNIQUE NOT NULL,
            password VARCHAR(255) NOT NULL,
            role ENUM('admin', 'chauffeur', 'etudiant') NOT NULL,
            nom VARCHAR(50) NOT NULL,
            prenom VARCHAR(50) NOT NULL,
            telephone VARCHAR(20),
            code_etudiant VARCHAR(10) UNIQUE,
            photo TEXT,
            statut ENUM('pending', 'validated', 'rejected') DEFAULT 'pending',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_role (role),
            INDEX idx_statut (statut),
            INDEX idx_email (email)
        ) ENGINE=InnoDB",
        'desc' => 'Utilisateurs (admin, chauffeur, étudiant)'
    ],
    
    'lignes' => [
        'sql' => "CREATE TABLE IF NOT EXISTS lignes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            numero VARCHAR(20) NOT NULL,
            nom VARCHAR(100) NOT NULL,
            description TEXT,
            trajet_depart VARCHAR(100) NOT NULL,
            trajet_arrivee VARCHAR(100) NOT NULL,
            heure_debut TIME NOT NULL,
            heure_fin TIME NOT NULL,
            interval_minutes INT DEFAULT 30,
            actif BOOLEAN DEFAULT TRUE,
            couleur VARCHAR(7) DEFAULT '#3B82F6',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_numero (numero),
            INDEX idx_actif (actif)
        ) ENGINE=InnoDB",
        'desc' => 'Lignes de bus'
    ],
    
    'arrets' => [
        'sql' => "CREATE TABLE IF NOT EXISTS arrets (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ligne_id INT NOT NULL,
            nom VARCHAR(100) NOT NULL,
            latitude DECIMAL(10, 8),
            longitude DECIMAL(11, 8),
            ordre INT NOT NULL COMMENT 'Ordre dans la ligne',
            actif BOOLEAN DEFAULT TRUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (ligne_id) REFERENCES lignes(id) ON DELETE CASCADE,
            INDEX idx_ligne (ligne_id),
            INDEX idx_ordre (ligne_id, ordre)
        ) ENGINE=InnoDB",
        'desc' => 'Arrêts de bus par ligne'
    ],
    
    'bus' => [
        'sql' => "CREATE TABLE IF NOT EXISTS bus (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ligne_id INT,
            chauffeur_id INT DEFAULT NULL,
            immatriculation VARCHAR(20) UNIQUE NOT NULL,
            marque VARCHAR(50),
            modele VARCHAR(50),
            places_totales INT DEFAULT 40,
            statut ENUM('actif', 'maintenance', 'hors_service') DEFAULT 'actif',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (ligne_id) REFERENCES lignes(id) ON DELETE SET NULL,
            FOREIGN KEY (chauffeur_id) REFERENCES users(id) ON DELETE SET NULL,
            INDEX idx_ligne (ligne_id),
            INDEX idx_chauffeur (chauffeur_id)
        ) ENGINE=InnoDB",
        'desc' => 'Bus physiques'
    ],
    
    'affectation_chauffeur' => [
        'sql' => "CREATE TABLE IF NOT EXISTS affectation_chauffeur (
            id INT AUTO_INCREMENT PRIMARY KEY,
            chauffeur_id INT NOT NULL,
            ligne_id INT NOT NULL,
            date_debut DATE NOT NULL,
            date_fin DATE,
            actif BOOLEAN DEFAULT TRUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (chauffeur_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (ligne_id) REFERENCES lignes(id) ON DELETE CASCADE,
            UNIQUE KEY unique_affectation (chauffeur_id, ligne_id, date_debut),
            INDEX idx_chauffeur (chauffeur_id),
            INDEX idx_ligne (ligne_id)
        ) ENGINE=InnoDB",
        'desc' => 'Affectation chauffeur-ligne'
    ],
    
    'bus_positions' => [
        'sql' => "CREATE TABLE IF NOT EXISTS bus_positions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            bus_id INT NOT NULL,
            ligne_id INT NOT NULL,
            latitude DECIMAL(10, 8) NOT NULL,
            longitude DECIMAL(11, 8) NOT NULL,
            vitesse DECIMAL(5, 1) DEFAULT 0 COMMENT 'km/h',
            acceleration DECIMAL(5, 2) DEFAULT 0 COMMENT 'm/s²',
            km_parcourus DECIMAL(10, 2) DEFAULT 0,
            places_libres INT DEFAULT 40,
            places_totales INT DEFAULT 40,
            moteur BOOLEAN DEFAULT FALSE,
            en_service BOOLEAN DEFAULT FALSE,
            temperature DECIMAL(4, 1) COMMENT '°C',
            next_stop_id INT,
            direction ENUM('aller', 'retour') DEFAULT 'aller',
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (bus_id) REFERENCES bus(id) ON DELETE CASCADE,
            FOREIGN KEY (ligne_id) REFERENCES lignes(id) ON DELETE CASCADE,
            FOREIGN KEY (next_stop_id) REFERENCES arrets(id) ON DELETE SET NULL,
            UNIQUE KEY unique_bus_position (bus_id),
            INDEX idx_bus (bus_id),
            INDEX idx_ligne (ligne_id),
            INDEX idx_en_service (en_service)
        ) ENGINE=InnoDB",
        'desc' => 'Position GPS et données capteurs'
    ],
    
    'historique_positions' => [
        'sql' => "CREATE TABLE IF NOT EXISTS historique_positions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            bus_id INT NOT NULL,
            ligne_id INT NOT NULL,
            latitude DECIMAL(10, 8) NOT NULL,
            longitude DECIMAL(11, 8) NOT NULL,
            vitesse DECIMAL(5, 1) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (bus_id) REFERENCES bus(id) ON DELETE CASCADE,
            FOREIGN KEY (ligne_id) REFERENCES lignes(id) ON DELETE CASCADE,
            INDEX idx_bus_created (bus_id, created_at)
        ) ENGINE=InnoDB",
        'desc' => 'Historique des positions GPS'
    ],
    
    'reservations' => [
        'sql' => "CREATE TABLE IF NOT EXISTS reservations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            ligne_id INT NOT NULL,
            arret_descente_id INT DEFAULT NULL,
            date_reservation DATE NOT NULL,
            heure_reservation TIME,
            statut ENUM('active', 'utilisee', 'annulee', 'expiree') DEFAULT 'active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (ligne_id) REFERENCES lignes(id) ON DELETE CASCADE,
            FOREIGN KEY (arret_descente_id) REFERENCES arrets(id) ON DELETE SET NULL,
            UNIQUE KEY unique_reservation_per_day (user_id, ligne_id, date_reservation),
            INDEX idx_user (user_id),
            INDEX idx_ligne_date (ligne_id, date_reservation),
            INDEX idx_arret_descente (arret_descente_id),
            INDEX idx_statut (statut)
        ) ENGINE=InnoDB",
        'desc' => 'Réservations étudiants'
    ],
    
    'incidents' => [
        'sql' => "CREATE TABLE IF NOT EXISTS incidents (
            id INT AUTO_INCREMENT PRIMARY KEY,
            bus_id INT,
            ligne_id INT,
            chauffeur_id INT,
            type ENUM('technique', 'mecanique', 'autre') NOT NULL,
            description TEXT NOT NULL,
            statut ENUM('ouvert', 'en_cours', 'resolu') DEFAULT 'ouvert',
            priorite ENUM('basse', 'moyenne', 'haute', 'urgente') DEFAULT 'moyenne',
            date_creation TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            date_resolution TIMESTAMP NULL,
            FOREIGN KEY (bus_id) REFERENCES bus(id) ON DELETE SET NULL,
            FOREIGN KEY (ligne_id) REFERENCES lignes(id) ON DELETE SET NULL,
            FOREIGN KEY (chauffeur_id) REFERENCES users(id) ON DELETE SET NULL,
            INDEX idx_statut (statut),
            INDEX idx_date (date_creation)
        ) ENGINE=InnoDB",
        'desc' => 'Incidents bus'
    ],
    
    'signalements' => [
        'sql' => "CREATE TABLE IF NOT EXISTS signalements (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            ligne_id INT,
            chauffeur_id INT,
            type ENUM('retard', 'comportement', 'securite', 'autre') NOT NULL,
            description TEXT NOT NULL,
            statut ENUM('recu', 'en_cours', 'traite') DEFAULT 'recu',
            date_creation TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            date_traitement TIMESTAMP NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (ligne_id) REFERENCES lignes(id) ON DELETE SET NULL,
            FOREIGN KEY (chauffeur_id) REFERENCES users(id) ON DELETE SET NULL,
            INDEX idx_statut (statut),
            INDEX idx_user (user_id)
        ) ENGINE=InnoDB",
        'desc' => 'Signalements étudiants'
    ],
    
    'historique_trajets' => [
        'sql' => "CREATE TABLE IF NOT EXISTS historique_trajets (
            id INT AUTO_INCREMENT PRIMARY KEY,
            chauffeur_id INT NOT NULL,
            ligne_id INT,
            bus_id INT,
            date_travail DATE NOT NULL,
            tickets_vendus INT DEFAULT 0,
            km_parcourus DECIMAL(10, 2) DEFAULT 0,
            nb_trajets INT DEFAULT 0,
            debut_service TIME,
            fin_service TIME,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            FOREIGN KEY (chauffeur_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (ligne_id) REFERENCES lignes(id) ON DELETE SET NULL,
            FOREIGN KEY (bus_id) REFERENCES bus(id) ON DELETE SET NULL,
            INDEX idx_chauffeur_date (chauffeur_id, date_travail)
        ) ENGINE=InnoDB",
        'desc' => 'Stats quotidiennes chauffeur'
    ],
    
    'verification_codes' => [
        'sql' => "CREATE TABLE IF NOT EXISTS verification_codes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            code VARCHAR(6) NOT NULL,
            expires_at TIMESTAMP NOT NULL,
            used TINYINT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_user (user_id),
            INDEX idx_expires (expires_at)
        ) ENGINE=InnoDB",
        'desc' => 'Codes 2FA étudiants'
    ],
    
    'password_reset_requests' => [
        'sql' => "CREATE TABLE IF NOT EXISTS password_reset_requests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            message TEXT,
            statut ENUM('pending', 'approved', 'rejected') DEFAULT 'pending',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            processed_at TIMESTAMP NULL,
            processed_by INT,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (processed_by) REFERENCES users(id) ON DELETE SET NULL,
            INDEX idx_statut (statut),
            INDEX idx_user (user_id)
        ) ENGINE=InnoDB",
        'desc' => 'Demandes reset mdp chauffeur'
    ],
    
    'notifications' => [
        'sql' => "CREATE TABLE IF NOT EXISTS notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            titre VARCHAR(100) NOT NULL,
            message TEXT NOT NULL,
            type ENUM('info', 'success', 'warning', 'error') DEFAULT 'info',
            lue TINYINT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_user_lue (user_id, lue)
        ) ENGINE=InnoDB",
        'desc' => 'Notifications'
    ],
    
    'horaires' => [
        'sql' => "CREATE TABLE IF NOT EXISTS horaires (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ligne_id INT NOT NULL,
            jour ENUM('lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche') NOT NULL,
            heure_depart TIME NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (ligne_id) REFERENCES lignes(id) ON DELETE CASCADE,
            INDEX idx_ligne_jour (ligne_id, jour)
        ) ENGINE=InnoDB",
        'desc' => 'Horaires détaillés'
    ]
];

echo "<div class='card'>";
echo "<h2 class='text-xl font-bold mb-4'>Tables</h2>";
foreach ($tables as $name => $info) {
    try {
        $pdo->exec($info['sql']);
        echo "<div class='success'>✓ $name</div>";
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'already exists') !== false) {
            echo "<div class='info'>ℹ $name (existe)</div>";
        } else {
            echo "<div class='error'>✗ $name: " . $e->getMessage() . "</div>";
        }
    }
}
echo "</div>";

// Données initiales
echo "<div class='card'>";
echo "<h2 class='text-xl font-bold mb-4'>Données initiales</h2>";

// Admin
try {
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute(['admin@cous.dz']);
    if (!$stmt->fetch()) {
        $hash = password_hash('buscous1', PASSWORD_BCRYPT);
        $pdo->prepare("INSERT INTO users (email, password, role, nom, prenom, statut) VALUES (?, ?, 'admin', 'Admin', 'Système', 'validated')")
            ->execute(['admin@cous.dz', $hash]);
        echo "<div class='success'>✓ Admin: admin@cous.dz / buscous1</div>";
    } else {
        echo "<div class='info'>ℹ Admin existe</div>";
    }
} catch (PDOException $e) {
    echo "<div class='error'>Admin: " . $e->getMessage() . "</div>";
}

// Lignes
try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM lignes");
    if ($stmt->fetchColumn() == 0) {
        $pdo->exec("INSERT INTO lignes (numero, nom, trajet_depart, trajet_arrivee, heure_debut, heure_fin, couleur) VALUES 
            ('L1', 'Campus Centre', 'Place des Martyrs', 'Campus Universitaire', '07:00:00', '19:00:00', '#3B82F6'),
            ('L2', 'Cité U', 'Cité Universitaire', 'Campus Universitaire', '07:30:00', '18:30:00', '#10B981'),
            ('L3', 'Aéroport', 'Aéroport Houari Boumediene', 'Campus Universitaire', '06:00:00', '22:00:00', '#F59E0B')");
        echo "<div class='success'>✓ Lignes ajoutées</div>";
    } else {
        echo "<div class='info'>ℹ Lignes existent</div>";
    }
} catch (PDOException $e) {
    echo "<div class='error'>Lignes: " . $e->getMessage() . "</div>";
}

// Arrêts
try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM arrets");
    if ($stmt->fetchColumn() == 0) {
        $pdo->exec("INSERT INTO arrets (ligne_id, nom, latitude, longitude, ordre) VALUES
            (1, 'Place des Martyrs', 36.7538, 3.0588, 1),
            (1, 'Centre Ville', 36.7550, 3.0600, 2),
            (1, 'Université Centrale', 36.7570, 3.0620, 3),
            (1, 'Campus Universitaire', 36.7590, 3.0640, 4),
            (2, 'Cité Universitaire', 36.7600, 3.0500, 1),
            (2, 'Résidence Étudiants', 36.7620, 3.0520, 2),
            (2, 'Campus Universitaire', 36.7640, 3.0540, 3),
            (3, 'Aéroport Houari Boumediene', 36.7700, 3.0400, 1),
            (3, 'Terminal Aérien', 36.7720, 3.0420, 2),
            (3, 'Route de l\'Aéroport', 36.7740, 3.0440, 3),
            (3, 'Campus Universitaire', 36.7760, 3.0460, 4)");
        echo "<div class='success'>✓ Arrêts ajoutés</div>";
    } else {
        echo "<div class='info'>ℹ Arrêts existent</div>";
    }
} catch (PDOException $e) {
    echo "<div class='error'>Arrêts: " . $e->getMessage() . "</div>";
}

// Bus
try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM bus");
    if ($stmt->fetchColumn() == 0) {
        $pdo->exec("INSERT INTO bus (ligne_id, immatriculation, marque, modele, places_totales, statut) VALUES
            (1, 'BUS-001', 'Mercedes', 'Citaro', 40, 'actif'),
            (2, 'BUS-002', 'Iveco', 'Daily', 40, 'actif'),
            (3, 'BUS-003', 'MAN', 'Lion''s City', 40, 'actif')");
        echo "<div class='success'>✓ Bus ajoutés</div>";
    } else {
        echo "<div class='info'>ℹ Bus existent</div>";
    }
} catch (PDOException $e) {
    echo "<div class='error'>Bus: " . $e->getMessage() . "</div>";
}

// Positions
try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM bus_positions");
    if ($stmt->fetchColumn() == 0) {
        $pdo->exec("INSERT INTO bus_positions (bus_id, ligne_id, latitude, longitude, vitesse, places_libres, places_totales, moteur, en_service, next_stop_id, direction) VALUES
            (1, 1, 36.7538, 3.0588, 35, 28, 40, TRUE, TRUE, 2, 'aller'),
            (2, 2, 36.7600, 3.0500, 0, 40, 40, FALSE, FALSE, NULL, 'aller'),
            (3, 3, 36.7700, 3.0400, 45, 15, 40, TRUE, TRUE, 8, 'aller')");
        echo "<div class='success'>✓ Positions ajoutées</div>";
    } else {
        echo "<div class='info'>ℹ Positions existent</div>";
    }
} catch (PDOException $e) {
    echo "<div class='error'>Positions: " . $e->getMessage() . "</div>";
}

echo "</div>";

// Afficher les tables
echo "<div class='card'>";
echo "<h2 class='text-xl font-bold mb-4'>Structure finale</h2>";
$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
echo "<div class='flex flex-wrap gap-2'>";
foreach ($tables as $t) {
    $cols = $pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema='$db' AND table_name='$t'")->fetchColumn();
    echo "<span class='bg-blue-600 px-3 py-1 rounded-full text-sm'>$t <span class='opacity-70'>($cols)</span></span>";
}
echo "</div>";
echo "</div>";

echo "<div class='card bg-green-900/30 border border-green-600'>
    <h2 class='text-xl font-bold mb-4 text-green-400'>✓ Terminé!</h2>
    <p class='mb-4'>La base de données est prête.</p>
    <div class='flex gap-4'>
        <a href='../index.html' class='bg-blue-600 px-4 py-2 rounded-lg text-white hover:bg-blue-700'>Accueil</a>
        <a href='../pages/auth/login.html' class='bg-green-600 px-4 py-2 rounded-lg text-white hover:bg-green-700'>Connexion</a>
    </div>
    <div class='mt-4 p-3 bg-green-900/50 rounded text-sm'>
        <strong>Compte Admin:</strong> admin@cous.dz / buscous1
    </div>
</div>";

echo "</div></body></html>";
?>
