<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

header('Content-Type: application/json; charset=UTF-8');

const MIN_FORM_FILL_SECONDS = 3;
const RATE_LIMIT_WINDOW_SECONDS = 600;
const RATE_LIMIT_MAX_REQUESTS = 3;

function sendJsonResponse(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function applyRateLimit(string $clientIp): void
{
    $rateLimitDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'eva-contact-rate-limit';

    if (!is_dir($rateLimitDirectory) && !mkdir($rateLimitDirectory, 0700, true) && !is_dir($rateLimitDirectory)) {
        error_log('EVA contact rate limit: não foi possível criar o diretório temporário.');
        sendJsonResponse(503, [
            'success' => false,
            'message' => 'Serviço temporariamente indisponível. Tente novamente mais tarde.'
        ]);
    }

    $clientKey = hash('sha256', $clientIp);
    $rateLimitFile = $rateLimitDirectory . DIRECTORY_SEPARATOR . $clientKey . '.json';
    $handle = fopen($rateLimitFile, 'c+');

    if ($handle === false || !flock($handle, LOCK_EX)) {
        if (is_resource($handle)) {
            fclose($handle);
        }

        error_log('EVA contact rate limit: não foi possível bloquear o arquivo temporário.');
        sendJsonResponse(503, [
            'success' => false,
            'message' => 'Serviço temporariamente indisponível. Tente novamente mais tarde.'
        ]);
    }

    $now = time();
    $contents = stream_get_contents($handle);
    $timestamps = json_decode($contents !== false ? $contents : '', true);
    $timestamps = is_array($timestamps) ? $timestamps : [];
    $timestamps = array_values(array_filter(
        $timestamps,
        static function ($timestamp) use ($now): bool {
            return is_int($timestamp) && $timestamp > $now - RATE_LIMIT_WINDOW_SECONDS;
        }
    ));

    if (count($timestamps) >= RATE_LIMIT_MAX_REQUESTS) {
        $retryAfter = max(1, RATE_LIMIT_WINDOW_SECONDS - ($now - $timestamps[0]));
        flock($handle, LOCK_UN);
        fclose($handle);
        header('Retry-After: ' . $retryAfter);
        sendJsonResponse(429, [
            'success' => false,
            'message' => 'Muitas tentativas. Aguarde alguns minutos antes de tentar novamente.'
        ]);
    }

    $timestamps[] = $now;
    rewind($handle);
    ftruncate($handle, 0);
    fwrite($handle, json_encode($timestamps));
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);
}



if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);

    echo json_encode([
        'success' => false,
        'message' => 'Método não permitido.'
    ], JSON_UNESCAPED_UNICODE);

    exit;
}



$input = json_decode(
    file_get_contents('php://input'),
    true
);

if (!is_array($input)) {
    http_response_code(400);

    echo json_encode([
        'success' => false,
        'message' => 'Dados inválidos.'
    ], JSON_UNESCAPED_UNICODE);

    exit;
}


$name = trim((string) ($input['name'] ?? ''));
$email = trim((string) ($input['email'] ?? ''));
$phone = trim((string) ($input['phone'] ?? ''));
$company = trim((string) ($input['company'] ?? ''));
$profile = trim((string) ($input['profile'] ?? ''));
$subject = trim((string) ($input['subject'] ?? ''));
$message = trim((string) ($input['message'] ?? ''));
$website = trim((string) ($input['website'] ?? ''));
$formStartedAt = filter_var(
    $input['form_started_at'] ?? null,
    FILTER_VALIDATE_INT
);

$privacy = filter_var(
    $input['privacy'] ?? false,
    FILTER_VALIDATE_BOOLEAN
);


if ($website !== '') {
    sendJsonResponse(200, [
        'success' => true,
        'message' => 'Mensagem enviada com sucesso.'
    ]);
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

$allowedProfiles = [
    'empresa',
    'pessoa-fisica'
];

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

if ($name === '') {
    $errors['name'] = 'Informe seu nome.';
} elseif (mb_strlen($name) < 2) {
    $errors['name'] = 'Informe um nome válido.';
} elseif (mb_strlen($name) > 120) {
    $errors['name'] = 'O nome informado é muito longo.';
}

if ($email === '') {
    $errors['email'] = 'Informe seu e-mail.';
} elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors['email'] = 'Informe um e-mail válido.';
} elseif (mb_strlen($email) > 254) {
    $errors['email'] = 'O e-mail informado é muito longo.';
}

if ($phone !== '' && mb_strlen($phone) > 30) {
    $errors['phone'] = 'Informe um telefone válido.';
}

if ($company !== '' && mb_strlen($company) > 150) {
    $errors['company'] = 'O nome da empresa é muito longo.';
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
    $errors['privacy'] =
        'Você precisa aceitar a Política de Privacidade.';
}

if ($errors !== []) {
    http_response_code(422);

    echo json_encode([
        'success' => false,
        'message' => 'Verifique os campos informados.',
        'errors' => $errors
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

applyRateLimit((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));


$configPath = __DIR__ . '/../config/mail.local.php';

if (!file_exists($configPath)) {
    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' => 'Configuração de e-mail indisponível.'
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

$config = require $configPath;


$safeName = htmlspecialchars(
    $name,
    ENT_QUOTES,
    'UTF-8'
);

$safeEmail = htmlspecialchars(
    $email,
    ENT_QUOTES,
    'UTF-8'
);

$safePhone = htmlspecialchars(
    $phone !== '' ? $phone : 'Não informado',
    ENT_QUOTES,
    'UTF-8'
);

$safeCompany = htmlspecialchars(
    $company !== '' ? $company : 'Não informado',
    ENT_QUOTES,
    'UTF-8'
);

$safeMessage = nl2br(
    htmlspecialchars(
        $message,
        ENT_QUOTES,
        'UTF-8'
    )
);

$profileLabel =
    $profileLabels[$profile] ?? 'Não informado';

$subjectLabel =
    $subjectLabels[$subject] ?? 'Contato pelo site';



$mail = new PHPMailer(true);

try {
    $mail->CharSet = 'UTF-8';

    $mail->isSMTP();

    $mail->Host = $config['host'];
    $mail->SMTPAuth = true;

    $mail->Username = $config['username'];
    $mail->Password = $config['password'];

    $mail->SMTPSecure =
        PHPMailer::ENCRYPTION_SMTPS;

    $mail->Port = $config['port'];


    $mail->setFrom(
        $config['from_email'],
        $config['from_name']
    );


    $mail->addAddress(
        $config['to_email']
    );



    $mail->addReplyTo(
        $email,
        $name
    );



    $mail->Subject =
        'Novo contato pelo site EVA - ' .
        $subjectLabel;



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
            margin:0 0 8px;
            font-size:24px;
        ">
            Novo contato pelo site EVA
        </h1>

        <p style="
            margin:0 0 28px;
            color:#667085;
        ">
            Uma nova mensagem foi enviada pelo formulário do site.
        </p>

        <table style="
            width:100%;
            border-collapse:collapse;
            margin-bottom:28px;
        ">
            <tr>
                <td style="padding:8px 0;font-weight:bold;">
                    Nome
                </td>
                <td style="padding:8px 0;">
                    {$safeName}
                </td>
            </tr>

            <tr>
                <td style="padding:8px 0;font-weight:bold;">
                    E-mail
                </td>
                <td style="padding:8px 0;">
                    {$safeEmail}
                </td>
            </tr>

            <tr>
                <td style="padding:8px 0;font-weight:bold;">
                    Telefone
                </td>
                <td style="padding:8px 0;">
                    {$safePhone}
                </td>
            </tr>

            <tr>
                <td style="padding:8px 0;font-weight:bold;">
                    Empresa
                </td>
                <td style="padding:8px 0;">
                    {$safeCompany}
                </td>
            </tr>

            <tr>
                <td style="padding:8px 0;font-weight:bold;">
                    Perfil
                </td>
                <td style="padding:8px 0;">
                    {$profileLabel}
                </td>
            </tr>

            <tr>
                <td style="padding:8px 0;font-weight:bold;">
                    Assunto
                </td>
                <td style="padding:8px 0;">
                    {$subjectLabel}
                </td>
            </tr>
        </table>

        <div style="
            padding:20px;
            border-radius:14px;
            background:#f8f9fc;
        ">
            <strong>Mensagem</strong>

            <p style="
                line-height:1.7;
                margin-bottom:0;
            ">
                {$safeMessage}
            </p>
        </div>
    </div>
</body>
</html>
HTML;


    $mail->AltBody =
        "Novo contato pelo site EVA\n\n" .
        "Nome: {$name}\n" .
        "E-mail: {$email}\n" .
        "Telefone: " .
        ($phone !== '' ? $phone : 'Não informado') .
        "\n" .
        "Empresa: " .
        ($company !== '' ? $company : 'Não informado') .
        "\n" .
        "Perfil: {$profileLabel}\n" .
        "Assunto: {$subjectLabel}\n\n" .
        "Mensagem:\n{$message}";


    $mail->send();

    echo json_encode([
        'success' => true,
        'message' =>
            'Mensagem enviada com sucesso.'
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $error) {


    error_log(
        'Erro SMTP EVA: ' .
        $mail->ErrorInfo
    );

    http_response_code(500);

    echo json_encode([
        'success' => false,
        'message' =>
            'Não foi possível enviar sua mensagem. Tente novamente mais tarde.'
    ], JSON_UNESCAPED_UNICODE);
}
