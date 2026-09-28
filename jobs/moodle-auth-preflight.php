<?php

declare(strict_types=1);

// Diagnostico somente leitura. NAO migra contas nem altera matriculas.
// Execucao: php jobs/moodle-auth-preflight.php (em ambiente de homologacao).
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$pdo = require __DIR__ . '/../config/database.php';
$client = require __DIR__ . '/../config/moodle-client.php';
require_once __DIR__ . '/../config/moodle-service.php';

$statement = $pdo->query(
    'SELECT id, email, moodle_user_id, status, email_verified_at
     FROM users ORDER BY id ASC'
);

$total = 0;
$issues = 0;
$linked = 0;
$unlinked = 0;

while ($user = $statement->fetch(PDO::FETCH_ASSOC)) {
    ++$total;
    $evaId = (int) $user['id'];
    $email = strtolower(trim((string) $user['email']));
    $savedMoodleId = (int) ($user['moodle_user_id'] ?? 0);

    try {
        $byIdNumber = $client->call(
            'core_user_get_users_by_field',
            ['field' => 'idnumber', 'values' => [evaMoodleIdNumber($evaId)]]
        );
        $byUsername = $client->call(
            'core_user_get_users_by_field',
            ['field' => 'username', 'values' => [$email]]
        );
        if (!is_array($byIdNumber) || !is_array($byUsername)) {
            throw new RuntimeException('Consulta Moodle retornou resultado inesperado.');
        }

        if (count($byIdNumber) > 1 || count($byUsername) > 1) {
            throw new RuntimeException('Identificador ou username Moodle duplicado.');
        }

        $existing = $byIdNumber[0] ?? null;
        $usernameMatch = $byUsername[0] ?? null;

        if ($existing === null) {
            ++$unlinked;
            if ($savedMoodleId > 0 || $usernameMatch !== null) {
                ++$issues;
                echo "CONFLITO EVA ID {$evaId}: referencia Moodle ausente ou username ocupado.\n";
            } else {
                echo "SEM CONTA EVA ID {$evaId}: novo provisionamento necessario.\n";
            }
            continue;
        }

        ++$linked;
        $moodleId = (int) ($existing['id'] ?? 0);
        $existingUsername = strtolower(trim((string) ($existing['username'] ?? '')));
        $existingAuth = trim((string) ($existing['auth'] ?? ''));
        $existingIdNumber = (string) ($existing['idnumber'] ?? '');

        $conflict = $moodleId <= 0
            || $existingIdNumber !== evaMoodleIdNumber($evaId)
            || ($savedMoodleId > 0 && $savedMoodleId !== $moodleId)
            || ($usernameMatch !== null && (int) ($usernameMatch['id'] ?? 0) !== $moodleId);

        if ($conflict) {
            ++$issues;
            echo "CONFLITO EVA ID {$evaId}: revisar vinculo/username antes de migrar.\n";
            continue;
        }

        if ($existingUsername !== $email || $existingAuth !== 'db') {
            echo "MIGRACAO PENDENTE EVA ID {$evaId}: username/auth ainda nao correspondem a db.\n";
        } else {
            echo "PRONTO EVA ID {$evaId}: username/auth compativeis.\n";
        }

        if ($user['status'] !== 'active' || $user['email_verified_at'] === null) {
            echo "ATENCAO EVA ID {$evaId}: conta nao apta a login pela visao eva_moodle.\n";
        }
    } catch (Throwable $e) {
        ++$issues;
        error_log('Preflight Moodle, EVA ID ' . $evaId . ': ' . $e->getMessage());
        echo "ERRO EVA ID {$evaId}: verifique o log interno.\n";
    }
}

echo sprintf(
    "RESUMO: total=%d vinculados=%d sem_conta=%d conflitos/erros=%d\n",
    $total, $linked, $unlinked, $issues
);

exit($issues === 0 ? 0 : 1);
