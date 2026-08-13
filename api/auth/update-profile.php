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

if ($_SERVER['REQUEST_METHOD'] !== 'PATCH') {
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

if (!is_array($input)) {
    sendJsonResponse(400, [
        'success' => false,
        'message' => 'Dados inválidos.'
    ]);
}

$firstName =
    trim((string) ($input['first_name'] ?? ''));

$lastName =
    trim((string) ($input['last_name'] ?? ''));

$errors = [];

if ($firstName === '') {
    $errors['first_name'] = 'Informe seu nome.';
} elseif (mb_strlen($firstName) > 80) {
    $errors['first_name'] =
        'O nome informado é muito longo.';
}

if ($lastName === '') {
    $errors['last_name'] =
        'Informe seu sobrenome.';
} elseif (mb_strlen($lastName) > 120) {
    $errors['last_name'] =
        'O sobrenome informado é muito longo.';
}

if ($errors !== []) {
    sendJsonResponse(422, [
        'success' => false,
        'message' => 'Verifique os campos informados.',
        'errors' => $errors
    ]);
}

$pdo = require __DIR__ . '/../../config/database.php';

$statement = $pdo->prepare(
    'UPDATE users
    SET
        first_name = :first_name,
        last_name = :last_name
    WHERE id = :id
        AND status = "active"'
);

$statement->execute([
    'first_name' => $firstName,
    'last_name' => $lastName,
    'id' => (int) $userId
]);

sendJsonResponse(200, [
    'success' => true,
    'message' => 'Perfil atualizado com sucesso.',
    'user' => [
        'id' => (int) $userId,
        'first_name' => $firstName,
        'last_name' => $lastName
    ]
]);