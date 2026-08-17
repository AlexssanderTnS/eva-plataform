<?php

declare(strict_types=1);

require __DIR__ . '/../../config/security.php';

evaApplyApiSecurityHeaders();

function sendJsonResponse(
    int $status,
    array $payload
): never {
    http_response_code($status);

    header(
        'Content-Type: application/json; charset=UTF-8'
    );

    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_THROW_ON_ERROR
    );

    exit;
}

if (
    strtoupper(
        trim(
            (string) ($_SERVER['REQUEST_METHOD'] ?? '')
        )
    ) !== 'POST'
) {
    header('Allow: POST');

    sendJsonResponse(
        405,
        [
            'success' => false,
            'message' => 'Método não permitido.',
        ]
    );
}

/*
 * Este endpoint altera estado no sistema.
 *
 * Portanto:
 * - exige origem EVA;
 * - exige JSON;
 * - exige sessão autenticada.
 */
evaEnforceTrustedOrigin();
evaEnforceJsonRequest(2048);

try {
    require __DIR__ . '/../../config/session.php';
} catch (Throwable $error) {
    error_log(
        'EVA Orders: falha ao iniciar sessão: ' .
        $error->getMessage()
    );

    sendJsonResponse(
        500,
        [
            'success' => false,
            'message' =>
                'Não foi possível iniciar o pedido.',
        ]
    );
}

$userId = $_SESSION['user_id'] ?? null;

if (
    !is_int($userId) &&
    !ctype_digit((string) $userId)
) {
    sendJsonResponse(
        401,
        [
            'success' => false,
            'message' => 'Não autenticado.',
        ]
    );
}

$userId = (int) $userId;

/*
 * Proteção contra criação abusiva de pedidos.
 *
 * Mesmo usuário:
 * máximo de 10 tentativas em 10 minutos.
 *
 * Mesmo IP:
 * máximo de 30 tentativas em 10 minutos.
 */
$rateLimitWindow = 600;

evaAssertRateLimit(
    'order-create-user',
    (string) $userId,
    10,
    $rateLimitWindow
);

evaAssertRateLimit(
    'order-create-ip',
    evaClientIp(),
    30,
    $rateLimitWindow
);

try {
    $rawInput = file_get_contents('php://input');

    $input = json_decode(
        $rawInput !== false ? $rawInput : '',
        true,
        512,
        JSON_THROW_ON_ERROR
    );
} catch (JsonException $error) {
    sendJsonResponse(
        400,
        [
            'success' => false,
            'message' => 'JSON inválido.',
        ]
    );
}

if (!is_array($input)) {
    sendJsonResponse(
        400,
        [
            'success' => false,
            'message' => 'Dados inválidos.',
        ]
    );
}

$courseSlug = trim(
    (string) ($input['course'] ?? '')
);

if (
    $courseSlug === '' ||
    strlen($courseSlug) > 120 ||
    !preg_match(
        '/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
        $courseSlug
    )
) {
    sendJsonResponse(
        422,
        [
            'success' => false,
            'message' =>
                'Curso informado é inválido.',
        ]
    );
}

/*
 * Registramos a tentativa depois que:
 *
 * - há sessão válida;
 * - o corpo é JSON válido;
 * - existe um slug sintaticamente válido.
 */
evaRecordRateLimitHit(
    'order-create-user',
    (string) $userId,
    $rateLimitWindow
);

evaRecordRateLimitHit(
    'order-create-ip',
    evaClientIp(),
    $rateLimitWindow
);

$pdo = null;

try {
    $pdo = require __DIR__ .
        '/../../config/database.php';

    $pdo->beginTransaction();

    /*
     * Não confiamos somente no fato de existir
     * user_id na sessão.
     *
     * Confirmamos novamente que:
     * - a conta existe;
     * - está ativa;
     * - possui e-mail confirmado.
     */
    $statement = $pdo->prepare(
        "
        SELECT
            status,
            email_verified_at
        FROM users
        WHERE id = :id
        LIMIT 1
        "
    );

    $statement->execute([
        'id' => $userId,
    ]);

    $user = $statement->fetch();

    if ($user === false) {
        $pdo->rollBack();

        evaDestroySession();

        sendJsonResponse(
            401,
            [
                'success' => false,
                'message' => 'Não autenticado.',
            ]
        );
    }

    if (
        $user['status'] !== 'active' ||
        $user['email_verified_at'] === null
    ) {
        $pdo->rollBack();

        evaDestroySession();

        sendJsonResponse(
            403,
            [
                'success' => false,
                'message' =>
                    'Esta conta não está disponível para compras.',
            ]
        );
    }

    /*
     * Fonte de verdade do produto.
     *
     * O navegador manda apenas o slug.
     *
     * Preço, moeda e ID interno vêm do banco.
     */
    $statement = $pdo->prepare(
        "
        SELECT
            id,
            slug,
            title,
            price,
            currency,
            status
        FROM courses
        WHERE slug = :slug
        LIMIT 1
        "
    );

    $statement->execute([
        'slug' => $courseSlug,
    ]);

    $course = $statement->fetch();

    if (
        $course === false ||
        $course['status'] !== 'active'
    ) {
        $pdo->rollBack();

        sendJsonResponse(
            404,
            [
                'success' => false,
                'message' =>
                    'Curso não encontrado ou indisponível.',
            ]
        );
    }

    $courseId = (int) $course['id'];
    $amount = (string) $course['price'];
    $currency = strtoupper(
        trim((string) $course['currency'])
    );

    /*
     * Uma configuração comercial inválida
     * deve bloquear a compra.
     *
     * Não tentamos "adivinhar" preço.
     */
    if (
        !preg_match(
            '/^\d{1,8}\.\d{2}$/',
            $amount
        ) ||
        $amount === '0.00'
    ) {
        throw new RuntimeException(
            'Curso possui preço inválido.'
        );
    }

    if ($currency !== 'BRL') {
        throw new RuntimeException(
            'Curso possui moeda não suportada.'
        );
    }

    /*
     * Se o usuário já possui acesso ativo
     * ou está aguardando liberação,
     * não permitimos nova compra.
     */
    $statement = $pdo->prepare(
        "
        SELECT
            status
        FROM course_access
        WHERE
            user_id = :user_id
            AND course_id = :course_id
        LIMIT 1
        FOR UPDATE
        "
    );

    $statement->execute([
        'user_id' => $userId,
        'course_id' => $courseId,
    ]);

    $existingAccess = $statement->fetch();

    if (
        $existingAccess !== false &&
        in_array(
            $existingAccess['status'],
            ['pending', 'active'],
            true
        )
    ) {
        $pdo->rollBack();

        sendJsonResponse(
            409,
            [
                'success' => false,
                'message' =>
                    'Você já possui este curso ou ele está em processo de liberação.',
            ]
        );
    }

    /*
     * Proteção contra duplo clique,
     * refresh ou repetição imediata.
     *
     * Se já existir pedido recente aberto
     * para o mesmo curso, reutilizamos.
     */
    $statement = $pdo->prepare(
        "
        SELECT
            external_reference,
            amount,
            currency,
            status
        FROM orders
        WHERE
            user_id = :user_id
            AND course_id = :course_id
            AND status IN (
                'created',
                'pending'
            )
            AND created_at >= (
                CURRENT_TIMESTAMP -
                INTERVAL 30 MINUTE
            )
        ORDER BY id DESC
        LIMIT 1
        FOR UPDATE
        "
    );

    $statement->execute([
        'user_id' => $userId,
        'course_id' => $courseId,
    ]);

    $existingOrder = $statement->fetch();

    if ($existingOrder !== false) {
        $pdo->commit();

        sendJsonResponse(
            200,
            [
                'success' => true,
                'reused' => true,
                'message' =>
                    'Pedido existente reutilizado.',
                'order' => [
                    'reference' =>
                        $existingOrder[
                            'external_reference'
                        ],
                    'status' =>
                        $existingOrder['status'],
                    'amount' =>
                        (string) $existingOrder[
                            'amount'
                        ],
                    'currency' =>
                        $existingOrder['currency'],
                    'course' => [
                        'slug' =>
                            $course['slug'],
                        'title' =>
                            $course['title'],
                    ],
                ],
            ]
        );
    }

    /*
     * Referência pública aleatória.
     *
     * Não expomos orders.id.
     *
     * Exemplo:
     *
     * eva_9e4c2c...
     */
    $externalReference =
        'eva_' . bin2hex(random_bytes(16));

    $statement = $pdo->prepare(
        "
        INSERT INTO orders (
            user_id,
            course_id,
            external_reference,
            amount,
            currency,
            status
        )
        VALUES (
            :user_id,
            :course_id,
            :external_reference,
            :amount,
            :currency,
            'created'
        )
        "
    );

    $statement->execute([
        'user_id' => $userId,
        'course_id' => $courseId,
        'external_reference' =>
            $externalReference,
        'amount' => $amount,
        'currency' => $currency,
    ]);

    $pdo->commit();

    sendJsonResponse(
        201,
        [
            'success' => true,
            'reused' => false,
            'message' =>
                'Pedido criado com sucesso.',
            'order' => [
                'reference' =>
                    $externalReference,
                'status' => 'created',
                'amount' => $amount,
                'currency' => $currency,
                'course' => [
                    'slug' =>
                        $course['slug'],
                    'title' =>
                        $course['title'],
                ],
            ],
        ]
    );
} catch (Throwable $error) {
    if (
        $pdo instanceof PDO &&
        $pdo->inTransaction()
    ) {
        $pdo->rollBack();
    }

    error_log(
        'EVA Orders: falha ao criar pedido: ' .
        $error->getMessage()
    );

    sendJsonResponse(
        500,
        [
            'success' => false,
            'message' =>
                'Não foi possível criar o pedido.',
        ]
    );
}