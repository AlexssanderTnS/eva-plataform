<?php

declare(strict_types=1);

require __DIR__ . '/../../config/security.php';

evaApplyApiSecurityHeaders();
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

try {
    require __DIR__ . '/../../config/session.php';
} catch (Throwable $error) {
    error_log('EVA Account: falha ao iniciar sessão: ' . $error->getMessage());
    evaSecurityJsonResponse(500, 'Não foi possível carregar seus cursos.');
}

function sendJsonResponse(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendJsonResponse(405, [
        'success' => false,
        'message' => 'Método não permitido.',
    ]);
}

$userId = $_SESSION['user_id'] ?? null;

if (!is_int($userId) && !ctype_digit((string) $userId)) {
    sendJsonResponse(401, [
        'success' => false,
        'message' => 'Não autenticado.',
    ]);
}

$userId = (int) $userId;
$rateLimitWindow = 300;

evaAssertRateLimit('account-courses-user', (string) $userId, 120, $rateLimitWindow);
evaRecordRateLimitHit('account-courses-user', (string) $userId, $rateLimitWindow);

try {
    $pdo = require __DIR__ . '/../../config/database.php';

    $statement = $pdo->prepare(
        "
        SELECT
            u.status AS user_status,
            u.email_verified_at,
            ca.id AS access_id,
            ca.status AS access_status,
            ca.granted_at,
            ca.revoked_at,
            c.slug,
            c.title,
            c.moodle_course_id,
            o.status AS order_status,
            o.paid_at
        FROM users u
        LEFT JOIN course_access ca
            ON ca.user_id = u.id
        LEFT JOIN courses c
            ON c.id = ca.course_id
        LEFT JOIN orders o
            ON o.id = ca.order_id
        WHERE u.id = :user_id
        ORDER BY
            CASE ca.status
                WHEN 'active' THEN 1
                WHEN 'pending' THEN 2
                WHEN 'revoked' THEN 3
                ELSE 4
            END,
            COALESCE(ca.granted_at, ca.created_at) DESC
        "
    );
    $statement->execute(['user_id' => $userId]);
    $rows = $statement->fetchAll();
} catch (Throwable $error) {
    error_log('EVA Account: erro ao carregar cursos: ' . $error->getMessage());
    sendJsonResponse(500, [
        'success' => false,
        'message' => 'Não foi possível carregar seus cursos.',
    ]);
}

if ($rows === []) {
    evaDestroySession();
    sendJsonResponse(401, [
        'success' => false,
        'message' => 'Não autenticado.',
    ]);
}

$firstRow = $rows[0];

if (($firstRow['user_status'] ?? null) !== 'active') {
    evaDestroySession();
    sendJsonResponse(403, [
        'success' => false,
        'message' => 'Esta conta não está disponível para acesso.',
    ]);
}

if (($firstRow['email_verified_at'] ?? null) === null) {
    evaDestroySession();
    sendJsonResponse(403, [
        'success' => false,
        'message' => 'Confirme seu e-mail antes de acessar sua conta.',
    ]);
}

$courses = [];

foreach ($rows as $row) {
    if (($row['access_id'] ?? null) === null || ($row['slug'] ?? null) === null) {
        continue;
    }

    $status = (string) $row['access_status'];

    $courses[] = [
        'slug' => (string) $row['slug'],
        'title' => (string) $row['title'],
        'status' => $status,
        'granted_at' => $row['granted_at'],
        'revoked_at' => $row['revoked_at'],
        'paid_at' => $row['paid_at'],
        'access_ready' => $status === 'active' && $row['moodle_course_id'] !== null,
    ];
}

sendJsonResponse(200, [
    'success' => true,
    'courses' => $courses,
]);
