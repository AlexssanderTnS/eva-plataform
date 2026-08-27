<?php

declare(strict_types=1);

require __DIR__ . '/../../config/security.php';
require __DIR__ . '/../../config/mercadopago-commerce.php';

evaApplyApiSecurityHeaders();

function statusJsonResponse(int $status, array $payload): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');

    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_THROW_ON_ERROR
    );

    exit;
}

if (
    strtoupper(trim((string) ($_SERVER['REQUEST_METHOD'] ?? ''))) !== 'POST'
) {
    header('Allow: POST');
    statusJsonResponse(405, [
        'success' => false,
        'message' => 'Método não permitido.',
    ]);
}

evaEnforceTrustedOrigin();
evaEnforceJsonRequest(4096);

try {
    require __DIR__ . '/../../config/session.php';
} catch (Throwable $error) {
    error_log('EVA Orders Status: falha ao iniciar sessão: ' . $error->getMessage());
    statusJsonResponse(500, [
        'success' => false,
        'message' => 'Não foi possível consultar o pedido.',
    ]);
}

$userId = $_SESSION['user_id'] ?? null;

if (!is_int($userId) && !ctype_digit((string) $userId)) {
    statusJsonResponse(401, [
        'success' => false,
        'message' => 'Não autenticado.',
    ]);
}

$userId = (int) $userId;
evaAssertRateLimit('order-status-user', (string) $userId, 30, 300);

try {
    $rawInput = file_get_contents('php://input');
    $input = json_decode(
        $rawInput !== false ? $rawInput : '',
        true,
        512,
        JSON_THROW_ON_ERROR
    );
} catch (JsonException) {
    statusJsonResponse(400, [
        'success' => false,
        'message' => 'JSON inválido.',
    ]);
}

if (!is_array($input)) {
    statusJsonResponse(400, [
        'success' => false,
        'message' => 'Dados inválidos.',
    ]);
}

$reference = trim((string) ($input['reference'] ?? ''));
$paymentId = trim((string) ($input['payment_id'] ?? ''));

if (
    $reference === '' ||
    strlen($reference) > 64 ||
    !preg_match('/^eva_[a-f0-9]{32}$/', $reference)
) {
    statusJsonResponse(422, [
        'success' => false,
        'message' => 'Referência de pedido inválida.',
    ]);
}

if (
    $paymentId !== '' &&
    (strlen($paymentId) > 100 || !preg_match('/^[A-Za-z0-9_-]+$/', $paymentId))
) {
    statusJsonResponse(422, [
        'success' => false,
        'message' => 'Identificador de pagamento inválido.',
    ]);
}

evaRecordRateLimitHit('order-status-user', (string) $userId, 300);

try {
    $pdo = require __DIR__ . '/../../config/database.php';

    $statement = $pdo->prepare(
        "
        SELECT id
        FROM orders
        WHERE external_reference = :reference AND user_id = :user_id
        LIMIT 1
        "
    );
    $statement->execute([
        'reference' => $reference,
        'user_id' => $userId,
    ]);

    if ($statement->fetch() === false) {
        statusJsonResponse(404, [
            'success' => false,
            'message' => 'Pedido não encontrado.',
        ]);
    }

    if ($paymentId !== '') {
        try {
            $mpClient = require __DIR__ . '/../../config/mercadopago-client.php';
            evaSyncMercadoPagoPayment(
                $pdo,
                $mpClient,
                $paymentId,
                $userId,
                $reference
            );
        } catch (Throwable $syncError) {
            error_log(
                'EVA Orders Status: sincronização de retorno falhou: ' .
                $syncError->getMessage()
            );
        }
    }

    $statement = $pdo->prepare(
        "
        SELECT
            o.external_reference,
            o.amount,
            o.currency,
            o.status,
            o.paid_at,
            c.slug AS course_slug,
            c.title AS course_title,
            ca.status AS access_status,
            p.provider_payment_id,
            p.status AS payment_status,
            p.status_detail AS payment_status_detail
        FROM orders o
        INNER JOIN courses c ON c.id = o.course_id
        LEFT JOIN course_access ca
            ON ca.user_id = o.user_id
            AND ca.course_id = o.course_id
        LEFT JOIN payments p
            ON p.id = (
                SELECT p2.id
                FROM payments p2
                WHERE p2.order_id = o.id
                ORDER BY
                    CASE
                        WHEN o.status = 'paid' AND p2.status = 'approved' THEN 0
                        WHEN o.status = 'refunded' AND p2.status IN ('refunded', 'charged_back') THEN 0
                        WHEN o.status = 'failed' AND p2.status = 'rejected' THEN 0
                        ELSE 1
                    END,
                    p2.updated_at DESC,
                    p2.id DESC
                LIMIT 1
            )
        WHERE
            o.external_reference = :reference
            AND o.user_id = :user_id
        LIMIT 1
        "
    );
    $statement->execute([
        'reference' => $reference,
        'user_id' => $userId,
    ]);
    $order = $statement->fetch();

    if ($order === false) {
        statusJsonResponse(404, [
            'success' => false,
            'message' => 'Pedido não encontrado.',
        ]);
    }

    statusJsonResponse(200, [
        'success' => true,
        'order' => [
            'reference' => (string) $order['external_reference'],
            'status' => (string) $order['status'],
            'amount' => (string) $order['amount'],
            'currency' => (string) $order['currency'],
            'paid_at' => $order['paid_at'],
            'course' => [
                'slug' => (string) $order['course_slug'],
                'title' => (string) $order['course_title'],
            ],
            'access_status' => $order['access_status'],
            'payment' => $order['provider_payment_id'] !== null
                ? [
                    'id' => (string) $order['provider_payment_id'],
                    'status' => (string) $order['payment_status'],
                    'status_detail' => $order['payment_status_detail'],
                ]
                : null,
        ],
    ]);
} catch (Throwable $error) {
    error_log('EVA Orders Status: falha: ' . $error->getMessage());
    statusJsonResponse(500, [
        'success' => false,
        'message' => 'Não foi possível consultar o pedido.',
    ]);
}
