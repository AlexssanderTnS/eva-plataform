<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/security.php';

evaApplyApiSecurityHeaders();

$method = strtoupper(
    trim((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'))
);

if ($method !== 'GET') {
    header('Allow: GET');

    evaSecurityJsonResponse(
        405,
        'Método não permitido.'
    );
}

try {
    $pdo = require __DIR__ . '/../config/database.php';

    $statement = $pdo->query(
        "
        SELECT
            slug,
            title,
            price,
            currency
        FROM courses
        WHERE status = 'active'
        ORDER BY id ASC
        "
    );

    $courses = $statement->fetchAll();

    $courses = array_map(
        static function (array $course): array {
            return [
                'slug' => (string) $course['slug'],
                'title' => (string) $course['title'],
                'price' => (string) $course['price'],
                'currency' => (string) $course['currency'],
            ];
        },
        $courses
    );

    http_response_code(200);

    header(
        'Content-Type: application/json; charset=UTF-8'
    );

    echo json_encode(
        [
            'success' => true,
            'courses' => $courses,
        ],
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_THROW_ON_ERROR
    );
} catch (Throwable $exception) {
    error_log(
        'EVA Courses API: falha ao carregar catálogo.'
    );

    evaSecurityJsonResponse(
        500,
        'Não foi possível carregar os cursos.'
    );
}