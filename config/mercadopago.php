<?php

declare(strict_types=1);

$configPath = __DIR__ . '/mercadopago.local.php';

if (!file_exists($configPath)) {
    throw new RuntimeException(
        'Arquivo de configuração do Mercado Pago não encontrado.'
    );
}

$config = require $configPath;

if (!is_array($config)) {
    throw new RuntimeException(
        'Configuração do Mercado Pago inválida.'
    );
}

$requiredFields = [
    'environment',
    'access_token',
    'currency',
];

foreach ($requiredFields as $field) {
    if (
        !isset($config[$field]) ||
        !is_string($config[$field]) ||
        trim($config[$field]) === ''
    ) {
        throw new RuntimeException(
            sprintf(
                'Configuração obrigatória do Mercado Pago ausente: %s.',
                $field
            )
        );
    }
}

$environment = strtolower(trim($config['environment']));

if (!in_array($environment, ['test', 'production'], true)) {
    throw new RuntimeException(
        'Ambiente do Mercado Pago inválido.'
    );
}

$currency = strtoupper(trim($config['currency']));

if ($currency !== 'BRL') {
    throw new RuntimeException(
        'Moeda configurada para o Mercado Pago não suportada.'
    );
}

$appConfig = require __DIR__ . '/app.php';
$expectedEnvironment = $appConfig['environment'] === 'production'
    ? 'production'
    : 'test';

if ($environment !== $expectedEnvironment) {
    throw new RuntimeException(
        'Ambiente do Mercado Pago não corresponde ao ambiente da aplicação.'
    );
}

$defaultBaseUrl = rtrim((string) $appConfig['base_url'], '/');

$baseUrl = isset($config['base_url'])
    ? rtrim(trim((string) $config['base_url']), '/')
    : $defaultBaseUrl;

if (
    !filter_var($baseUrl, FILTER_VALIDATE_URL) ||
    strtolower((string) parse_url($baseUrl, PHP_URL_SCHEME)) !== 'https'
) {
    throw new RuntimeException(
        'URL base configurada para o Mercado Pago é inválida.'
    );
}

if (!hash_equals(strtolower($defaultBaseUrl), strtolower($baseUrl))) {
    throw new RuntimeException(
        'URL base do Mercado Pago não corresponde ao ambiente da aplicação.'
    );
}

return [
    'environment' => $environment,
    'access_token' => trim($config['access_token']),
    'webhook_secret' => isset($config['webhook_secret'])
        ? trim((string) $config['webhook_secret'])
        : '',
    'currency' => $currency,
    'base_url' => $baseUrl,
];
