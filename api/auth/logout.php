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



$_SESSION = [];


if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();

    setcookie(
        session_name(),
        '',
        [
            'expires' => time() - 42000,
            'path' => $params['path'],
            'domain' => $params['domain'],
            'secure' => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'] ?? 'Lax'
        ]
    );
}


session_destroy();

sendJsonResponse(200, [
    'success' => true,
    'message' => 'Logout realizado com sucesso.'
]);