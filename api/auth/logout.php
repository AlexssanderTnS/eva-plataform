<?php

declare(strict_types=1);

require __DIR__ . '/../../config/security.php';

evaApplyApiSecurityHeaders();

try {
    require __DIR__ . '/../../config/session.php';
} catch (Throwable $error) {
    error_log('EVA Auth: falha ao iniciar sessão: ' . $error->getMessage());
    evaSecurityJsonResponse(500, 'Não foi possível encerrar sua sessão.');
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
evaDestroySession();

sendJsonResponse(200, [
    'success' => true,
    'message' => 'Logout realizado com sucesso.'
]);
