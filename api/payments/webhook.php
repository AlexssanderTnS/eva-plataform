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

try {
    $mpConfig = require __DIR__ . '/../../config/mercadopago.php';
} catch (Throwable $error) {
    error_log('EVA Mercado Pago Webhook: configuração indisponível: ' . $error->getMessage());
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

if ($dataId === '' || strlen($dataId) > 100) {
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

$pdo = null;

try {
    $pdo = require __DIR__ . '/../../config/database.php';

    $statement = $pdo->prepare(
        "
        INSERT INTO payment_webhook_events (
            provider,
            event_key,
            topic,
            resource_id,
            status
        )
        VALUES (
            'mercado_pago',
            :event_key,
            :topic,
            :resource_id,
            'received'
        )
        ON DUPLICATE KEY UPDATE
            event_key = VALUES(event_key)
        "
    );
    $statement->execute([
        'event_key' => $eventKey,
        'topic' => $action !== '' ? $action : 'payment',
        'resource_id' => $dataId,
    ]);

    $statement = $pdo->prepare(
        "
        UPDATE payment_webhook_events
        SET
            status = 'processing',
            processed_at = CURRENT_TIMESTAMP
        WHERE
            provider = 'mercado_pago'
            AND event_key = :event_key
            AND (
                status IN ('received', 'failed')
                OR (
                    status = 'processing'
                    AND processed_at < (CURRENT_TIMESTAMP - INTERVAL 5 MINUTE)
                )
            )
        "
    );
    $statement->execute(['event_key' => $eventKey]);

    if ($statement->rowCount() !== 1) {
        $statement = $pdo->prepare(
            "
            SELECT status
            FROM payment_webhook_events
            WHERE provider = 'mercado_pago' AND event_key = :event_key
            LIMIT 1
            "
        );
        $statement->execute(['event_key' => $eventKey]);
        $event = $statement->fetch();
        $eventStatus = $event !== false ? (string) $event['status'] : '';

        if (in_array($eventStatus, ['processing', 'processed'], true)) {
            webhookResponse(200, [
                'success' => true,
                'duplicate' => true,
                'processing' => $eventStatus === 'processing',
            ]);
        }

        throw new RuntimeException(
            'Não foi possível adquirir o evento de webhook para processamento.'
        );
    }

    $mpClient = require __DIR__ . '/../../config/mercadopago-client.php';
    $syncResult = evaSyncMercadoPagoPayment(
        $pdo,
        $mpClient,
        $dataId
    );

    $statement = $pdo->prepare(
        "
        UPDATE payment_webhook_events
        SET
            status = 'processed',
            processed_at = CURRENT_TIMESTAMP
        WHERE provider = 'mercado_pago' AND event_key = :event_key
        "
    );
    $statement->execute(['event_key' => $eventKey]);

    webhookResponse(200, [
        'success' => true,
        'payment_status' => $syncResult['payment_status'],
    ]);
} catch (Throwable $error) {
    if ($pdo instanceof PDO) {
        try {
            $statement = $pdo->prepare(
                "
                UPDATE payment_webhook_events
                SET
                    status = 'failed',
                    processed_at = CURRENT_TIMESTAMP
                WHERE provider = 'mercado_pago' AND event_key = :event_key
                "
            );
            $statement->execute(['event_key' => $eventKey]);
        } catch (Throwable) {
        }
    }

    error_log(
        'EVA Mercado Pago Webhook: falha no processamento: ' .
        $error->getMessage()
    );

    webhookResponse(500, ['success' => false]);
}
