<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/db.php';

function haversineDistance($lat1, $lng1, $lat2, $lng2) {
    if (!$lat1 || !$lng1 || !$lat2 || !$lng2) return null;
    $earthRadius = 6371;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat/2) * sin($dLat/2) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng/2) * sin($dLng/2);
    $c = 2 * atan2(sqrt($a), sqrt(1-$a));
    return round($earthRadius * $c, 2);
}

$action = $_GET['action'] ?? '';

// ===== BUS ET POSITIONS (Public) =====
if ($action === 'buses') {
    try {
        $rows = $pdo->query("
            SELECT l.id, l.numero, l.nom, l.trajet_depart, l.trajet_arrivee,
                   l.heure_debut, l.heure_fin, l.couleur, l.interval_minutes, l.actif,
                   b.id AS bus_id, b.immatriculation, b.marque, b.modele, b.places_totales AS bus_places_totales,
                   bp.latitude, bp.longitude, bp.vitesse, bp.acceleration,
                   bp.places_libres, bp.places_totales, bp.en_service
            FROM lignes l
            LEFT JOIN bus b ON b.id = (
                SELECT id FROM bus WHERE ligne_id = l.id AND statut = 'actif' ORDER BY id LIMIT 1
            )
            LEFT JOIN bus_positions bp ON bp.bus_id = b.id
            WHERE l.actif = 1
            ORDER BY l.id
        ")->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            $lat = floatval($row['latitude']);
            $lng = floatval($row['longitude']);

            // Si pas de position GPS réelle : marquer hors service, coordonnées nulles
            if (!$lat || !$lng) {
                $row['latitude']   = null;
                $row['longitude']  = null;
                $row['en_service'] = 0;
                $row['vitesse']    = 0;
            }

            // Accélération et température : uniquement L1 (ESP32)
            if (strtoupper($row['numero'] ?? '') !== 'L1') {
                $row['acceleration'] = null;
                $row['temperature']  = null;
            }

            // Indique si un bus (chauffeur) est assigné à cette ligne
            $row['has_bus'] = !empty($row['bus_id']);

            // Caster en entiers pour éviter les décimales MySQL DECIMAL
            $row['vitesse']        = (int)($row['vitesse'] ?? 0);
            $row['places_libres']  = $row['places_libres']  !== null ? (int)$row['places_libres']  : null;
            $row['places_totales'] = $row['places_totales'] !== null ? (int)$row['places_totales'] : null;
        }
        unset($row);
        echo json_encode($rows);
    } catch (Exception $e) {
        echo json_encode([]);
    }
    exit;
}

if ($action === 'positions') {
    try {
        $rows = $pdo->query("
            SELECT b.id as id, b.ligne_id, l.numero, l.couleur,
                   l.nom, l.trajet_depart, l.trajet_arrivee,
                   COALESCE(bp.latitude,  0) as latitude,
                   COALESCE(bp.longitude, 0) as longitude,
                   COALESCE(bp.vitesse,   0) as vitesse,
                   COALESCE(bp.acceleration, 0) as acceleration,
                   bp.places_libres, bp.places_totales,
                   COALESCE(bp.en_service, 0) as en_service,
                   bp.temperature, bp.updated_at
            FROM lignes l
            JOIN bus b ON b.ligne_id = l.id AND b.statut = 'actif'
            LEFT JOIN bus_positions bp ON bp.bus_id = b.id
            WHERE l.actif = 1
        ")->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            $lat = floatval($row['latitude']);
            $lng = floatval($row['longitude']);

            if (!$lat || !$lng) {
                $row['latitude']   = null;
                $row['longitude']  = null;
                $row['en_service'] = 0;
                $row['vitesse']    = 0;
            }
        }
        unset($row);
        echo json_encode($rows);
    } catch (Exception $e) {
        echo json_encode([]);
    }
    exit;
}

if ($action === 'lignes') {
    $stmt = $pdo->query("SELECT l.*, bp.places_libres, bp.places_totales, bp.en_service
                          FROM lignes l
                          LEFT JOIN bus b ON b.ligne_id = l.id
                          LEFT JOIN bus_positions bp ON b.id = bp.bus_id
                          ORDER BY l.id");
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

if ($action === 'search') {
    $q = sanitize($_GET['q'] ?? '');
    $stmt = $pdo->prepare("SELECT l.*, bp.places_libres, bp.places_totales FROM lignes l 
                            LEFT JOIN bus b ON b.ligne_id = l.id
                            LEFT JOIN bus_positions bp ON b.id = bp.bus_id
                            WHERE (l.numero LIKE ? OR l.nom LIKE ? OR l.trajet_depart LIKE ? OR l.trajet_arrivee LIKE ?)
                            ORDER BY l.numero");
    $stmt->execute(["%$q%", "%$q%", "%$q%", "%$q%"]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// ===== POSITIONS EN TEMPS RÉEL (lecture directe bus_positions) =====
if ($action === 'live_positions') {
    $stmt = $pdo->query("
        SELECT b.ligne_id,
               bp.latitude, bp.longitude, bp.vitesse, bp.acceleration,
               bp.places_libres, bp.places_totales, bp.en_service,
               bp.updated_at,
               TIMESTAMPDIFF(SECOND, bp.updated_at, NOW()) AS sec_ago
        FROM (
            SELECT bp1.*
            FROM bus_positions bp1
            JOIN (
                SELECT bus_id, MAX(updated_at) AS max_updated
                FROM bus_positions
                GROUP BY bus_id
            ) latest ON bp1.bus_id = latest.bus_id AND bp1.updated_at = latest.max_updated
        ) bp
        JOIN bus b ON bp.bus_id = b.id
        ORDER BY b.ligne_id
    ");
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

if ($action === 'arrets') {
    $stmt = $pdo->query("SELECT a.*, l.numero as ligne_numero, l.couleur FROM arrets a JOIN lignes l ON a.ligne_id = l.id ORDER BY a.ligne_id, a.ordre");
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

if ($action === 'export_sensor_data') {
    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="sensor_data.json"');
    $stmt = $pdo->query("SELECT bp.*, b.immatriculation, l.numero FROM bus_positions bp JOIN bus b ON bp.bus_id = b.id JOIN lignes l ON bp.ligne_id = l.id");
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

if ($action === 'nearby') {
    $lat = floatval($_GET['lat'] ?? 0);
    $lng = floatval($_GET['lng'] ?? 0);
    $radius = floatval($_GET['radius'] ?? 5);
    
    if ($lat == 0 || $lng == 0) {
        echo json_encode([]);
        exit;
    }
    
    $stmt = $pdo->prepare("SELECT l.*, b.immatriculation, b.marque, b.modele, 
                          u.nom as chauffeur_nom, u.prenom as chauffeur_prenom,
                          bp.latitude, bp.longitude,
                          bp.vitesse, bp.places_libres, bp.places_totales, bp.en_service,
                          a.nom as next_stop, a.latitude as next_stop_lat, a.longitude as next_stop_lng,
                          bp.direction,
                          (6371 * acos(cos(radians(?)) * cos(radians(bp.latitude)) * cos(radians(bp.longitude) - radians(?)) + sin(radians(?)) * sin(radians(bp.latitude)))) AS distance
                          FROM lignes l
                          JOIN bus b ON b.ligne_id = l.id
                          LEFT JOIN affectation_chauffeur ac ON l.id = ac.ligne_id AND ac.actif = 1
                          LEFT JOIN users u ON ac.chauffeur_id = u.id
                          JOIN bus_positions bp ON b.id = bp.bus_id AND bp.latitude IS NOT NULL AND bp.longitude IS NOT NULL
                          LEFT JOIN arrets a ON bp.next_stop_id = a.id
                          HAVING distance <= ?
                          ORDER BY distance");
    $stmt->execute([$lat, $lng, $lat, $radius]);
    $nearby = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($nearby as &$bus) {
        $dist = haversineDistance($bus['latitude'], $bus['longitude'], $bus['next_stop_lat'], $bus['next_stop_lng']);
        $speed = floatval($bus['vitesse'] ?? 0);
        $bus['eta_minutes'] = ($dist !== null && $speed > 0) ? round(($dist / $speed) * 60) : null;
    }
    echo json_encode($nearby);
    exit;
}

// ===== RESERVATION =====
if ($action === 'reserve') {
    $data = json_decode(file_get_contents('php://input'), true);
    $user_id = intval($data['user_id'] ?? 0);
    $ligne_id = intval($data['ligne_id'] ?? 0);
    
    if ($user_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Connexion requise']);
        exit;
    }
    
    if ($ligne_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Ligne invalide']);
        exit;
    }
    
    $arret_descente_id = intval($data['arret_descente'] ?? 0);

    try {
        $pdo->beginTransaction();

        // 1. Réservation existante aujourd'hui (toute statut — pour éviter le conflit UNIQUE)
        $existing = $pdo->prepare("SELECT id, statut, date_reservation, heure_reservation FROM reservations WHERE user_id=? AND ligne_id=? AND date_reservation=CURDATE() LIMIT 1");
        $existing->execute([$user_id, $ligne_id]);
        $existingRes = $existing->fetch(PDO::FETCH_ASSOC);

        // 2. Trouver le bus actif de cette ligne
        $busStmt = $pdo->prepare("SELECT b.id AS bus_id, COALESCE(bp.places_libres, b.places_totales) AS places_libres, COALESCE(bp.places_totales, b.places_totales) AS places_totales FROM bus b LEFT JOIN bus_positions bp ON bp.bus_id = b.id WHERE b.ligne_id = ? AND b.statut = 'actif' LIMIT 1");
        $busStmt->execute([$ligne_id]);
        $busRow = $busStmt->fetch(PDO::FETCH_ASSOC);

        if (!$busRow || !$busRow['bus_id']) {
            $pdo->rollback();
            echo json_encode(['success' => false, 'message' => 'Réservation indisponible — aucun bus assigné à cette ligne. Contactez l\'administration.']);
            exit;
        }
        $busId = (int)$busRow['bus_id'];

        // 3. Créer bus_positions si manquante
        $pdo->prepare("INSERT IGNORE INTO bus_positions (bus_id, ligne_id, places_libres, places_totales, latitude, longitude, updated_at) SELECT ?, ?, places_totales, places_totales, 0, 0, NOW() FROM bus WHERE id = ?")->execute([$busId, $ligne_id, $busId]);

        $decrementPlaces = false;

        if ($existingRes) {
            if ($existingRes['statut'] === 'active') {
                // Déjà réservé aujourd'hui — renvoyer la réservation existante
                $pdo->commit();
                $pos = $pdo->prepare("SELECT places_libres, places_totales FROM bus_positions WHERE bus_id = ?");
                $pos->execute([$busId]);
                $posRow = $pos->fetch(PDO::FETCH_ASSOC);
                echo json_encode([
                    'success'          => true,
                    'reservation_id'   => $existingRes['id'],
                    'arret_descente_id'=> $arret_descente_id,
                    'date_reservation' => $existingRes['date_reservation'],
                    'heure_reservation'=> $existingRes['heure_reservation'],
                    'places_libres'    => $posRow['places_libres'] ?? null,
                    'places_totales'   => $posRow['places_totales'] ?? null,
                ]);
                exit;
            }
            // Réservation annulée/expirée → la réactiver
            $pdo->prepare("UPDATE reservations SET statut='active', heure_reservation=CURTIME(), arret_descente_id=? WHERE id=?")
                ->execute([$arret_descente_id ?: null, $existingRes['id']]);
            $reservation_id = $existingRes['id'];
            $decrementPlaces = true; // on réserve de nouveau → décrémenter
        } else {
            // 4. Nouvelle réservation
            $pdo->prepare("INSERT INTO reservations (user_id, ligne_id, arret_descente_id, date_reservation, heure_reservation) VALUES (?, ?, ?, CURDATE(), CURTIME())")
                ->execute([$user_id, $ligne_id, $arret_descente_id ?: null]);
            $reservation_id = $pdo->lastInsertId();
            $decrementPlaces = true;
        }

        // 5. Décrémenter les places si nouvelle réservation
        if ($decrementPlaces) {
            // S'assurer que places_libres n'est pas null avant de décrémenter
            $pdo->prepare("UPDATE bus_positions SET
                places_libres = GREATEST(COALESCE(places_libres, places_totales, 40) - 1, 0),
                updated_at    = NOW()
                WHERE bus_id  = ?")->execute([$busId]);
        }

        $pdo->commit();

        // 6. Lire les places mises à jour
        $pos = $pdo->prepare("SELECT places_libres, places_totales FROM bus_positions WHERE bus_id = ?");
        $pos->execute([$busId]);
        $posRow = $pos->fetch(PDO::FETCH_ASSOC);

        echo json_encode([
            'success'          => true,
            'reservation_id'   => $reservation_id,
            'arret_descente_id'=> $arret_descente_id,
            'date_reservation' => date('Y-m-d'),
            'heure_reservation'=> date('H:i:s'),
            'places_libres'    => $posRow['places_libres']  ?? null,
            'places_totales'   => $posRow['places_totales'] ?? null,
        ]);
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollback();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// ===== MES RESERVATIONS =====
if ($action === 'my_reservations') {
    $data = json_decode(file_get_contents('php://input'), true);
    $user_id = intval($data['user_id'] ?? 0);
    
    if ($user_id <= 0) {
        echo json_encode(['error' => 'Connexion requise']);
        exit;
    }
    
    $stmt = $pdo->prepare("SELECT r.*, l.numero, l.nom, l.trajet_depart, l.trajet_arrivee, l.heure_debut, l.heure_fin, l.couleur, a.nom AS arret_descente
                            FROM reservations r
                            JOIN lignes l ON r.ligne_id = l.id 
                            LEFT JOIN arrets a ON r.arret_descente_id = a.id
                            WHERE r.user_id = ? AND r.statut = 'active'
                            ORDER BY r.date_reservation DESC, r.heure_reservation DESC");
    $stmt->execute([$user_id]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// ===== ANNULER RESERVATION =====
if ($action === 'cancel_reservation') {
    $data = json_decode(file_get_contents('php://input'), true);
    $reservation_id = intval($data['reservation_id'] ?? 0);
    $user_id = intval($data['user_id'] ?? 0);
    
    $stmt = $pdo->prepare("SELECT ligne_id FROM reservations WHERE id = ? AND user_id = ? AND statut = 'active'");
    $stmt->execute([$reservation_id, $user_id]);
    $res = $stmt->fetch();
    
    if ($res) {
        $pdo->prepare("UPDATE bus_positions bp
            JOIN bus b ON bp.bus_id = b.id
            SET bp.places_libres = GREATEST(COALESCE(bp.places_libres, bp.places_totales) + 1, 0)
            WHERE b.ligne_id = ?
            ORDER BY bp.updated_at DESC
            LIMIT 1")
            ->execute([$res['ligne_id']]);
        $pdo->prepare("UPDATE reservations SET statut = 'annulee' WHERE id = ?")->execute([$reservation_id]);
        echo json_encode(['success' => true, 'message' => 'Réservation annulée']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Réservation non trouvée']);
    }
    exit;
}

// ===== EXPIRATION RESERVATIONS (déconnexion) =====
if ($action === 'expire_reservations') {
    $data    = json_decode(file_get_contents('php://input'), true);
    $user_id = intval($data['user_id'] ?? 0);
    if ($user_id > 0) {
        // Remettre les places libres pour chaque réservation active du jour
        $actives = $pdo->prepare("SELECT r.id, r.ligne_id FROM reservations r WHERE r.user_id = ? AND r.date_reservation = CURDATE() AND r.statut = 'active'");
        $actives->execute([$user_id]);
        foreach ($actives->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $pdo->prepare("UPDATE bus_positions bp JOIN bus b ON bp.bus_id = b.id SET bp.places_libres = LEAST(COALESCE(bp.places_libres,0)+1, bp.places_totales) WHERE b.ligne_id = ? LIMIT 1")->execute([$r['ligne_id']]);
        }
        $pdo->prepare("UPDATE reservations SET statut='expiree' WHERE user_id=? AND date_reservation=CURDATE() AND statut='active'")->execute([$user_id]);
    }
    echo json_encode(['success' => true]);
    exit;
}

// ===== SIGNALEMENT =====
if ($action === 'report') {
    $data = json_decode(file_get_contents('php://input'), true);
    $user_id = intval($data['user_id'] ?? 0);
    $ligne_id = intval($data['ligne_id'] ?? 0);
    $type = sanitize($data['type'] ?? '');
    $description = sanitize($data['description'] ?? '');
    
    if ($user_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Connexion requise']);
        exit;
    }
    
    $stmt = $pdo->prepare("INSERT INTO signalements (user_id, ligne_id, type, description) VALUES (?, ?, ?, ?)");
    $stmt->execute([$user_id, $ligne_id > 0 ? $ligne_id : null, $type, $description]);
    
    echo json_encode(['success' => true, 'message' => 'Signalement envoyé!']);
    exit;
}

// ===== UPLOAD PHOTO =====
if ($action === 'upload_photo') {
    $data = json_decode(file_get_contents('php://input'), true);
    $user_id = intval($data['user_id'] ?? 0);
    $photo = sanitize($data['photo'] ?? '');
    
    if ($user_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Utilisateur non identifié']);
        exit;
    }
    
    if (preg_match('/^data:image\/\w+;base64,/', $photo)) {
        $pdo->prepare("UPDATE users SET photo = ? WHERE id = ?")->execute([$photo, $user_id]);
        echo json_encode(['success' => true, 'message' => 'Photo mise à jour']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Format invalide']);
    }
    exit;
}

// ===== GET PHOTO =====
if ($action === 'get_photo') {
    $data = json_decode(file_get_contents('php://input'), true);
    $user_id = intval($data['user_id'] ?? 0);
    
    if ($user_id <= 0) {
        echo json_encode(['error' => 'User ID requis']);
        exit;
    }
    
    $stmt = $pdo->prepare("SELECT photo FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();
    
    if ($user && $user['photo']) {
        echo json_encode(['photo' => $user['photo']]);
    } else {
        echo json_encode(['photo' => null]);
    }
    exit;
}

echo json_encode(['error' => 'Action invalide']);
