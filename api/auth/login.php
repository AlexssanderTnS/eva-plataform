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

error_log('EVA SESSION STATUS: ' . session_status());
error_log('EVA SESSION ID INICIAL: ' . session_id());
error_log('EVA SESSION USE COOKIES: ' . ini_get('session.use_cookies'));
error_log('EVA SESSION SAVE PATH: ' . session_save_path());

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

$email = trim((string) ($input['email'] ?? ''));
$password = (string) ($input['password'] ?? '');

$errors = [];

if ($email === '') {
    $errors['email'] = 'Informe seu e-mail.';
} elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors['email'] = 'Informe um e-mail válido.';
}

if ($password === '') {
    $errors['password'] = 'Informe sua senha.';
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
    'SELECT
        id,
        first_name,
        last_name,
        email,
        password_hash,
        email_verified_at,
        moodle_user_id,
        status
     FROM users
     WHERE email = :email
     LIMIT 1'
);

$statement->execute([
    'email' => $email
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
        'message' => 'E-mail ou senha inválidos.'
    ]);
}


if ($user['status'] !== 'active') {
    sendJsonResponse(403, [
        'success' => false,
        'message' =>
            'Esta conta não está disponível para acesso.'
    ]);
}


if ($user['email_verified_at'] === null) {
    sendJsonResponse(403, [
        'success' => false,
        'message' =>
            'Confirme seu e-mail antes de acessar sua conta.',
        'email_verification_required' => true
    ]);
}

session_regenerate_id(true);

$_SESSION['user_id'] = (int) $user['id'];
error_log('EVA SESSION ID FINAL: ' . session_id());
error_log('EVA SESSION USER: ' . ($_SESSION['user_id'] ?? 'null'));

sendJsonResponse(200, [
    'success' => true,
    'message' => 'Login realizado com sucesso.',
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