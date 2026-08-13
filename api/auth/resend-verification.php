<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;

header('Content-Type: application/json; charset=UTF-8');

const EMAIL_VERIFICATION_EXPIRATION_SECONDS = 86400; // 24 horas

function sendJsonResponse(int $status, array $payload): void
{
    http_response_code($status);

    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE
    );

    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJsonResponse(405, [
        'success' => false,
        'message' => 'Método não permitido.'
    ]);
}

$input = json_decode(
    file_get_contents('php://input'),
    true
);

if (!is_array($input)) {
    sendJsonResponse(400, [
        'success' => false,
        'message' => 'Dados inválidos.'
    ]);
}

$email = trim((string) ($input['email'] ?? ''));

if (
    $email === '' ||
    !filter_var($email, FILTER_VALIDATE_EMAIL)
) {
    sendJsonResponse(422, [
        'success' => false,
        'message' => 'Informe um e-mail válido.'
    ]);
}

$pdo = require __DIR__ . '/../../config/database.php';

$mailConfigPath =
    __DIR__ . '/../../config/mail.local.php';

if (!file_exists($mailConfigPath)) {
    error_log(
        'EVA Auth: configuração SMTP não encontrada.'
    );

    sendJsonResponse(500, [
        'success' => false,
        'message' =>
            'Não foi possível reenviar o e-mail de confirmação.'
    ]);
}

$mailConfig = require $mailConfigPath;

$statement = $pdo->prepare(
    'SELECT
        id,
        first_name,
        last_name,
        email,
        email_verified_at
     FROM users
     WHERE email = :email
     LIMIT 1'
);

$statement->execute([
    'email' => $email
]);

$user = $statement->fetch();



if ($user === false) {
    sendJsonResponse(200, [
        'success' => true,
        'message' =>
            'Se existir uma conta pendente para este e-mail, enviaremos uma nova confirmação.'
    ]);
}

if ($user['email_verified_at'] !== null) {
    sendJsonResponse(200, [
        'success' => true,
        'message' =>
            'Este e-mail já foi confirmado.'
    ]);
}


$statement = $pdo->prepare(
    'SELECT created_at
     FROM email_verification_tokens
     WHERE user_id = :user_id
     ORDER BY created_at DESC
     LIMIT 1'
);

$statement->execute([
    'user_id' => $user['id']
]);

$existingToken = $statement->fetch();

if ($existingToken !== false) {
    $lastSentAt = strtotime(
        (string) $existingToken['created_at']
    );

    if (
        $lastSentAt !== false &&
        (time() - $lastSentAt) < 60
    ) {
        sendJsonResponse(429, [
            'success' => false,
            'message' =>
                'Aguarde alguns instantes antes de solicitar um novo e-mail.'
        ]);
    }
}



try {
    $verificationToken = bin2hex(
        random_bytes(32)
    );
} catch (Throwable $error) {
    error_log(
        'EVA Auth: erro ao gerar novo token: ' .
        $error->getMessage()
    );

    sendJsonResponse(500, [
        'success' => false,
        'message' =>
            'Não foi possível reenviar o e-mail de confirmação.'
    ]);
}

$verificationTokenHash = hash(
    'sha256',
    $verificationToken
);

$verificationExpiresAt = date(
    'Y-m-d H:i:s',
    time() + EMAIL_VERIFICATION_EXPIRATION_SECONDS
);

$verificationUrl =
    'https://www.evaglobal.com.br/api/auth/verify-email.php?token=' .
    rawurlencode($verificationToken);

try {
    $pdo->beginTransaction();


    $statement = $pdo->prepare(
        'DELETE FROM email_verification_tokens
         WHERE user_id = :user_id'
    );

    $statement->execute([
        'user_id' => $user['id']
    ]);



    $statement = $pdo->prepare(
        'INSERT INTO email_verification_tokens (
            user_id,
            token_hash,
            expires_at
        ) VALUES (
            :user_id,
            :token_hash,
            :expires_at
        )'
    );

    $statement->execute([
        'user_id' => $user['id'],
        'token_hash' => $verificationTokenHash,
        'expires_at' => $verificationExpiresAt
    ]);

    $pdo->commit();

} catch (Throwable $error) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log(
        'EVA Auth: erro ao renovar token: ' .
        $error->getMessage()
    );

    sendJsonResponse(500, [
        'success' => false,
        'message' =>
            'Não foi possível reenviar o e-mail de confirmação.'
    ]);
}



try {
    $mail = new PHPMailer(true);

    $mail->CharSet = 'UTF-8';

    $mail->isSMTP();

    $mail->Host =
        $mailConfig['host'];

    $mail->SMTPAuth = true;

    $mail->Username =
        $mailConfig['username'];

    $mail->Password =
        $mailConfig['password'];

    $mail->SMTPSecure =
        PHPMailer::ENCRYPTION_SMTPS;

    $mail->Port =
        $mailConfig['port'];

    $mail->setFrom(
        $mailConfig['from_email'],
        $mailConfig['from_name']
    );

    $mail->addAddress(
        $user['email'],
        $user['first_name'] . ' ' . $user['last_name']
    );

    $mail->Subject =
        'Novo link de confirmação - EVA';

    $safeFirstName = htmlspecialchars(
        (string) $user['first_name'],
        ENT_QUOTES,
        'UTF-8'
    );

    $safeVerificationUrl = htmlspecialchars(
        $verificationUrl,
        ENT_QUOTES,
        'UTF-8'
    );

    $mail->isHTML(true);

    $mail->Body = <<<HTML
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
</head>

<body style="
    margin:0;
    padding:24px;
    background:#f5f7fb;
    font-family:Arial, Helvetica, sans-serif;
    color:#1d2440;
">
    <div style="
        max-width:640px;
        margin:0 auto;
        background:#ffffff;
        border-radius:18px;
        padding:32px;
        border:1px solid #e7eaf3;
    ">

        <h1 style="
            margin:0 0 16px;
            font-size:24px;
        ">
            Novo link de confirmação
        </h1>

        <p style="
            line-height:1.7;
            margin:0 0 16px;
        ">
            Olá, {$safeFirstName}!
        </p>

        <p style="
            line-height:1.7;
            margin:0 0 24px;
        ">
            Você solicitou um novo link para confirmar
            seu e-mail na EVA.
        </p>

        <p style="
            margin:32px 0;
            text-align:center;
        ">
            <a
                href="{$safeVerificationUrl}"
                style="
                    display:inline-block;
                    padding:14px 24px;
                    border-radius:10px;
                    background:#1d2440;
                    color:#ffffff;
                    text-decoration:none;
                    font-weight:bold;
                "
            >
                Confirmar meu e-mail
            </a>
        </p>

        <p style="
            line-height:1.7;
            color:#667085;
            margin:0;
        ">
            Este novo link é válido por 24 horas.
        </p>

    </div>
</body>
</html>
HTML;

    $mail->AltBody =
        "Olá, {$user['first_name']}!\n\n" .
        "Você solicitou um novo link para confirmar " .
        "seu e-mail na EVA.\n\n" .
        "Acesse:\n\n" .
        $verificationUrl .
        "\n\nEste link é válido por 24 horas.";

    $mail->send();

} catch (Throwable $error) {

    error_log(
        'EVA Auth: erro ao reenviar verificação: ' .
        $error->getMessage()
    );

    sendJsonResponse(500, [
        'success' => false,
        'message' =>
            'Não foi possível reenviar o e-mail de confirmação.'
    ]);
}

sendJsonResponse(200, [
    'success' => true,
    'message' =>
        'Um novo e-mail de confirmação foi enviado.'
]);