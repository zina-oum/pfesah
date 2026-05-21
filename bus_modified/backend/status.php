<?php
/**
 * Vérifier l'état en temps réel :
 * http://localhost/bus_modified/backend/status.php
 */
header('Content-Type: application/json');
require_once __DIR__ . '/db.php';

$buses = $pdo->query("
    SELECT b.id as bus_id, l.numero, l.trajet_depart, l.trajet_arrivee,
           bp.latitude, bp.longitude, bp.vitesse, bp.en_service,
           bp.places_libres, bp.places_totales,
           bp.updated_at,
           TIMESTAMPDIFF(SECOND, bp.updated_at, NOW()) as secondes_depuis_update
    FROM bus b
    JOIN lignes l ON b.ligne_id = l.id
    LEFT JOIN bus_positions bp ON bp.bus_id = b.id
    ORDER BY l.id
")->fetchAll(PDO::FETCH_ASSOC);

$simulation_ok = false;
foreach ($buses as $b) {
    if ($b['secondes_depuis_update'] !== null && $b['secondes_depuis_update'] < 10) {
        $simulation_ok = true;
        break;
    }
}

echo json_encode([
    'simulation_active' => $simulation_ok,
    'message'           => $simulation_ok
        ? '✅ Simulation en cours (updated < 10s)'
        : '❌ Simulation arrêtée ou non démarrée',
    'buses'             => $buses,
    'heure_serveur'     => date('H:i:s'),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
