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

$requiredFields = ['environment','public_key','access_token','currency',
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

return [
    'environment' => $environment,

 
    'public_key' => trim($config['public_key']),

   
    'access_token' => trim($config['access_token']),

 
    'webhook_secret' => isset($config['webhook_secret'])
        ? trim((string) $config['webhook_secret'])
        : '',

    'currency' => $currency,
];
