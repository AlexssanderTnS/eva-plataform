<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function respond(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function string_value(array $data, string $key): string
{
    $value = $data[$key] ?? '';
    return is_string($value) ? trim($value) : '';
}

function escape_html(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    respond(405, [
        'success' => false,
        'message' => 'Método não permitido.',
    ]);
}

$contentType = $_SERVER['CONTENT_TYPE'] ?? '';

if (stripos($contentType, 'application/json') === false) {
    respond(415, [
        'success' => false,
        'message' => 'Formato da requisição não suportado.',
    ]);
}

$rawBody = file_get_contents('php://input');
$data = json_decode($rawBody ?: '', true);

if (!is_array($data)) {
    respond(400, [
        'success' => false,
        'message' => 'Dados inválidos.',
    ]);
}

$honeypot = string_value($data, 'website');

if ($honeypot !== '') {
    respond(200, [
        'success' => true,
        'message' => 'Mensagem enviada com sucesso.',
    ]);
}

$name = string_value($data, 'name');
$email = string_value($data, 'email');
$phone = string_value($data, 'phone');
$company = string_value($data, 'company');
$profile = string_value($data, 'profile');
$subject = string_value($data, 'subject');
$message = string_value($data, 'message');
$privacyAccepted = ($data['privacy'] ?? false) === true;

$errors = [];

if (mb_strlen($name) < 3 || mb_strlen($name) > 120) {
    $errors['name'] = 'Informe um nome válido.';
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 254) {
    $errors['email'] = 'Informe um e-mail válido.';
}

if ($phone !== '') {
    $phoneDigits = preg_replace('/\D+/', '', $phone) ?? '';

    if (strlen($phoneDigits) < 10 || strlen($phoneDigits) > 11) {
        $errors['phone'] = 'Informe um telefone válido com DDD.';
    }
}

if (mb_strlen($company) > 160) {
    $errors['company'] = 'O nome da empresa é muito longo.';
}

$allowedProfiles = [
    'empresa' => 'Empresa',
    'pessoa-fisica' => 'Pessoa física',
];

if (!array_key_exists($profile, $allowedProfiles)) {
    $errors['profile'] = 'Selecione um perfil válido.';
}

$allowedSubjects = [
    'proposta-empresarial' => 'Solicitar proposta para empresa',
    'palestras' => 'Palestras e workshops',
    'treinamentos' => 'Treinamentos e jornadas',
    'recursos' => 'Recursos para você',
    'parceria' => 'Parcerias',
    'suporte' => 'Suporte',
    'outro' => 'Outro assunto',
];

if (!array_key_exists($subject, $allowedSubjects)) {
    $errors['subject'] = 'Selecione um assunto válido.';
}

if (mb_strlen($message) < 20 || mb_strlen($message) > 1000) {
    $errors['message'] = 'A mensagem deve ter entre 20 e 1000 caracteres.';
}

if (!$privacyAccepted) {
    $errors['privacy'] = 'É necessário concordar com a Política de Privacidade.';
}

if ($errors !== []) {
    respond(422, [
        'success' => false,
        'message' => 'Verifique os dados informados.',
        'errors' => $errors,
    ]);
}

$clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$rateLimitFile = sys_get_temp_dir() . '/eva-contact-' . hash('sha256', $clientIp) . '.lock';
$now = time();
$lastRequest = is_file($rateLimitFile) ? (int) file_get_contents($rateLimitFile) : 0;

if ($lastRequest > 0 && ($now - $lastRequest) < 15) {
    respond(429, [
        'success' => false,
        'message' => 'Aguarde alguns segundos antes de enviar outra mensagem.',
    ]);
}

@file_put_contents($rateLimitFile, (string) $now, LOCK_EX);

$apiKey = getenv('RESEND_API_KEY') ?: '';
$fromEmail = getenv('RESEND_FROM_EMAIL') ?: 'EVA Site <site@evaglobal.com.br>';
$toEmail = getenv('CONTACT_TO_EMAIL') ?: 'contato@evaglobal.com.br';

if ($apiKey === '') {
    $privateConfigPath = dirname(__DIR__, 2) . '/eva-private/resend.php';

    if (is_file($privateConfigPath)) {
        $privateConfig = require $privateConfigPath;

        if (is_array($privateConfig)) {
            $apiKey = (string) ($privateConfig['api_key'] ?? '');
            $fromEmail = (string) ($privateConfig['from'] ?? $fromEmail);
            $toEmail = (string) ($privateConfig['to'] ?? $toEmail);
        }
    }
}

if ($apiKey === '') {
    error_log('EVA contato: RESEND_API_KEY não configurada.');

    respond(503, [
        'success' => false,
        'message' => 'O envio de mensagens está temporariamente indisponível.',
    ]);
}

if (!function_exists('curl_init')) {
    error_log('EVA contato: extensão cURL não disponível no PHP.');

    respond(503, [
        'success' => false,
        'message' => 'O envio de mensagens está temporariamente indisponível.',
    ]);
}

$subjectLabel = $allowedSubjects[$subject];
$profileLabel = $allowedProfiles[$profile];

$safeName = escape_html($name);
$safeEmail = escape_html($email);
$safePhone = escape_html($phone !== '' ? $phone : 'Não informado');
$safeCompany = escape_html($company !== '' ? $company : 'Não informado');
$safeProfile = escape_html($profileLabel);
$safeSubject = escape_html($subjectLabel);
$safeMessage = nl2br(escape_html($message));

$emailHtml = <<<HTML
<!doctype html>
<html lang="pt-BR">
  <body style="margin:0;padding:24px;background:#f6f8ff;font-family:Arial,sans-serif;color:#07143f;">
    <div style="max-width:680px;margin:0 auto;background:#ffffff;border-radius:20px;padding:32px;border:1px solid #e5e9f5;">
      <p style="margin:0 0 8px;color:#ff5e48;font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;">Novo contato pelo site EVA</p>
      <h1 style="margin:0 0 24px;font-size:24px;">{$safeSubject}</h1>

      <table role="presentation" style="width:100%;border-collapse:collapse;margin-bottom:24px;">
        <tr><td style="padding:8px 0;font-weight:700;width:150px;">Nome</td><td style="padding:8px 0;">{$safeName}</td></tr>
        <tr><td style="padding:8px 0;font-weight:700;">E-mail</td><td style="padding:8px 0;">{$safeEmail}</td></tr>
        <tr><td style="padding:8px 0;font-weight:700;">Telefone</td><td style="padding:8px 0;">{$safePhone}</td></tr>
        <tr><td style="padding:8px 0;font-weight:700;">Empresa</td><td style="padding:8px 0;">{$safeCompany}</td></tr>
        <tr><td style="padding:8px 0;font-weight:700;">Perfil</td><td style="padding:8px 0;">{$safeProfile}</td></tr>
      </table>

      <div style="padding:20px;border-radius:14px;background:#f7f9ff;line-height:1.7;">{$safeMessage}</div>

      <p style="margin:24px 0 0;color:#64708f;font-size:13px;line-height:1.6;">
        Para responder ao contato, utilize o botão Responder do seu cliente de e-mail. O endereço do visitante foi configurado como Reply-To.
      </p>
    </div>
  </body>
</html>
HTML;

$emailText = "Novo contato pelo site EVA\n\n"
    . "Assunto: {$subjectLabel}\n"
    . "Nome: {$name}\n"
    . "E-mail: {$email}\n"
    . "Telefone: " . ($phone !== '' ? $phone : 'Não informado') . "\n"
    . "Empresa: " . ($company !== '' ? $company : 'Não informado') . "\n"
    . "Perfil: {$profileLabel}\n\n"
    . "Mensagem:\n{$message}";

$resendPayload = [
    'from' => $fromEmail,
    'to' => [$toEmail],
    'reply_to' => $email,
    'subject' => '[Site EVA] ' . $subjectLabel . ' — ' . $name,
    'html' => $emailHtml,
    'text' => $emailText,
];

$curl = curl_init('https://api.resend.com/emails');

curl_setopt_array($curl, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/json',
        'User-Agent: EVA-Website/1.0',
    ],
    CURLOPT_POSTFIELDS => json_encode($resendPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
]);

$responseBody = curl_exec($curl);
$curlError = curl_error($curl);
$statusCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
curl_close($curl);

if ($responseBody === false || $curlError !== '') {
    error_log('EVA contato: falha cURL ao chamar Resend: ' . $curlError);

    respond(502, [
        'success' => false,
        'message' => 'Não foi possível enviar sua mensagem agora. Tente novamente em instantes.',
    ]);
}

$responseData = json_decode($responseBody, true);

if ($statusCode < 200 || $statusCode >= 300) {
    error_log('EVA contato: Resend respondeu HTTP ' . $statusCode . ': ' . $responseBody);

    respond(502, [
        'success' => false,
        'message' => 'Não foi possível enviar sua mensagem agora. Tente novamente em instantes.',
    ]);
}

respond(200, [
    'success' => true,
    'message' => 'Mensagem enviada com sucesso. A equipe da EVA entrará em contato em breve.',
    'id' => is_array($responseData) ? ($responseData['id'] ?? null) : null,
]);
