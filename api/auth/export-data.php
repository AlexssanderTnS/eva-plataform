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
    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_PRETTY_PRINT
    );
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendJsonResponse(405, ['success' => false, 'message' => 'Método não permitido.']);
}

$userId = $_SESSION['user_id'] ?? null;

if (!is_int($userId) && !ctype_digit((string) $userId)) {
    sendJsonResponse(401, ['success' => false, 'message' => 'Não autenticado.']);
}

$userId = (int) $userId;

$rateLimitWindow = 3600;

evaAssertRateLimit('data-export-user', (string) $userId, 5, $rateLimitWindow);
evaRecordRateLimitHit('data-export-user', (string) $userId, $rateLimitWindow);

try {
    $pdo = require __DIR__ . '/../../config/database.php';

    $statement = $pdo->prepare(
        'SELECT first_name, last_name, email, email_verified_at, moodle_user_id, status, created_at, updated_at
         FROM users
         WHERE id = :id
         LIMIT 1'
    );
    $statement->execute(['id' => $userId]);
    $user = $statement->fetch();

    if ($user === false) {
        evaDestroySession();
        sendJsonResponse(401, ['success' => false, 'message' => 'Não autenticado.']);
    }

    if ($user['status'] !== 'active' || $user['email_verified_at'] === null) {
        evaDestroySession();
        sendJsonResponse(403, ['success' => false, 'message' => 'Esta conta não está disponível para exportação.']);
    }

    $statement = $pdo->prepare(
        'SELECT
            o.external_reference,
            c.slug AS course_slug,
            c.title AS course_title,
            o.amount,
            o.currency,
            o.status,
            o.paid_at,
            o.created_at,
            o.updated_at
         FROM orders o
         INNER JOIN courses c ON c.id = o.course_id
         WHERE o.user_id = :user_id
         ORDER BY o.id ASC'
    );
    $statement->execute(['user_id' => $userId]);
    $orders = $statement->fetchAll();

    $statement = $pdo->prepare(
        'SELECT
            o.external_reference AS order_reference,
            p.provider,
            p.provider_merchant_order_id,
            p.provider_payment_id,
            p.payment_method_id,
            p.payment_method_type,
            p.status,
            p.status_detail,
            p.amount,
            p.currency,
            p.approved_at,
            p.created_at,
            p.updated_at
         FROM payments p
         INNER JOIN orders o ON o.id = p.order_id
         WHERE o.user_id = :user_id
         ORDER BY p.id ASC'
    );
    $statement->execute(['user_id' => $userId]);
    $payments = $statement->fetchAll();

    $statement = $pdo->prepare(
        'SELECT
            c.slug AS course_slug,
            c.title AS course_title,
            o.external_reference AS order_reference,
            ca.status,
            ca.granted_at,
            ca.revoked_at,
            ca.created_at,
            ca.updated_at
         FROM course_access ca
         INNER JOIN courses c ON c.id = ca.course_id
         INNER JOIN orders o ON o.id = ca.order_id
         WHERE ca.user_id = :user_id
         ORDER BY ca.id ASC'
    );
    $statement->execute(['user_id' => $userId]);
    $courseAccess = $statement->fetchAll();

    $statement = $pdo->prepare(
        'SELECT status, requested_at, processed_at
         FROM account_deletion_requests
         WHERE user_id = :user_id
         ORDER BY id ASC'
    );
    $statement->execute(['user_id' => $userId]);
    $deletionRequests = $statement->fetchAll();
} catch (Throwable $error) {
    error_log('EVA LGPD: erro ao exportar dados: ' . $error->getMessage());
    sendJsonResponse(500, ['success' => false, 'message' => 'Não foi possível exportar seus dados.']);
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
            'moodle_user_id' => $user['moodle_user_id'],
            'status' => $user['status'],
            'created_at' => $user['created_at'],
            'updated_at' => $user['updated_at'],
        ],
        'orders' => $orders,
        'payments' => $payments,
        'course_access' => $courseAccess,
        'account_deletion_requests' => $deletionRequests,
    ],
]);
