<?php
/**
 * import_sensor_data.php - Import des données capteurs ST depuis un fichier TXT
 *
 * Lit un fichier texte (ex: donnees_capteur.txt) contenant les données
 * envoyées par le microcontrôleur STM32 via liaison série.
 * Décode les données et les insère dans bus_positions et historique_positions.
 *
 * Formats supportés (auto-détection) :
 *   - JSON      : {"bus_id":1,"lat":36.7538,"lng":3.0588,"speed":35.0,"accel":0.5,"temp":25.3,"engine":1,"service":1}
 *   - CSV       : bus_id;lat;lng;speed;accel;temp;engine;service\n1;36.7538;3.0588;35.0;0.5;25.3;1;1
 *   - Key=Value : bus=1 lat=36.7538 lng=3.0588 speed=35.0 accel=0.5 temp=25.3 engine=1 service=1
 *   - NMEA-like : $BUS,1,36.7538,3.0588,35.0,0.5,25.3,1,1*XX
 *
 * Utilisation :
 *   php import_sensor_data.php --file=backend/st_data/donnees_capteur.txt
 *   php import_sensor_data.php --file=backend/st_data/donnees_capteur.txt --watch
 *   php import_sensor_data.php --file=backend/st_data/donnees_capteur.txt --watch --interval=2
 */

require_once __DIR__ . '/../db.php';

$config = require __DIR__ . '/config.php';

function logLine($message) {
    echo '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
}

function parseArgs() {
    global $argv;
    $params = [
        'file' => null,
        'watch' => false,
        'interval' => 1,
    ];
    foreach ($argv as $arg) {
        if (strpos($arg, '--file=') === 0) {
            $params['file'] = substr($arg, 7);
        } elseif ($arg === '--watch') {
            $params['watch'] = true;
        } elseif (strpos($arg, '--interval=') === 0) {
            $params['interval'] = max(1, intval(substr($arg, 11)));
        }
    }
    return $params;
}

// ===== DECODEURS DE FORMAT =====

function tryDecodeJson($line) {
    $data = json_decode($line, true);
    if (!$data || !is_array($data)) return null;
    return normalizeFields($data);
}

function tryDecodeKeyValue($line) {
    if (!preg_match('/\w+[=:]/', $line)) return null;
    $data = [];
    preg_match_all('/(\w+)\s*[=:]\s*([^\s,;]+)/', $line, $matches, PREG_SET_ORDER);
    foreach ($matches as $m) {
        $data[strtolower($m[1])] = $m[2];
    }
    return empty($data) ? null : normalizeFields($data);
}

function tryDecodeNmea($line) {
    if (strpos($line, '$BUS') !== 0) return null;
    $parts = explode(',', $line);
    $checksum = explode('*', end($parts));
    $parts[count($parts) - 1] = $checksum[0];
    if (count($parts) < 8) return null;

    return normalizeFields([
        'bus_id' => trim($parts[1] ?? ''),
        'lat' => trim($parts[2] ?? ''),
        'lng' => trim($parts[3] ?? ''),
        'speed' => trim($parts[4] ?? ''),
        'accel' => trim($parts[5] ?? ''),
        'temp' => trim($parts[6] ?? ''),
        'engine' => trim($parts[7] ?? ''),
        'service' => trim($parts[8] ?? '0'),
    ]);
}

function tryDecodeCsv($line, &$header = null) {
    $parts = str_getcsv($line, ';');
    if (count($parts) === 0) return null;

    if ($header === null) {
        $lower = array_map('strtolower', $parts);
        $knownHeaders = ['bus_id','lat','lng','speed','accel','temp','engine','service','acceleration','temperature','vitesse','latitude','longitude'];
        $matchCount = count(array_intersect($lower, $knownHeaders));
        if ($matchCount >= 3) {
            $header = $lower;
            return null;
        }
    }

    if ($header !== null) {
        $data = array_combine($header, $parts);
        return $data ? normalizeFields($data) : null;
    }

    if (count($parts) >= 4 && is_numeric($parts[0])) {
        $keys = ['bus_id','lat','lng','speed','accel','temp','engine','service'];
        $data = [];
        foreach ($keys as $i => $k) {
            if (isset($parts[$i])) $data[$k] = $parts[$i];
        }
        return normalizeFields($data);
    }

    return null;
}

// ===== NORMALISATION DES CHAMPS =====

function normalizeFields($raw) {
    $map = [
        'bus_id' => null, 'bus' => 'bus_id', 'id' => 'bus_id', 'busid' => 'bus_id',
        'latitude' => null, 'lat' => 'latitude',
        'longitude' => null, 'lng' => 'longitude', 'lon' => 'longitude',
        'speed' => 'vitesse', 'vitesse' => null, 'v' => 'vitesse', 'spd' => 'vitesse',
        'acceleration' => null, 'accel' => 'acceleration', 'acc' => 'acceleration', 'a' => 'acceleration',
        'temperature' => null, 'temp' => 'temperature', 't' => 'temperature', 'tmp' => 'temperature',
        'engine' => 'moteur', 'moteur' => null, 'e' => 'moteur', 'eng' => 'moteur',
        'service' => 'en_service', 'en_service' => null, 'srv' => 'en_service',
        'places_libres' => null, 'seats' => 'places_libres', 'places' => 'places_libres',
        'direction' => null, 'dir' => 'direction',
        'km' => 'km_parcourus', 'km_parcourus' => null,
        'next_stop' => 'next_stop_id', 'nextstop' => 'next_stop_id',
    ];

    $normalized = [];
    foreach ($raw as $key => $value) {
        $k = strtolower(trim($key));
        $target = $map[$k] ?? $k;
        $normalized[$target] = $value;
    }

    if (!isset($normalized['bus_id']) && !isset($normalized['ligne_id'])) return null;
    if (!isset($normalized['latitude']) && !isset($normalized['longitude'])) return null;

    return $normalized;
}

function decodeLine($line, &$csvHeader = null) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#' || $line[0] === '/') return null;

    $decoded = tryDecodeJson($line);
    if ($decoded) return $decoded;

    $decoded = tryDecodeNmea($line);
    if ($decoded) return $decoded;

    $decoded = tryDecodeKeyValue($line);
    if ($decoded) return $decoded;

    $decoded = tryDecodeCsv($line, $csvHeader);
    return $decoded;
}

function getBusInfo($pdo, $busIdOrImmat) {
    static $cache = [];
    $key = (string)$busIdOrImmat;
    if (isset($cache[$key])) return $cache[$key];

    $stmt = $pdo->prepare("SELECT id, ligne_id FROM bus WHERE id = ? OR immatriculation = ? LIMIT 1");
    $stmt->execute([$busIdOrImmat, $busIdOrImmat]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $cache[$key] = $result ?: null;
    return $cache[$key];
}

function importData($pdo, array $data) {
    $busInfo = getBusInfo($pdo, $data['bus_id']);
    if (!$busInfo) {
        logLine("ERREUR: Bus '{$data['bus_id']}' introuvable");
        return false;
    }

    $busId = $busInfo['id'];
    $ligneId = $busInfo['ligne_id'];

    $latitude = floatval($data['latitude'] ?? 0);
    $longitude = floatval($data['longitude'] ?? 0);
    $vitesse = floatval($data['vitesse'] ?? 0);
    $acceleration = floatval($data['acceleration'] ?? 0);
    $temperature = isset($data['temperature']) ? floatval($data['temperature']) : null;
    $moteur = isset($data['moteur']) ? (intval($data['moteur']) > 0 ? 1 : 0) : null;
    $en_service = isset($data['en_service']) ? (intval($data['en_service']) > 0 ? 1 : 0) :
                  ($vitesse > 5 ? 1 : 0);
    $places_libres = isset($data['places_libres']) ? max(0, intval($data['places_libres'])) : null;
    $direction = $data['direction'] ?? null;
    $km_parcourus = isset($data['km_parcourus']) ? floatval($data['km_parcourus']) : null;
    $next_stop_id = isset($data['next_stop_id']) ? intval($data['next_stop_id']) : null;

    try {
        $pdo->prepare("
            INSERT INTO bus_positions (bus_id, ligne_id, latitude, longitude, vitesse, acceleration, temperature, moteur, en_service, places_libres, direction, km_parcourus, next_stop_id, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                ligne_id = VALUES(ligne_id),
                latitude = VALUES(latitude),
                longitude = VALUES(longitude),
                vitesse = VALUES(vitesse),
                acceleration = VALUES(acceleration),
                temperature = VALUES(temperature),
                moteur = VALUES(moteur),
                en_service = VALUES(en_service),
                places_libres = COALESCE(VALUES(places_libres), bus_positions.places_libres),
                direction = VALUES(direction),
                km_parcourus = VALUES(km_parcourus),
                next_stop_id = VALUES(next_stop_id),
                updated_at = NOW()
        ")->execute([$busId, $ligneId, $latitude, $longitude, $vitesse, $acceleration,
                     $temperature, $moteur, $en_service, $places_libres, $direction,
                     $km_parcourus, $next_stop_id]);

        $pdo->prepare("
            INSERT INTO historique_positions (bus_id, ligne_id, latitude, longitude, vitesse, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ")->execute([$busId, $ligneId, $latitude, $longitude, $vitesse]);

        logLine("OK bus=$busId (ligne=$ligneId) pos=$latitude,$longitude speed={$vitesse}km/h" .
                ($temperature !== null ? " temp={$temperature}°C" : "") .
                ($moteur !== null ? " engine=" . ($moteur ? 'on' : 'off') : ""));

        return true;
    } catch (Exception $e) {
        logLine("ERREUR import: " . $e->getMessage());
        return false;
    }
}

function processFile($pdo, $filePath, $config) {
    if (!file_exists($filePath)) {
        logLine("Fichier introuvable: $filePath");
        return 0;
    }

    $maxSize = $config['max_file_size'] ?? 2 * 1024 * 1024;
    if (filesize($filePath) > $maxSize) {
        logLine("Fichier trop volumineux: " . filesize($filePath) . " > $maxSize");
        return 0;
    }

    $handle = fopen($filePath, 'r');
    if (!$handle) {
        logLine("Impossible d'ouvrir le fichier: $filePath");
        return 0;
    }

    $count = 0;
    $csvHeader = null;

    while (($line = fgets($handle)) !== false) {
        $data = decodeLine($line, $csvHeader);
        if ($data) {
            if (importData($pdo, $data)) $count++;
        }
    }

    fclose($handle);
    return $count;
}

// ===== SCRIPT PRINCIPAL =====

$params = parseArgs();

if (!$params['file']) {
    $params['file'] = $config['default_file'];
}

logLine("=== Import données capteurs ST ===");
logLine("Fichier: {$params['file']}");
logLine("Mode: " . ($params['watch'] ? 'WATCH (intervalle: ' . $params['interval'] . 's)' : 'UNE FOIS'));

if ($params['watch']) {
    logLine("Surveillance du fichier en temps réel...");
    $lastSize = 0;
    if (file_exists($params['file'])) {
        $lastSize = filesize($params['file']);
    }

    while (true) {
        if (!file_exists($params['file'])) {
            logLine("Fichier supprimé ou déplacé, attente...");
            sleep($params['interval']);
            continue;
        }

        $currentSize = filesize($params['file']);
        if ($currentSize > $lastSize) {
            $handle = fopen($params['file'], 'r');
            if ($handle) {
                fseek($handle, $lastSize);
                $csvHeader = null;
                $count = 0;
                while (($line = fgets($handle)) !== false) {
                    $data = decodeLine($line, $csvHeader);
                    if ($data) {
                        if (importData($pdo, $data)) $count++;
                    }
                }
                fclose($handle);
                if ($count > 0) logLine("$count ligne(s) importée(s)");
            }
            $lastSize = $currentSize;
        } elseif ($currentSize < $lastSize) {
            logLine("Fichier réinitialisé (rotation)");
            $lastSize = 0;
        }

        sleep($params['interval']);
    }
} else {
    $count = processFile($pdo, $params['file'], $config);
    logLine("Import terminé: $count ligne(s) traitée(s)");
}
