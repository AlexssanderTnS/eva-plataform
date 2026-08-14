<?php

declare(strict_types=1);

require __DIR__ . '/../../config/security.php';

evaApplyApiSecurityHeaders();

try {
    require __DIR__ . '/../../config/session.php';
} catch (Throwable $error) {
    error_log('EVA Auth: falha ao iniciar sessão: ' . $error->getMessage());
    evaSecurityJsonResponse(500, 'Não foi possível exportar seus dados.');
}

header('Content-Type: application/json; charset=UTF-8');

function sendJsonResponse(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
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
        'SELECT first_name, last_name, email, email_verified_at, status, created_at, updated_at FROM users WHERE id = :id LIMIT 1'
    );
    $statement->execute(['id' => (int) $userId]);
    $user = $statement->fetch();
} catch (Throwable $error) {
    error_log('EVA LGPD: erro ao exportar dados: ' . $error->getMessage());
    sendJsonResponse(500, ['success' => false, 'message' => 'Não foi possível exportar seus dados.']);
}

if ($user === false) {
    evaDestroySession();
    sendJsonResponse(401, ['success' => false, 'message' => 'Não autenticado.']);
}

if ($user['status'] !== 'active' || $user['email_verified_at'] === null) {
    evaDestroySession();
    sendJsonResponse(403, ['success' => false, 'message' => 'Esta conta não está disponível para exportação.']);
}

sendJsonResponse(200, [
    'success' => true,
    'generated_at' => gmdate(DATE_ATOM),
    'data' => [
        'account' => [
            'first_name' => $user['first_name'],
            'last_name' => $user['last_name'],
            'email' => $user['email'],
            'email_verified_at' => $user['email_verified_at'],
            'status' => $user['status'],
            'created_at' => $user['created_at'],
            'updated_at' => $user['updated_at']
        ]
    ]
]);
