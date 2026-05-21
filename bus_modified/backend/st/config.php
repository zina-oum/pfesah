<?php
return [
    // Fichier de données pour le mode local (serial → fichier)
    'allowed_dir'  => realpath(__DIR__ . '/../st_data'),
    'default_file' => __DIR__ . '/../st_data/donnees_capteur.txt',
    'max_file_size' => 2 * 1024 * 1024,

    // Token de sécurité partagé entre le serveur PHP et l'ESP32
    // IMPORTANT : changer cette valeur et mettre la même dans l'Arduino
    'import_token' => getenv('SENSOR_IMPORT_TOKEN') ?: 'BUS_ESP32_SECRET_2026',

    // Ligne connectée à l'ESP32 réel
    'ligne_reelle' => 'L1',

    // Port série pour le mode local (lire_serial.py)
    'serial_port'  => 'COM6',
    'serial_baud'  => 115200,
];
