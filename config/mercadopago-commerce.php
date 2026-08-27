<?php

declare(strict_types=1);

function evaMercadoPagoCheckoutUrl(
    array $preference,
    string $environment
): ?string {
    $key = $environment === 'test'
        ? 'sandbox_init_point'
        : 'init_point';

    $value = trim((string) ($preference[$key] ?? ''));

    if (
        $value === '' ||
        filter_var($value, FILTER_VALIDATE_URL) === false ||
        strtolower((string) parse_url($value, PHP_URL_SCHEME)) !== 'https'
    ) {
        return null;
    }

    return $value;
}

function evaValidateMercadoPagoWebhookSignature(
    ?string $xSignature,
    ?string $xRequestId,
    ?string $dataId,
    string $secret
): bool {
    $xSignature = trim((string) $xSignature);
    $xRequestId = trim((string) $xRequestId);
    $dataId = trim((string) $dataId);
    $secret = trim($secret);

    if ($xSignature === '' || $secret === '') {
        return false;
    }

    $timestamp = null;
    $receivedHash = null;

    foreach (explode(',', $xSignature) as $part) {
        $pieces = explode('=', $part, 2);

        if (count($pieces) !== 2) {
            continue;
        }

        $key = strtolower(trim($pieces[0]));
        $value = trim($pieces[1]);

        if ($key === 'ts') {
            $timestamp = $value;
        } elseif ($key === 'v1') {
            $receivedHash = $value;
        }
    }

    if (
        $timestamp === null ||
        $receivedHash === null ||
        !ctype_digit($timestamp)
    ) {
        return false;
    }

    $manifestParts = [];

    if ($dataId !== '') {
        $manifestParts[] = 'id:' . $dataId;
    }

    if ($xRequestId !== '') {
        $manifestParts[] = 'request-id:' . $xRequestId;
    }

    $manifestParts[] = 'ts:' . $timestamp;
    $manifest = implode(';', $manifestParts) . ';';

    $computedHash = hash_hmac(
        'sha256',
        $manifest,
        $secret
    );

    return hash_equals($computedHash, $receivedHash);
}

function evaMercadoPagoOrderStatus(string $paymentStatus): string
{
    return match ($paymentStatus) {
        'approved' => 'paid',
        'refunded', 'charged_back' => 'refunded',
        'cancelled' => 'cancelled',
        'rejected' => 'failed',
        default => 'pending',
    };
}

function evaNormalizeMoneyToCents(mixed $value): ?int
{
    if (!is_numeric($value)) {
        return null;
    }

    $floatValue = (float) $value;

    if (!is_finite($floatValue) || $floatValue < 0) {
        return null;
    }

    return (int) round($floatValue * 100);
}

function evaSyncMercadoPagoPayment(
    PDO $pdo,
    object $client,
    string $paymentId,
    ?int $expectedUserId = null,
    ?string $expectedReference = null
): array {
    $paymentId = trim($paymentId);

    if (
        $paymentId === '' ||
        strlen($paymentId) > 100 ||
        !preg_match('/^[A-Za-z0-9_-]+$/', $paymentId)
    ) {
        throw new InvalidArgumentException(
            'Identificador de pagamento inválido.'
        );
    }

    $response = $client->request(
        'GET',
        '/v1/payments/' . rawurlencode($paymentId)
    );

    if (
        (int) ($response['status'] ?? 0) !== 200 ||
        !is_array($response['data'] ?? null)
    ) {
        throw new RuntimeException(
            'Não foi possível consultar o pagamento no Mercado Pago.'
        );
    }

    $payment = $response['data'];
    $providerPaymentId = trim((string) ($payment['id'] ?? ''));
    $externalReference = trim(
        (string) ($payment['external_reference'] ?? '')
    );
    $status = strtolower(trim((string) ($payment['status'] ?? '')));
    $statusDetail = trim((string) ($payment['status_detail'] ?? ''));
    $currency = strtoupper(trim((string) ($payment['currency_id'] ?? '')));
    $amountCents = evaNormalizeMoneyToCents(
        $payment['transaction_amount'] ?? null
    );

    if (
        $providerPaymentId === '' ||
        $externalReference === '' ||
        $status === '' ||
        $currency !== 'BRL' ||
        $amountCents === null
    ) {
        throw new RuntimeException(
            'Pagamento retornado pelo Mercado Pago possui dados incompletos.'
        );
    }

    if (!hash_equals($paymentId, $providerPaymentId)) {
        throw new RuntimeException(
            'Pagamento retornado não corresponde ao identificador consultado.'
        );
    }

    if (
        $expectedReference !== null &&
        !hash_equals($expectedReference, $externalReference)
    ) {
        throw new RuntimeException(
            'Pagamento não corresponde ao pedido informado.'
        );
    }

    $paymentMethodId = trim(
        (string) ($payment['payment_method_id'] ?? '')
    );
    $paymentMethodType = trim(
        (string) ($payment['payment_type_id'] ?? '')
    );
    $merchantOrderId = trim(
        (string) ($payment['order']['id'] ?? '')
    );
    $approvedAt = null;

    if (!empty($payment['date_approved'])) {
        try {
            $approvedAt = (new DateTimeImmutable(
                (string) $payment['date_approved']
            ))
                ->setTimezone(new DateTimeZone('America/Sao_Paulo'))
                ->format('Y-m-d H:i:s');
        } catch (Throwable) {
            $approvedAt = null;
        }
    }

    $pdo->beginTransaction();

    try {
        $statement = $pdo->prepare(
            "
            SELECT
                id,
                user_id,
                course_id,
                amount,
                currency,
                status
            FROM orders
            WHERE external_reference = :external_reference
            LIMIT 1
            FOR UPDATE
            "
        );
        $statement->execute([
            'external_reference' => $externalReference,
        ]);
        $order = $statement->fetch();

        if ($order === false) {
            throw new RuntimeException(
                'Pedido associado ao pagamento não foi encontrado.'
            );
        }

        $orderUserId = (int) $order['user_id'];
        $orderId = (int) $order['id'];
        $courseId = (int) $order['course_id'];
        $orderAmountCents = evaNormalizeMoneyToCents($order['amount']);

        if (
            $expectedUserId !== null &&
            $orderUserId !== $expectedUserId
        ) {
            throw new RuntimeException(
                'Pedido não pertence ao usuário autenticado.'
            );
        }

        if (
            strtoupper((string) $order['currency']) !== $currency ||
            $orderAmountCents === null ||
            $orderAmountCents !== $amountCents
        ) {
            throw new RuntimeException(
                'Valor ou moeda do pagamento não corresponde ao pedido.'
            );
        }

        $statement = $pdo->prepare(
            "
            INSERT INTO payments (
                order_id,
                provider,
                provider_merchant_order_id,
                provider_payment_id,
                idempotency_key,
                payment_method_id,
                payment_method_type,
                status,
                status_detail,
                amount,
                currency,
                approved_at
            )
            VALUES (
                :order_id,
                'mercado_pago',
                :merchant_order_id,
                :payment_id,
                NULL,
                :payment_method_id,
                :payment_method_type,
                :status,
                :status_detail,
                :amount,
                :currency,
                :approved_at
            )
            ON DUPLICATE KEY UPDATE
                provider_merchant_order_id = VALUES(provider_merchant_order_id),
                payment_method_id = VALUES(payment_method_id),
                payment_method_type = VALUES(payment_method_type),
                status = VALUES(status),
                status_detail = VALUES(status_detail),
                amount = VALUES(amount),
                currency = VALUES(currency),
                approved_at = VALUES(approved_at),
                updated_at = CURRENT_TIMESTAMP
            "
        );
        $statement->execute([
            'order_id' => $orderId,
            'merchant_order_id' => $merchantOrderId !== ''
                ? $merchantOrderId
                : null,
            'payment_id' => $providerPaymentId,
            'payment_method_id' => $paymentMethodId !== ''
                ? $paymentMethodId
                : null,
            'payment_method_type' => $paymentMethodType !== ''
                ? $paymentMethodType
                : null,
            'status' => $status,
            'status_detail' => $statusDetail !== ''
                ? $statusDetail
                : null,
            'amount' => number_format($amountCents / 100, 2, '.', ''),
            'currency' => $currency,
            'approved_at' => $approvedAt,
        ]);

        $mappedOrderStatus = evaMercadoPagoOrderStatus($status);
        $currentOrderStatus = (string) $order['status'];

        if ($currentOrderStatus === 'refunded') {
            $mappedOrderStatus = 'refunded';
        } elseif (
            $currentOrderStatus === 'paid' &&
            $mappedOrderStatus !== 'refunded'
        ) {
            $mappedOrderStatus = 'paid';
        }

        $statement = $pdo->prepare(
            "
            UPDATE orders
            SET
                status = :status,
                paid_at = CASE
                    WHEN :is_paid = 1
                    THEN COALESCE(paid_at, :approved_at, CURRENT_TIMESTAMP)
                    ELSE paid_at
                END
            WHERE id = :id
            "
        );
        $statement->execute([
            'status' => $mappedOrderStatus,
            'is_paid' => $mappedOrderStatus === 'paid' ? 1 : 0,
            'approved_at' => $approvedAt,
            'id' => $orderId,
        ]);

        if ($mappedOrderStatus === 'paid') {
            $statement = $pdo->prepare(
                "
                INSERT INTO course_access (
                    user_id,
                    course_id,
                    order_id,
                    status
                )
                VALUES (
                    :user_id,
                    :course_id,
                    :order_id,
                    'pending'
                )
                ON DUPLICATE KEY UPDATE
                    order_id = VALUES(order_id),
                    status = CASE
                        WHEN course_access.status = 'active'
                        THEN 'active'
                        ELSE 'pending'
                    END,
                    revoked_at = NULL,
                    updated_at = CURRENT_TIMESTAMP
                "
            );
            $statement->execute([
                'user_id' => $orderUserId,
                'course_id' => $courseId,
                'order_id' => $orderId,
            ]);
        } elseif ($mappedOrderStatus === 'refunded') {
            $statement = $pdo->prepare(
                "
                UPDATE course_access
                SET
                    status = 'revoked',
                    revoked_at = CURRENT_TIMESTAMP
                WHERE
                    user_id = :user_id
                    AND course_id = :course_id
                    AND order_id = :order_id
                "
            );
            $statement->execute([
                'user_id' => $orderUserId,
                'course_id' => $courseId,
                'order_id' => $orderId,
            ]);
        }

        $pdo->commit();

        return [
            'payment_id' => $providerPaymentId,
            'payment_status' => $status,
            'payment_status_detail' => $statusDetail,
            'order_reference' => $externalReference,
            'order_status' => $mappedOrderStatus,
            'user_id' => $orderUserId,
            'course_id' => $courseId,
        ];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $error;
    }
}
