<?php

declare(strict_types=1);

require __DIR__ . '/../../config/security.php';
require __DIR__ . '/../../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;

evaApplyApiSecurityHeaders();
header('Content-Type: application/json; charset=UTF-8');

const EMAIL_VERIFICATION_EXPIRATION_HOURS = 24;
const REGISTER_RATE_LIMIT_WINDOW = 3600;
const REGISTER_RATE_LIMIT_MAX_REQUESTS = 10;

function sendJsonResponse(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function neutralRegistrationResponse(): never
{
    sendJsonResponse(202, [
        'success' => true,
        'message' => 'Se este e-mail estiver disponível para cadastro, enviaremos uma confirmação em instantes.'
    ]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJsonResponse(405, ['success' => false, 'message' => 'Método não permitido.']);
}

evaEnforceTrustedOrigin();
evaEnforceJsonRequest(8192);

$clientIp = evaClientIp();
evaAssertRateLimit(
    'register-ip',
    $clientIp,
    REGISTER_RATE_LIMIT_MAX_REQUESTS,
    REGISTER_RATE_LIMIT_WINDOW
);
evaRecordRateLimitHit(
    'register-ip',
    $clientIp,
    REGISTER_RATE_LIMIT_WINDOW
);

$input = json_decode(file_get_contents('php://input'), true);

if (!is_array($input)) {
    sendJsonResponse(400, ['success' => false, 'message' => 'Dados inválidos.']);
}

$firstName = trim((string) ($input['first_name'] ?? ''));
$lastName = trim((string) ($input['last_name'] ?? ''));
$email = trim((string) ($input['email'] ?? ''));
$password = (string) ($input['password'] ?? '');
$errors = [];

if ($firstName === '') {
    $errors['first_name'] = 'Informe seu nome.';
} elseif (mb_strlen($firstName) > 80) {
    $errors['first_name'] = 'O nome informado é muito longo.';
} elseif (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $firstName)) {
    $errors['first_name'] = 'O nome contém caracteres inválidos.';
}

if ($lastName === '') {
    $errors['last_name'] = 'Informe seu sobrenome.';
} elseif (mb_strlen($lastName) > 120) {
    $errors['last_name'] = 'O sobrenome informado é muito longo.';
} elseif (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $lastName)) {
    $errors['last_name'] = 'O sobrenome contém caracteres inválidos.';
}

if ($email === '') {
    $errors['email'] = 'Informe seu e-mail.';
} elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 254) {
    $errors['email'] = 'Informe um e-mail válido.';
} else {
    $atPosition = strrpos($email, '@');
    $emailDomain = $atPosition !== false ? substr($email, $atPosition + 1) : '';
    $domainHasMailServer = $emailDomain !== '' && (
        checkdnsrr($emailDomain, 'MX') ||
        checkdnsrr($emailDomain, 'A') ||
        checkdnsrr($emailDomain, 'AAAA')
    );

    if (!$domainHasMailServer) {
        $errors['email'] = 'Informe um e-mail com domínio válido.';
    }
}

if ($password === '') {
    $errors['password'] = 'Informe uma senha.';
} elseif (mb_strlen($password) < 8) {
    $errors['password'] = 'A senha deve ter pelo menos 8 caracteres.';
} elseif (mb_strlen($password) > 128) {
    $errors['password'] = 'A senha pode ter no máximo 128 caracteres.';
}

if ($errors !== []) {
    sendJsonResponse(422, [
        'success' => false,
        'message' => 'Verifique os campos informados.',
        'errors' => $errors
    ]);
}

try {
    $pdo = require __DIR__ . '/../../config/database.php';

    $statement = $pdo->prepare(
        'SELECT id FROM users WHERE email = :email LIMIT 1'
    );
    $statement->execute(['email' => $email]);

    if ($statement->fetch() !== false) {
        neutralRegistrationResponse();
    }

    $mailConfigPath = __DIR__ . '/../../config/mail.local.php';

    if (!is_file($mailConfigPath)) {
        throw new RuntimeException('Configuração SMTP não encontrada.');
    }

    $mailConfig = require $mailConfigPath;

    $requiredMailConfigKeys = [
        'host',
        'port',
        'username',
        'password',
        'from_email',
        'from_name'
    ];

    if (!is_array($mailConfig)) {
        throw new RuntimeException('Configuração SMTP inválida.');
    }

    foreach ($requiredMailConfigKeys as $key) {
        if (!array_key_exists($key, $mailConfig) || $mailConfig[$key] === '') {
            throw new RuntimeException('Configuração SMTP incompleta.');
        }
    }
} catch (Throwable $error) {
    error_log('EVA Auth: erro de configuração/cadastro: ' . $error->getMessage());
    sendJsonResponse(500, ['success' => false, 'message' => 'Não foi possível criar a conta.']);
}

$passwordHash = password_hash($password, PASSWORD_DEFAULT);

if ($passwordHash === false) {
    sendJsonResponse(500, ['success' => false, 'message' => 'Não foi possível criar a conta.']);
}

try {
    $verificationToken = bin2hex(random_bytes(32));
} catch (Throwable $error) {
    error_log('EVA Auth: erro ao gerar token de verificação: ' . $error->getMessage());
    sendJsonResponse(500, ['success' => false, 'message' => 'Não foi possível criar a conta.']);
}

$verificationTokenHash = hash('sha256', $verificationToken);
$appConfig = require __DIR__ . '/../../config/app.php';
$verificationUrl =
    rtrim((string) $appConfig['base_url'], '/') .
    '/api/auth/verify-email.php?token=' .
    rawurlencode($verificationToken);

try {
    $pdo->beginTransaction();

    $statement = $pdo->prepare(
        'INSERT INTO users (first_name, last_name, email, password_hash)
         VALUES (:first_name, :last_name, :email, :password_hash)'
    );
    $statement->execute([
        'first_name' => $firstName,
        'last_name' => $lastName,
        'email' => $email,
        'password_hash' => $passwordHash
    ]);

    $userId = (int) $pdo->lastInsertId();

    $statement = $pdo->prepare(
        'INSERT INTO email_verification_tokens (user_id, token_hash, expires_at)
         VALUES (:user_id, :token_hash, DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 24 HOUR))'
    );
    $statement->execute([
        'user_id' => $userId,
        'token_hash' => $verificationTokenHash
    ]);

    $pdo->commit();
} catch (PDOException $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    if ($error->getCode() === '23000') {
        neutralRegistrationResponse();
    }

    error_log('EVA Auth: erro ao cadastrar usuário: ' . $error->getMessage());
    sendJsonResponse(500, ['success' => false, 'message' => 'Não foi possível criar a conta.']);
} catch (Throwable $error) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('EVA Auth: erro ao cadastrar usuário: ' . $error->getMessage());
    sendJsonResponse(500, ['success' => false, 'message' => 'Não foi possível criar a conta.']);
}

try {
    $mail = new PHPMailer(true);
    $mail->CharSet = 'UTF-8';
    $mail->isSMTP();
    $mail->Host = $mailConfig['host'];
    $mail->SMTPAuth = true;
    $mail->Username = $mailConfig['username'];
    $mail->Password = $mailConfig['password'];
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    $mail->Port = $mailConfig['port'];
    $mail->setFrom($mailConfig['from_email'], $mailConfig['from_name']);
    $mail->addAddress($email, $firstName . ' ' . $lastName);
    $mail->Subject = 'Confirme seu e-mail - EVA';

    $safeFirstName = htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8');
    $safeVerificationUrl = htmlspecialchars($verificationUrl, ENT_QUOTES, 'UTF-8');

    $mail->isHTML(true);
    $mail->Body = <<<HTML
<!doctype html>
<html lang="pt-BR">
<body style="margin:0;padding:24px;background:#f5f7fb;font-family:Arial,Helvetica,sans-serif;color:#1d2440;">
<div style="max-width:640px;margin:0 auto;background:#fff;border-radius:18px;padding:32px;border:1px solid #e7eaf3;">
<h1 style="margin:0 0 16px;font-size:24px;">Confirme seu e-mail</h1>
<p style="line-height:1.7;">Olá, {$safeFirstName}!</p>
<p style="line-height:1.7;">Seu cadastro na EVA foi recebido. Para confirmar este endereço de e-mail, clique no botão abaixo.</p>
<p style="margin:32px 0;text-align:center;"><a href="{$safeVerificationUrl}" style="display:inline-block;padding:14px 24px;border-radius:10px;background:#1d2440;color:#fff;text-decoration:none;font-weight:bold;">Confirmar meu e-mail</a></p>
<p style="line-height:1.7;color:#667085;">Este link é válido por 24 horas. Se você não realizou este cadastro, ignore esta mensagem.</p>
</div>
</body>
</html>
HTML;

    $mail->AltBody =
        "Olá, {$firstName}!\n\n" .
        "Confirme seu e-mail EVA acessando:\n{$verificationUrl}\n\n" .
        "Este link é válido por 24 horas.";

    $mail->send();
} catch (Throwable $error) {
    error_log('EVA Auth: conta criada, mas o envio da verificação falhou: ' . $error->getMessage());
}

neutralRegistrationResponse();
