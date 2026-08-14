<?php

declare(strict_types=1);

require __DIR__ . '/../../config/security.php';

evaApplyApiSecurityHeaders();

try {
    require __DIR__ . '/../../config/session.php';
} catch (Throwable $error) {
    error_log('EVA Auth: falha ao iniciar sessão: ' . $error->getMessage());
    evaSecurityJsonResponse(500, 'Não foi possível carregar sua conta.');
}

header('Content-Type: application/json; charset=UTF-8');

function sendJsonResponse(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendJsonResponse(405, ['success' => false, 'message' => 'Método não permitido.']);
}

$userId = $_SESSION['user_id'] ?? null;

if (!is_int($userId) && !ctype_digit((string) $userId)) {
    sendJsonResponse(401, ['success' => false, 'message' => 'Não autenticado.']);
}

try {
    $pdo = require __DIR__ . '/../../config/database.php';
    $statement = $pdo->prepare(
        'SELECT first_name, last_name, email, email_verified_at, status FROM users WHERE id = :id LIMIT 1'
    );
    $statement->execute(['id' => (int) $userId]);
    $user = $statement->fetch();
} catch (Throwable $error) {
    error_log('EVA Auth: erro ao carregar conta: ' . $error->getMessage());
    sendJsonResponse(500, ['success' => false, 'message' => 'Não foi possível carregar sua conta.']);
}

if ($user === false) {
    evaDestroySession();
    sendJsonResponse(401, ['success' => false, 'message' => 'Não autenticado.']);
}

if ($user['status'] !== 'active') {
    evaDestroySession();
    sendJsonResponse(403, ['success' => false, 'message' => 'Esta conta não está disponível para acesso.']);
}

if ($user['email_verified_at'] === null) {
    evaDestroySession();
    sendJsonResponse(403, ['success' => false, 'message' => 'Confirme seu e-mail antes de acessar sua conta.']);
}

sendJsonResponse(200, [
    'success' => true,
    'user' => [
        'first_name' => $user['first_name'],
        'last_name' => $user['last_name'],
        'email' => $user['email'],
        'email_verified' => true
    ]
]);
