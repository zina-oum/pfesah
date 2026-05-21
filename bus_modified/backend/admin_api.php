<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mail.php';

$data = json_decode(file_get_contents('php://input'), true);
$action = sanitize($data['action'] ?? '');

$user_id = intval($data['user_id'] ?? 0);
$user_role = sanitize($data['user_role'] ?? '');

if ($user_role !== 'admin') {
    echo json_encode(['error' => 'Accès refusé']);
    exit;
}

// ===== STATS =====
if ($action === 'stats') {
    $stats = [
        'bus' => intval($pdo->query("SELECT COUNT(*) FROM bus WHERE statut = 'actif'")->fetchColumn()),
        'chauffeurs' => intval($pdo->query("SELECT COUNT(*) FROM users WHERE role = 'chauffeur'")->fetchColumn()),
        'etudiants' => intval($pdo->query("SELECT COUNT(*) FROM users WHERE role = 'etudiant' AND statut = 'validated'")->fetchColumn()),
        'pending' => intval($pdo->query("SELECT COUNT(*) FROM users WHERE role = 'etudiant' AND statut = 'pending'")->fetchColumn()),
        'lignes' => intval($pdo->query("SELECT COUNT(*) FROM lignes WHERE actif = 1")->fetchColumn())
    ];
    echo json_encode($stats);
    exit;
}

// ===== ETUDIANTS EN ATTENTE =====
if ($action === 'pending') {
    $stmt = $pdo->query("SELECT id, email, nom, prenom, code_etudiant, created_at FROM users WHERE statut = 'pending' AND role = 'etudiant' ORDER BY created_at DESC");
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// ===== TOUS LES ETUDIANTS =====
if ($action === 'all_students') {
    $stmt = $pdo->query("SELECT id, email, nom, prenom, code_etudiant, statut, created_at FROM users WHERE role = 'etudiant' ORDER BY created_at DESC");
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// ===== ETUDIANTS REFUSES =====
if ($action === 'rejected') {
    $stmt = $pdo->query("SELECT id, email, nom, prenom, code_etudiant, created_at, updated_at FROM users WHERE statut = 'rejected' AND role = 'etudiant' ORDER BY updated_at DESC LIMIT 20");
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// ===== VALIDATION ETUDIANT =====
if ($action === 'validate') {
    $id = intval($data['id']);
    
    $stmt = $pdo->prepare("SELECT email, nom, prenom FROM users WHERE id = ? AND role = 'etudiant' AND statut = 'pending'");
    $stmt->execute([$id]);
    $student = $stmt->fetch();
    
    $updateStmt = $pdo->prepare("UPDATE users SET statut = 'validated' WHERE id = ? AND role = 'etudiant' AND statut = 'pending'");
    $updateStmt->execute([$id]);
    $updated = $updateStmt->rowCount();
    if (!$student || !$updated) {
        echo json_encode(['success' => false, 'message' => 'Cet étudiant n\'est pas en attente ou n\'existe pas.']);
        exit;
    }
    
    // Notification
    $pdo->prepare("INSERT INTO notifications (user_id, titre, message, type) VALUES (?, 'Compte validé', 'Votre compte a été validé par l\'administrateur.', 'success')")
        ->execute([$id]);
    
    // Email de confirmation
    $subject = "Confirmation d'inscription - Système BUS";
    $message = "
    <html>
    <head>
        <title>Confirmation d'inscription</title>
    </head>
    <body>
        <h2>Bonjour {$student['prenom']} {$student['nom']},</h2>
        <p>Votre inscription au système BUS universitaire a été <strong>validée</strong> par l'administrateur.</p>
        <p>Vous pouvez maintenant vous connecter à votre compte et utiliser toutes les fonctionnalités du système.</p>
        <p>Cordialement,<br>L'équipe BUS</p>
    </body>
    </html>
    ";
    $mailSent = sendEmail($student['email'], $subject, $message);
    echo json_encode(['success' => true, 'message' => $mailSent ? 'Étudiant validé et email envoyé.' : 'Étudiant validé, mais l’email n’a pas pu être envoyé. Vérifiez la configuration du serveur de mails.']);
    exit;
}

// ===== REFUS ETUDIANT =====
if ($action === 'reject') {
    $id = intval($data['id']);
    
    $stmt = $pdo->prepare("SELECT email, nom, prenom FROM users WHERE id = ? AND role = 'etudiant' AND statut = 'pending'");
    $stmt->execute([$id]);
    $student = $stmt->fetch();
    
    $updateStmt = $pdo->prepare("UPDATE users SET statut = 'rejected' WHERE id = ? AND role = 'etudiant' AND statut = 'pending'");
    $updateStmt->execute([$id]);
    $updated = $updateStmt->rowCount();
    if (!$student || !$updated) {
        echo json_encode(['success' => false, 'message' => 'Cet étudiant n\'est pas en attente ou n\'existe pas.']);
        exit;
    }
    
    // Notification
    $pdo->prepare("INSERT INTO notifications (user_id, titre, message, type) VALUES (?, 'Compte refusé', 'Votre compte a été refusé par l\'administrateur.', 'error')")
        ->execute([$id]);
    
    // Email de refus
    $subject = "Refus d'inscription - Système BUS";
    $message = "
    <html>
    <head>
        <title>Refus d'inscription</title>
    </head>
    <body>
        <h2>Bonjour {$student['prenom']} {$student['nom']},</h2>
        <p>Nous regrettons de vous informer que votre inscription au système BUS universitaire a été <strong>refusée</strong>.</p>
        <p>Si vous pensez qu'il s'agit d'une erreur de notre part, veuillez nous contacter à l'adresse support@bus-system.com en fournissant votre code étudiant et les détails de votre demande.</p>
        <p>Cordialement,<br>L'équipe BUS</p>
    </body>
    </html>
    ";
    $mailSent = sendEmail($student['email'], $subject, $message);
    echo json_encode(['success' => true, 'message' => $mailSent ? 'Étudiant refusé et email envoyé.' : 'Étudiant refusé, mais l’email n’a pas pu être envoyé. Vérifiez la configuration du serveur de mails.']);
    exit;
}

// ===== LISTE DE TOUS LES BUS (admin) =====
if ($action === 'all_buses') {
    $stmt = $pdo->query("
        SELECT b.id, b.immatriculation, b.marque, b.modele, b.places_totales,
               l.id AS ligne_id, l.numero AS ligne_numero, l.nom AS ligne_nom, l.couleur,
               l.trajet_depart, l.trajet_arrivee,
               u.id AS chauffeur_id, u.nom AS chauffeur_nom, u.prenom AS chauffeur_prenom,
               bp.en_service, bp.moteur, bp.vitesse, bp.acceleration, bp.temperature,
               bp.places_libres, bp.latitude, bp.longitude, bp.direction, bp.km_parcourus,
               bp.updated_at AS position_updated_at
        FROM bus b
        LEFT JOIN lignes l ON b.ligne_id = l.id
        LEFT JOIN users u ON b.chauffeur_id = u.id
        LEFT JOIN bus_positions bp ON b.id = bp.bus_id
        WHERE b.statut = 'actif'
        ORDER BY l.numero, b.immatriculation
    ");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    // Accélération et température : uniquement L1 (ESP32 physique)
    foreach ($rows as &$r) {
        if (strtoupper($r['ligne_numero'] ?? '') !== 'L1') {
            $r['acceleration'] = null;
            $r['temperature']  = null;
        }
    }
    unset($r);
    echo json_encode($rows);
    exit;
}

// ===== CHAUFFEURS =====
if ($action === 'drivers') {
    $stmt = $pdo->query("
        SELECT u.id, u.email, u.nom, u.prenom, u.telephone,
               b.id AS bus_id, b.immatriculation, b.marque, b.modele,
               l.numero AS ligne, l.nom AS ligne_nom
        FROM users u
        LEFT JOIN bus b ON b.chauffeur_id = u.id AND b.statut = 'actif'
        LEFT JOIN lignes l ON b.ligne_id = l.id
        WHERE u.role = 'chauffeur'
        ORDER BY u.nom
    ");
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// ===== CREER CHAUFFEUR + BUS =====
if ($action === 'create_driver') {
    $email           = sanitize($data['email'] ?? '');
    $password        = $data['password'] ?? '';
    $nom             = sanitize($data['nom'] ?? '');
    $prenom          = sanitize($data['prenom'] ?? '');
    $telephone       = sanitize($data['telephone'] ?? '');
    $ligne_id        = intval($data['ligne_id'] ?? 0);
    $immatriculation = sanitize($data['immatriculation'] ?? '');
    $marque          = sanitize($data['marque'] ?? '');
    $modele          = sanitize($data['modele'] ?? '');
    $places_totales  = max(1, intval($data['places_totales'] ?? 40));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => 'Email invalide']); exit;
    }
    if (strlen($password) < 6) {
        echo json_encode(['success' => false, 'message' => 'Mot de passe min 6 caractères']); exit;
    }
    if (!$ligne_id) {
        echo json_encode(['success' => false, 'message' => 'Sélectionnez une ligne']); exit;
    }
    if (!$immatriculation) {
        echo json_encode(['success' => false, 'message' => 'Immatriculation obligatoire']); exit;
    }

    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Email déjà utilisé']); exit;
    }

    $chk = $pdo->prepare("SELECT id FROM bus WHERE immatriculation = ?");
    $chk->execute([$immatriculation]);
    if ($chk->fetch()) {
        echo json_encode(['success' => false, 'message' => "Immatriculation « $immatriculation » déjà utilisée"]); exit;
    }

    // Créer le compte chauffeur
    $hashed = hashPassword($password);
    $pdo->prepare("INSERT INTO users (email, password, role, nom, prenom, telephone, statut) VALUES (?, ?, 'chauffeur', ?, ?, ?, 'validated')")
        ->execute([$email, $hashed, $nom, $prenom, $telephone]);
    $chauffeur_id = $pdo->lastInsertId();

    // Créer le bus et l'assigner directement au chauffeur
    $pdo->prepare("INSERT INTO bus (ligne_id, chauffeur_id, immatriculation, marque, modele, places_totales, statut) VALUES (?, ?, ?, ?, ?, ?, 'actif')")
        ->execute([$ligne_id, $chauffeur_id, $immatriculation, $marque, $modele, $places_totales]);
    $bus_id = $pdo->lastInsertId();

    // Initialiser la position du bus (vitesse=0, places=max, pas de GPS encore)
    if ($bus_id) {
        $pdo->prepare("INSERT IGNORE INTO bus_positions (bus_id, ligne_id, latitude, longitude, vitesse, acceleration, places_libres, places_totales, moteur, en_service, temperature) VALUES (?, ?, 0, 0, 0, 0, ?, ?, 0, 0, NULL)")
            ->execute([$bus_id, $ligne_id, $places_totales, $places_totales]);
    }

    echo json_encode(['success' => true, 'message' => 'Chauffeur et bus créés', 'id' => $chauffeur_id]);
    exit;
}

// ===== ASSIGNER UN BUS À UN CHAUFFEUR EXISTANT =====
if ($action === 'assign_driver_bus') {
    $chauffeur_id = intval($data['chauffeur_id'] ?? 0);
    $bus_id       = intval($data['bus_id'] ?? 0);
    if (!$chauffeur_id || !$bus_id) {
        echo json_encode(['success' => false, 'message' => 'Données manquantes']); exit;
    }
    // Vérifier que le bus n'est pas déjà pris par un autre
    $chk = $pdo->prepare("SELECT chauffeur_id FROM bus WHERE id = ?");
    $chk->execute([$bus_id]);
    $row = $chk->fetch();
    if ($row && $row['chauffeur_id'] && $row['chauffeur_id'] != $chauffeur_id) {
        echo json_encode(['success' => false, 'message' => 'Ce bus est déjà assigné à un autre chauffeur']); exit;
    }
    // Libérer l'ancien bus du chauffeur
    $pdo->prepare("UPDATE bus SET chauffeur_id = NULL WHERE chauffeur_id = ?")->execute([$chauffeur_id]);
    // Assigner le nouveau bus
    $pdo->prepare("UPDATE bus SET chauffeur_id = ? WHERE id = ?")->execute([$chauffeur_id, $bus_id]);
    echo json_encode(['success' => true]);
    exit;
}

// ===== SUPPRIMER CHAUFFEUR =====
if ($action === 'delete_driver') {
    $id = intval($data['id']);
    $pdo->prepare("UPDATE bus SET chauffeur_id = NULL WHERE chauffeur_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM users WHERE id = ? AND role = 'chauffeur'")->execute([$id]);
    echo json_encode(['success' => true]);
    exit;
}

// ===== LIGNES =====
if ($action === 'lignes') {
    try {
        $stmt = $pdo->query("
            SELECT l.*,
                   b.immatriculation, b.marque, b.modele,
                   bp.places_libres, bp.places_totales,
                   u.nom AS chauffeur_nom, u.prenom AS chauffeur_prenom
            FROM lignes l
            LEFT JOIN bus b ON b.id = (
                SELECT id FROM bus WHERE ligne_id = l.id AND statut = 'actif' ORDER BY id LIMIT 1
            )
            LEFT JOIN bus_positions bp ON b.id = bp.bus_id
            LEFT JOIN users u ON b.chauffeur_id = u.id
            WHERE l.actif = 1
            ORDER BY l.numero
        ");
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    } catch (PDOException $e) {
        echo json_encode([]);
    }
    exit;
}

// ===== CREER LIGNE =====
if ($action === 'create_ligne') {
    $numero         = sanitize($data['numero'] ?? '');
    $nom            = sanitize($data['nom'] ?? '');
    $trajet_depart  = sanitize($data['trajet_depart'] ?? '');
    $trajet_arrivee = sanitize($data['trajet_arrivee'] ?? '');
    $heure_debut    = sanitize($data['heure_debut'] ?? '');
    $heure_fin      = sanitize($data['heure_fin'] ?? '');
    $couleur        = sanitize($data['couleur'] ?? '#3B82F6');

    $pdo->prepare("INSERT INTO lignes (numero, nom, trajet_depart, trajet_arrivee, heure_debut, heure_fin, couleur, actif) VALUES (?, ?, ?, ?, ?, ?, ?, 1)")
        ->execute([$numero, $nom, $trajet_depart, $trajet_arrivee, $heure_debut, $heure_fin, $couleur]);
    $ligne_id = $pdo->lastInsertId();
    
    echo json_encode(['success' => true, 'message' => 'Ligne créée', 'id' => $ligne_id]);
    exit;
}

// ===== ARRETS D'UNE LIGNE =====
if ($action === 'get_line_stops') {
    $ligne_id = intval($data['ligne_id'] ?? 0);
    $stmt = $pdo->prepare("SELECT id, nom, latitude, longitude, ordre FROM arrets WHERE ligne_id = ? AND actif = 1 ORDER BY ordre");
    $stmt->execute([$ligne_id]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// ===== AJOUTER ARRET A UNE LIGNE =====
if ($action === 'add_stop') {
    $ligne_id  = intval($data['ligne_id'] ?? 0);
    $nom       = sanitize($data['nom'] ?? '');
    $latitude  = is_numeric($data['latitude']  ?? null) ? floatval($data['latitude'])  : null;
    $longitude = is_numeric($data['longitude'] ?? null) ? floatval($data['longitude']) : null;

    if (!$ligne_id || !$nom) {
        echo json_encode(['success' => false, 'message' => 'Ligne et nom requis']);
        exit;
    }
    $stmt = $pdo->prepare("SELECT COALESCE(MAX(ordre), 0) + 1 FROM arrets WHERE ligne_id = ?");
    $stmt->execute([$ligne_id]);
    $ordre = intval($stmt->fetchColumn());

    $pdo->prepare("INSERT INTO arrets (ligne_id, nom, latitude, longitude, ordre) VALUES (?, ?, ?, ?, ?)")
        ->execute([$ligne_id, $nom, $latitude, $longitude, $ordre]);
    echo json_encode(['success' => true, 'id' => $pdo->lastInsertId(), 'ordre' => $ordre]);
    exit;
}

// ===== SUPPRIMER ARRET =====
if ($action === 'delete_stop') {
    $id = intval($data['id'] ?? 0);
    $pdo->prepare("DELETE FROM arrets WHERE id = ?")->execute([$id]);
    echo json_encode(['success' => true]);
    exit;
}

// ===== AJOUTER UN BUS À UNE LIGNE EXISTANTE =====
if ($action === 'add_bus_to_ligne') {
    $ligne_id       = intval($data['ligne_id'] ?? 0);
    $immatriculation = sanitize($data['immatriculation'] ?? '');
    $marque         = sanitize($data['marque'] ?? '');
    $modele         = sanitize($data['modele'] ?? '');
    $places_totales = max(1, intval($data['places_totales'] ?? 40));

    if (!$ligne_id || !$immatriculation) {
        echo json_encode(['success' => false, 'message' => 'Ligne et immatriculation requis']); exit;
    }
    $chk = $pdo->prepare("SELECT id FROM bus WHERE immatriculation = ?");
    $chk->execute([$immatriculation]);
    if ($chk->fetch()) {
        echo json_encode(['success' => false, 'message' => "L'immatriculation « $immatriculation » est déjà utilisée"]); exit;
    }

    $pdo->prepare("INSERT INTO bus (ligne_id, immatriculation, marque, modele, places_totales, statut) VALUES (?, ?, ?, ?, ?, 'actif')")
        ->execute([$ligne_id, $immatriculation, $marque, $modele, $places_totales]);
    $bus_id = $pdo->lastInsertId();

    if ($bus_id) {
        $pdo->prepare("INSERT IGNORE INTO bus_positions (bus_id, ligne_id, latitude, longitude, vitesse, acceleration, places_libres, places_totales, moteur, en_service, temperature) VALUES (?, ?, 0, 0, 0, 0, ?, ?, 0, 0, NULL)")
            ->execute([$bus_id, $ligne_id, $places_totales, $places_totales]);
    }
    echo json_encode(['success' => true, 'bus_id' => $bus_id]);
    exit;
}

// ===== SUPPRIMER LIGNE =====
if ($action === 'delete_ligne') {
    $id = intval($data['id']);
    $pdo->prepare("UPDATE lignes SET actif = 0 WHERE id = ?")->execute([$id]);
    echo json_encode(['success' => true]);
    exit;
}

// ===== INCIDENTS =====
if ($action === 'incidents') {
    $stmt = $pdo->query("SELECT i.*, l.numero as ligne_numero, u.nom, u.prenom, b.immatriculation 
                          FROM incidents i 
                          LEFT JOIN lignes l ON i.ligne_id = l.id 
                          LEFT JOIN users u ON i.chauffeur_id = u.id
                          LEFT JOIN bus b ON i.bus_id = b.id
                          ORDER BY i.date_creation DESC");
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// ===== RESOUDRE INCIDENT =====
if ($action === 'resolve_incident') {
    $id = intval($data['id']);
    $pdo->prepare("UPDATE incidents SET statut = 'resolu', date_resolution = NOW() WHERE id = ?")->execute([$id]);
    echo json_encode(['success' => true]);
    exit;
}

// ===== SIGNALEMENTS =====
if ($action === 'signalements') {
    $stmt = $pdo->query("SELECT s.*, u.nom, u.prenom, u.role, u.code_etudiant, l.numero as ligne_numero
                          FROM signalements s
                          LEFT JOIN users u ON s.user_id = u.id
                          LEFT JOIN lignes l ON s.ligne_id = l.id
                          ORDER BY s.date_creation DESC");
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// ===== TRAITER SIGNALEMENT =====
if ($action === 'traiter_signalement') {
    $id = intval($data['id']);
    $pdo->prepare("UPDATE signalements SET statut = 'traite', date_traitement = NOW() WHERE id = ?")->execute([$id]);
    echo json_encode(['success' => true]);
    exit;
}

// ===== STATISTIQUES MENSUELLES =====
if ($action === 'monthly_stats') {
    $months = [];
    $reservations = [];
    $incidents = [];
    
    for ($i = 5; $i >= 0; $i--) {
        $month = date('Y-m', strtotime("-$i months"));
        $months[] = $month;
        
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM reservations WHERE DATE(date_reservation) LIKE ?");
        $stmt->execute([$month . '%']);
        $reservations[] = intval($stmt->fetchColumn());
        
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM incidents WHERE DATE(date_creation) LIKE ?");
        $stmt->execute([$month . '%']);
        $incidents[] = intval($stmt->fetchColumn());
    }
    
    echo json_encode([
        'months' => $months,
        'reservations' => $reservations,
        'incidents' => $incidents
    ]);
    exit;
}

// ===== PASSWORD RESET REQUESTS =====
if ($action === 'password_reset_requests') {
    $stmt = $pdo->query("SELECT prr.*, u.email, u.nom, u.prenom 
                          FROM password_reset_requests prr 
                          JOIN users u ON prr.user_id = u.id 
                          ORDER BY prr.created_at DESC");
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// ===== APPROUVE RESET =====
if ($action === 'approve_password_reset') {
    $id = intval($data['id']);
    
    $stmt = $pdo->prepare("SELECT prr.*, u.email FROM password_reset_requests prr JOIN users u ON prr.user_id = u.id WHERE prr.id = ?");
    $stmt->execute([$id]);
    $request = $stmt->fetch();
    
    if (!$request) {
        echo json_encode(['success' => false, 'message' => 'Demande non trouvée']);
        exit;
    }
    
    $new_password = substr(md5(uniqid()), 0, 8);
    $hashed = hashPassword($new_password);
    
    $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([$hashed, $request['user_id']]);
    $pdo->prepare("UPDATE password_reset_requests SET statut = 'approved', processed_at = NOW(), processed_by = ? WHERE id = ?")
        ->execute([$user_id, $id]);
    
    // Notification au chauffeur
    $pdo->prepare("INSERT INTO notifications (user_id, titre, message, type) VALUES (?, 'Mot de passe réinitialisé', 'Votre mot de passe a été réinitialisé. Nouveau mot de passe: $new_password', 'success')")
        ->execute([$request['user_id']]);
    
    echo json_encode(['success' => true, 'message' => 'Mot de passe réinitialisé', 'new_password' => $new_password]);
    exit;
}

// ===== REJETER RESET =====
if ($action === 'reject_password_reset') {
    $id = intval($data['id']);
    $pdo->prepare("UPDATE password_reset_requests SET statut = 'rejected', processed_at = NOW(), processed_by = ? WHERE id = ?")
        ->execute([$user_id, $id]);
    echo json_encode(['success' => true]);
    exit;
}

echo json_encode(['error' => 'Action invalide']);
