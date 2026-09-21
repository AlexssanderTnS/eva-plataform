<?php

declare(strict_types=1);

require __DIR__ . '/../../config/security.php';

evaApplyApiSecurityHeaders();

try {
    require __DIR__ . '/../../config/session.php';
} catch (Throwable $error) {
    error_log('EVA Auth: falha ao iniciar sessão: ' . $error->getMessage());
    evaSecurityJsonResponse(500, 'Não foi possível cancelar a solicitação.');
}

header('Content-Type: application/json; charset=UTF-8');

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
evaEnforceJsonRequest(1024);

$userId = $_SESSION['user_id'] ?? null;

if (!is_int($userId) && !ctype_digit((string) $userId)) {
    sendJsonResponse(401, ['success' => false, 'message' => 'Não autenticado.']);
}

try {
    $pdo = require __DIR__ . '/../../config/database.php';
    $pdo->beginTransaction();

    $statement = $pdo->prepare(
        'SELECT status, scheduled_for
         FROM account_deletion_requests
         WHERE user_id = :user_id
         LIMIT 1
         FOR UPDATE'
    );
    $statement->execute(['user_id' => (int) $userId]);
    $request = $statement->fetch();

    if ($request === false || (string) $request['status'] !== 'pending') {
        $pdo->commit();
        sendJsonResponse(409, [
            'success' => false,
            'message' => 'Não existe uma exclusão pendente que possa ser cancelada.'
        ]);
    }

    if (
        $request['scheduled_for'] === null ||
        strtotime((string) $request['scheduled_for']) <= time()
    ) {
        $pdo->commit();
        sendJsonResponse(409, [
            'success' => false,
            'message' => 'O prazo para cancelar esta exclusão terminou.'
        ]);
    }

    $statement = $pdo->prepare(
        'UPDATE account_deletion_requests
         SET
            status = "cancelled",
            processed_at = CURRENT_TIMESTAMP
         WHERE user_id = :user_id AND status = "pending"'
    );
    $statement->execute(['user_id' => (int) $userId]);

    if ($statement->rowCount() !== 1) {
        throw new RuntimeException('A solicitação mudou durante o cancelamento.');
    }

    $pdo->commit();
} catch (Throwable $error) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log('EVA LGPD: erro ao cancelar exclusão: ' . $error->getMessage());
    sendJsonResponse(500, ['success' => false, 'message' => 'Não foi possível cancelar a solicitação.']);
}

sendJsonResponse(200, [
    'success' => true,
    'message' => 'A exclusão da sua conta foi cancelada.'
]);
