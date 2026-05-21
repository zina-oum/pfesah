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
$email = sanitize($input['email'] ?? '');
$password = $input['password'] ?? '';

if (!$email || !$password) {
    echo json_encode(['success' => false, 'message' => 'Email et mot de passe requis']);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT id, email, password, nom, prenom, role, statut FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // Compte inexistant
    if (!$user) {
        echo json_encode(['success' => false, 'message' => 'Aucun compte trouvé pour cet email. Contactez l\'administrateur.', 'code' => 'not_found']);
        exit;
    }

    // Mauvais mot de passe
    if (!password_verify($password, $user['password'])) {
        echo json_encode(['success' => false, 'message' => 'Mot de passe incorrect.', 'code' => 'wrong_password']);
        exit;
    }

    // Statut non validé
    if ($user['statut'] !== 'validated') {
        if ($user['statut'] === 'pending') {
            echo json_encode(['success' => false, 'message' => 'Votre compte est en attente de validation par l\'administrateur.', 'code' => 'pending']);
        } elseif ($user['statut'] === 'rejected') {
            echo json_encode(['success' => false, 'message' => 'Votre compte a été refusé. Contactez l\'administrateur.', 'code' => 'rejected']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Compte non validé.', 'code' => 'invalid']);
        }
        exit;
    }

    // Chauffeur sans bus assigné → connexion autorisée mais avertissement
    $warning = null;
    if ($user['role'] === 'chauffeur') {
        $busStmt = $pdo->prepare("SELECT id FROM bus WHERE chauffeur_id = ? AND statut = 'actif' LIMIT 1");
        $busStmt->execute([$user['id']]);
        if (!$busStmt->fetch()) {
            $warning = 'Aucun bus assigné à votre compte. Contactez l\'administrateur.';
        }
    }

    unset($user['password']);

    echo json_encode([
        'success' => true,
        'user'    => $user,
        'warning' => $warning
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Erreur serveur: ' . $e->getMessage()]);
}
?>