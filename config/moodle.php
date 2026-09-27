<?php

declare(strict_types=1);

$configPath = __DIR__ . '/moodle.local.php';

if (!file_exists($configPath)) {
    throw new RuntimeException(
        'Arquivo de configuração do Moodle não encontrado.'
    );
}

$config = require $configPath;

if (!is_array($config)) {
    throw new RuntimeException('Configuração do Moodle inválida.');
}

$baseUrl = rtrim(trim((string) ($config['base_url'] ?? '')), '/');
$token = trim((string) ($config['token'] ?? ''));
$studentRoleId = (int) ($config['student_role_id'] ?? 0);
$authMode = trim((string) ($config['auth_mode'] ?? 'manual'));

if (
    $baseUrl === '' ||
    filter_var($baseUrl, FILTER_VALIDATE_URL) === false ||
    strtolower((string) parse_url($baseUrl, PHP_URL_SCHEME)) !== 'https'
) {
    throw new RuntimeException('URL base do Moodle inválida.');
}

if ($token === '' || strlen($token) < 20) {
    throw new RuntimeException('Token do Moodle inválido.');
}

if ($studentRoleId <= 0) {
    throw new RuntimeException('ID do papel Estudante no Moodle inválido.');
}

if (!in_array($authMode, ['manual', 'db'], true)) {
    throw new RuntimeException('Modo de autenticação do Moodle inválido.');
}

return [
    'auth_mode' => $authMode,
    'base_url' => $baseUrl,
    'token' => $token,
    'student_role_id' => $studentRoleId,
];
