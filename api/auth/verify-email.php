<?php

declare(strict_types=1);

require __DIR__ . '/../../config/security.php';

evaApplyApiSecurityHeaders();
header('Content-Type: text/plain; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo 'Método não permitido.';
    exit;
}

$token = trim((string) ($_GET['token'] ?? ''));

if ($token === '' || !ctype_xdigit($token) || strlen($token) !== 64) {
    http_response_code(400);
    echo 'Token de verificação inválido.';
    exit;
}

$clientIp = evaClientIp();
$rateLimitWindow = 3600;

evaAssertRateLimit('email-verification-ip', $clientIp, 60, $rateLimitWindow);
evaRecordRateLimitHit('email-verification-ip', $clientIp, $rateLimitWindow);

$tokenHash = hash('sha256', $token);

try {
    $pdo = require __DIR__ . '/../../config/database.php';

    $statement = $pdo->prepare(
        'SELECT
            evt.id,
            evt.user_id,
            evt.expires_at,
            (evt.expires_at < CURRENT_TIMESTAMP) AS is_expired,
            u.email_verified_at
         FROM email_verification_tokens evt
         INNER JOIN users u ON u.id = evt.user_id
         WHERE evt.token_hash = :token_hash
         LIMIT 1'
    );

    $statement->execute(['token_hash' => $tokenHash]);
    $verification = $statement->fetch();
} catch (Throwable $error) {
    error_log('EVA Auth: erro ao consultar verificação: ' . $error->getMessage());
    http_response_code(500);
    echo 'Não foi possível confirmar seu e-mail.';
    exit;
}

if ($verification === false) {
    http_response_code(400);
    echo 'Token de verificação inválido ou já utilizado.';
    exit;
}

if ($verification['email_verified_at'] !== null) {
    try {
        $deleteStatement = $pdo->prepare(
            'DELETE FROM email_verification_tokens WHERE user_id = :user_id'
        );
        $deleteStatement->execute(['user_id' => (int) $verification['user_id']]);
    } catch (Throwable $error) {
        error_log('EVA Auth: erro ao limpar token já verificado: ' . $error->getMessage());
    }

    echo 'Este e-mail já foi confirmado.';
    exit;
}

if ((int) $verification['is_expired'] === 1) {
    try {
        $deleteStatement = $pdo->prepare(
            'DELETE FROM email_verification_tokens WHERE id = :id'
        );
        $deleteStatement->execute(['id' => (int) $verification['id']]);
    } catch (Throwable $error) {
        error_log('EVA Auth: erro ao remover token expirado: ' . $error->getMessage());
    }

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
    $statement->execute(['user_id' => (int) $verification['user_id']]);

    $statement = $pdo->prepare(
        'DELETE FROM email_verification_tokens WHERE user_id = :user_id'
    );
    $statement->execute(['user_id' => (int) $verification['user_id']]);

    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('EVA Auth: erro ao verificar e-mail: ' . $error->getMessage());
    http_response_code(500);
    echo 'Não foi possível confirmar seu e-mail.';
    exit;
}

echo 'E-mail confirmado com sucesso.';
