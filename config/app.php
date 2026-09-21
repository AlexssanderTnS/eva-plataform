<?php

declare(strict_types=1);

$environmentOverride = strtolower(trim((string) getenv('EVA_APP_ENV')));

if ($environmentOverride !== '' && !in_array($environmentOverride, ['production', 'staging', 'development'], true)) {
    throw new RuntimeException('Ambiente da aplicação inválido.');
}

$requestHost = strtolower(trim((string) ($_SERVER['HTTP_HOST'] ?? '')));
$requestHost = preg_replace('/:\\d+$/', '', $requestHost) ?? $requestHost;

if ($environmentOverride !== 'development' && PHP_SAPI !== 'cli') {
    $allowedHosts = [
        'evaglobal.com.br',
        'www.evaglobal.com.br',
        'staging.evaglobal.com.br',
    ];

    if (!in_array($requestHost, $allowedHosts, true)) {
        throw new RuntimeException('Host da aplicação inválido.');
    }
}

if ($environmentOverride !== '') {
    $environment = $environmentOverride;
} elseif ($requestHost === 'staging.evaglobal.com.br') {
    $environment = 'staging';
} else {
    $environment = 'production';
}

if ($environment === 'production') {
    $baseUrl = 'https://evaglobal.com.br';
    $allowedOrigins = [
        'https://evaglobal.com.br',
        'https://www.evaglobal.com.br',
    ];
} elseif ($environment === 'staging') {
    $baseUrl = 'https://staging.evaglobal.com.br';
    $allowedOrigins = [
        'https://staging.evaglobal.com.br',
    ];
} else {
    $baseUrlOverride = trim((string) getenv('EVA_APP_BASE_URL'));
    $baseUrl = $baseUrlOverride !== '' ? rtrim($baseUrlOverride, '/') : 'http://localhost';
    $allowedOrigins = [$baseUrl];
}

if (filter_var($baseUrl, FILTER_VALIDATE_URL) === false) {
    throw new RuntimeException('URL base da aplicação inválida.');
}

return [
    'environment' => $environment,
    'base_url' => $baseUrl,
    'allowed_origins' => $allowedOrigins,
    'session_directory' => 'eva_sessions_' . $environment,
];
