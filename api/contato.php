<?php

declare(strict_types=1);

require __DIR__ . '/../config/security.php';
require __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

evaApplyApiSecurityHeaders();
header('Content-Type: application/json; charset=UTF-8');

const MIN_FORM_FILL_SECONDS = 3;
const CONTACT_RATE_LIMIT_WINDOW = 600;
const CONTACT_RATE_LIMIT_MAX_REQUESTS = 3;

function sendJsonResponse(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJsonResponse(405, ['success' => false, 'message' => 'Método não permitido.']);
}

evaEnforceTrustedOrigin();
evaEnforceJsonRequest(16384);

$input = json_decode(file_get_contents('php://input'), true);

if (!is_array($input)) {
    sendJsonResponse(400, ['success' => false, 'message' => 'Dados inválidos.']);
}

$name = trim((string) ($input['name'] ?? ''));
$email = trim((string) ($input['email'] ?? ''));
$phone = trim((string) ($input['phone'] ?? ''));
$company = trim((string) ($input['company'] ?? ''));
$profile = trim((string) ($input['profile'] ?? ''));
$subject = trim((string) ($input['subject'] ?? ''));
$message = trim((string) ($input['message'] ?? ''));
$website = trim((string) ($input['website'] ?? ''));
$formStartedAt = filter_var($input['form_started_at'] ?? null, FILTER_VALIDATE_INT);
$privacy = filter_var($input['privacy'] ?? false, FILTER_VALIDATE_BOOLEAN);

if ($website !== '') {
    sendJsonResponse(200, ['success' => true, 'message' => 'Mensagem enviada com sucesso.']);
}

$nowInMilliseconds = (int) floor(microtime(true) * 1000);
$minimumFillTime = MIN_FORM_FILL_SECONDS * 1000;

if (
    $formStartedAt === false ||
    $formStartedAt <= 0 ||
    $formStartedAt > $nowInMilliseconds ||
    ($nowInMilliseconds - $formStartedAt) < $minimumFillTime
) {
    sendJsonResponse(400, [
        'success' => false,
        'message' => 'Não foi possível validar o envio. Recarregue a página e tente novamente.'
    ]);
}

$allowedProfiles = ['empresa', 'pessoa-fisica'];
$allowedSubjects = [
    'proposta-empresarial',
    'palestras',
    'treinamentos',
    'recursos',
    'parceria',
    'suporte',
    'outro'
];

$subjectLabels = [
    'proposta-empresarial' => 'Solicitar proposta para empresa',
    'palestras' => 'Palestras e workshops',
    'treinamentos' => 'Treinamentos e jornadas',
    'recursos' => 'Recursos para você',
    'parceria' => 'Parcerias',
    'suporte' => 'Suporte',
    'outro' => 'Outro assunto'
];

$profileLabels = [
    'empresa' => 'Empresa',
    'pessoa-fisica' => 'Pessoa física'
];

$errors = [];
$hasControlChars = static fn (string $value): bool =>
    preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value) === 1;

if ($name === '' || mb_strlen($name) < 2) {
    $errors['name'] = 'Informe um nome válido.';
} elseif (mb_strlen($name) > 120 || $hasControlChars($name)) {
    $errors['name'] = 'O nome informado é inválido.';
}

if ($email === '') {
    $errors['email'] = 'Informe seu e-mail.';
} elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 254) {
    $errors['email'] = 'Informe um e-mail válido.';
}

if ($phone !== '' && (mb_strlen($phone) > 30 || $hasControlChars($phone))) {
    $errors['phone'] = 'Informe um telefone válido.';
}

if ($company !== '' && (mb_strlen($company) > 150 || $hasControlChars($company))) {
    $errors['company'] = 'O nome da empresa é inválido.';
}

if (!in_array($profile, $allowedProfiles, true)) {
    $errors['profile'] = 'Selecione um perfil válido.';
}

if (!in_array($subject, $allowedSubjects, true)) {
    $errors['subject'] = 'Selecione um assunto válido.';
}

if ($message === '') {
    $errors['message'] = 'Digite sua mensagem.';
} elseif (mb_strlen($message) < 10) {
    $errors['message'] = 'A mensagem precisa ter pelo menos 10 caracteres.';
} elseif (mb_strlen($message) > 1000) {
    $errors['message'] = 'A mensagem pode ter no máximo 1000 caracteres.';
}

if (!$privacy) {
    $errors['privacy'] = 'Você precisa aceitar a Política de Privacidade.';
}

if ($errors !== []) {
    sendJsonResponse(422, [
        'success' => false,
        'message' => 'Verifique os campos informados.',
        'errors' => $errors
    ]);
}

$clientIp = evaClientIp();
evaAssertRateLimit(
    'contact-ip',
    $clientIp,
    CONTACT_RATE_LIMIT_MAX_REQUESTS,
    CONTACT_RATE_LIMIT_WINDOW
);
evaRecordRateLimitHit('contact-ip', $clientIp, CONTACT_RATE_LIMIT_WINDOW);

$configPath = __DIR__ . '/../config/mail.local.php';

if (!is_file($configPath)) {
    error_log('EVA Contato: configuração de e-mail indisponível.');
    sendJsonResponse(500, [
        'success' => false,
        'message' => 'Configuração de e-mail indisponível.'
    ]);
}

$config = require $configPath;

if (!is_array($config)) {
    error_log('EVA Contato: configuração de e-mail inválida.');
    sendJsonResponse(500, [
        'success' => false,
        'message' => 'Configuração de e-mail indisponível.'
    ]);
}

$safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
$safeEmail = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
$safePhone = htmlspecialchars($phone !== '' ? $phone : 'Não informado', ENT_QUOTES, 'UTF-8');
$safeCompany = htmlspecialchars($company !== '' ? $company : 'Não informado', ENT_QUOTES, 'UTF-8');
$safeMessage = nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'));
$profileLabel = $profileLabels[$profile] ?? 'Não informado';
$subjectLabel = $subjectLabels[$subject] ?? 'Contato pelo site';

$mail = new PHPMailer(true);

try {
    $mail->CharSet = 'UTF-8';
    $mail->isSMTP();
    $mail->Host = $config['host'];
    $mail->SMTPAuth = true;
    $mail->Username = $config['username'];
    $mail->Password = $config['password'];
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    $mail->Port = $config['port'];
    $mail->setFrom($config['from_email'], $config['from_name']);
    $mail->addAddress($config['to_email']);
    $mail->addReplyTo($email, $name);
    $mail->Subject = 'Novo contato pelo site EVA - ' . $subjectLabel;
    $mail->isHTML(true);

    $mail->Body = <<<HTML
<!doctype html>
<html lang="pt-BR">
<body style="margin:0;padding:24px;background:#f5f7fb;font-family:Arial,Helvetica,sans-serif;color:#1d2440;">
<div style="max-width:640px;margin:0 auto;background:#fff;border-radius:18px;padding:32px;border:1px solid #e7eaf3;">
<h1 style="margin:0 0 8px;font-size:24px;">Novo contato pelo site EVA</h1>
<p><strong>Nome:</strong> {$safeName}</p>
<p><strong>E-mail:</strong> {$safeEmail}</p>
<p><strong>Telefone:</strong> {$safePhone}</p>
<p><strong>Empresa:</strong> {$safeCompany}</p>
<p><strong>Perfil:</strong> {$profileLabel}</p>
<p><strong>Assunto:</strong> {$subjectLabel}</p>
<div style="padding:20px;border-radius:14px;background:#f8f9fc;"><strong>Mensagem</strong><p style="line-height:1.7;margin-bottom:0;">{$safeMessage}</p></div>
</div>
</body>
</html>
HTML;

    $mail->AltBody =
        "Novo contato pelo site EVA\n\n" .
        "Nome: {$name}\n" .
        "E-mail: {$email}\n" .
        "Telefone: " . ($phone !== '' ? $phone : 'Não informado') . "\n" .
        "Empresa: " . ($company !== '' ? $company : 'Não informado') . "\n" .
        "Perfil: {$profileLabel}\n" .
        "Assunto: {$subjectLabel}\n\n" .
        "Mensagem:\n{$message}";

    $mail->send();

    sendJsonResponse(200, [
        'success' => true,
        'message' => 'Mensagem enviada com sucesso.'
    ]);
} catch (Exception $error) {
    error_log('EVA Contato: erro SMTP: ' . $mail->ErrorInfo);
    sendJsonResponse(500, [
        'success' => false,
        'message' => 'Não foi possível enviar sua mensagem. Tente novamente mais tarde.'
    ]);
}
