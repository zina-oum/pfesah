<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/db.php';
function sendNotificationEmail($to, $subject, $message, $fromName = 'BUS Tracker', $fromEmail = 'no-reply@bustracker.local') {
    $headers = "From: {$fromName} <{$fromEmail}>\r\n" .
               "MIME-Version: 1.0\r\n" .
               "Content-Type: text/html; charset=UTF-8\r\n";
    if (filter_var($to, FILTER_VALIDATE_EMAIL)) {
        @mail($to, $subject, $message, $headers);
    }
}
$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['error' => 'Corps JSON invalide']);
    exit;
}
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
if (!$_roleRow || $_roleRow['role'] !== 'chauffeur') {
    http_response_code(403);
    echo json_encode(['error' => 'Accès refusé']);
    exit;
}

// ===== MON PROFIL =====
if ($action === 'profile') {
    $stmt = $pdo->prepare("SELECT id, email, nom, prenom, telephone, photo, created_at FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    echo json_encode($user);
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

// ===== PHOTO DE PROFIL =====
if ($action === 'get_photo') {
    $stmt = $pdo->prepare("SELECT photo FROM users WHERE id = ? AND role = 'chauffeur'");
    $stmt->execute([$user_id]);
    $photo = $stmt->fetchColumn();
    echo $photo ?: '';
    exit;
}

// ===== STATS TOTALES =====
if ($action === 'stats') {
    $stmt = $pdo->prepare("SELECT SUM(tickets_vendus) as total_passengers, SUM(km_parcourus) as total_km, SUM(nb_trajets) as total_trips
                            FROM historique_trajets 
                            WHERE chauffeur_id = ?");
    $stmt->execute([$user_id]);
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'total_passengers' => intval($stats['total_passengers'] ?? 0),
        'total_km' => floatval($stats['total_km'] ?? 0),
        'total_trips' => intval($stats['total_trips'] ?? 0)
    ]);
    exit;
}

// ===== MA LIGNE =====
if ($action === 'my_line') {
    $stmt = $pdo->prepare("
        SELECT l.*, b.id AS bus_id, b.immatriculation, b.marque, b.modele,
               bp.id AS position_id, bp.latitude, bp.longitude,
               COALESCE(bp.vitesse,        0)               AS vitesse,
               bp.acceleration,
               COALESCE(bp.km_parcourus,   0)               AS km_parcourus,
               COALESCE(bp.places_libres,  b.places_totales) AS places_libres,
               COALESCE(bp.places_totales, b.places_totales) AS places_totales,
               COALESCE(bp.en_service,     0)               AS en_service,
               COALESCE(bp.moteur,         0)               AS moteur,
               bp.temperature,
               bp.updated_at
        FROM bus b
        JOIN lignes l ON b.ligne_id = l.id
        LEFT JOIN bus_positions bp ON b.id = bp.bus_id
        WHERE b.chauffeur_id = ? AND b.statut = 'actif'
        LIMIT 1
    ");
    $stmt->execute([$user_id]);
    $line = $stmt->fetch(PDO::FETCH_ASSOC);

    // L1 = ESP32 : toutes les données capteur sont réelles (vitesse, accél, temp, GPS)
    // Autres lignes = GPS téléphone : accélération et température non disponibles (pas de capteur)
    if ($line && strtoupper($line['numero'] ?? '') !== 'L1') {
        $line['acceleration'] = null;
        $line['temperature']  = null;
    }

    echo json_encode($line ?: null);
    exit;
}

// ===== CHOISIR SA LIGNE =====
if ($action === 'select_line') {
    $bus_id = intval($data['bus_id'] ?? 0);
    if (!$bus_id) {
        echo json_encode(['success' => false, 'message' => 'Bus invalide']); exit;
    }
    // Vérifier que le bus est libre
    $chk = $pdo->prepare("SELECT chauffeur_id FROM bus WHERE id = ? AND statut = 'actif'");
    $chk->execute([$bus_id]);
    $row = $chk->fetch();
    if (!$row) {
        echo json_encode(['success' => false, 'message' => 'Bus introuvable ou inactif']); exit;
    }
    if ($row['chauffeur_id'] && $row['chauffeur_id'] != $user_id) {
        echo json_encode(['success' => false, 'message' => 'Ce bus est déjà pris par un autre chauffeur']); exit;
    }
    // Libérer l'ancien bus du chauffeur
    $pdo->prepare("UPDATE bus SET chauffeur_id = NULL WHERE chauffeur_id = ?")->execute([$user_id]);
    // Assigner le nouveau bus
    $pdo->prepare("UPDATE bus SET chauffeur_id = ? WHERE id = ?")->execute([$user_id, $bus_id]);
    echo json_encode(['success' => true]);
    exit;
}

// ===== QUITTER SA LIGNE =====
if ($action === 'leave_line') {
    $pdo->prepare("UPDATE bus SET chauffeur_id = NULL WHERE chauffeur_id = ?")->execute([$user_id]);
    echo json_encode(['success' => true]);
    exit;
}

// ===== LIGNES DISPONIBLES =====
if ($action === 'available_lines') {
    $stmt = $pdo->query("
        SELECT b.id AS bus_id, b.immatriculation, b.marque, b.modele, b.places_totales,
               l.id AS ligne_id, l.numero, l.nom, l.trajet_depart, l.trajet_arrivee,
               l.heure_debut, l.heure_fin, l.couleur,
               bp.places_libres, bp.en_service
        FROM bus b
        JOIN lignes l ON b.ligne_id = l.id AND l.actif = 1
        LEFT JOIN bus_positions bp ON b.id = bp.bus_id
        WHERE b.statut = 'actif' AND (b.chauffeur_id IS NULL OR b.chauffeur_id = 0)
        ORDER BY l.numero
    ");
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// ===== TOGGLE SERVICE =====
if ($action === 'toggle_service') {
    $bus_id = intval($data['bus_id'] ?? 0);
    $active = $data['active'] ? 1 : 0;

    // Créer bus_positions si absent (premier démarrage)
    $pdo->prepare("
        INSERT IGNORE INTO bus_positions
            (bus_id, ligne_id, places_libres, places_totales, latitude, longitude, en_service, moteur, updated_at)
        SELECT b.id, b.ligne_id, b.places_totales, b.places_totales, 0, 0, ?, ?, NOW()
        FROM bus b WHERE b.id = ?
    ")->execute([$active, $active, $bus_id]);

    $pdo->prepare("UPDATE bus_positions SET en_service = ?, moteur = ?, updated_at = NOW() WHERE bus_id = ?")
        ->execute([$active, $active, $bus_id]);
    
    // Historique
    if ($active) {
        $stmt = $pdo->prepare("SELECT id FROM historique_trajets WHERE chauffeur_id = ? AND date_travail = CURDATE()");
        $stmt->execute([$user_id]);
        $ht = $stmt->fetch();
        
        if (!$ht) {
            $pdo->prepare("INSERT INTO historique_trajets (chauffeur_id, date_travail, debut_service) VALUES (?, CURDATE(), CURTIME())")
                ->execute([$user_id]);
        } else {
            $pdo->prepare("UPDATE historique_trajets SET debut_service = CURTIME() WHERE id = ?")->execute([$ht['id']]);
        }
    } else {
        $stmt = $pdo->prepare("SELECT id FROM historique_trajets WHERE chauffeur_id = ? AND date_travail = CURDATE()");
        $stmt->execute([$user_id]);
        $ht = $stmt->fetch();
        if ($ht) {
            $pdo->prepare("UPDATE historique_trajets SET fin_service = CURTIME() WHERE id = ?")->execute([$ht['id']]);
        }
    }
    
    echo json_encode(['success' => true, 'active' => $active]);
    exit;
}

// ===== UPDATE POSITION =====
if ($action === 'update_position') {
    $bus_id    = intval($data['bus_id'] ?? 0);
    $latitude  = floatval($data['latitude'] ?? 0);
    $longitude = floatval($data['longitude'] ?? 0);
    $vitesse   = floatval($data['vitesse'] ?? 0);

    if ($bus_id > 0 && $latitude != 0 && $longitude != 0) {
        // Met à jour position + marque le bus en service actif
        $pdo->prepare("
            UPDATE bus_positions
            SET latitude = ?, longitude = ?, vitesse = ?,
                en_service = 1, moteur = 1, updated_at = NOW()
            WHERE bus_id = ?
        ")->execute([$latitude, $longitude, $vitesse, $bus_id]);

        $pdo->prepare("
            INSERT INTO historique_positions (bus_id, ligne_id, latitude, longitude, vitesse)
            SELECT bp.bus_id, bp.ligne_id, ?, ?, ? FROM bus_positions bp WHERE bp.bus_id = ?
        ")->execute([$latitude, $longitude, $vitesse, $bus_id]);

        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Données invalides']);
    }
    exit;
}

// ===== TICKETS VENDUS =====
if ($action === 'tickets_vendus') {
    $bus_id = intval($data['bus_id'] ?? 0);
    $nb_tickets = intval($data['nb_tickets'] ?? 0);
    
    if ($bus_id > 0 && $nb_tickets > 0) {
        $pdo->prepare("UPDATE bus_positions SET places_libres = places_libres - ? WHERE bus_id = ? AND places_libres >= ?")
            ->execute([$nb_tickets, $bus_id, $nb_tickets]);
        
        // Historique
        $stmt = $pdo->prepare("SELECT id FROM historique_trajets WHERE chauffeur_id = ? AND date_travail = CURDATE()");
        $stmt->execute([$user_id]);
        $ht = $stmt->fetch();
        
        if ($ht) {
            $pdo->prepare("UPDATE historique_trajets SET tickets_vendus = tickets_vendus + ? WHERE id = ?")
                ->execute([$nb_tickets, $ht['id']]);
        } else {
            $pdo->prepare("INSERT INTO historique_trajets (chauffeur_id, date_travail, tickets_vendus) VALUES (?, CURDATE(), ?)")
                ->execute([$user_id, $nb_tickets]);
        }
        
        echo json_encode(['success' => true, 'message' => $nb_tickets . ' tickets enregistrés']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Données invalides']);
    }
    exit;
}

// ===== AJOUTER KM =====
if ($action === 'add_km') {
    $bus_id = intval($data['bus_id'] ?? 0);
    $km = floatval($data['km'] ?? 0);
    
    if ($bus_id > 0 && $km > 0) {
        $pdo->prepare("UPDATE bus_positions SET km_parcourus = km_parcourus + ? WHERE bus_id = ?")
            ->execute([$km, $bus_id]);
        
        // Historique
        $stmt = $pdo->prepare("SELECT id FROM historique_trajets WHERE chauffeur_id = ? AND date_travail = CURDATE()");
        $stmt->execute([$user_id]);
        $ht = $stmt->fetch();
        
        if ($ht) {
            $pdo->prepare("UPDATE historique_trajets SET km_parcourus = km_parcourus + ? WHERE id = ?")
                ->execute([$km, $ht['id']]);
        } else {
            $pdo->prepare("INSERT INTO historique_trajets (chauffeur_id, date_travail, km_parcourus) VALUES (?, CURDATE(), ?)")
                ->execute([$user_id, $km]);
        }
        
        echo json_encode(['success' => true, 'message' => $km . ' km ajoutés']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Données invalides']);
    }
    exit;
}

// ===== AJOUTER TRAJET =====
if ($action === 'add_trip') {
    $bus_id = intval($data['bus_id'] ?? 0);
    
    if ($bus_id > 0) {
        $stmt = $pdo->prepare("SELECT id FROM historique_trajets WHERE chauffeur_id = ? AND date_travail = CURDATE()");
        $stmt->execute([$user_id]);
        $ht = $stmt->fetch();
        
        if ($ht) {
            $pdo->prepare("UPDATE historique_trajets SET nb_trajets = nb_trajets + 1 WHERE id = ?")->execute([$ht['id']]);
        } else {
            $pdo->prepare("INSERT INTO historique_trajets (chauffeur_id, date_travail, nb_trajets) VALUES (?, CURDATE(), 1)")
                ->execute([$user_id]);
        }
        
        echo json_encode(['success' => true, 'message' => 'Trajet enregistré']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Bus non trouvé']);
    }
    exit;
}

// ===== STATS DU JOUR =====
if ($action === 'today_stats') {
    // Passagers = réservations actives/utilisées aujourd'hui sur la ligne du chauffeur
    $stmtPass = $pdo->prepare("
        SELECT COUNT(*) FROM reservations r
        JOIN bus b ON b.ligne_id = r.ligne_id
        WHERE b.chauffeur_id = ? AND r.date_reservation = CURDATE()
          AND r.statut IN ('active','utilisee')
    ");
    $stmtPass->execute([$user_id]);
    $passagers = intval($stmtPass->fetchColumn());

    // Infos ligne
    $stmtLigne = $pdo->prepare("
        SELECT l.numero, bp.km_parcourus
        FROM bus b JOIN lignes l ON b.ligne_id = l.id
        LEFT JOIN bus_positions bp ON bp.bus_id = b.id
        WHERE b.chauffeur_id = ? AND b.statut = 'actif' LIMIT 1
    ");
    $stmtLigne->execute([$user_id]);
    $ligneRow = $stmtLigne->fetch(PDO::FETCH_ASSOC);
    $isL1 = ($ligneRow['numero'] ?? '') === 'L1';

    $stmtTr = $pdo->prepare("SELECT nb_trajets, debut_service, fin_service FROM historique_trajets WHERE chauffeur_id = ? AND date_travail = CURDATE()");
    $stmtTr->execute([$user_id]);
    $ht = $stmtTr->fetch(PDO::FETCH_ASSOC);

    if ($isL1) {
        // L1 = ESP32 : valeurs réelles
        $km    = floatval($ligneRow['km_parcourus'] ?? 0);
        $trips = intval($ht['nb_trajets'] ?? 0);
    } else {
        // Autres lignes : simulation stable basée sur la date (change chaque jour, stable dans la journée)
        $seed = intval(date('Ymd')) + $user_id * 7;
        srand($seed);
        $km    = round(rand(420, 890) / 10, 1); // 42.0 – 89.0 km
        $trips = rand(3, 9);                     // 3 – 9 trajets
        srand();                                 // reset
    }

    echo json_encode([
        'tickets' => $passagers,
        'km'      => round($km, 1),
        'trips'   => $trips,
        'debut'   => $ht['debut_service'] ?? null,
        'fin'     => $ht['fin_service'] ?? null
    ]);
    exit;
}

// ===== MES RESERVATIONS =====
if ($action === 'my_reservations') {
    $stmt = $pdo->prepare("SELECT r.*, u.nom, u.prenom 
                            FROM reservations r 
                            JOIN users u ON r.user_id = u.id
                            JOIN lignes l ON r.ligne_id = l.id
                            JOIN affectation_chauffeur ac ON l.id = ac.ligne_id AND ac.actif = 1
                            WHERE ac.chauffeur_id = ? AND r.date_reservation >= CURDATE() AND r.statut = 'active'
                            ORDER BY r.heure_reservation");
    $stmt->execute([$user_id]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// ===== SIGNALER INCIDENT =====
if ($action === 'report_incident') {
    $bus_id = intval($data['bus_id'] ?? 0);
    $ligne_id = intval($data['ligne_id'] ?? 0);
    $type = sanitize($data['type'] ?? '');
    $description = sanitize($data['description'] ?? '');
    $priorite = sanitize($data['priorite'] ?? 'moyenne');
    
    $stmt = $pdo->prepare("INSERT INTO incidents (bus_id, ligne_id, chauffeur_id, type, description, priorite) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$bus_id > 0 ? $bus_id : null, $ligne_id > 0 ? $ligne_id : null, $user_id, $type, $description, $priorite]);

    $adminEmail = 'admin@cous.dz';
    $adminStmt = $pdo->query("SELECT email FROM users WHERE role = 'admin' ORDER BY id LIMIT 1");
    $adminRow = $adminStmt->fetch();
    if ($adminRow && filter_var($adminRow['email'], FILTER_VALIDATE_EMAIL)) {
        $adminEmail = $adminRow['email'];
    }

    $subject = 'Signalement bus reçu';
    $message = "<h2>Signalement de bus</h2>" .
               "<p>Chauffeur : <strong>{$user_id}</strong></p>" .
               "<p>Ligne : <strong>" . ($ligne_id > 0 ? $ligne_id : 'Non spécifiée') . "</strong></p>" .
               "<p>Type : <strong>{$type}</strong></p>" .
               "<p>Description :<br>{$description}</p>";
    sendNotificationEmail($adminEmail, $subject, $message);
    
    echo json_encode(['success' => true, 'message' => 'Incident signalé']);
    exit;
}

// ===== SIGNALER ETUDIANT =====
if ($action === 'report_student') {
    $studentIdentifier = sanitize($data['student_identifier'] ?? '');
    $ligne_id = intval($data['ligne_id'] ?? 0);
    $type = sanitize($data['type'] ?? '');
    $description = sanitize($data['description'] ?? '');
    
    if (filter_var($studentIdentifier, FILTER_VALIDATE_EMAIL)) {
        $stmt = $pdo->prepare("SELECT id, email, nom, prenom FROM users WHERE email = ? AND role = 'etudiant'");
        $stmt->execute([$studentIdentifier]);
    } else {
        $stmt = $pdo->prepare("SELECT id, email, nom, prenom FROM users WHERE code_etudiant = ? AND role = 'etudiant'");
        $stmt->execute([$studentIdentifier]);
    }
    $student = $stmt->fetch();
    
    if ($student) {
        $stmt = $pdo->prepare("INSERT INTO signalements (user_id, ligne_id, chauffeur_id, type, description) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$student['id'], $ligne_id > 0 ? $ligne_id : null, $user_id, $type, $description]);

        $adminEmail = 'admin@cous.dz';
        $adminStmt = $pdo->query("SELECT email FROM users WHERE role = 'admin' ORDER BY id LIMIT 1");
        $adminRow = $adminStmt->fetch();
        if ($adminRow && filter_var($adminRow['email'], FILTER_VALIDATE_EMAIL)) {
            $adminEmail = $adminRow['email'];
        }

        $subjectAdmin = 'Nouveau signalement étudiant';
        $messageAdmin = "<h2>Nouveau signalement étudiant</h2>" .
                        "<p>Chauffeur : <strong>{$user_id}</strong></p>" .
                        "<p>Étudiant : <strong>{$student['nom']} {$student['prenom']}</strong></p>" .
                        "<p>Ligne : <strong>" . ($ligne_id > 0 ? $ligne_id : 'Non spécifiée') . "</strong></p>" .
                        "<p>Type : <strong>{$type}</strong></p>" .
                        "<p>Description :<br>{$description}</p>";
        sendNotificationEmail($adminEmail, $subjectAdmin, $messageAdmin);

        if (filter_var($student['email'], FILTER_VALIDATE_EMAIL)) {
            $subjectStudent = 'Vous avez été signalé';
            $messageStudent = "<h2>Notification de signalement</h2>" .
                              "<p>Bonjour {$student['prenom']},</p>" .
                              "<p>Un signalement a été enregistré par un chauffeur pour le motif : <strong>{$type}</strong>.</p>" .
                              "<p>Description :<br>{$description}</p>" .
                              "<p>La direction a été informée et prendra contact si nécessaire.</p>";
            sendNotificationEmail($student['email'], $subjectStudent, $messageStudent);
        }

        echo json_encode(['success' => true, 'message' => 'Étudiant signalé']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Étudiant non trouvé']);
    }
    exit;
}

// ===== GET STOPS =====
if ($action === 'get_stops') {
    $ligne_id = intval($data['ligne_id'] ?? 0);
    if ($ligne_id > 0) {
        $stmt = $pdo->prepare("SELECT id, nom, latitude, longitude FROM arrets WHERE ligne_id = ? AND actif = 1 ORDER BY ordre");
        $stmt->execute([$ligne_id]);
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    } else {
        echo json_encode([]);
    }
    exit;
}

// ===== UPDATE NEXT STOP =====
if ($action === 'update_next_stop') {
    $ligne_id = intval($data['ligne_id'] ?? 0);
    $next_stop_id = intval($data['next_stop_id'] ?? 0);
    
    if ($ligne_id > 0 && $next_stop_id > 0) {
        $pdo->prepare("UPDATE bus_positions bp 
                       JOIN bus b ON bp.bus_id = b.id 
                       SET bp.next_stop_id = ? 
                       WHERE b.ligne_id = ?")
            ->execute([$next_stop_id, $ligne_id]);
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Données invalides']);
    }
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

// ===== DEMANDE RESET MDP =====
if ($action === 'request_password_reset') {
    $email = sanitize($data['email'] ?? '');
    $message = sanitize($data['message'] ?? '');
    
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND role = 'chauffeur'");
    $stmt->execute([$email]);
    $chauffeur = $stmt->fetch();
    
    if (!$chauffeur) {
        echo json_encode(['success' => false, 'message' => 'Chauffeur non trouvé']);
        exit;
    }
    
    $stmt = $pdo->prepare("SELECT id FROM password_reset_requests WHERE user_id = ? AND statut = 'pending'");
    $stmt->execute([$chauffeur['id']]);
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Une demande est déjà en cours']);
        exit;
    }
    
    $pdo->prepare("INSERT INTO password_reset_requests (user_id, message, statut) VALUES (?, ?, 'pending')")
        ->execute([$chauffeur['id'], $message]);
    
    echo json_encode(['success' => true, 'message' => 'Demande envoyée à l\'administrateur']);
    exit;
}

echo json_encode(['error' => 'Action invalide']);
