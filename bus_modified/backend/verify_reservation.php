<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/db.php';

// Lire les données — accepte GET, POST form-data, ou JSON body
$body = file_get_contents('php://input');
$json = $body ? json_decode($body, true) : null;

// Le QR contient un JSON avec : res, ligne, etudiant, date, heure, arret, exp
$qrJson       = $json['qr_data'] ?? null;   // JSON string brut du QR
$reservationId = intval($json['reservation_id'] ?? $_GET['reservation_id'] ?? $_POST['reservation_id'] ?? 0);
$markUsed      = !empty($json['mark_used']); // true = valider le ticket

// Si on reçoit le JSON brut du QR, le parser
if ($qrJson) {
    $qr = json_decode($qrJson, true);
    if ($qr) {
        $reservationId = intval($qr['res'] ?? 0);

        // Vérifier l'expiration côté client (timestamp)
        $exp = intval($qr['exp'] ?? 0);
        if ($exp > 0 && $exp < (time() * 1000)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'QR code expiré']);
            exit;
        }
    }
}

if ($reservationId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'QR invalide — aucun identifiant de réservation']);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT r.id, r.user_id, r.ligne_id, r.arret_descente_id,
               r.date_reservation, r.heure_reservation, r.statut,
               a.nom AS arret_descente,
               l.numero AS ligne_numero, l.nom AS ligne_nom,
               l.trajet_depart, l.trajet_arrivee, l.heure_fin,
               u.nom AS user_nom, u.prenom AS user_prenom,
               u.code_etudiant, u.email AS user_email
        FROM reservations r
        LEFT JOIN arrets a ON r.arret_descente_id = a.id
        JOIN lignes l ON r.ligne_id = l.id
        JOIN users u ON r.user_id = u.id
        WHERE r.id = ? AND r.date_reservation = CURDATE()
    ");
    $stmt->execute([$reservationId]);
    $reservation = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$reservation) {
        $check = $pdo->prepare("SELECT statut, date_reservation FROM reservations WHERE id = ?");
        $check->execute([$reservationId]);
        $row = $check->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            echo json_encode(['success' => false, 'message' => 'Réservation introuvable']);
        } elseif ($row['date_reservation'] !== date('Y-m-d')) {
            echo json_encode(['success' => false, 'message' => 'QR expiré — valable uniquement le jour de réservation']);
        } else {
            $labels = ['annulee' => 'Réservation annulée', 'expiree' => 'Réservation expirée (déconnexion)', 'utilisee' => 'Ticket déjà utilisé'];
            echo json_encode(['success' => false, 'message' => $labels[$row['statut']] ?? 'Réservation invalide']);
        }
        exit;
    }

    if ($reservation['statut'] === 'utilisee') {
        echo json_encode(['success' => false, 'message' => 'Ticket déjà utilisé', 'reservation' => $reservation]);
        exit;
    }

    if ($reservation['statut'] !== 'active') {
        echo json_encode(['success' => false, 'message' => 'Ticket ' . $reservation['statut'], 'reservation' => $reservation]);
        exit;
    }

    // Vérifier que la ligne est encore en service
    $heureFin = $reservation['heure_fin'] ?? null;
    if ($heureFin && date('H:i:s') > $heureFin) {
        echo json_encode(['success' => false, 'message' => 'Service terminé pour cette ligne']);
        exit;
    }

    // Marquer comme utilisé si demandé par le chauffeur
    if ($markUsed) {
        $pdo->prepare("UPDATE reservations SET statut = 'utilisee', updated_at = NOW() WHERE id = ?")
            ->execute([$reservationId]);
        $reservation['statut'] = 'utilisee';
    }

    echo json_encode(['success' => true, 'reservation' => $reservation, 'marked_used' => $markUsed]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erreur serveur', 'error' => $e->getMessage()]);
}
