<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$pdo = require __DIR__ . '/../config/database.php';
$moodleClient = require __DIR__ . '/../config/moodle-client.php';
$moodleConfig = require __DIR__ . '/../config/moodle.php';
require_once __DIR__ . '/../config/moodle-service.php';

const EVA_MOODLE_JOB_LIMIT = 10;
const EVA_MOODLE_JOB_MAX_ATTEMPTS = 8;

function evaRecoverStaleMoodleJobs(PDO $pdo): void
{
    $pdo->exec(
        "
        UPDATE moodle_provisioning_jobs
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

function evaClaimMoodleJob(PDO $pdo): ?array
{
    $pdo->beginTransaction();

    try {
        $statement = $pdo->query(
            "
            SELECT *
            FROM moodle_provisioning_jobs
            WHERE
                status IN ('pending', 'failed')
                AND attempts < " . EVA_MOODLE_JOB_MAX_ATTEMPTS . "
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
            UPDATE moodle_provisioning_jobs
            SET
                status = 'processing',
                attempts = attempts + 1,
                started_at = CURRENT_TIMESTAMP,
                completed_at = NULL,
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

function evaLoadMoodleJobContext(PDO $pdo, int $jobId): array
{
    $statement = $pdo->prepare(
        "
        SELECT
            j.id AS job_id,
            j.order_id AS job_order_id,
            j.action,
            j.attempts,
            ca.id AS course_access_id,
            ca.order_id AS current_order_id,
            ca.status AS access_status,
            u.id AS user_id,
            u.first_name,
            u.last_name,
            u.email,
            u.moodle_user_id,
            c.id AS course_id,
            c.slug AS course_slug,
            c.title AS course_title,
            c.moodle_course_id
        FROM moodle_provisioning_jobs j
        INNER JOIN course_access ca
            ON ca.id = j.course_access_id
        INNER JOIN users u
            ON u.id = ca.user_id
        INNER JOIN courses c
            ON c.id = ca.course_id
        WHERE j.id = :job_id
        LIMIT 1
        "
    );
    $statement->execute(['job_id' => $jobId]);

    $context = $statement->fetch();

    if ($context === false) {
        throw new RuntimeException(
            'Contexto do job de provisionamento não foi encontrado.'
        );
    }

    return $context;
}

function evaFinishMoodleJob(
    PDO $pdo,
    int $jobId,
    string $status,
    ?string $errorMessage = null,
    ?string $availableAt = null
): void {
    if (!in_array($status, ['completed', 'failed', 'ignored'], true)) {
        throw new InvalidArgumentException(
            'Status final de job Moodle inválido.'
        );
    }

    $statement = $pdo->prepare(
        "
        UPDATE moodle_provisioning_jobs
        SET
            status = :status,
            completed_at = CASE
                WHEN :is_final = 1 THEN CURRENT_TIMESTAMP
                ELSE NULL
            END,
            available_at = COALESCE(:available_at, available_at),
            last_error = :last_error,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = :id
        "
    );
    $statement->execute([
        'status' => $status,
        'is_final' => in_array($status, ['completed', 'ignored'], true) ? 1 : 0,
        'available_at' => $availableAt,
        'last_error' => $errorMessage,
        'id' => $jobId,
    ]);
}

function evaStoreMoodleUserId(
    PDO $pdo,
    int $evaUserId,
    int $moodleUserId
): void {
    $statement = $pdo->prepare(
        "
        SELECT moodle_user_id
        FROM users
        WHERE id = :id
        LIMIT 1
        FOR UPDATE
        "
    );
    $statement->execute(['id' => $evaUserId]);
    $row = $statement->fetch();

    if ($row === false) {
        throw new RuntimeException('Usuário EVA não foi encontrado.');
    }

    $currentMoodleUserId = $row['moodle_user_id'] !== null
        ? (int) $row['moodle_user_id']
        : null;

    if (
        $currentMoodleUserId !== null &&
        $currentMoodleUserId !== $moodleUserId
    ) {
        throw new RuntimeException(
            'Usuário EVA já está vinculado a outra conta Moodle.'
        );
    }

    if ($currentMoodleUserId === null) {
        $statement = $pdo->prepare(
            "
            UPDATE users
            SET moodle_user_id = :moodle_user_id
            WHERE id = :id
            "
        );
        $statement->execute([
            'moodle_user_id' => $moodleUserId,
            'id' => $evaUserId,
        ]);
    }
}

function evaProcessMoodleProvisionJob(
    PDO $pdo,
    EvaMoodleClient $client,
    array $config,
    array $context
): void {
    $jobId = (int) $context['job_id'];
    $jobOrderId = (int) $context['job_order_id'];
    $currentOrderId = (int) $context['current_order_id'];
    $accessStatus = (string) $context['access_status'];

    if ($jobOrderId !== $currentOrderId) {
        evaFinishMoodleJob($pdo, $jobId, 'ignored');
        return;
    }

    if ($accessStatus === 'active') {
        evaFinishMoodleJob($pdo, $jobId, 'completed');
        return;
    }

    if ($accessStatus !== 'pending') {
        evaFinishMoodleJob($pdo, $jobId, 'ignored');
        return;
    }

    $moodleCourseId = (int) ($context['moodle_course_id'] ?? 0);

    if ($moodleCourseId <= 1) {
        throw new RuntimeException(
            'Curso EVA ainda não possui mapeamento Moodle válido.'
        );
    }

    evaMoodleValidateCourse($client, $moodleCourseId);

    $evaUser = [
        'id' => (int) $context['user_id'],
        'first_name' => (string) $context['first_name'],
        'last_name' => (string) $context['last_name'],
        'email' => (string) $context['email'],
    ];

    $moodleUserId = evaMoodleEnsureUser($client, $evaUser);

    evaMoodleEnrolUser(
        $client,
        $moodleUserId,
        $moodleCourseId,
        (int) $config['student_role_id']
    );

    $pdo->beginTransaction();

    try {
        $statement = $pdo->prepare(
            "
            SELECT order_id, status
            FROM course_access
            WHERE id = :id
            LIMIT 1
            FOR UPDATE
            "
        );
        $statement->execute([
            'id' => (int) $context['course_access_id'],
        ]);
        $latestAccess = $statement->fetch();

        if (
            $latestAccess === false ||
            (int) $latestAccess['order_id'] !== $jobOrderId ||
            (string) $latestAccess['status'] !== 'pending'
        ) {
            evaFinishMoodleJob($pdo, $jobId, 'ignored');
            $pdo->commit();
            return;
        }

        evaStoreMoodleUserId(
            $pdo,
            (int) $context['user_id'],
            $moodleUserId
        );

        $statement = $pdo->prepare(
            "
            UPDATE course_access
            SET
                status = 'active',
                granted_at = COALESCE(granted_at, CURRENT_TIMESTAMP),
                revoked_at = NULL,
                updated_at = CURRENT_TIMESTAMP
            WHERE
                id = :id
                AND order_id = :order_id
                AND status = 'pending'
            "
        );
        $statement->execute([
            'id' => (int) $context['course_access_id'],
            'order_id' => $jobOrderId,
        ]);

        if ($statement->rowCount() !== 1) {
            throw new RuntimeException(
                'Acesso mudou durante o provisionamento Moodle.'
            );
        }

        evaFinishMoodleJob($pdo, $jobId, 'completed');
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $error;
    }
}

function evaProcessMoodleRevokeJob(
    PDO $pdo,
    EvaMoodleClient $client,
    array $context
): void {
    $jobId = (int) $context['job_id'];
    $jobOrderId = (int) $context['job_order_id'];

    if (
        $jobOrderId !== (int) $context['current_order_id'] ||
        (string) $context['access_status'] !== 'revoked'
    ) {
        evaFinishMoodleJob($pdo, $jobId, 'ignored');
        return;
    }

    $moodleCourseId = (int) ($context['moodle_course_id'] ?? 0);

    if ($moodleCourseId <= 1) {
        throw new RuntimeException(
            'Curso EVA ainda não possui mapeamento Moodle válido.'
        );
    }

    evaMoodleValidateCourse($client, $moodleCourseId);

    $moodleUser = evaMoodleFindUserByEvaId(
        $client,
        (int) $context['user_id']
    );

    if ($moodleUser !== null) {
        $moodleUserId = (int) $moodleUser['id'];

        evaMoodleUnenrolUser(
            $client,
            $moodleUserId,
            $moodleCourseId
        );

        $pdo->beginTransaction();

        try {
            evaStoreMoodleUserId(
                $pdo,
                (int) $context['user_id'],
                $moodleUserId
            );

            $statement = $pdo->prepare(
                "
                SELECT order_id, status
                FROM course_access
                WHERE id = :id
                LIMIT 1
                FOR UPDATE
                "
            );
            $statement->execute([
                'id' => (int) $context['course_access_id'],
            ]);
            $latestAccess = $statement->fetch();

            if (
                $latestAccess === false ||
                (int) $latestAccess['order_id'] !== $jobOrderId ||
                (string) $latestAccess['status'] !== 'revoked'
            ) {
                evaFinishMoodleJob($pdo, $jobId, 'ignored');
                $pdo->commit();
                return;
            }

            evaFinishMoodleJob($pdo, $jobId, 'completed');
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $error;
        }

        return;
    }

    evaFinishMoodleJob($pdo, $jobId, 'completed');
}

evaRecoverStaleMoodleJobs($pdo);

$processed = 0;
$failed = 0;

for ($index = 0; $index < EVA_MOODLE_JOB_LIMIT; $index++) {
    $job = evaClaimMoodleJob($pdo);

    if ($job === null) {
        break;
    }

    $jobId = (int) $job['id'];

    try {
        $context = evaLoadMoodleJobContext($pdo, $jobId);

        if ((string) $context['action'] === 'provision') {
            evaProcessMoodleProvisionJob(
                $pdo,
                $moodleClient,
                $moodleConfig,
                $context
            );
        } elseif ((string) $context['action'] === 'revoke') {
            evaProcessMoodleRevokeJob(
                $pdo,
                $moodleClient,
                $context
            );
        } else {
            evaFinishMoodleJob($pdo, $jobId, 'ignored');
        }

        $processed++;
    } catch (Throwable $error) {
        $failed++;

        $attempt = max(1, (int) ($job['attempts'] ?? 1));
        $retryMinutes = min(60, 2 ** min($attempt, 6));
        $availableAt = (new DateTimeImmutable(
            '+' . $retryMinutes . ' minutes'
        ))->format('Y-m-d H:i:s');

        $safeMessage = mb_substr(
            trim($error->getMessage()),
            0,
            1000
        );

        evaFinishMoodleJob(
            $pdo,
            $jobId,
            'failed',
            $safeMessage !== '' ? $safeMessage : 'Falha não especificada.',
            $availableAt
        );

        error_log(sprintf(
            'EVA Moodle job %d falhou: %s',
            $jobId,
            $safeMessage
        ));
    }
}

fwrite(
    STDOUT,
    sprintf(
        "Moodle provisioning: %d processado(s), %d falha(s).\n",
        $processed,
        $failed
    )
);

exit($failed > 0 ? 1 : 0);
