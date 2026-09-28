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

    $user = $result[0] ?? null;

    if (
        !is_array($user) ||
        (int) ($user['id'] ?? 0) <= 0 ||
        !hash_equals(
            evaMoodleIdNumber($evaUserId),
            (string) ($user['idnumber'] ?? '')
        )
    ) {
        throw new RuntimeException(
            'Usuário retornado pelo Moodle possui identidade inconsistente.'
        );
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
    string $authMode = 'manual'
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
