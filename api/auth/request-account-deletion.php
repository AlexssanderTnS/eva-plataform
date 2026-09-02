<?php

declare(strict_types=1);

require __DIR__ . '/../../config/security.php';

evaApplyApiSecurityHeaders();

try {
    require __DIR__ . '/../../config/session.php';
} catch (Throwable $error) {
    error_log('EVA Auth: falha ao iniciar sessão: ' . $error->getMessage());
    evaSecurityJsonResponse(500, 'Não foi possível registrar a solicitação.');
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
$password = is_array($input) ? (string) ($input['password'] ?? '') : '';

if ($password === '' || mb_strlen($password) > 128) {
    sendJsonResponse(422, [
        'success' => false,
        'message' => 'Informe sua senha para confirmar a solicitação.'
    ]);
}

$clientIp = evaClientIp();
$userRateKey = (string) (int) $userId;
$rateLimitWindow = 900;

evaAssertRateLimit('delete-account-user', $userRateKey, 5, $rateLimitWindow);
evaAssertRateLimit('delete-account-ip', $clientIp, 20, $rateLimitWindow);

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
        sendJsonResponse(403, ['success' => false, 'message' => 'Esta conta não está disponível para esta operação.']);
    }

    if (!password_verify($password, (string) $user['password_hash'])) {
        evaRecordRateLimitHit('delete-account-user', $userRateKey, $rateLimitWindow);
        evaRecordRateLimitHit('delete-account-ip', $clientIp, $rateLimitWindow);
        sendJsonResponse(401, ['success' => false, 'message' => 'Senha incorreta.']);
    }

    evaClearRateLimit('delete-account-user', $userRateKey);

    $statement = $pdo->prepare(
        'INSERT INTO account_deletion_requests (user_id)
         VALUES (:user_id)
         ON DUPLICATE KEY UPDATE
            requested_at = IF(status = "completed", requested_at, CURRENT_TIMESTAMP),
            processed_at = IF(status = "completed", processed_at, NULL),
            status = IF(status = "completed", status, "pending")'
    );

    $statement->execute(['user_id' => (int) $userId]);
} catch (Throwable $error) {
    error_log('EVA LGPD: erro na solicitação de exclusão: ' . $error->getMessage());
    sendJsonResponse(500, ['success' => false, 'message' => 'Não foi possível registrar a solicitação.']);
}

sendJsonResponse(202, [
    'success' => true,
    'message' => 'Sua solicitação de exclusão foi registrada.'
]);
