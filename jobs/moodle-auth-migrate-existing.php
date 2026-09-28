<?php

declare(strict_types=1);

/**
 * Migracao pontual de usuario legado, somente apos homologacao conjunta.
 *
 * Padrao: simulacao (sem alteracoes).
 * php jobs/moodle-auth-migrate-existing.php --eva-user-id=123
 *
 * Aplicacao: EXCLUSIVAMENTE apos o suporte Moodle confirmar que
 * core_user_update_users da versao instalada aceita username E auth.
 * EVA_MOODLE_AUTH_MIGRATION_APPROVED=YES php jobs/moodle-auth-migrate-existing.php --eva-user-id=123 --apply
 *
 * Nao executar em producao enquanto houver conexao MySQL sem protecao TLS
 * ou sem arquitetura alternativa HTTPS/tunel aprovada.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$options = getopt('', ['eva-user-id:', 'apply']);
$userIdValue = (string) ($options['eva-user-id'] ?? '');
if (!ctype_digit($userIdValue) || (int) $userIdValue <= 0) {
    fwrite(STDERR, "Informe --eva-user-id=NUMERO (um usuario por execucao).\n");
    exit(2);
}

$apply = array_key_exists('apply', $options);
if ($apply && getenv('EVA_MOODLE_AUTH_MIGRATION_APPROVED') !== 'YES') {
    fwrite(STDERR, "Execucao bloqueada: autorizacao explicita ausente.\n");
    exit(2);
}

$pdo = require __DIR__ . '/../config/database.php';
$moodleConfig = require __DIR__ . '/../config/moodle.php';
$client = require __DIR__ . '/../config/moodle-client.php';
require_once __DIR__ . '/../config/moodle-service.php';

if ($apply && ($moodleConfig['auth_mode'] ?? '') !== 'db') {
    fwrite(STDERR, "Execucao bloqueada: auth_mode db nao esta habilitado.\n");
    exit(2);
}

$statement = $pdo->prepare(
    'SELECT id, email, moodle_user_id, status, email_verified_at
     FROM users WHERE id = :id LIMIT 1'
);
$statement->execute(['id' => (int) $userIdValue]);
$user = $statement->fetch(PDO::FETCH_ASSOC);

if (!is_array($user)) {
    fwrite(STDERR, "Usuario EVA nao encontrado.\n");
    exit(1);
}

$evaUserId = (int) $user['id'];
$email = strtolower(trim((string) $user['email']));
$storedMoodleId = (int) ($user['moodle_user_id'] ?? 0);
if ($user['status'] !== 'active' || $user['email_verified_at'] === null
    || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
    fwrite(STDERR, "Usuario EVA nao apto para autenticacao externa.\n");
    exit(1);
}

try {
    $linked = evaMoodleFindUserByEvaId($client, $evaUserId);
    if ($linked === null) {
        throw new RuntimeException('Nao existe vinculo Moodle por idnumber; revisao manual necessaria.');
    }

    $moodleId = (int) $linked['id'];
    if ($storedMoodleId > 0 && $storedMoodleId !== $moodleId) {
        throw new RuntimeException('moodle_user_id local difere da conta Moodle vinculada.');
    }

    // Nunca vincular outra conta apenas porque o email coincide.
    $byUsername = $client->call(
        'core_user_get_users_by_field',
        ['field' => 'username', 'values' => [$email]]
    );
    if (!is_array($byUsername) || count($byUsername) > 1) {
        throw new RuntimeException('Resultado Moodle invalido/duplicado para username.');
    }
    if ($byUsername !== [] && (int) ($byUsername[0]['id'] ?? 0) !== $moodleId) {
        throw new RuntimeException('Username de destino pertence a outra conta Moodle.');
    }

    $currentUsername = strtolower(trim((string) ($linked['username'] ?? '')));
    if ($currentUsername !== $email &&
        $currentUsername !== evaMoodleUsername($evaUserId)) {
        throw new RuntimeException('Username legado inesperado; revisao manual necessaria.');
    }

    // Algumas versoes do Moodle nao devolvem auth nesta funcao WS.
    // Sem saber o auth atual nao e seguro alterar a conta automaticamente.
    if (!array_key_exists('auth', $linked)) {
        throw new RuntimeException(
            'O Web Service nao retornou auth; Thiago deve confirmar via administrador Moodle.'
        );
    }

    $currentAuth = (string) $linked['auth'];
    if (!in_array($currentAuth, ['manual', 'db'], true)) {
        throw new RuntimeException('Metodo de autenticacao inesperado; revisao manual necessaria.');
    }

    if ($currentUsername === $email && $currentAuth === 'db') {
        echo "EVA {$evaUserId}: conta ja adaptada; IDs e matriculas preservados.\n";
        exit(0);
    }

    echo "EVA {$evaUserId}: alteracao proposta para mesmo Moodle ID {$moodleId}: username=email, auth=db.\n";
    if (!$apply) {
        echo "SIMULACAO: nenhuma alteracao executada.\n";
        exit(0);
    }

    // Nao cria conta nova, nao altera idnumber, matriculas ou IDs.
    // A compatibilidade do parametro auth deve ter sido confirmada
    // na documentacao WS da INSTALACAO pelo suporte do Moodle.
    $client->call('core_user_update_users', [
        'users' => [[
            'id' => $moodleId,
            'username' => $email,
            'auth' => 'db',
        ]],
    ]);

    $after = evaMoodleFindUserByEvaId($client, $evaUserId);
    if ($after === null || (int) $after['id'] !== $moodleId
        || strtolower(trim((string) ($after['username'] ?? ''))) !== $email
        || !isset($after['auth']) || (string) $after['auth'] !== 'db') {
        throw new RuntimeException(
            'Verificacao apos alteracao incompleta. Revisar no Moodle antes de repetir.'
        );
    }

    echo "EVA {$evaUserId}: alteracao verificada no mesmo Moodle ID.\n";
} catch (Throwable $error) {
    error_log('Migracao auth Moodle EVA ' . $evaUserId . ': ' . $error->getMessage());
    fwrite(STDERR, "MIGRACAO INTERROMPIDA: revise logs e conta no Moodle.\n");
    exit(1);
}
