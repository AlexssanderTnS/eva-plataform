<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;

$pdo = require __DIR__ . '/../config/database.php';
$appConfig = require __DIR__ . '/../config/app.php';

const EVA_PURCHASE_EMAIL_LIMIT = 10;
const EVA_PURCHASE_EMAIL_MAX_ATTEMPTS = 8;

function evaLoadMailConfig(): array
{
    $path = __DIR__ . '/../config/mail.local.php';

    if (!is_file($path)) {
        throw new RuntimeException('Configuração SMTP não encontrada.');
    }

    $config = require $path;

    if (!is_array($config)) {
        throw new RuntimeException('Configuração SMTP inválida.');
    }

    foreach ([
        'host',
        'port',
        'username',
        'password',
        'from_email',
        'from_name',
    ] as $key) {
        if (!array_key_exists($key, $config) || trim((string) $config[$key]) === '') {
            throw new RuntimeException('Configuração SMTP incompleta.');
        }
    }

    return $config;
}

function evaQueueMissingPurchaseEmailJobs(PDO $pdo): void
{
    $pdo->exec(
        "
        INSERT INTO purchase_email_jobs (order_id, status)
        SELECT id, 'pending'
        FROM orders
        WHERE status = 'paid'
        ON DUPLICATE KEY UPDATE
            order_id = VALUES(order_id)
        "
    );
}

function evaRecoverStalePurchaseEmailJobs(PDO $pdo): void
{
    $pdo->exec(
        "
        UPDATE purchase_email_jobs
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

function evaClaimPurchaseEmailJob(PDO $pdo): ?array
{
    $pdo->beginTransaction();

    try {
        $statement = $pdo->query(
            "
            SELECT *
            FROM purchase_email_jobs
            WHERE
                status IN ('pending', 'failed')
                AND attempts < " . EVA_PURCHASE_EMAIL_MAX_ATTEMPTS . "
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
            UPDATE purchase_email_jobs
            SET
                status = 'processing',
                attempts = attempts + 1,
                started_at = CURRENT_TIMESTAMP,
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

function evaLoadPurchaseEmailContext(PDO $pdo, int $jobId): array
{
    $statement = $pdo->prepare(
        "
        SELECT
            j.id AS job_id,
            j.order_id,
            j.attempts,
            o.status AS order_status,
            o.amount,
            o.currency,
            o.paid_at,
            u.first_name,
            u.last_name,
            u.email,
            c.title AS course_title,
            ca.status AS access_status
        FROM purchase_email_jobs j
        INNER JOIN orders o
            ON o.id = j.order_id
        INNER JOIN users u
            ON u.id = o.user_id
        INNER JOIN courses c
            ON c.id = o.course_id
        LEFT JOIN course_access ca
            ON ca.user_id = o.user_id
            AND ca.course_id = o.course_id
            AND ca.order_id = o.id
        WHERE j.id = :job_id
        LIMIT 1
        "
    );
    $statement->execute(['job_id' => $jobId]);

    $context = $statement->fetch();

    if ($context === false) {
        throw new RuntimeException('Contexto do e-mail de compra não foi encontrado.');
    }

    return $context;
}

function evaFinishPurchaseEmailJob(
    PDO $pdo,
    int $jobId,
    string $status,
    ?string $errorMessage = null,
    ?string $availableAt = null
): void {
    if (!in_array($status, ['sent', 'failed', 'ignored'], true)) {
        throw new InvalidArgumentException('Status final de e-mail inválido.');
    }

    $statement = $pdo->prepare(
        "
        UPDATE purchase_email_jobs
        SET
            status = :status,
            sent_at = CASE
                WHEN :is_sent = 1 THEN CURRENT_TIMESTAMP
                ELSE sent_at
            END,
            available_at = COALESCE(:available_at, available_at),
            last_error = :last_error,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = :id
        "
    );
    $statement->execute([
        'status' => $status,
        'is_sent' => $status === 'sent' ? 1 : 0,
        'available_at' => $availableAt,
        'last_error' => $errorMessage,
        'id' => $jobId,
    ]);
}

function evaSendPurchaseConfirmationEmail(
    array $mailConfig,
    array $appConfig,
    array $context
): void {
    $firstName = trim((string) $context['first_name']);
    $lastName = trim((string) $context['last_name']);
    $email = trim((string) $context['email']);
    $courseTitle = trim((string) $context['course_title']);
    $currency = strtoupper(trim((string) $context['currency']));
    $amount = (float) $context['amount'];
    $accessStatus = (string) ($context['access_status'] ?? '');

    if ($firstName === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        throw new RuntimeException('Destinatário do e-mail de compra é inválido.');
    }

    if ($courseTitle === '' || $currency !== 'BRL' || $amount < 0) {
        throw new RuntimeException('Dados da compra são inválidos para o e-mail.');
    }

    $accountUrl = rtrim((string) $appConfig['base_url'], '/') . '/conta.html';
    $formattedAmount = 'R$ ' . number_format($amount, 2, ',', '.');

    $safeFirstName = htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8');
    $safeCourseTitle = htmlspecialchars($courseTitle, ENT_QUOTES, 'UTF-8');
    $safeAmount = htmlspecialchars($formattedAmount, ENT_QUOTES, 'UTF-8');
    $safeAccountUrl = htmlspecialchars($accountUrl, ENT_QUOTES, 'UTF-8');

    $accessMessage = $accessStatus === 'active'
        ? 'Seu curso já está disponível na área Meus Cursos.'
        : 'Seu pagamento foi confirmado e a liberação do curso está sendo concluída.';

    $safeAccessMessage = htmlspecialchars($accessMessage, ENT_QUOTES, 'UTF-8');

    $mail = new PHPMailer(true);
    $mail->CharSet = 'UTF-8';
    $mail->isSMTP();
    $mail->Host = (string) $mailConfig['host'];
    $mail->SMTPAuth = true;
    $mail->Username = (string) $mailConfig['username'];
    $mail->Password = (string) $mailConfig['password'];
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    $mail->Port = (int) $mailConfig['port'];
    $mail->setFrom(
        (string) $mailConfig['from_email'],
        (string) $mailConfig['from_name']
    );
    $mail->addAddress($email, trim($firstName . ' ' . $lastName));
    $mail->Subject = 'Pagamento confirmado - EVA';
    $mail->isHTML(true);

    $mail->Body = <<<HTML
<!doctype html>
<html lang="pt-BR">
<body style="margin:0;padding:24px;background:#f5f7fb;font-family:Arial,Helvetica,sans-serif;color:#1d2440;">
<div style="max-width:640px;margin:0 auto;background:#fff;border-radius:18px;padding:32px;border:1px solid #e7eaf3;">
<p style="margin:0 0 8px;color:#5f46d8;font-size:13px;font-weight:bold;text-transform:uppercase;letter-spacing:.08em;">EVA</p>
<h1 style="margin:0 0 18px;font-size:26px;">Pagamento confirmado</h1>
<p style="line-height:1.7;">Olá, {$safeFirstName}!</p>
<p style="line-height:1.7;">Recebemos a confirmação do seu pagamento. Obrigado por escolher a EVA.</p>
<div style="margin:24px 0;padding:20px;border-radius:14px;background:#f7f6ff;border:1px solid #e8e3ff;">
<p style="margin:0 0 8px;font-size:13px;color:#667085;">Curso</p>
<p style="margin:0 0 16px;font-size:18px;font-weight:bold;">{$safeCourseTitle}</p>
<p style="margin:0 0 8px;font-size:13px;color:#667085;">Valor confirmado</p>
<p style="margin:0;font-size:18px;font-weight:bold;">{$safeAmount}</p>
</div>
<p style="line-height:1.7;">{$safeAccessMessage}</p>
<p style="margin:30px 0;text-align:center;"><a href="{$safeAccountUrl}" style="display:inline-block;padding:14px 24px;border-radius:10px;background:#2a1f6f;color:#fff;text-decoration:none;font-weight:bold;">Acessar meus cursos</a></p>
<p style="line-height:1.7;color:#667085;">Se precisar de ajuda com a compra ou solicitar um reembolso, entre em contato pelo e-mail <strong>contato@evaglobal.com.br</strong>.</p>
</div>
</body>
</html>
HTML;

    $mail->AltBody =
        "Olá, {$firstName}!\n\n" .
        "Seu pagamento foi confirmado.\n" .
        "Curso: {$courseTitle}\n" .
        "Valor: {$formattedAmount}\n\n" .
        "{$accessMessage}\n\n" .
        "Acesse seus cursos em: {$accountUrl}\n\n" .
        "Suporte e reembolso: contato@evaglobal.com.br";

    $mail->send();
}

$mailConfig = evaLoadMailConfig();

evaQueueMissingPurchaseEmailJobs($pdo);
evaRecoverStalePurchaseEmailJobs($pdo);

$processed = 0;
$failed = 0;

for ($index = 0; $index < EVA_PURCHASE_EMAIL_LIMIT; $index++) {
    $job = evaClaimPurchaseEmailJob($pdo);

    if ($job === null) {
        break;
    }

    $jobId = (int) $job['id'];

    try {
        $context = evaLoadPurchaseEmailContext($pdo, $jobId);

        if ((string) $context['order_status'] !== 'paid') {
            evaFinishPurchaseEmailJob($pdo, $jobId, 'ignored');
            $processed++;
            continue;
        }

        evaSendPurchaseConfirmationEmail($mailConfig, $appConfig, $context);
        evaFinishPurchaseEmailJob($pdo, $jobId, 'sent');
        $processed++;
    } catch (Throwable $error) {
        $failed++;

        $attempt = max(1, (int) ($job['attempts'] ?? 1));
        $retryMinutes = min(60, 2 ** min($attempt, 6));
        $availableAt = (new DateTimeImmutable(
            '+' . $retryMinutes . ' minutes'
        ))->format('Y-m-d H:i:s');

        $safeMessage = substr(trim($error->getMessage()), 0, 1000);

        evaFinishPurchaseEmailJob(
            $pdo,
            $jobId,
            'failed',
            $safeMessage !== '' ? $safeMessage : 'Falha não especificada.',
            $availableAt
        );

        error_log(sprintf(
            'EVA purchase email job %d falhou: %s',
            $jobId,
            $safeMessage
        ));
    }
}

fwrite(
    STDOUT,
    sprintf(
        "Purchase confirmation emails: %d processado(s), %d falha(s).\n",
        $processed,
        $failed
    )
);

exit($failed > 0 ? 1 : 0);
