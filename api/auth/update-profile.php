<?php

declare(strict_types=1);

require __DIR__ . '/../../config/security.php';

evaApplyApiSecurityHeaders();

try {
    require __DIR__ . '/../../config/session.php';
} catch (Throwable $error) {
    error_log('EVA Auth: falha ao iniciar sessão: ' . $error->getMessage());
    evaSecurityJsonResponse(500, 'Não foi possível atualizar o perfil.');
}

header('Content-Type: application/json; charset=UTF-8');

function sendJsonResponse(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'PATCH') {
    sendJsonResponse(405, ['success' => false, 'message' => 'Método não permitido.']);
}

evaEnforceTrustedOrigin();
evaEnforceJsonRequest(4096);

$userId = $_SESSION['user_id'] ?? null;

if (!is_int($userId) && !ctype_digit((string) $userId)) {
    sendJsonResponse(401, ['success' => false, 'message' => 'Não autenticado.']);
}

$userId = (int) $userId;
$rateLimitWindow = 3600;

evaAssertRateLimit('profile-update-user', (string) $userId, 30, $rateLimitWindow);

$input = json_decode(file_get_contents('php://input'), true);

if (!is_array($input)) {
    sendJsonResponse(400, ['success' => false, 'message' => 'Dados inválidos.']);
}

$firstName = trim((string) ($input['first_name'] ?? ''));
$lastName = trim((string) ($input['last_name'] ?? ''));
$errors = [];

if ($firstName === '') {
    $errors['first_name'] = 'Informe seu nome.';
} elseif (mb_strlen($firstName) > 80) {
    $errors['first_name'] = 'O nome informado é muito longo.';
} elseif (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $firstName)) {
    $errors['first_name'] = 'O nome contém caracteres inválidos.';
}

if ($lastName === '') {
    $errors['last_name'] = 'Informe seu sobrenome.';
} elseif (mb_strlen($lastName) > 120) {
    $errors['last_name'] = 'O sobrenome informado é muito longo.';
} elseif (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $lastName)) {
    $errors['last_name'] = 'O sobrenome contém caracteres inválidos.';
}

if ($errors !== []) {
    sendJsonResponse(422, [
        'success' => false,
        'message' => 'Verifique os campos informados.',
        'errors' => $errors
    ]);
}

evaRecordRateLimitHit('profile-update-user', (string) $userId, $rateLimitWindow);

try {
    $pdo = require __DIR__ . '/../../config/database.php';

    $statement = $pdo->prepare(
        'SELECT status, email_verified_at FROM users WHERE id = :id LIMIT 1'
    );
    $statement->execute(['id' => (int) $userId]);
    $account = $statement->fetch();

    if ($account === false) {
        evaDestroySession();
        sendJsonResponse(401, ['success' => false, 'message' => 'Não autenticado.']);
    }

    if ($account['status'] !== 'active' || $account['email_verified_at'] === null) {
        evaDestroySession();
        sendJsonResponse(403, ['success' => false, 'message' => 'Esta conta não está disponível para alteração.']);
    }

    $statement = $pdo->prepare(
        'UPDATE users SET first_name = :first_name, last_name = :last_name WHERE id = :id'
    );
    $statement->execute([
        'first_name' => $firstName,
        'last_name' => $lastName,
        'id' => (int) $userId
    ]);
} catch (Throwable $error) {
    error_log('EVA Auth: erro ao atualizar perfil: ' . $error->getMessage());
    sendJsonResponse(500, ['success' => false, 'message' => 'Não foi possível atualizar o perfil.']);
}

sendJsonResponse(200, [
    'success' => true,
    'message' => 'Perfil atualizado com sucesso.',
    'user' => [
        'first_name' => $firstName,
        'last_name' => $lastName
    ]
]);
