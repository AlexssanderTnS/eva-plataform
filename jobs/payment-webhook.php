<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$pdo = require __DIR__ . '/../config/database.php';
$mpClient = require __DIR__ . '/../config/mercadopago-client.php';
require_once __DIR__ . '/../config/mercadopago-commerce.php';

const EVA_PAYMENT_WEBHOOK_JOB_LIMIT = 20;
const EVA_PAYMENT_WEBHOOK_JOB_MAX_ATTEMPTS = 8;

function evaRecoverStalePaymentWebhookJobs(PDO $pdo): void
{
    $pdo->exec(
        "
        UPDATE payment_webhook_events
        SET
            status = 'failed',
            available_at = CURRENT_TIMESTAMP,
            started_at = NULL,
            last_error = 'Processamento anterior interrompido.',
            updated_at = CURRENT_TIMESTAMP
        WHERE
            status = 'processing'
            AND updated_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 15 MINUTE)
        "
    );
}

function evaClaimPaymentWebhookJob(PDO $pdo): ?array
{
    $pdo->beginTransaction();

    try {
        $statement = $pdo->query(
            "
            SELECT *
            FROM payment_webhook_events
            WHERE
                status IN ('received', 'failed')
                AND attempts < " . EVA_PAYMENT_WEBHOOK_JOB_MAX_ATTEMPTS . "
                AND available_at <= CURRENT_TIMESTAMP
            ORDER BY available_at ASC, id ASC
            LIMIT 1
            FOR UPDATE
            "
        );

        $job = $statement->fetch();

        if ($job === false) {
            $pdo->commit();
            return null;
        }

        $statement = $pdo->prepare(
            "
            UPDATE payment_webhook_events
            SET
                status = 'processing',
                attempts = attempts + 1,
                started_at = CURRENT_TIMESTAMP,
                last_error = NULL,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
            "
        );
        $statement->execute(['id' => (int) $job['id']]);

        $pdo->commit();

        $job['attempts'] = (int) $job['attempts'] + 1;
        $job['status'] = 'processing';

        return $job;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $error;
    }
}

function evaFinishPaymentWebhookJob(
    PDO $pdo,
    int $jobId,
    string $status,
    ?string $errorMessage = null,
    ?string $availableAt = null
): void {
    if (!in_array($status, ['processed', 'failed', 'ignored'], true)) {
        throw new InvalidArgumentException(
            'Status final de webhook inválido.'
        );
    }

    $statement = $pdo->prepare(
        "
        UPDATE payment_webhook_events
        SET
            status = :status,
            processed_at = CASE
                WHEN :is_final = 1
                THEN CURRENT_TIMESTAMP
                ELSE processed_at
            END,
            available_at = COALESCE(:available_at, available_at),
            last_error = :last_error,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = :id
        "
    );

    $statement->execute([
        'status' => $status,
        'is_final' => in_array($status, ['processed', 'ignored'], true) ? 1 : 0,
        'available_at' => $availableAt,
        'last_error' => $errorMessage,
        'id' => $jobId,
    ]);
}

evaRecoverStalePaymentWebhookJobs($pdo);

$processed = 0;
$failed = 0;

for ($index = 0; $index < EVA_PAYMENT_WEBHOOK_JOB_LIMIT; $index++) {
    $job = evaClaimPaymentWebhookJob($pdo);

    if ($job === null) {
        break;
    }

    $jobId = (int) $job['id'];
    $resourceId = trim((string) ($job['resource_id'] ?? ''));

    try {
        if ($resourceId === '') {
            evaFinishPaymentWebhookJob($pdo, $jobId, 'ignored');
            $processed++;
            continue;
        }

        evaSyncMercadoPagoPayment($pdo, $mpClient, $resourceId);

        evaFinishPaymentWebhookJob($pdo, $jobId, 'processed');
        $processed++;
    } catch (Throwable $error) {
        $failed++;

        $attempt = max(1, (int) ($job['attempts'] ?? 1));
        $retryMinutes = min(60, 2 ** min($attempt, 6));
        $availableAt = (
            new DateTimeImmutable('+' . $retryMinutes . ' minutes')
        )->format('Y-m-d H:i:s');

        $safeMessage = substr(trim($error->getMessage()), 0, 1000);

        evaFinishPaymentWebhookJob(
            $pdo,
            $jobId,
            'failed',
            $safeMessage !== '' ? $safeMessage : 'Falha não especificada.',
            $availableAt
        );

        error_log(
            sprintf(
                'EVA payment webhook job %d falhou: %s',
                $jobId,
                $safeMessage
            )
        );
    }
}

fwrite(
    STDOUT,
    sprintf(
        "Payment webhooks: %d processado(s), %d falha(s).\n",
        $processed,
        $failed
    )
);

exit($failed > 0 ? 1 : 0);
