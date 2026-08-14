<?php

declare(strict_types=1);

require __DIR__ . '/../../config/security.php';

evaApplyApiSecurityHeaders();

try {
    require __DIR__ . '/../../config/session.php';
} catch (Throwable $error) {
    error_log('EVA Auth: falha ao iniciar sessão: ' . $error->getMessage());
    evaSecurityJsonResponse(500, 'Não foi possível iniciar sua sessão.');
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

$input = json_decode(file_get_contents('php://input'), true);

if (!is_array($input)) {
    sendJsonResponse(400, ['success' => false, 'message' => 'Dados inválidos.']);
}

$email = trim((string) ($input['email'] ?? ''));
$password = (string) ($input['password'] ?? '');
$errors = [];

if ($email === '') {
    $errors['email'] = 'Informe seu e-mail.';
} elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 254) {
    $errors['email'] = 'Informe um e-mail válido.';
}

if ($password === '') {
    $errors['password'] = 'Informe sua senha.';
} elseif (mb_strlen($password) > 128) {
    $errors['password'] = 'Senha inválida.';
}

if ($errors !== []) {
    sendJsonResponse(422, ['success' => false, 'message' => 'Verifique os campos informados.', 'errors' => $errors]);
}

$clientIp = evaClientIp();
$accountKey = hash('sha256', mb_strtolower($email, 'UTF-8'));
$rateLimitWindow = 900;

evaAssertRateLimit('login-ip', $clientIp, 25, $rateLimitWindow);
evaAssertRateLimit('login-account', $accountKey, 8, $rateLimitWindow);

try {
    $pdo = require __DIR__ . '/../../config/database.php';
    $statement = $pdo->prepare(
        'SELECT id, password_hash, email_verified_at, status FROM users WHERE email = :email LIMIT 1'
    );
    $statement->execute(['email' => $email]);
    $user = $statement->fetch();
} catch (Throwable $error) {
    error_log('EVA Auth: erro ao consultar login: ' . $error->getMessage());
    sendJsonResponse(500, ['success' => false, 'message' => 'Não foi possível realizar o login.']);
}

$dummyHash = '$2y$12$o6gaANvWo5Fkov2yIUtETehi7ZLCOMY2v7P1Q69SgVBYMAYEJAkwi';
$passwordHash = $user !== false ? (string) $user['password_hash'] : $dummyHash;
$passwordIsValid = password_verify($password, $passwordHash);

if ($user === false || !$passwordIsValid) {
    evaRecordRateLimitHit('login-ip', $clientIp, $rateLimitWindow);
    evaRecordRateLimitHit('login-account', $accountKey, $rateLimitWindow);
    sendJsonResponse(401, ['success' => false, 'message' => 'E-mail ou senha inválidos.']);
}

evaClearRateLimit('login-account', $accountKey);

if ($user['status'] !== 'active') {
    sendJsonResponse(403, ['success' => false, 'message' => 'Esta conta não está disponível para acesso.']);
}

if ($user['email_verified_at'] === null) {
    sendJsonResponse(403, [
        'success' => false,
        'message' => 'Confirme seu e-mail antes de acessar sua conta.',
        'email_verification_required' => true
    ]);
}

session_regenerate_id(true);
$_SESSION['user_id'] = (int) $user['id'];

sendJsonResponse(200, ['success' => true, 'message' => 'Login realizado com sucesso.']);
