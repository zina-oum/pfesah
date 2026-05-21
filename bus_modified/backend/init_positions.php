<?php
/**
 * init_positions.php
 * À appeler UNE SEULE FOIS depuis le navigateur :
 *   http://localhost/bus_modified/backend/init_positions.php
 *
 * Crée les entrées bus_positions manquantes pour tous les bus,
 * initialise leur position au premier arrêt de leur ligne.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/db.php';

$created = 0;
$fixed   = 0;

// Créer les bus_positions manquantes (0,0 = pas de GPS encore, chauffeur démarrera)
$stmt = $pdo->query("
    SELECT b.id as bus_id, b.ligne_id, b.places_totales
    FROM bus b
    LEFT JOIN bus_positions bp ON bp.bus_id = b.id
    WHERE bp.id IS NULL AND b.statut = 'actif'
");

$insert = $pdo->prepare("
    INSERT INTO bus_positions
        (bus_id, ligne_id, latitude, longitude, vitesse, acceleration,
         en_service, moteur, places_libres, places_totales, updated_at)
    VALUES (?, ?, 0, 0, 0, 0, 0, 0, ?, ?, NOW())
");

foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $total = $row['places_totales'] ?? 40;
    $insert->execute([$row['bus_id'], $row['ligne_id'], $total, $total]);
    $created++;
}

// (plus de correction 0,0 → Algiers : 0,0 signifie "pas de GPS réel")

// Résumé des lignes
$lignes = $pdo->query("
    SELECT l.id, l.numero, l.trajet_depart, l.trajet_arrivee,
           bp.latitude, bp.longitude, bp.vitesse, bp.places_libres, bp.places_totales, bp.en_service
    FROM lignes l
    LEFT JOIN bus b ON b.ligne_id = l.id
    LEFT JOIN bus_positions bp ON bp.bus_id = b.id
    ORDER BY l.id
")->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'success' => true,
    'created' => $created,
    'fixed'   => $fixed,
    'message' => "$created bus_positions créées, $fixed positions 0,0 corrigées → centre d'Alger",
    'lignes'  => $lignes
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
