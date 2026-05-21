-- =============================================
-- BUS Transport - Structure de la Base de Données
-- =============================================
-- Si la base existe déjà, exécuter ces commandes pour ajouter les colonnes manquantes :
--   ALTER TABLE bus_positions ADD COLUMN acceleration DECIMAL(5,2) DEFAULT 0 COMMENT 'm/s²' AFTER vitesse;
--   ALTER TABLE reservations ADD COLUMN arret_descente_id INT DEFAULT NULL AFTER ligne_id;
--   ALTER TABLE bus ADD COLUMN chauffeur_id INT DEFAULT NULL AFTER ligne_id;
--   ALTER TABLE bus ADD CONSTRAINT fk_bus_chauffeur FOREIGN KEY (chauffeur_id) REFERENCES users(id) ON DELETE SET NULL;
--   ALTER TABLE reservations DROP INDEX unique_reservation_per_day;

-- Utilisation de la base
CREATE DATABASE IF NOT EXISTS bus_transport CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE bus_transport;

-- =============================================
-- TABLE: users (Tous les utilisateurs)
-- =============================================
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin', 'chauffeur', 'etudiant') NOT NULL,
    nom VARCHAR(50) NOT NULL,
    prenom VARCHAR(50) NOT NULL,
    telephone VARCHAR(20),
    code_etudiant VARCHAR(10) UNIQUE,
    photo VARCHAR(500),
    statut ENUM('pending', 'validated', 'rejected') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_role (role),
    INDEX idx_statut (statut),
    INDEX idx_email (email)
);

-- =============================================
-- TABLE: lignes (Lignes de bus)
-- =============================================
CREATE TABLE IF NOT EXISTS lignes (
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
    INDEX idx_actif (actif),
    INDEX idx_actif_numero (actif, numero)
);

-- =============================================
-- TABLE: arrets (Arrêts de bus par ligne)
-- =============================================
CREATE TABLE IF NOT EXISTS arrets (
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
);

-- =============================================
-- TABLE: bus (Les bus eux-mêmes)
-- =============================================
CREATE TABLE IF NOT EXISTS bus (
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
);

-- =============================================
-- TABLE: affectation_chauffeur (Affectation chauffeur-ligne)
-- =============================================
CREATE TABLE IF NOT EXISTS affectation_chauffeur (
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
);

-- =============================================
-- TABLE: bus_positions (Position GPS et données capteurs)
-- =============================================
CREATE TABLE IF NOT EXISTS bus_positions (
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
);

-- =============================================
-- TABLE: historique_positions (Historique GPS)
-- =============================================
CREATE TABLE IF NOT EXISTS historique_positions (
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
);

-- =============================================
-- TABLE: reservations (Réservations étudiants)
-- =============================================
CREATE TABLE IF NOT EXISTS reservations (
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
);

-- =============================================
-- TABLE: incidents (Incidents bus)
-- =============================================
CREATE TABLE IF NOT EXISTS incidents (
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
);

-- =============================================
-- TABLE: signalements (Signalements étudiants)
-- =============================================
CREATE TABLE IF NOT EXISTS signalements (
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
);

-- =============================================
-- TABLE: historique_trajets (Stats quotidiennes chauffeur)
-- =============================================
CREATE TABLE IF NOT EXISTS historique_trajets (
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
);

-- =============================================
-- TABLE: verification_codes (2FA étudiants)
-- =============================================
CREATE TABLE IF NOT EXISTS verification_codes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    code VARCHAR(6) NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    used TINYINT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user (user_id),
    INDEX idx_expires (expires_at)
);

-- =============================================
-- TABLE: password_reset_requests (Reset mdp chauffeur)
-- =============================================
CREATE TABLE IF NOT EXISTS password_reset_requests (
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
);

-- =============================================
-- TABLE: notifications
-- =============================================
CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    titre VARCHAR(100) NOT NULL,
    message TEXT NOT NULL,
    type ENUM('info', 'success', 'warning', 'error') DEFAULT 'info',
    lue TINYINT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_lue (user_id, lue)
);

-- =============================================
-- TABLE: horaires (Horaires détaillés)
-- =============================================
CREATE TABLE IF NOT EXISTS horaires (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ligne_id INT NOT NULL,
    jour ENUM('lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche') NOT NULL,
    heure_depart TIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (ligne_id) REFERENCES lignes(id) ON DELETE CASCADE,
    INDEX idx_ligne_jour (ligne_id, jour)
);

-- =============================================
-- Données initiales: Admin
-- =============================================
INSERT IGNORE INTO users (email, password, role, nom, prenom, statut) 
VALUES ('admin@cous.dz', '$2y$12$e98N9qSbqB3O1GuFfdcOCeZFePhcxh9HuRuWntYnPprNE2MyWkoHa', 'admin', 'Admin', 'Système', 'validated');

