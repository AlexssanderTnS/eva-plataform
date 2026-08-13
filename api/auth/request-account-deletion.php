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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
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

$input = json_decode(
    file_get_contents('php://input'),
    true
);

$password = is_array($input)
    ? (string) ($input['password'] ?? '')
    : '';

if ($password === '') {
    sendJsonResponse(422, [
        'success' => false,
        'message' =>
            'Informe sua senha para confirmar a solicitação.'
    ]);
}

$pdo = require __DIR__ . '/../../config/database.php';

$statement = $pdo->prepare(
    'SELECT id, password_hash
        FROM users
        WHERE id = :id
        LIMIT 1'
);

$statement->execute([
    'id' => (int) $userId
]);

$user = $statement->fetch();

if (
    $user === false ||
    !password_verify(
        $password,
        (string) $user['password_hash']
    )
) {
    sendJsonResponse(401, [
        'success' => false,
        'message' => 'Senha incorreta.'
    ]);
}

try {
    $statement = $pdo->prepare(
        'INSERT INTO account_deletion_requests (
            user_id
        ) VALUES (
            :user_id
        )
        ON DUPLICATE KEY UPDATE
            status = IF(
                status = "completed",
                status,
                "pending"
            ),
            requested_at = IF(
                status = "completed",
                requested_at,
                CURRENT_TIMESTAMP
            )'
    );

    $statement->execute([
        'user_id' => (int) $userId
    ]);

} catch (Throwable $error) {
    error_log(
        'EVA LGPD: erro na solicitação de exclusão: ' .
        $error->getMessage()
    );

    sendJsonResponse(500, [
        'success' => false,
        'message' =>
            'Não foi possível registrar a solicitação.'
    ]);
}

sendJsonResponse(202, [
    'success' => true,
    'message' =>
        'Sua solicitação de exclusão foi registrada.'
]);