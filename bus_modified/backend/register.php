<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Méthode non autorisée']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$nom = sanitize($input['nom'] ?? '');
$prenom = sanitize($input['prenom'] ?? '');
$email = sanitize($input['email'] ?? '');
$code = sanitize($input['code'] ?? '');
$password = $input['password'] ?? '';

if (!$nom || !$prenom || !$email || !$code || !$password) {
    echo json_encode(['success' => false, 'message' => 'Tous les champs sont requis']);
    exit;
}

if (strlen($password) < 6) {
    echo json_encode(['success' => false, 'message' => 'Mot de passe minimum 6 caractères']);
    exit;
}

if (!preg_match('/^\d{10}$/', $code)) {
    echo json_encode(['success' => false, 'message' => 'Code étudiant : 10 chiffres']);
    exit;
}

try {
    // Check if email already exists
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Email déjà utilisé']);
        exit;
    }

    // Check if code_etudiant already exists
    $stmt = $pdo->prepare("SELECT id FROM users WHERE code_etudiant = ?");
    $stmt->execute([$code]);
    if ($stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Code étudiant déjà utilisé']);
        exit;
    }

    // Hash password
    $hashed = hashPassword($password);

    // Insert user
    $stmt = $pdo->prepare("INSERT INTO users (email, password, nom, prenom, code_etudiant, role, statut) VALUES (?, ?, ?, ?, ?, 'etudiant', 'pending')");
    $stmt->execute([$email, $hashed, $nom, $prenom, $code]);

    echo json_encode(['success' => true, 'message' => 'Compte créé ! En attente de validation par l\'administrateur.']);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Erreur serveur: ' . $e->getMessage()]);
}
?>