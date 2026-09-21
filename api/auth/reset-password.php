<?php

declare(strict_types=1);

require __DIR__ . '/../../config/security.php';
evaApplyApiSecurityHeaders();
header('Content-Type: application/json; charset=UTF-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    evaSecurityJsonResponse(405, 'Método não permitido.');
}
evaEnforceTrustedOrigin();
evaEnforceJsonRequest(4096);
evaAssertRateLimit('password-reset-ip', evaClientIp(), 20, 900);
evaRecordRateLimitHit('password-reset-ip', evaClientIp(), 900);
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    evaSecurityJsonResponse(400, 'Dados inválidos.');
}
$token = is_string($input['token'] ?? null) ? $input['token'] : '';
$password = is_string($input['password'] ?? null) ? $input['password'] : '';
$confirmation = is_string($input['password_confirmation'] ?? null) ? $input['password_confirmation'] : '';
if (!preg_match('/\A[a-f0-9]{64}\z/', $token)) {
    evaSecurityJsonResponse(422, 'Link inválido ou expirado. Solicite um novo link.');
}
if (mb_strlen($password) < 8) {
    evaSecurityJsonResponse(422, 'Use pelo menos 8 caracteres na senha.');
}
if (mb_strlen($password) > 128 || strlen($password) > 72 || str_contains($password, "\0")) {
    evaSecurityJsonResponse(422, 'Essa senha é muito longa ou contém caracteres inválidos. Escolha outra senha.');
}
if ($password !== $confirmation) {
    evaSecurityJsonResponse(422, 'As senhas não coincidem.');
}
try {
    $pdo = require __DIR__ . '/../../config/database.php';
    $tokenHash = hash('sha256', $token);
    $query = $pdo->prepare('SELECT user_id FROM password_reset_tokens WHERE token_hash = :token_hash');
    $query->execute(['token_hash' => $tokenHash]);
    $userId = $query->fetchColumn();
    if ($userId === false) {
        evaSecurityJsonResponse(422, 'Link inválido ou expirado. Solicite um novo link.');
    }
    $newHash = password_hash($password, PASSWORD_DEFAULT);
    $pdo->beginTransaction();
    $query = $pdo->prepare('SELECT id, status, session_version FROM users WHERE id = :id FOR UPDATE');
    $query->execute(['id' => $userId]);
    $user = $query->fetch();
    $query = $pdo->prepare('SELECT session_version FROM password_reset_tokens WHERE user_id = :user_id AND token_hash = :token_hash AND expires_at > CURRENT_TIMESTAMP FOR UPDATE');
    $query->execute(['user_id' => $userId, 'token_hash' => $tokenHash]);
    $reset = $query->fetch();
    if (!$user || !$reset || $user['status'] !== 'active' || (int) $user['session_version'] !== (int) $reset['session_version']) {
        $pdo->rollBack();
        evaSecurityJsonResponse(422, 'Link inválido ou expirado. Solicite um novo link.');
    }
    $query = $pdo->prepare('UPDATE users SET password_hash = :password_hash, session_version = session_version + 1 WHERE id = :id');
    $query->execute(['password_hash' => $newHash, 'id' => $userId]);
    $query = $pdo->prepare('DELETE FROM password_reset_tokens WHERE user_id = :user_id');
    $query->execute(['user_id' => $userId]);
    $pdo->commit();
} catch (Throwable $error) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('EVA Auth: falha ao redefinir senha.');
    evaSecurityJsonResponse(500, 'Não foi possível alterar sua senha. Tente novamente.');
}
echo json_encode(['success' => true, 'message' => 'Senha atualizada. Entre novamente com sua nova senha.'], JSON_UNESCAPED_UNICODE);
