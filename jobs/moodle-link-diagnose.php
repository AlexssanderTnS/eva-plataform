<?php

declare(strict_types=1);

// Diagnostico somente leitura: execute via Cron Jobs/cPanel e remova a tarefa depois.
// Nao exibe e-mails, nomes, senhas, tokens ou IDs internos do Moodle.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$evaUserId = filter_var($argv[1] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($evaUserId === false || $evaUserId === null) {
    fwrite(STDERR, "Uso: php jobs/moodle-link-diagnose.php <id-usuario-eva>\n");
    exit(2);
}

try {
    $pdo = require __DIR__ . '/../config/database.php';
    $client = require __DIR__ . '/../config/moodle-client.php';
    require_once __DIR__ . '/../config/moodle-service.php';

    $stmt = $pdo->prepare(
        'SELECT id, email, status, email_verified_at, moodle_user_id
         FROM users WHERE id = :id LIMIT 1'
    );
    $stmt->execute(['id' => $evaUserId]);
    $eva = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($eva === false) {
        throw new RuntimeException('Usuario EVA nao encontrado.');
    }

    $email = strtolower(trim((string) $eva['email']));
    $result = $client->call(
        'core_user_get_users_by_field',
        ['field' => 'username', 'values' => [$email]]
    );

    if (!is_array($result)) {
        throw new RuntimeException('Resposta inesperada do Web Service.');
    }

    echo 'eva_ativa=' . ($eva['status'] === 'active' ? 'sim' : 'nao') . PHP_EOL;
    echo 'eva_email_verificado=' . ($eva['email_verified_at'] !== null ? 'sim' : 'nao') . PHP_EOL;
    echo 'contas_por_username=' . count($result) . PHP_EOL;

    if (count($result) !== 1 || !is_array($result[0])) {
        echo "resultado=NAO_VINCULAR: consulta ambigua ou conta ausente\n";
        exit(0);
    }

    $moodle = $result[0];
    $moodleId = (int) ($moodle['id'] ?? 0);
    $username = strtolower(trim((string) ($moodle['username'] ?? '')));
    $wsEmail = array_key_exists('email', $moodle)
        ? strtolower(trim((string) $moodle['email'])) : null;
    $auth = array_key_exists('auth', $moodle)
        ? trim((string) $moodle['auth']) : null;
    $suspended = array_key_exists('suspended', $moodle)
        ? $moodle['suspended'] : null;
    $idnumber = array_key_exists('idnumber', $moodle)
        ? trim((string) $moodle['idnumber']) : null;

    echo 'moodle_id_valido=' . ($moodleId > 0 ? 'sim' : 'nao') . PHP_EOL;
    echo 'username_corresponde=' . ($username === $email ? 'sim' : 'nao') . PHP_EOL;
    echo 'email_ws=' . ($wsEmail === null ? 'AUSENTE' : (
        $wsEmail === '' ? 'VAZIO' : ($wsEmail === $email ? 'CORRESPONDE' : 'DIFERENTE')
    )) . PHP_EOL;
    echo 'auth_ws=' . ($auth === null ? 'AUSENTE' : (
        $auth === 'db' ? 'DB' : 'OUTRO'
    )) . PHP_EOL;
    echo 'suspended_ws=' . ($suspended === null ? 'AUSENTE' : (
        (string) (int) $suspended === '0' ? 'NAO' : 'SIM_OU_INVALIDO'
    )) . PHP_EOL;
    echo 'idnumber_ws=' . ($idnumber === null ? 'AUSENTE' : (
        $idnumber === '' ? 'VAZIO' : (
            $idnumber === evaMoodleIdNumber((int) $evaUserId)
                ? 'EVA_CORRETO' : 'OUTRO'
        )
    )) . PHP_EOL;
    echo 'vinculo_local=' . (
        $eva['moodle_user_id'] === null ? 'VAZIO' :
        ((int) $eva['moodle_user_id'] === $moodleId ? 'MESMO_ID' : 'OUTRO_ID')
    ) . PHP_EOL;

    if ($moodleId > 0) {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM users
             WHERE moodle_user_id = :moodle_id AND id <> :eva_id'
        );
        $stmt->execute(['moodle_id' => $moodleId, 'eva_id' => $evaUserId]);
        echo 'vinculado_a_outro_eva=' .
            ((int) $stmt->fetchColumn() > 0 ? 'sim' : 'nao') . PHP_EOL;
    }
} catch (Throwable $error) {
    error_log('Diagnostico de vinculo Moodle falhou: ' . $error->getMessage());
    fwrite(STDERR, "Diagnostico falhou; verifique o log privado do servidor.\n");
    exit(1);
}
