<?php
@ini_set('display_errors', 0);
// Backend shared DB connection and helper functions
// Configuration: lit d'abord db_access.json, puis variables d'environnement, puis défaut XAMPP
$configFile = __DIR__ . '/../config/db_access.json';
if (file_exists($configFile)) {
    $json = json_decode(file_get_contents($configFile), true);
    $host    = $json['host'] ?? '127.0.0.1';
    $port    = $json['port'] ?? 3306;
    $database= $json['database'] ?? 'bus_transport';
    $user    = $json['username'] ?? 'root';
    $pass    = $json['password'] ?? '';
} else {
    $host    = getenv('DB_HOST') ?: '127.0.0.1';
    $port    = getenv('DB_PORT') ?: 3306;
    $database= getenv('DB_NAME') ?: 'bus_transport';
    $user    = getenv('DB_USER') ?: 'root';
    $pass    = getenv('DB_PASS') ?: '';
}
$charset = 'utf8mb4';

$dsnServer = "mysql:host=$host;port=$port;charset=$charset";
$dsnDatabase = "mysql:host=$host;port=$port;dbname=$database;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

try {
    $pdo = new PDO($dsnDatabase, $user, $pass, $options);
} catch (PDOException $e) {
    $message = $e->getMessage();
    if (strpos($message, 'Unknown database') !== false || strpos($message, '1049') !== false) {
        try {
            $pdo = new PDO($dsnServer, $user, $pass, $options);
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `$database` CHARACTER SET $charset COLLATE {$charset}_unicode_ci");
            $pdo = new PDO($dsnDatabase, $user, $pass, $options);
        } catch (PDOException $inner) {
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Erreur lors de la création de la base de données', 'error' => $inner->getMessage()]);
            exit;
        }
    } else {
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Erreur de connexion à la base de données', 'error' => $message]);
        exit;
    }
}

function sanitize($value) {
    if (is_array($value)) {
        return array_map('sanitize', $value);
    }
    return trim(filter_var($value, FILTER_SANITIZE_STRING, FILTER_FLAG_NO_ENCODE_QUOTES));
}

function hashPassword($password) {
    return password_hash($password, PASSWORD_BCRYPT);
}
