<?php

declare(strict_types=1);

require __DIR__ . '/../../config/security.php';

evaApplyApiSecurityHeaders();

try {
    require __DIR__ . '/../../config/session.php';
} catch (Throwable $error) {
    error_log('EVA Auth: falha ao iniciar sessão: ' . $error->getMessage());
    evaSecurityJsonResponse(500, 'Não foi possível alterar sua senha.');
}

header('Content-Type: application/json; charset=UTF-8');

function sendJsonResponse(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJsonResponse(405, ['success' => false, 'message' => 'Método não permitido.']);
}

evaEnforceTrustedOrigin();
evaEnforceJsonRequest(4096);

$userId = $_SESSION['user_id'] ?? null;

if (!is_int($userId) && !ctype_digit((string) $userId)) {
    sendJsonResponse(401, ['success' => false, 'message' => 'Não autenticado.']);
}

$input = json_decode(file_get_contents('php://input'), true);

if (!is_array($input)) {
    sendJsonResponse(400, ['success' => false, 'message' => 'Dados inválidos.']);
}

$currentPassword = (string) ($input['current_password'] ?? '');
$newPassword = (string) ($input['new_password'] ?? '');
$newPasswordConfirmation = (string) ($input['new_password_confirmation'] ?? '');
$errors = [];

if ($currentPassword === '') {
    $errors['current_password'] = 'Informe sua senha atual.';
} elseif (mb_strlen($currentPassword) > 128) {
    $errors['current_password'] = 'Senha atual inválida.';
}

if ($newPassword === '') {
    $errors['new_password'] = 'Informe a nova senha.';
} elseif (mb_strlen($newPassword) < 8) {
    $errors['new_password'] = 'A nova senha deve ter pelo menos 8 caracteres.';
} elseif (mb_strlen($newPassword) > 128) {
    $errors['new_password'] = 'A nova senha pode ter no máximo 128 caracteres.';
}

if ($newPassword !== $newPasswordConfirmation) {
    $errors['new_password_confirmation'] = 'As senhas não coincidem.';
}

if ($currentPassword !== '' && $newPassword !== '' && hash_equals($currentPassword, $newPassword)) {
    $errors['new_password'] = 'A nova senha deve ser diferente da senha atual.';
}

if ($errors !== []) {
    sendJsonResponse(422, [
        'success' => false,
        'message' => 'Verifique os campos informados.',
        'errors' => $errors
    ]);
}

$clientIp = evaClientIp();
$userRateKey = (string) (int) $userId;
$rateLimitWindow = 900;

evaAssertRateLimit('change-password-user', $userRateKey, 5, $rateLimitWindow);
evaAssertRateLimit('change-password-ip', $clientIp, 20, $rateLimitWindow);

try {
    $pdo = require __DIR__ . '/../../config/database.php';
    $statement = $pdo->prepare(
        'SELECT id, password_hash, email_verified_at, status FROM users WHERE id = :id LIMIT 1'
    );
    $statement->execute(['id' => (int) $userId]);
    $user = $statement->fetch();

    if ($user === false) {
        evaDestroySession();
        sendJsonResponse(401, ['success' => false, 'message' => 'Não autenticado.']);
    }

    if ($user['status'] !== 'active' || $user['email_verified_at'] === null) {
        evaDestroySession();
        sendJsonResponse(403, ['success' => false, 'message' => 'Esta conta não está disponível para alteração.']);
    }

    if (!password_verify($currentPassword, (string) $user['password_hash'])) {
        evaRecordRateLimitHit('change-password-user', $userRateKey, $rateLimitWindow);
        evaRecordRateLimitHit('change-password-ip', $clientIp, $rateLimitWindow);
        sendJsonResponse(401, ['success' => false, 'message' => 'Senha atual incorreta.']);
    }

    $newPasswordHash = password_hash($newPassword, PASSWORD_DEFAULT);

    if ($newPasswordHash === false) {
        throw new RuntimeException('Falha ao gerar hash da nova senha.');
    }

    $statement = $pdo->prepare(
        'UPDATE users SET password_hash = :password_hash WHERE id = :id'
    );
    $statement->execute([
        'password_hash' => $newPasswordHash,
        'id' => (int) $userId
    ]);

    evaClearRateLimit('change-password-user', $userRateKey);
    session_regenerate_id(true);
} catch (Throwable $error) {
    if (http_response_code() >= 400 && http_response_code() < 500) {
        throw $error;
    }

    error_log('EVA Auth: erro ao alterar senha: ' . $error->getMessage());
    sendJsonResponse(500, ['success' => false, 'message' => 'Não foi possível alterar sua senha.']);
}

sendJsonResponse(200, [
    'success' => true,
    'message' => 'Senha alterada com sucesso.'
]);
