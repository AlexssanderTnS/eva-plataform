<?php

declare(strict_types=1);

require __DIR__ . '/../../config/security.php';
require __DIR__ . '/../../config/mercadopago-commerce.php';

evaApplyApiSecurityHeaders();

function sendJsonResponse(int $status, array $payload): never
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
    sendJsonResponse(405, [
        'success' => false,
        'message' => 'Método não permitido.',
    ]);
}

evaEnforceTrustedOrigin();
evaEnforceJsonRequest(2048);

try {
    require __DIR__ . '/../../config/session.php';
} catch (Throwable $error) {
    error_log('EVA Orders: falha ao iniciar sessão: ' . $error->getMessage());
    sendJsonResponse(500, [
        'success' => false,
        'message' => 'Não foi possível iniciar o pedido.',
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
$rateLimitWindow = 600;

evaAssertRateLimit('order-create-user', (string) $userId, 10, $rateLimitWindow);
evaAssertRateLimit('order-create-ip', evaClientIp(), 30, $rateLimitWindow);

try {
    $rawInput = file_get_contents('php://input');
    $input = json_decode(
        $rawInput !== false ? $rawInput : '',
        true,
        512,
        JSON_THROW_ON_ERROR
    );
} catch (JsonException) {
    sendJsonResponse(400, [
        'success' => false,
        'message' => 'JSON inválido.',
    ]);
}

if (!is_array($input)) {
    sendJsonResponse(400, [
        'success' => false,
        'message' => 'Dados inválidos.',
    ]);
}

$courseSlug = trim((string) ($input['course'] ?? ''));

if (
    $courseSlug === '' ||
    strlen($courseSlug) > 120 ||
    !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $courseSlug)
) {
    sendJsonResponse(422, [
        'success' => false,
        'message' => 'Curso informado é inválido.',
    ]);
}

evaRecordRateLimitHit('order-create-user', (string) $userId, $rateLimitWindow);
evaRecordRateLimitHit('order-create-ip', evaClientIp(), $rateLimitWindow);

$pdo = null;
$order = null;
$course = null;
$user = null;
$reusedOrder = false;

try {
    $pdo = require __DIR__ . '/../../config/database.php';
    $pdo->beginTransaction();

    $statement = $pdo->prepare(
        "
        SELECT
            first_name,
            last_name,
            email,
            status,
            email_verified_at
        FROM users
        WHERE id = :id
        LIMIT 1
        "
    );
    $statement->execute(['id' => $userId]);
    $user = $statement->fetch();

    if ($user === false) {
        $pdo->rollBack();
        evaDestroySession();
        sendJsonResponse(401, [
            'success' => false,
            'message' => 'Não autenticado.',
        ]);
    }

    if ($user['status'] !== 'active' || $user['email_verified_at'] === null) {
        $pdo->rollBack();
        sendJsonResponse(403, [
            'success' => false,
            'message' => 'Esta conta não está disponível para compras.',
        ]);
    }

    $statement = $pdo->prepare(
        "
        SELECT
            id,
            slug,
            title,
            price,
            currency,
            status
        FROM courses
        WHERE slug = :slug
        LIMIT 1
        "
    );
    $statement->execute(['slug' => $courseSlug]);
    $course = $statement->fetch();

    if ($course === false || $course['status'] !== 'active') {
        $pdo->rollBack();
        sendJsonResponse(404, [
            'success' => false,
            'message' => 'Curso não encontrado ou indisponível.',
        ]);
    }

    $courseId = (int) $course['id'];
    $amount = (string) $course['price'];
    $currency = strtoupper(trim((string) $course['currency']));

    if (
        !preg_match('/^\d{1,8}\.\d{2}$/', $amount) ||
        $amount === '0.00' ||
        $currency !== 'BRL'
    ) {
        throw new RuntimeException('Configuração comercial inválida para o curso.');
    }

    $statement = $pdo->prepare(
        "
        SELECT status
        FROM course_access
        WHERE user_id = :user_id AND course_id = :course_id
        LIMIT 1
        FOR UPDATE
        "
    );
    $statement->execute([
        'user_id' => $userId,
        'course_id' => $courseId,
    ]);
    $existingAccess = $statement->fetch();

    if (
        $existingAccess !== false &&
        in_array($existingAccess['status'], ['pending', 'active'], true)
    ) {
        $pdo->rollBack();
        sendJsonResponse(409, [
            'success' => false,
            'message' => 'Você já possui este curso ou ele está em processo de liberação.',
        ]);
    }

    $statement = $pdo->prepare(
        "
        SELECT
            id,
            external_reference,
            provider_preference_id,
            amount,
            currency,
            status,
            created_at
        FROM orders
        WHERE
            user_id = :user_id
            AND course_id = :course_id
            AND status IN ('created', 'pending')
            AND created_at >= (CURRENT_TIMESTAMP - INTERVAL 30 MINUTE)
        ORDER BY id DESC
        LIMIT 1
        FOR UPDATE
        "
    );
    $statement->execute([
        'user_id' => $userId,
        'course_id' => $courseId,
    ]);
    $order = $statement->fetch();

    if ($order === false) {
        $externalReference = 'eva_' . bin2hex(random_bytes(16));

        $statement = $pdo->prepare(
            "
            INSERT INTO orders (
                user_id,
                course_id,
                external_reference,
                amount,
                currency,
                status
            )
            VALUES (
                :user_id,
                :course_id,
                :external_reference,
                :amount,
                :currency,
                'created'
            )
            "
        );
        $statement->execute([
            'user_id' => $userId,
            'course_id' => $courseId,
            'external_reference' => $externalReference,
            'amount' => $amount,
            'currency' => $currency,
        ]);

        $order = [
            'id' => (int) $pdo->lastInsertId(),
            'external_reference' => $externalReference,
            'provider_preference_id' => null,
            'amount' => $amount,
            'currency' => $currency,
            'status' => 'created',
        ];
    } else {
        $reusedOrder = true;
    }

    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('EVA Orders: falha ao preparar pedido: ' . $error->getMessage());
    sendJsonResponse(500, [
        'success' => false,
        'message' => 'Não foi possível criar o pedido.',
    ]);
}

try {
    $mpConfig = require __DIR__ . '/../../config/mercadopago.php';
    $mpClient = require __DIR__ . '/../../config/mercadopago-client.php';

    $preference = null;
    $preferenceId = trim((string) ($order['provider_preference_id'] ?? ''));

    if ($preferenceId !== '') {
        $existingPreferenceResponse = $mpClient->request(
            'GET',
            '/checkout/preferences/' . rawurlencode($preferenceId)
        );

        if (
            (int) ($existingPreferenceResponse['status'] ?? 0) === 200 &&
            is_array($existingPreferenceResponse['data'] ?? null)
        ) {
            $candidate = $existingPreferenceResponse['data'];
            $candidateReference = trim(
                (string) ($candidate['external_reference'] ?? '')
            );
            $expired = (bool) ($candidate['preference_expired'] ?? false);
            $candidateUrl = evaMercadoPagoCheckoutUrl(
                $candidate,
                $mpConfig['environment']
            );

            if (
                !$expired &&
                $candidateUrl !== null &&
                hash_equals(
                    (string) $order['external_reference'],
                    $candidateReference
                )
            ) {
                $preference = $candidate;
            }
        }
    }

    if ($preference === null) {
        $now = new DateTimeImmutable('now');
        $expiresAt = $now->modify('+30 minutes');
        $baseUrl = rtrim($mpConfig['base_url'], '/');
        $reference = (string) $order['external_reference'];

        $preferencePayload = [
            'items' => [[
                'id' => (string) $course['slug'],
                'title' => (string) $course['title'],
                'quantity' => 1,
                'currency_id' => 'BRL',
                'unit_price' => (float) $order['amount'],
            ]],
            'payer' => [
                'name' => (string) $user['first_name'],
                'surname' => (string) $user['last_name'],
                'email' => (string) $user['email'],
            ],
            'external_reference' => $reference,
            'back_urls' => [
                'success' => $baseUrl . '/pagamento.html?result=success',
                'pending' => $baseUrl . '/pagamento.html?result=pending',
                'failure' => $baseUrl . '/pagamento.html?result=failure',
            ],
            'notification_url' => $baseUrl . '/api/payments/webhook.php',
            'auto_return' => 'approved',
            'expires' => true,
            'expiration_date_from' => $now->format(DATE_ATOM),
            'expiration_date_to' => $expiresAt->format(DATE_ATOM),
            'metadata' => [
                'eva_order_reference' => $reference,
                'eva_course_slug' => (string) $course['slug'],
            ],
        ];

        $idempotencyKey = 'pref_' . substr(
            hash('sha256', $reference),
            0,
            59
        );

        $preferenceResponse = $mpClient->request(
            'POST',
            '/checkout/preferences',
            $preferencePayload,
            $idempotencyKey
        );

        if (
            !in_array((int) ($preferenceResponse['status'] ?? 0), [200, 201], true) ||
            !is_array($preferenceResponse['data'] ?? null)
        ) {
            throw new RuntimeException(
                'Mercado Pago não criou a preferência de pagamento.'
            );
        }

        $preference = $preferenceResponse['data'];
        $preferenceId = trim((string) ($preference['id'] ?? ''));

        if ($preferenceId === '') {
            throw new RuntimeException(
                'Mercado Pago retornou uma preferência sem identificador.'
            );
        }

        $statement = $pdo->prepare(
            "
            UPDATE orders
            SET provider_preference_id = :preference_id
            WHERE id = :id
            "
        );
        $statement->execute([
            'preference_id' => $preferenceId,
            'id' => (int) $order['id'],
        ]);
    }

    $checkoutUrl = evaMercadoPagoCheckoutUrl(
        $preference,
        $mpConfig['environment']
    );

    if ($checkoutUrl === null) {
        throw new RuntimeException(
            'Mercado Pago não retornou uma URL de checkout válida.'
        );
    }

    sendJsonResponse($reusedOrder ? 200 : 201, [
        'success' => true,
        'reused' => $reusedOrder,
        'message' => 'Checkout preparado com sucesso.',
        'checkout_url' => $checkoutUrl,
        'order' => [
            'reference' => (string) $order['external_reference'],
            'status' => (string) $order['status'],
            'amount' => (string) $order['amount'],
            'currency' => (string) $order['currency'],
            'course' => [
                'slug' => (string) $course['slug'],
                'title' => (string) $course['title'],
            ],
        ],
    ]);
} catch (Throwable $error) {
    error_log('EVA Checkout Pro: falha ao criar preferência: ' . $error->getMessage());

    sendJsonResponse(502, [
        'success' => false,
        'message' => 'Não foi possível abrir o checkout do Mercado Pago. Tente novamente.',
    ]);
}
