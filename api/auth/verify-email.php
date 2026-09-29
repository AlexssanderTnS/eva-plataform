<?php

declare(strict_types=1);

require __DIR__ . '/../../config/security.php';

evaApplyApiSecurityHeaders();
header('Content-Type: text/plain; charset=UTF-8');

function evaShowVerificationSuccess(bool $alreadyVerified = false): never
{
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store, private');

    $heading = $alreadyVerified
        ? 'E-mail já confirmado'
        : 'E-mail confirmado com sucesso!';
    $message = $alreadyVerified
        ? 'Seu endereço de e-mail já foi verificado. Você já pode entrar na EVA.'
        : 'Seu cadastro foi confirmado. Agora você já pode entrar na sua conta EVA.';

    echo '<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Confirmação de e-mail | EVA</title>
<style>
*{box-sizing:border-box}
body{margin:0;min-height:100vh;display:grid;place-items:center;padding:24px;background:#f6f5fb;font-family:Arial,Helvetica,sans-serif;color:#030e2e}
main{width:min(100%,460px);padding:40px 28px;text-align:center;background:#fff;border:1px solid #e8e4f4;border-radius:20px;box-shadow:0 12px 36px rgba(3,14,46,.08)}
.brand{font-size:22px;font-weight:800;letter-spacing:.1em;color:#2a1f6f}
h1{font-size:27px;line-height:1.25;margin:24px 0 14px}
p{line-height:1.65;color:#424765}
a{display:inline-block;margin-top:18px;padding:15px 26px;border-radius:10px;background:#2a1f6f;color:white;text-decoration:none;font-weight:700}
a:hover,a:focus-visible{background:#42338e}
</style>
</head>
<body>
<main>
<div class="brand">EVA</div>
<h1>' . htmlspecialchars($heading, ENT_QUOTES, 'UTF-8') . '</h1>
<p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>
<a href="/acesso.html">Entrar na EVA</a>
</main>
</body>
</html>';
    exit;
}

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

    evaShowVerificationSuccess(true);
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

evaShowVerificationSuccess();
