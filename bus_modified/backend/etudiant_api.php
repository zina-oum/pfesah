<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/db.php';

$data = json_decode(file_get_contents('php://input'), true);
$action = sanitize($data['action'] ?? '');

$user_id = intval($data['user_id'] ?? 0);

// SECURITY: verify role from DB, never trust client-sent role
if ($user_id <= 0) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentification requise']);
    exit;
}
$_roleStmt = $pdo->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
$_roleStmt->execute([$user_id]);
$_roleRow = $_roleStmt->fetch(PDO::FETCH_ASSOC);
if (!$_roleRow || $_roleRow['role'] !== 'etudiant') {
    http_response_code(403);
    echo json_encode(['error' => 'Accès refusé']);
    exit;
}

// ===== MON PROFIL =====
if ($action === 'profile') {
    $stmt = $pdo->prepare("SELECT id, email, nom, prenom, telephone, code_etudiant, photo, statut, created_at FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    echo json_encode($user);
    exit;
}

// ===== PHOTO DE PROFIL =====
if ($action === 'get_photo') {
    $stmt = $pdo->prepare("SELECT photo FROM users WHERE id = ? AND role = 'etudiant'");
    $stmt->execute([$user_id]);
    $photo = $stmt->fetchColumn();
    echo $photo ?: '';
    exit;
}

// ===== CHANGER MOT DE PASSE =====
if ($action === 'change_password') {
    $current = $data['current_password'] ?? '';
    $new = $data['new_password'] ?? '';
    
    if (strlen($new) < 6) {
        echo json_encode(['success' => false, 'message' => 'Mot de passe min 6 caractères']);
        exit;
    }
    
    $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();
    
    if (!$user || !password_verify($current, $user['password'])) {
        echo json_encode(['success' => false, 'message' => 'Mot de passe actuel incorrect']);
        exit;
    }
    
    $hashed = hashPassword($new);
    $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([$hashed, $user_id]);
    
    echo json_encode(['success' => true, 'message' => 'Mot de passe changé']);
    exit;
}

// ===== METTRE A JOUR PROFIL =====
if ($action === 'update_profile') {
    $nom = sanitize($data['nom'] ?? '');
    $prenom = sanitize($data['prenom'] ?? '');
    $telephone = sanitize($data['telephone'] ?? '');

    if (!$nom || !$prenom) {
        echo json_encode(['success' => false, 'message' => 'Nom et prénom requis']);
        exit;
    }

    if ($telephone !== '' && !preg_match('/^[0-9]{10}$/', $telephone)) {
        echo json_encode(['success' => false, 'message' => 'Téléphone invalide']);
        exit;
    }

    $stmt = $pdo->prepare("UPDATE users SET nom = ?, prenom = ?, telephone = ? WHERE id = ?");
    $stmt->execute([$nom, $prenom, $telephone, $user_id]);

    echo json_encode(['success' => true, 'message' => 'Profil mis à jour']);
    exit;
}

// ===== MES RESERVATIONS =====
if ($action === 'my_reservations') {
    $stmt = $pdo->prepare("SELECT r.*, a.nom AS arret_descente, l.numero, l.nom, l.trajet_depart, l.trajet_arrivee, l.heure_debut, l.heure_fin, l.couleur
                            FROM reservations r 
                            LEFT JOIN arrets a ON r.arret_descente_id = a.id
                            JOIN lignes l ON r.ligne_id = l.id 
                            WHERE r.user_id = ? AND r.date_reservation >= CURDATE() AND r.statut = 'active'
                            ORDER BY r.date_reservation, r.heure_reservation");
    $stmt->execute([$user_id]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// ===== HISTORIQUE RESERVATIONS =====
if ($action === 'reservation_history') {
    $stmt = $pdo->prepare("SELECT r.*, a.nom AS arret_descente, l.numero, l.nom, l.trajet_depart, l.trajet_arrivee, l.couleur
                            FROM reservations r 
                            LEFT JOIN arrets a ON r.arret_descente_id = a.id
                            JOIN lignes l ON r.ligne_id = l.id 
                            WHERE r.user_id = ?
                            ORDER BY r.date_reservation DESC, r.heure_reservation DESC
                            LIMIT 50");
    $stmt->execute([$user_id]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// ===== RESERVER =====
if ($action === 'reserve') {
    $ligne_id = intval($data['ligne_id'] ?? 0);
    $arret_descente_id = intval($data['arret_descente'] ?? 0);
    
    if ($ligne_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Ligne invalide']);
        exit;
    }
    
    if ($arret_descente_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Sélectionnez un arrêt de descente valide']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT id FROM arrets WHERE id = ? AND ligne_id = ? LIMIT 1");
    $stmt->execute([$arret_descente_id, $ligne_id]);
    if (!$stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Arrêt de descente invalide pour cette ligne']);
        exit;
    }
    
    $stmt = $pdo->prepare("SELECT r.id FROM reservations r
        JOIN lignes l ON r.ligne_id = l.id
        WHERE r.user_id = ? AND r.ligne_id = ? AND r.date_reservation = CURDATE() AND r.statut = 'active'
        AND TIMESTAMPDIFF(MINUTE, r.heure_reservation, CURTIME()) < COALESCE(l.interval_minutes, 30)");
    $stmt->execute([$user_id, $ligne_id]);
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Vous avez déjà réservé sur ce créneau. Attendez le prochain passage pour réserver à nouveau.']);
        exit;
    }
    
    // Initialise bus_positions si absent (nouvelle ligne sans entrée)
    $pdo->prepare("INSERT INTO bus_positions (bus_id, ligne_id, places_libres, places_totales, latitude, longitude)
        SELECT b.id, b.ligne_id, b.places_totales, b.places_totales,
               COALESCE(a.latitude, 0), COALESCE(a.longitude, 0)
        FROM bus b
        LEFT JOIN bus_positions bp ON bp.bus_id = b.id
        LEFT JOIN arrets a ON a.id = (SELECT id FROM arrets WHERE ligne_id = b.ligne_id ORDER BY ordre LIMIT 1)
        WHERE b.ligne_id = ? AND bp.id IS NULL
        LIMIT 1")
        ->execute([$ligne_id]);

    $stmt = $pdo->prepare("SELECT bp.places_libres FROM bus_positions bp
                            JOIN bus b ON bp.bus_id = b.id
                            WHERE b.ligne_id = ?");
    $stmt->execute([$ligne_id]);
    $bp = $stmt->fetch();

    if (!$bp || $bp['places_libres'] <= 0) {
        echo json_encode(['success' => false, 'message' => 'Plus de places disponibles']);
        exit;
    }
    
    $stmt = $pdo->prepare("INSERT INTO reservations (user_id, ligne_id, arret_descente_id, date_reservation, heure_reservation) VALUES (?, ?, ?, CURDATE(), CURTIME())");
    $stmt->execute([$user_id, $ligne_id, $arret_descente_id]);
    $reservation_id = $pdo->lastInsertId();

    $pdo->prepare("INSERT INTO bus_positions (bus_id, ligne_id, places_libres, places_totales, latitude, longitude)
        SELECT b.id, b.ligne_id, 40, 40, COALESCE(bp.latitude, a.latitude, 0), COALESCE(bp.longitude, a.longitude, 0)
        FROM bus b
        LEFT JOIN bus_positions bp ON bp.bus_id = b.id
        LEFT JOIN arrets a ON a.id = (SELECT id FROM arrets WHERE ligne_id = b.ligne_id ORDER BY ordre LIMIT 1)
        WHERE b.ligne_id = ? AND bp.id IS NULL
        LIMIT 1")
        ->execute([$ligne_id]);

    $pdo->prepare("UPDATE bus_positions bp SET bp.places_libres = GREATEST(bp.places_libres - 1, 0)
        WHERE bp.bus_id = (
            SELECT id FROM bus WHERE ligne_id = ? LIMIT 1
        ) AND bp.places_libres > 0")
        ->execute([$ligne_id]);
    
    $stmt = $pdo->prepare("SELECT DATE_FORMAT(NOW(), '%Y-%m-%d') AS date_reservation, DATE_FORMAT(NOW(), '%H:%i:%s') AS heure_reservation");
    $stmt->execute();
    $timestamps = $stmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'message' => 'Réservation confirmée!',
        'reservation_id' => $reservation_id,
        'arret_descente_id' => $arret_descente_id,
        'date_reservation' => $timestamps['date_reservation'],
        'heure_reservation' => $timestamps['heure_reservation']
    ]);
    exit;
}

// ===== ANNULER RESERVATION =====
if ($action === 'cancel_reservation') {
    $reservation_id = intval($data['reservation_id'] ?? 0);
    
    $stmt = $pdo->prepare("SELECT ligne_id FROM reservations WHERE id = ? AND user_id = ? AND statut = 'active'");
    $stmt->execute([$reservation_id, $user_id]);
    $res = $stmt->fetch();
    
    if ($res) {
        $pdo->prepare("UPDATE bus_positions bp JOIN bus b ON bp.bus_id = b.id SET bp.places_libres = bp.places_libres + 1 WHERE b.ligne_id = ?")
            ->execute([$res['ligne_id']]);
        $pdo->prepare("UPDATE reservations SET statut = 'annulee' WHERE id = ?")->execute([$reservation_id]);
        echo json_encode(['success' => true, 'message' => 'Réservation annulée']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Réservation non trouvée']);
    }
    exit;
}

// ===== SIGNALER =====
if ($action === 'report') {
    $ligne_id = intval($data['ligne_id'] ?? 0);
    $type = sanitize($data['type'] ?? '');
    $description = sanitize($data['description'] ?? '');
    
    $stmt = $pdo->prepare("INSERT INTO signalements (user_id, ligne_id, type, description) VALUES (?, ?, ?, ?)");
    $stmt->execute([$user_id, $ligne_id > 0 ? $ligne_id : null, $type, $description]);
    
    echo json_encode(['success' => true, 'message' => 'Signalement envoyé!']);
    exit;
}

// ===== UPLOAD PHOTO =====
if ($action === 'upload_photo') {
    $photo = sanitize($_POST['photo'] ?? '');
    if ($photo && preg_match('/^data:image\/\w+;base64,/', $photo)) {
        $pdo->prepare("UPDATE users SET photo = ? WHERE id = ?")->execute([$photo, $user_id]);
        echo json_encode(['success' => true, 'message' => 'Photo mise à jour']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Aucune photo']);
    }
    exit;
}

// ===== GET PHOTO =====
if ($action === 'get_photo') {
    $stmt = $pdo->prepare("SELECT photo FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();
    if ($user && $user['photo']) {
        echo $user['photo'];
    }
    exit;
}

// ===== MES NOTIFICATIONS =====
if ($action === 'notifications') {
    $stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 20");
    $stmt->execute([$user_id]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// ===== MARQUER NOTIFICATION LUE =====
if ($action === 'mark_notification_read') {
    $id = intval($data['id'] ?? 0);
    $pdo->prepare("UPDATE notifications SET lue = 1 WHERE id = ? AND user_id = ?")->execute([$id, $user_id]);
    echo json_encode(['success' => true]);
    exit;
}

// ===== COMPTER NOTIFICATIONS NON LUES =====
if ($action === 'unread_notifications') {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND lue = 0");
    $stmt->execute([$user_id]);
    echo json_encode(['count' => intval($stmt->fetchColumn())]);
    exit;
}

echo json_encode(['error' => 'Action invalide']);
