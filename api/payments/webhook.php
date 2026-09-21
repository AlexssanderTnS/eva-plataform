<?php

declare(strict_types=1);

require __DIR__ . '/../../config/security.php';
require __DIR__ . '/../../config/mercadopago-commerce.php';

evaApplyApiSecurityHeaders();

function webhookResponse(int $status, array $payload = []): never
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
    webhookResponse(405, [
        'success' => false,
        'message' => 'Método não permitido.',
    ]);
}

$contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);

if ($contentLength > 65536) {
    webhookResponse(413, [
        'success' => false,
        'message' => 'Payload muito grande.',
    ]);
}

$clientIp = evaClientIp();
$rateLimitWindow = 60;

evaAssertRateLimit('mercadopago-webhook-ip', $clientIp, 300, $rateLimitWindow);
evaRecordRateLimitHit('mercadopago-webhook-ip', $clientIp, $rateLimitWindow);

try {
    $mpConfig = require __DIR__ . '/../../config/mercadopago.php';
} catch (Throwable $error) {
    error_log(
        'EVA Mercado Pago Webhook: configuração indisponível: ' .
        $error->getMessage()
    );
    webhookResponse(503, ['success' => false]);
}

$webhookSecret = trim((string) ($mpConfig['webhook_secret'] ?? ''));

if ($webhookSecret === '') {
    error_log('EVA Mercado Pago Webhook: webhook_secret não configurado.');
    webhookResponse(503, ['success' => false]);
}

$rawBody = file_get_contents('php://input');

try {
    $payload = json_decode(
        $rawBody !== false ? $rawBody : '',
        true,
        512,
        JSON_THROW_ON_ERROR
    );
} catch (JsonException) {
    webhookResponse(400, [
        'success' => false,
        'message' => 'JSON inválido.',
    ]);
}

if (!is_array($payload)) {
    webhookResponse(400, ['success' => false]);
}

$queryDataId = trim((string) (
    $_GET['data_id'] ??
    $_GET['data.id'] ??
    ''
));
$bodyDataId = trim((string) ($payload['data']['id'] ?? ''));
$dataId = $queryDataId !== '' ? $queryDataId : $bodyDataId;

if (
    $queryDataId !== '' &&
    $bodyDataId !== '' &&
    !hash_equals($queryDataId, $bodyDataId)
) {
    webhookResponse(400, ['success' => false]);
}

$xSignature = $_SERVER['HTTP_X_SIGNATURE'] ?? null;
$xRequestId = $_SERVER['HTTP_X_REQUEST_ID'] ?? null;

if (!evaValidateMercadoPagoWebhookSignature(
    is_string($xSignature) ? $xSignature : null,
    is_string($xRequestId) ? $xRequestId : null,
    $dataId,
    $webhookSecret
)) {
    error_log(
        'EVA Mercado Pago Webhook: assinatura inválida. request_id=' .
        substr((string) $xRequestId, 0, 100)
    );
    webhookResponse(401, ['success' => false]);
}

$type = strtolower(trim((string) ($payload['type'] ?? $_GET['type'] ?? '')));
$action = strtolower(trim((string) ($payload['action'] ?? '')));

if ($type !== 'payment') {
    webhookResponse(200, [
        'success' => true,
        'ignored' => true,
    ]);
}

if (
    $dataId === '' ||
    strlen($dataId) > 100 ||
    !preg_match('/^[A-Za-z0-9_-]+$/', $dataId)
) {
    webhookResponse(400, ['success' => false]);
}

$notificationId = trim((string) ($payload['id'] ?? ''));
$eventKeySeed = implode('|', [
    'payment',
    $notificationId,
    $action,
    $dataId,
]);
$eventKey = 'payment:' . hash('sha256', $eventKeySeed);

try {
    $pdo = require __DIR__ . '/../../config/database.php';

    $statement = $pdo->prepare(
        "
        INSERT INTO payment_webhook_events (
            provider,
            event_key,
            topic,
            resource_id,
            status,
            available_at
        )
        VALUES (
            'mercado_pago',
            :event_key,
            :topic,
            :resource_id,
            'received',
            CURRENT_TIMESTAMP
        )
        ON DUPLICATE KEY UPDATE
            topic = VALUES(topic),
            resource_id = VALUES(resource_id),
            available_at = CASE
                WHEN status = 'failed'
                THEN CURRENT_TIMESTAMP
                ELSE available_at
            END,
            last_error = CASE
                WHEN status = 'failed'
                THEN NULL
                ELSE last_error
            END
        "
    );

    $statement->execute([
        'event_key' => $eventKey,
        'topic' => $action !== '' ? $action : 'payment',
        'resource_id' => $dataId,
    ]);

    webhookResponse(200, [
        'success' => true,
        'queued' => true,
    ]);
} catch (Throwable $error) {
    error_log(
        'EVA Mercado Pago Webhook: falha ao registrar evento: ' .
        $error->getMessage()
    );

    webhookResponse(500, ['success' => false]);
}
