<?php

declare(strict_types=1);

$sessionPath = __DIR__ . '/../../tmp/sessions';

if (!is_dir($sessionPath)) {
    mkdir($sessionPath, 0700, true);
}

session_save_path($sessionPath);

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Lax'
]);



session_start();



header('Content-Type: application/json; charset=UTF-8');

function sendJsonResponse(int $status, array $payload): void
{
    http_response_code($status);

    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE
    );

    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendJsonResponse(405, [
        'success' => false,
        'message' => 'Método não permitido.'
    ]);
}

$userId = $_SESSION['user_id'] ?? null;

if (!is_int($userId) && !ctype_digit((string) $userId)) {
    sendJsonResponse(401, [
        'success' => false,
        'message' => 'Não autenticado.'
    ]);
}

$pdo = require __DIR__ . '/../../config/database.php';

$statement = $pdo->prepare(
    'SELECT
        id,
        first_name,
        last_name,
        email,
        email_verified_at,
        moodle_user_id,
        status
     FROM users
     WHERE id = :id
     LIMIT 1'
);

$statement->execute([
    'id' => (int) $userId
]);

$user = $statement->fetch();

if ($user === false) {
    $_SESSION = [];

    session_destroy();

    sendJsonResponse(401, [
        'success' => false,
        'message' => 'Não autenticado.'
    ]);
}

if ($user['status'] !== 'active') {
    $_SESSION = [];

    session_destroy();

    sendJsonResponse(403, [
        'success' => false,
        'message' => 'Esta conta não está disponível para acesso.'
    ]);
}

if ($user['email_verified_at'] === null) {
    $_SESSION = [];

    session_destroy();

    sendJsonResponse(403, [
        'success' => false,
        'message' => 'Confirme seu e-mail antes de acessar sua conta.'
    ]);
}

sendJsonResponse(200, [
    'success' => true,
    'user' => [
        'id' => (int) $user['id'],
        'first_name' => $user['first_name'],
        'last_name' => $user['last_name'],
        'email' => $user['email'],
        'email_verified' => true,
        'moodle_user_id' =>
            $user['moodle_user_id'] !== null
                ? (int) $user['moodle_user_id']
                : null
    ]
]);