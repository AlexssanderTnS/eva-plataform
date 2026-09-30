<?php

declare(strict_types=1);

function evaMoodleIdNumber(int $evaUserId): string
{
    if ($evaUserId <= 0) {
        throw new InvalidArgumentException('ID de usuário EVA inválido.');
    }

    return 'eva:' . $evaUserId;
}

function evaMoodleUsername(int $evaUserId): string
{
    if ($evaUserId <= 0) {
        throw new InvalidArgumentException('ID de usuário EVA inválido.');
    }

    return 'eva_' . $evaUserId;
}

function evaMoodleFindUserByEvaId(
    EvaMoodleClient $client,
    int $evaUserId
): ?array {
    $result = $client->call(
        'core_user_get_users_by_field',
        [
            'field' => 'idnumber',
            'values' => [evaMoodleIdNumber($evaUserId)],
        ]
    );

    if (!is_array($result)) {
        throw new RuntimeException(
            'Resposta inesperada ao consultar usuário no Moodle.'
        );
    }

    if ($result === []) {
        return null;
    }

    // A consulta por idnumber filtra no Moodle, mesmo quando o Web Service
    // omite o proprio campo na resposta. Validar se ele estiver disponivel.
    if (count($result) !== 1) {
        throw new RuntimeException('Consulta Moodle por identificador ambigua.');
    }

    $user = $result[0] ?? null;

    if (
        !is_array($user) ||
        (int) ($user['id'] ?? 0) <= 0 ||
        (
            array_key_exists('idnumber', $user) &&
            !hash_equals(
                evaMoodleIdNumber($evaUserId),
                (string) $user['idnumber']
            )
        )
    ) {
        throw new RuntimeException(
            'Usuário retornado pelo Moodle possui identidade inconsistente.'
        );
    }

    return $user;
}

/**
 * Vincula uma conta criada pela autenticacao externa somente quando ela
 * corresponde de maneira inequivoca ao usuario ativo e verificado da EVA.
 */
function evaMoodleAdoptExternalDbUser(
    EvaMoodleClient $client,
    PDO $pdo,
    array $evaUser
): ?int {
    $evaUserId = (int) ($evaUser['id'] ?? 0);
    $email = strtolower(trim((string) ($evaUser['email'] ?? '')));

    if ($evaUserId <= 0 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        throw new InvalidArgumentException('Usuario EVA invalido para vinculo Moodle.');
    }

    $statement = $pdo->prepare(
        "SELECT id, moodle_user_id
         FROM users
         WHERE id = :id
           AND LOWER(email) = :email
           AND status = 'active'
           AND email_verified_at IS NOT NULL
         LIMIT 1"
    );
    $statement->execute(['id' => $evaUserId, 'email' => $email]);
    $evaAccount = $statement->fetch(PDO::FETCH_ASSOC);

    if ($evaAccount === false) {
        throw new RuntimeException(
            'Conta EVA nao esta ativa ou nao possui e-mail verificado.'
        );
    }

    $byUsername = $client->call(
        'core_user_get_users_by_field',
        ['field' => 'username', 'values' => [$email]]
    );

    if (!is_array($byUsername)) {
        throw new RuntimeException('Resposta inesperada ao consultar username no Moodle.');
    }

    if ($byUsername === []) {
        return null;
    }

    if (count($byUsername) !== 1 || !is_array($byUsername[0])) {
        throw new RuntimeException('Conta Moodle por e-mail possui resultado ambiguo.');
    }

    $candidate = $byUsername[0];
    $moodleId = (int) ($candidate['id'] ?? 0);
    $candidateUsername = strtolower(trim((string) ($candidate['username'] ?? '')));
    $candidateEmail = strtolower(trim((string) ($candidate['email'] ?? '')));
    $candidateIdNumber = trim((string) ($candidate['idnumber'] ?? ''));

    // Nao assumir que e-mails iguais comprovam identidade.
    // A API deve confirmar explicitamente o metodo de autenticacao.
    // Diagnostico sem expor identificadores ou dados pessoais em last_error.
    if ($moodleId <= 0) {
        throw new RuntimeException('Vinculo Moodle: identificador numerico ausente.');
    }
    if ($candidateUsername !== $email) {
        throw new RuntimeException('Vinculo Moodle: username nao corresponde ao email EVA.');
    }
    // O Moodle pode ocultar o e-mail no Web Service. Nesse caso, o
    // username e a autenticacao db (configurada contra a visao EVA)
    // sao a referencia; um e-mail explicitamente divergente bloqueia.
    if (array_key_exists('email', $candidate) && $candidateEmail !== $email) {
        throw new RuntimeException('Vinculo Moodle: email retornado nao corresponde ao email EVA.');
    }
    if (!array_key_exists('auth', $candidate)) {
        throw new RuntimeException('Vinculo Moodle: campo auth ausente no Web Service.');
    }
    if ((string) $candidate['auth'] !== 'db') {
        throw new RuntimeException('Vinculo Moodle: metodo de autenticacao nao corresponde a db.');
    }
    if (!array_key_exists('suspended', $candidate)) {
        throw new RuntimeException('Vinculo Moodle: campo suspended ausente no Web Service.');
    }
    if ((int) $candidate['suspended'] !== 0) {
        throw new RuntimeException('Vinculo Moodle: conta suspensa ou status invalido.');
    }

    $savedMoodleId = $evaAccount['moodle_user_id'] !== null
        ? (int) $evaAccount['moodle_user_id']
        : null;

    if ($savedMoodleId !== null && $savedMoodleId !== $moodleId) {
        throw new RuntimeException('Conta EVA ja vinculada a outro usuario Moodle.');
    }

    $statement = $pdo->prepare(
        'SELECT id FROM users WHERE moodle_user_id = :moodle_id AND id <> :eva_id LIMIT 1'
    );
    $statement->execute(['moodle_id' => $moodleId, 'eva_id' => $evaUserId]);

    if ($statement->fetch() !== false) {
        throw new RuntimeException('Conta Moodle ja vinculada a outro usuario EVA.');
    }

    $expectedIdNumber = evaMoodleIdNumber($evaUserId);

    if ($candidateIdNumber !== '' && $candidateIdNumber !== $expectedIdNumber) {
        throw new RuntimeException('Conta Moodle possui identificador de outra conta.');
    }

    // A ausencia de idnumber na API nao autoriza sobrescrever esse campo:
    // ele pode estar oculto em uma conta preexistente. No modo db, manter
    // username/e-mail como chave, e gravar o ID numerico em users apos
    // a matricula bem-sucedida (jobs/moodle-provision.php).
    return $moodleId;
}

/**
 * Localiza por username uma conta db ja vinculada na EVA, para revogacao
 * e exclusao. O ID numerico gravado e obrigatorio quando disponivel.
 */
function evaMoodleFindLinkedDbUserByEmail(
    EvaMoodleClient $client,
    string $email,
    int $evaUserId,
    ?int $savedMoodleId
): ?array {
    $email = strtolower(trim($email));
    if ($evaUserId <= 0 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        throw new InvalidArgumentException('Dados invalidos para consulta Moodle.');
    }

    $result = $client->call(
        'core_user_get_users_by_field',
        ['field' => 'username', 'values' => [$email]]
    );
    if (!is_array($result) || count($result) > 1) {
        throw new RuntimeException('Consulta Moodle por username invalida ou ambigua.');
    }
    if ($result === []) {
        return null;
    }

    $user = $result[0];
    $moodleId = is_array($user) ? (int) ($user['id'] ?? 0) : 0;
    if (
        !is_array($user) ||
        $moodleId <= 0 ||
        strtolower(trim((string) ($user['username'] ?? ''))) !== $email ||
        !isset($user['auth']) || (string) $user['auth'] !== 'db' ||
        (array_key_exists('email', $user) &&
            strtolower(trim((string) $user['email'])) !== $email) ||
        (array_key_exists('idnumber', $user) &&
            trim((string) $user['idnumber']) !== '' &&
            trim((string) $user['idnumber']) !== evaMoodleIdNumber($evaUserId)) ||
        ($savedMoodleId !== null && $moodleId !== $savedMoodleId)
    ) {
        throw new RuntimeException('Conta Moodle nao corresponde ao vinculo EVA.');
    }

    return $user;
}

function evaMoodleCreateUser(
    EvaMoodleClient $client,
    array $evaUser,
    string $authMode = 'manual'
): array {
    $evaUserId = (int) ($evaUser['id'] ?? 0);
    $firstName = trim((string) ($evaUser['first_name'] ?? ''));
    $lastName = trim((string) ($evaUser['last_name'] ?? ''));
    $email = strtolower(trim((string) ($evaUser['email'] ?? '')));

    if (
        $evaUserId <= 0 ||
        $firstName === '' ||
        $lastName === '' ||
        filter_var($email, FILTER_VALIDATE_EMAIL) === false
    ) {
        throw new InvalidArgumentException(
            'Dados do usuário EVA inválidos para criação no Moodle.'
        );
    }

    if (!in_array($authMode, ['manual', 'db'], true)) {
        throw new InvalidArgumentException('Modo de autenticação Moodle inválido.');
    }

    // No modo db, o username deve coincidir com o e-mail consultado na EVA.
    // Nunca reutilizar uma conta Moodle preexistente só por coincidência de e-mail.
    if ($authMode === 'db') {
        $sameUsername = $client->call(
            'core_user_get_users_by_field',
            [
                'field' => 'username',
                'values' => [$email],
            ]
        );

        if (!is_array($sameUsername)) {
            throw new RuntimeException('Resposta inesperada ao consultar username no Moodle.');
        }

        if ($sameUsername !== []) {
            throw new RuntimeException(
                'Já existe uma conta Moodle com esse e-mail como username. ' .
                'Verifique o vínculo antes de criar um novo usuário.'
            );
        }
    }

    $randomPassword = bin2hex(random_bytes(24)) . 'Aa1!';

    $result = $client->call(
        'core_user_create_users',
        [
            'users' => [[
                'username' => $authMode === 'db' ? $email : evaMoodleUsername($evaUserId),
                'password' => $randomPassword,
                'firstname' => $firstName,
                'lastname' => $lastName,
                'email' => $email,
                'idnumber' => evaMoodleIdNumber($evaUserId),
                'auth' => $authMode,
                'lang' => 'pt_br',
                'country' => 'BR',
            ]],
        ]
    );

    $created = is_array($result) ? ($result[0] ?? null) : null;

    if (
        !is_array($created) ||
        (int) ($created['id'] ?? 0) <= 0
    ) {
        throw new RuntimeException(
            'Moodle não confirmou a criação do usuário.'
        );
    }

    return [
        'id' => (int) $created['id'],
        'username' => (string) ($created['username'] ?? (
            $authMode === 'db' ? $email : evaMoodleUsername($evaUserId)
        )),
        'idnumber' => evaMoodleIdNumber($evaUserId),
    ];
}

function evaMoodleUpdateUser(
    EvaMoodleClient $client,
    int $moodleUserId,
    array $evaUser
): void {
    $firstName = trim((string) ($evaUser['first_name'] ?? ''));
    $lastName = trim((string) ($evaUser['last_name'] ?? ''));
    $email = strtolower(trim((string) ($evaUser['email'] ?? '')));
    $evaUserId = (int) ($evaUser['id'] ?? 0);

    if (
        $moodleUserId <= 0 ||
        $evaUserId <= 0 ||
        $firstName === '' ||
        $lastName === '' ||
        filter_var($email, FILTER_VALIDATE_EMAIL) === false
    ) {
        throw new InvalidArgumentException(
            'Dados inválidos para atualização do usuário Moodle.'
        );
    }

    $client->call(
        'core_user_update_users',
        [
            'users' => [[
                'id' => $moodleUserId,
                'firstname' => $firstName,
                'lastname' => $lastName,
                'email' => $email,
                'idnumber' => evaMoodleIdNumber($evaUserId),
            ]],
        ]
    );
}

function evaMoodleEnsureUser(
    EvaMoodleClient $client,
    array $evaUser,
    string $authMode = 'manual',
    ?PDO $pdo = null
): int {
    $evaUserId = (int) ($evaUser['id'] ?? 0);
    $existing = evaMoodleFindUserByEvaId($client, $evaUserId);

    if ($existing !== null) {
        $moodleUserId = (int) $existing['id'];

        if ($authMode === 'db') {
            $email = strtolower(trim((string) ($evaUser['email'] ?? '')));
            $existingUsername = strtolower(trim((string) ($existing['username'] ?? '')));
            // Uma conta legada precisa ser migrada e validada primeiro.
            // Nunca a recriar nem trocar username/auth silenciosamente no cron.
            if ($existingUsername !== $email || !isset($existing['auth'])
                || (string) $existing['auth'] !== 'db') {
                throw new RuntimeException(
                    'Conta Moodle existente requer migracao validada para auth db.'
                );
            }
        }

        evaMoodleUpdateUser($client, $moodleUserId, $evaUser);

        return $moodleUserId;
    }

    if ($authMode === 'db' && $pdo !== null) {
        $adoptedId = evaMoodleAdoptExternalDbUser($client, $pdo, $evaUser);
        if ($adoptedId !== null) {
            // A conta do banco externo ja existe e e gerida pelo Moodle.
            // Nao escrever email/idnumber quando a API oculta os campos.
            return $adoptedId;
        }
    }

    $created = evaMoodleCreateUser($client, $evaUser, $authMode);

    return (int) $created['id'];
}

function evaMoodleValidateCourse(
    EvaMoodleClient $client,
    int $moodleCourseId
): array {
    if ($moodleCourseId <= 1) {
        throw new InvalidArgumentException(
            'ID de curso Moodle inválido.'
        );
    }

    $result = $client->call(
        'core_course_get_courses_by_field',
        [
            'field' => 'id',
            'value' => (string) $moodleCourseId,
        ]
    );

    $courses = is_array($result)
        ? ($result['courses'] ?? null)
        : null;

    if (!is_array($courses) || count($courses) !== 1) {
        throw new RuntimeException(
            'Curso configurado não foi encontrado no Moodle.'
        );
    }

    $course = $courses[0];

    if (
        !is_array($course) ||
        (int) ($course['id'] ?? 0) !== $moodleCourseId
    ) {
        throw new RuntimeException(
            'Moodle retornou um curso diferente do solicitado.'
        );
    }

    return $course;
}

function evaMoodleEnrolUser(
    EvaMoodleClient $client,
    int $moodleUserId,
    int $moodleCourseId,
    int $studentRoleId
): void {
    if (
        $moodleUserId <= 0 ||
        $moodleCourseId <= 1 ||
        $studentRoleId <= 0
    ) {
        throw new InvalidArgumentException(
            'Dados inválidos para matrícula no Moodle.'
        );
    }

    $client->call(
        'enrol_manual_enrol_users',
        [
            'enrolments' => [[
                'roleid' => $studentRoleId,
                'userid' => $moodleUserId,
                'courseid' => $moodleCourseId,
            ]],
        ]
    );
}

function evaMoodleUnenrolUser(
    EvaMoodleClient $client,
    int $moodleUserId,
    int $moodleCourseId
): void {
    if ($moodleUserId <= 0 || $moodleCourseId <= 1) {
        throw new InvalidArgumentException(
            'Dados inválidos para desmatrícula no Moodle.'
        );
    }

    $client->call(
        'enrol_manual_unenrol_users',
        [
            'enrolments' => [[
                'userid' => $moodleUserId,
                'courseid' => $moodleCourseId,
            ]],
        ]
    );
}
