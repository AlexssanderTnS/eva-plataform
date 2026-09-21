<?php

declare(strict_types=1);

require __DIR__ . '/../../config/security.php';
require __DIR__ . '/../../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;

evaApplyApiSecurityHeaders();
header('Content-Type: application/json; charset=UTF-8');

function recoveryResponse(): never
{
    echo json_encode(['success' => true, 'message' => 'Se houver uma conta elegível para este e-mail, você receberá um link válido por 30 minutos. Confira também o spam.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    evaSecurityJsonResponse(405, 'Método não permitido.');
}
evaEnforceTrustedOrigin();
evaEnforceJsonRequest(4096);
evaAssertRateLimit('password-recovery-ip', evaClientIp(), 10, 3600);
evaRecordRateLimitHit('password-recovery-ip', evaClientIp(), 3600);
$input = json_decode(file_get_contents('php://input'), true);
$email = is_array($input) && is_string($input['email'] ?? null) ? trim($input['email']) : '';
if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    evaSecurityJsonResponse(422, 'Informe um e-mail válido.');
}
$key = hash('sha256', strtolower($email));
evaAssertRateLimit('password-recovery-email', $key, 3, 3600);
evaRecordRateLimitHit('password-recovery-email', $key, 3600);

try {
    $pdo = require __DIR__ . '/../../config/database.php';
    $pdo->beginTransaction();
    $query = $pdo->prepare('SELECT id, first_name, email, status, session_version FROM users WHERE email = :email LIMIT 1 FOR UPDATE');
    $query->execute(['email' => $email]);
    $user = $query->fetch();
    if (!$user || $user['status'] !== 'active') {
        $pdo->rollBack();
        recoveryResponse();
    }
    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $query = $pdo->prepare('INSERT INTO password_reset_tokens (user_id, token_hash, session_version, expires_at)
        VALUES (:user_id, :token_hash, :session_version, DATE_ADD(CURRENT_TIMESTAMP, INTERVAL 30 MINUTE))
        ON DUPLICATE KEY UPDATE token_hash = VALUES(token_hash), session_version = VALUES(session_version), expires_at = VALUES(expires_at), created_at = CURRENT_TIMESTAMP');
    $query->execute(['user_id' => $user['id'], 'token_hash' => $tokenHash, 'session_version' => $user['session_version']]);
    $pdo->commit();
    $app = require __DIR__ . '/../../config/app.php';
    $url = rtrim($app['base_url'], '/') . '/recuperar-senha.html#token=' . $token;
    $mailConfig = require __DIR__ . '/../../config/mail.local.php';
    $mail = new PHPMailer(true);
    $mail->CharSet = 'UTF-8';
    $mail->isSMTP();
    $mail->Host = $mailConfig['host'];
    $mail->SMTPAuth = true;
    $mail->Username = $mailConfig['username'];
    $mail->Password = $mailConfig['password'];
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    $mail->Port = $mailConfig['port'];
    $mail->Timeout = 15;
    $mail->setFrom($mailConfig['from_email'], $mailConfig['from_name']);
    $mail->addAddress($user['email'], $user['first_name']);
    $mail->Subject = 'Recuperação de senha - EVA';
    $safeUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
    $mail->isHTML(true);
    $mail->Body = '<div style="font-family:Arial,sans-serif;color:#2A1F6F;max-width:600px;margin:auto;padding:24px"><h1>Recuperar sua senha</h1><p>Recebemos uma solicitação para redefinir a senha da sua conta EVA.</p><p><a style="display:inline-block;background:#2A1F6F;color:#fff;padding:14px 24px;border-radius:12px" href="' . $safeUrl . '">Criar nova senha</a></p><p>O link é válido por 30 minutos e só pode ser usado uma vez. Um novo pedido substitui o link anterior.</p><p>Se você não solicitou esta alteração, ignore este e-mail. Sua senha permanece igual.</p></div>';
    $mail->AltBody = "Redefina sua senha EVA: {$url}\n\nLink válido por 30 minutos, de uso único. Se não solicitou, ignore este e-mail.";
    $mail->send();
} catch (Throwable $error) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('EVA Auth: falha ao processar recuperação de senha.');
}
recoveryResponse();
