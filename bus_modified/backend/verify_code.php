<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Méthode non autorisée']);
    exit;
}

$action = $_GET['action'] ?? '';

// ===== VÉRIFICATION CODE 2FA =====
if ($action === 'verify_code') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Corps JSON invalide']);
        exit;
    }

    $user_id = intval($input['user_id'] ?? 0);
    $code    = sanitize($input['code'] ?? '');

    if ($user_id <= 0 || !$code) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Paramètres manquants']);
        exit;
    }

    try {
        // Chercher un code valide non utilisé et non expiré
        $stmt = $pdo->prepare(
            "SELECT id FROM verification_codes
             WHERE user_id = ? AND code = ? AND used = 0 AND expires_at > NOW()
             ORDER BY created_at DESC LIMIT 1"
        );
        $stmt->execute([$user_id, $code]);
        $row = $stmt->fetch();

        if (!$row) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Code invalide ou expiré']);
            exit;
        }

        // Marquer le code comme utilisé
        $pdo->prepare("UPDATE verification_codes SET used = 1 WHERE id = ?")
            ->execute([$row['id']]);

        // Récupérer l'utilisateur
        $userStmt = $pdo->prepare(
            "SELECT id, email, nom, prenom, role, statut FROM users WHERE id = ? AND statut = 'validated' LIMIT 1"
        );
        $userStmt->execute([$user_id]);
        $user = $userStmt->fetch();

        if (!$user) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Compte non trouvé ou non validé']);
            exit;
        }

        echo json_encode(['success' => true, 'user' => $user]);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Erreur serveur']);
    }
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'message' => 'Action invalide']);
