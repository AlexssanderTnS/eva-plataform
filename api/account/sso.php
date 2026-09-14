<?php

declare(strict_types=1);

require __DIR__ . '/../../config/security.php';

evaApplyApiSecurityHeaders();

header('Content-Type: application/json; charset=UTF-8');

try {
    require __DIR__ . '/../../config/session.php';
} catch (Throwable $error) {
    error_log(
        'EVA SSO: falha ao iniciar sessão: ' .
        $error->getMessage()
    );

    evaSecurityJsonResponse(
        500,
        'Não foi possível iniciar o acesso ao curso.'
    );
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    evaSecurityJsonResponse(
        405,
        'Método não permitido.'
    );
}

evaEnforceTrustedOrigin();
evaEnforceJsonRequest(4096);

$userId = $_SESSION['user_id'] ?? null;

if (
    !is_int($userId) &&
    !ctype_digit((string) $userId)
) {
    evaSecurityJsonResponse(
        401,
        'Não autenticado.'
    );
}

evaAssertRateLimit(
    'moodle-sso',
    (string) $userId,
    10,
    60
);

$rawBody = file_get_contents('php://input');

try {
    $payload = json_decode(
        (string) $rawBody,
        true,
        32,
        JSON_THROW_ON_ERROR
    );
} catch (JsonException) {
    evaSecurityJsonResponse(
        400,
        'Dados inválidos.'
    );
}

$slug = trim(
    (string) ($payload['slug'] ?? '')
);

if (
    $slug === '' ||
    !preg_match(
        '/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
        $slug
    )
) {
    evaSecurityJsonResponse(
        422,
        'Curso inválido.'
    );
}

try {
    $pdo = require __DIR__ .
        '/../../config/database.php';

    $statement = $pdo->prepare(
        '
        SELECT
            u.status AS user_status,
            u.email_verified_at,
            u.moodle_user_id,

            c.id AS course_id,
            c.moodle_course_id,

            ca.status AS access_status,

            o.status AS order_status

        FROM users u

        INNER JOIN course_access ca
            ON ca.user_id = u.id

        INNER JOIN courses c
            ON c.id = ca.course_id

        INNER JOIN orders o
            ON o.id = ca.order_id

        WHERE
            u.id = :user_id
            AND c.slug = :slug

        LIMIT 1
        '
    );

    $statement->execute([
        'user_id' => (int) $userId,
        'slug' => $slug,
    ]);

    $access = $statement->fetch();
} catch (Throwable $error) {
    error_log(
        'EVA SSO: erro ao validar acesso: ' .
        $error->getMessage()
    );

    evaSecurityJsonResponse(
        500,
        'Não foi possível validar seu acesso ao curso.'
    );
}

if ($access === false) {
    evaSecurityJsonResponse(
        404,
        'Curso não encontrado para esta conta.'
    );
}

if (
    ($access['user_status'] ?? null) !==
    'active'
) {
    evaDestroySession();

    evaSecurityJsonResponse(
        403,
        'Esta conta não está disponível para acesso.'
    );
}

if (
    ($access['email_verified_at'] ?? null)
    === null
) {
    evaDestroySession();

    evaSecurityJsonResponse(
        403,
        'Confirme seu e-mail antes de acessar o curso.'
    );
}

if (
    ($access['access_status'] ?? null) !==
    'active'
) {
    evaSecurityJsonResponse(
        403,
        'Este curso ainda não está liberado para sua conta.'
    );
}

if (
    ($access['order_status'] ?? null) !==
    'paid'
) {
    evaSecurityJsonResponse(
        403,
        'O pagamento deste curso não está ativo.'
    );
}

$moodleUserId = (int) (
    $access['moodle_user_id'] ?? 0
);

$moodleCourseId = (int) (
    $access['moodle_course_id'] ?? 0
);

if (
    $moodleUserId <= 0 ||
    $moodleCourseId <= 1
) {
    error_log(
        sprintf(
            'EVA SSO: vínculo Moodle incompleto ' .
            'para usuário %d e curso %d.',
            (int) $userId,
            (int) (
                $access['course_id'] ?? 0
            )
        )
    );

    evaSecurityJsonResponse(
        503,
        'Seu acesso ao ambiente de aprendizagem ainda está sendo preparado.'
    );
}

evaRecordRateLimitHit(
    'moodle-sso',
    (string) $userId,
    60
);

try {
    $moodleConfig = require __DIR__ .
        '/../../config/moodle.php';

    $moodleClient = require __DIR__ .
        '/../../config/moodle-client.php';

    $result = $moodleClient->call(
        'auth_userkey_request_login_url',
        [
            'user' => [
                'idnumber' =>
                    'eva:' . (int) $userId,
            ],
        ]
    );

    $loginUrl = is_array($result)
        ? trim(
            (string) (
                $result['loginurl'] ?? ''
            )
        )
        : '';

    if (
        $loginUrl === '' ||
        filter_var(
            $loginUrl,
            FILTER_VALIDATE_URL
        ) === false
    ) {
        throw new RuntimeException(
            'Moodle não retornou uma URL de login válida.'
        );
    }

    $moodleBaseUrl = rtrim(
        (string) $moodleConfig['base_url'],
        '/'
    );

    $loginParts = parse_url($loginUrl);
    $baseParts = parse_url($moodleBaseUrl);

    if (
        strtolower(
            (string) (
                $loginParts['scheme'] ?? ''
            )
        ) !== 'https' ||

        strtolower(
            (string) (
                $loginParts['host'] ?? ''
            )
        ) !== strtolower(
            (string) (
                $baseParts['host'] ?? ''
            )
        )
    ) {
        throw new RuntimeException(
            'Moodle retornou um destino inesperado.'
        );
    }

    $courseUrl =
        $moodleBaseUrl .
        '/course/view.php?id=' .
        $moodleCourseId;

    $separator =
        str_contains($loginUrl, '?')
            ? '&'
            : '?';

    $redirectUrl =
        $loginUrl .
        $separator .
        'wantsurl=' .
        rawurlencode($courseUrl);

} catch (Throwable $error) {
    error_log(
        'EVA SSO: falha ao gerar acesso Moodle: ' .
        $error->getMessage()
    );

    evaSecurityJsonResponse(
        502,
        'Não foi possível abrir o curso agora. Tente novamente em instantes.'
    );
}

echo json_encode(
    [
        'success' => true,
        'url' => $redirectUrl,
    ],
    JSON_UNESCAPED_UNICODE |
    JSON_UNESCAPED_SLASHES
);