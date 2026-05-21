<?php
/**
 * gps_receiver.php - Endpoint HTTP pour recevoir les données GPS de l'ESP32 via WiFi
 *
 * L'ESP32 envoie un POST JSON à cette URL depuis n'importe où avec connexion internet.
 * Fonctionne en local ET en hébergement distant.
 *
 * Format POST (JSON body) :
 *   {"token":"SECRET","bus_id":1,"lat":36.7538,"lng":3.0588,"speed":45.0,"accel":0.12,"temp":23.5,"engine":1,"service":1}
 *
 * Réponse :
 *   {"ok":true,"msg":"Position mise à jour"}
 *   {"ok":false,"error":"Token invalide"}
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Token');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Méthode non autorisée (POST requis)']);
    exit;
}

require_once __DIR__ . '/../db.php';
$config = require __DIR__ . '/config.php';

$body = file_get_contents('php://input');
if (!$body) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Corps de requête vide']);
    exit;
}

$data = json_decode($body, true);
if (!$data || !is_array($data)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'JSON invalide']);
    exit;
}

// Vérification du token (depuis body JSON ou header X-Token)
$receivedToken = $data['token'] ?? ($_SERVER['HTTP_X_TOKEN'] ?? '');
if ($receivedToken !== $config['import_token']) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Token invalide']);
    exit;
}

// Extraction des champs GPS
$busId    = intval($data['bus_id'] ?? $data['bus'] ?? 0);
$lat      = floatval($data['lat'] ?? $data['latitude'] ?? 0);
$lng      = floatval($data['lng'] ?? $data['longitude'] ?? 0);
$speed    = floatval($data['speed'] ?? $data['vitesse'] ?? 0);
$accel    = floatval($data['accel'] ?? $data['acceleration'] ?? 0);
$temp     = isset($data['temp']) || isset($data['temperature']) ? floatval($data['temp'] ?? $data['temperature']) : null;
$engine   = isset($data['engine']) ? (intval($data['engine']) > 0 ? 1 : 0) : ($speed > 2 ? 1 : 0);
$service  = isset($data['service']) ? (intval($data['service']) > 0 ? 1 : 0) : $engine;
$places   = isset($data['places']) ? max(0, intval($data['places'])) : null;
$dir      = $data['dir'] ?? $data['direction'] ?? null;
$km       = isset($data['km']) ? floatval($data['km']) : null;

if ($busId <= 0 || $lat == 0 || $lng == 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'bus_id, lat et lng sont requis et non nuls']);
    exit;
}

// Vérifier que le bus existe
$stmt = $pdo->prepare("SELECT id, ligne_id FROM bus WHERE id = ? OR immatriculation = ? LIMIT 1");
$stmt->execute([$busId, (string)$busId]);
$bus = $stmt->fetch();

if (!$bus) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => "Bus $busId introuvable en base"]);
    exit;
}

$busDbId  = $bus['id'];
$ligneId  = $bus['ligne_id'];

try {
    $pdo->prepare("
        INSERT INTO bus_positions
            (bus_id, ligne_id, latitude, longitude, vitesse, acceleration, temperature, moteur, en_service, places_libres, direction, km_parcourus, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
            ligne_id      = VALUES(ligne_id),
            latitude      = VALUES(latitude),
            longitude     = VALUES(longitude),
            vitesse       = VALUES(vitesse),
            acceleration  = VALUES(acceleration),
            temperature   = COALESCE(VALUES(temperature), temperature),
            moteur        = VALUES(moteur),
            en_service    = VALUES(en_service),
            places_libres = COALESCE(VALUES(places_libres), places_libres),
            direction     = COALESCE(VALUES(direction), direction),
            km_parcourus  = COALESCE(VALUES(km_parcourus), km_parcourus),
            updated_at    = NOW()
    ")->execute([$busDbId, $ligneId, $lat, $lng, $speed, $accel, $temp, $engine, $service, $places, $dir, $km]);

    $pdo->prepare("
        INSERT INTO historique_positions (bus_id, ligne_id, latitude, longitude, vitesse, created_at)
        VALUES (?, ?, ?, ?, ?, NOW())
    ")->execute([$busDbId, $ligneId, $lat, $lng, $speed]);

    echo json_encode([
        'ok'  => true,
        'msg' => 'Position mise à jour',
        'bus' => $busDbId,
        'lat' => $lat,
        'lng' => $lng,
        'spd' => $speed,
        'ts'  => date('H:i:s'),
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
