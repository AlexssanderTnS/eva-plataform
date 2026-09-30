<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$pdo = require __DIR__ . '/../config/database.php';
$moodleClient = require __DIR__ . '/../config/moodle-client.php';
require_once __DIR__ . '/../config/moodle-service.php';

const EVA_ACCOUNT_DELETION_LIMIT = 10;

function evaClaimAccountDeletion(PDO $pdo): ?array
{
    $pdo->beginTransaction();

    try {
        $statement = $pdo->query(
            "
            SELECT
                adr.id AS request_id,
                adr.user_id,
                adr.scheduled_for,
                u.moodle_user_id,
                u.email
            FROM account_deletion_requests adr
            INNER JOIN users u ON u.id = adr.user_id
            WHERE
                adr.status = 'pending'
                AND adr.scheduled_for IS NOT NULL
                AND adr.scheduled_for <= CURRENT_TIMESTAMP
            ORDER BY adr.scheduled_for ASC, adr.id ASC
            LIMIT 1
            FOR UPDATE
            "
        );

        $request = $statement->fetch();

        if ($request === false) {
            $pdo->commit();
            return null;
        }

        $statement = $pdo->prepare(
            "
            UPDATE account_deletion_requests
            SET
                status = 'processing',
                processed_at = NULL
            WHERE id = :id AND status = 'pending'
            "
        );
        $statement->execute(['id' => (int) $request['request_id']]);

        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('A solicitação mudou durante o processamento.');
        }

        $pdo->commit();

        return $request;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $error;
    }
}

function evaReleaseAccountDeletion(PDO $pdo, int $requestId): void
{
    $statement = $pdo->prepare(
        "
        UPDATE account_deletion_requests
        SET status = 'pending'
        WHERE id = :id AND status = 'processing'
        "
    );
    $statement->execute(['id' => $requestId]);
}

function evaSuspendMoodleAccount(
    EvaMoodleClient $client,
    array $request
): void {
    $evaUserId = (int) $request['user_id'];
    $moodleUser = evaMoodleFindUserByEvaId($client, $evaUserId);

    if ($moodleUser === null && $request['moodle_user_id'] !== null) {
        $moodleUser = evaMoodleFindLinkedDbUserByEmail(
            $client,
            (string) $request['email'],
            $evaUserId,
            (int) $request['moodle_user_id']
        );
        if ($moodleUser === null) {
            throw new RuntimeException('Conta Moodle vinculada ausente durante exclusao.');
        }
    }

    if ($moodleUser === null) {
        return;
    }

    $moodleUserId = (int) $moodleUser['id'];
    $anonymousEmail = sprintf('deleted-%d@example.invalid', $evaUserId);

    // Nao sobrescrever idnumber: o Web Service pode ocultar um valor
    // preexistente. Suspensao e anonimização devem preservar a identidade.
    $client->call(
        'core_user_update_users',
        [
            'users' => [[
                'id' => $moodleUserId,
                'firstname' => 'Usuário',
                'lastname' => 'Excluído',
                'email' => $anonymousEmail,
                'suspended' => 1,
            ]],
        ]
    );
}

function evaCompleteAccountDeletion(
    PDO $pdo,
    int $requestId,
    int $userId
): void {
    $anonymousEmail = sprintf('deleted-%d@example.invalid', $userId);
    $randomPassword = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);

    if ($randomPassword === false) {
        throw new RuntimeException('Não foi possível invalidar a senha da conta.');
    }

    $pdo->beginTransaction();

    try {
        $statement = $pdo->prepare(
            "
            SELECT status
            FROM account_deletion_requests
            WHERE id = :id AND user_id = :user_id
            LIMIT 1
            FOR UPDATE
            "
        );
        $statement->execute([
            'id' => $requestId,
            'user_id' => $userId,
        ]);
        $request = $statement->fetch();

        if ($request === false || (string) $request['status'] !== 'processing') {
            throw new RuntimeException('Solicitação de exclusão não está disponível para conclusão.');
        }

        $statement = $pdo->prepare(
            "
            UPDATE course_access
            SET
                status = 'revoked',
                revoked_at = COALESCE(revoked_at, CURRENT_TIMESTAMP),
                updated_at = CURRENT_TIMESTAMP
            WHERE user_id = :user_id AND status <> 'revoked'
            "
        );
        $statement->execute(['user_id' => $userId]);

        $statement = $pdo->prepare(
            "
            DELETE FROM email_verification_tokens
            WHERE user_id = :user_id
            "
        );
        $statement->execute(['user_id' => $userId]);

        $statement = $pdo->prepare(
            "
            DELETE FROM password_reset_tokens
            WHERE user_id = :user_id
            "
        );
        $statement->execute(['user_id' => $userId]);

        $statement = $pdo->prepare(
            "
            UPDATE users
            SET
                first_name = 'Usuário',
                last_name = 'Excluído',
                email = :email,
                email_verified_at = NULL,
                password_hash = :password_hash,
                status = 'blocked',
                session_version = session_version + 1,
                updated_at = CURRENT_TIMESTAMP
            WHERE id = :id
            "
        );
        $statement->execute([
            'email' => $anonymousEmail,
            'password_hash' => $randomPassword,
            'id' => $userId,
        ]);

        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('Usuário não foi atualizado durante a exclusão.');
        }

        $statement = $pdo->prepare(
            "
            UPDATE account_deletion_requests
            SET
                status = 'completed',
                processed_at = CURRENT_TIMESTAMP
            WHERE id = :id AND status = 'processing'
            "
        );
        $statement->execute(['id' => $requestId]);

        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('Solicitação não foi concluída corretamente.');
        }

        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $error;
    }
}

$processed = 0;
$failed = 0;

for ($index = 0; $index < EVA_ACCOUNT_DELETION_LIMIT; $index++) {
    $request = evaClaimAccountDeletion($pdo);

    if ($request === null) {
        break;
    }

    $requestId = (int) $request['request_id'];
    $userId = (int) $request['user_id'];

    try {
        evaSuspendMoodleAccount($moodleClient, $request);
        evaCompleteAccountDeletion($pdo, $requestId, $userId);
        $processed++;
    } catch (Throwable $error) {
        $failed++;
        evaReleaseAccountDeletion($pdo, $requestId);

        error_log(sprintf(
            'EVA exclusão de conta %d falhou: %s',
            $requestId,
            substr(trim($error->getMessage()), 0, 1000)
        ));
    }
}

fwrite(
    STDOUT,
    sprintf(
        "Exclusão de contas: %d processada(s), %d falha(s).\n",
        $processed,
        $failed
    )
);

exit($failed > 0 ? 1 : 0);
