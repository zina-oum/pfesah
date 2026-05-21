<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/db.php';

$user_id = intval($_POST['user_id'] ?? 0);

// SECURITY: verify role from DB
if ($user_id <= 0) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentification requise']);
    exit;
}
$_roleStmt = $pdo->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
$_roleStmt->execute([$user_id]);
$_roleRow = $_roleStmt->fetch(PDO::FETCH_ASSOC);
if (!$_roleRow || !in_array($_roleRow['role'], ['etudiant', 'chauffeur', 'admin'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Accès refusé']);
    exit;
}
$user_role = $_roleRow['role'];

if (!isset($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'message' => 'Aucune photo uploadée']);
    exit;
}

$file = $_FILES['photo'];
$allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
if (!in_array($file['type'], $allowedTypes)) {
    echo json_encode(['success' => false, 'message' => 'Type de fichier non autorisé']);
    exit;
}

if ($file['size'] > 2 * 1024 * 1024) { // 2MB max
    echo json_encode(['success' => false, 'message' => 'Fichier trop volumineux (max 2MB)']);
    exit;
}

// S'assurer que la colonne photo est TEXT pour stocker les base64 (corrige les anciennes installations)
try { $pdo->exec("ALTER TABLE users MODIFY photo MEDIUMTEXT"); } catch (PDOException $e) {}

// Lire le fichier en base64
$imageData = file_get_contents($file['tmp_name']);
$base64 = 'data:' . $file['type'] . ';base64,' . base64_encode($imageData);

// Sauvegarder en base
try {
    $pdo->prepare("UPDATE users SET photo = ? WHERE id = ?")->execute([$base64, $user_id]);
    echo json_encode(['success' => true, 'photo_url' => $base64]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Erreur sauvegarde: ' . $e->getMessage()]);
}
?>