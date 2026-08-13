<?php

declare(strict_types=1);

$pdo = require __DIR__ . '/../../config/database.php';

$token = trim((string) ($_GET['token'] ?? ''));

if ($token === '') {
    http_response_code(400);

    echo 'Token de verificação inválido.';
    exit;
}

if (!ctype_xdigit($token) || strlen($token) !== 64) {
    http_response_code(400);

    echo 'Token de verificação inválido.';
    exit;
}

$tokenHash = hash(
    'sha256',
    $token
);

$statement = $pdo->prepare(
    'SELECT
        evt.id,
        evt.user_id,
        evt.expires_at,
        u.email_verified_at
     FROM email_verification_tokens evt
     INNER JOIN users u
        ON u.id = evt.user_id
     WHERE evt.token_hash = :token_hash
     LIMIT 1'
);

$statement->execute([
    'token_hash' => $tokenHash
]);

$verification = $statement->fetch();

if ($verification === false) {
    http_response_code(400);

    echo 'Token de verificação inválido ou já utilizado.';
    exit;
}

if ($verification['email_verified_at'] !== null) {
    $deleteStatement = $pdo->prepare(
        'DELETE FROM email_verification_tokens
         WHERE user_id = :user_id'
    );

    $deleteStatement->execute([
        'user_id' => $verification['user_id']
    ]);

    echo 'Este e-mail já foi confirmado.';
    exit;
}

$expiresAt = strtotime(
    (string) $verification['expires_at']
);

if (
    $expiresAt === false ||
    $expiresAt < time()
) {
    $deleteStatement = $pdo->prepare(
        'DELETE FROM email_verification_tokens
         WHERE id = :id'
    );

    $deleteStatement->execute([
        'id' => $verification['id']
    ]);

    http_response_code(410);

    echo 'Este link de verificação expirou.';
    exit;
}

try {
    $pdo->beginTransaction();

    $statement = $pdo->prepare(
        'UPDATE users
         SET email_verified_at = CURRENT_TIMESTAMP
         WHERE id = :user_id
           AND email_verified_at IS NULL'
    );

    $statement->execute([
        'user_id' => $verification['user_id']
    ]);

    $statement = $pdo->prepare(
        'DELETE FROM email_verification_tokens
         WHERE user_id = :user_id'
    );

    $statement->execute([
        'user_id' => $verification['user_id']
    ]);

    $pdo->commit();
} catch (Throwable $error) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log(
        'Erro ao verificar e-mail EVA: ' .
            $error->getMessage()
    );

    http_response_code(500);

    echo 'Não foi possível confirmar seu e-mail.';
    exit;
}

echo 'E-mail confirmado com sucesso.';
