<?php

declare(strict_types=1);

function evaSecurityJsonResponse(
    int $status,
    string $message,
    array $extra = []
): never {
    http_response_code($status);

    header('Content-Type: application/json; charset=UTF-8');

    echo json_encode(
        array_merge([
            'success' => false,
            'message' => $message,
        ], $extra),
        JSON_UNESCAPED_UNICODE
    );

    exit;
}

function evaApplyApiSecurityHeaders(): void
{
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header('Cross-Origin-Resource-Policy: same-origin');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; base-uri 'none'");
}

function evaEnforceJsonRequest(int $maxBodyBytes = 16384): void
{
    $contentType = trim((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
    $mediaType = strtolower(
        trim(explode(';', $contentType, 2)[0])
    );

    if ($mediaType !== 'application/json') {
        evaSecurityJsonResponse(
            415,
            'Formato da requisição não suportado.'
        );
    }

    $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);

    if ($contentLength > $maxBodyBytes) {
        evaSecurityJsonResponse(
            413,
            'A requisição excede o tamanho permitido.'
        );
    }
}

function evaEnforceTrustedOrigin(): void
{
    $origin = strtolower(
        rtrim(trim((string) ($_SERVER['HTTP_ORIGIN'] ?? '')), '/')
    );

    $appConfig = require __DIR__ . '/app.php';
    $allowedOrigins = array_map(
        static fn (string $value): string => strtolower(rtrim($value, '/')),
        $appConfig['allowed_origins']
    );

    if (
        $origin === '' ||
        !in_array($origin, $allowedOrigins, true)
    ) {
        evaSecurityJsonResponse(
            403,
            'Origem da requisição não autorizada.'
        );
    }

    $fetchSite = strtolower(
        trim((string) ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? ''))
    );

    if (
        $fetchSite !== '' &&
        !in_array($fetchSite, ['same-origin', 'same-site'], true)
    ) {
        evaSecurityJsonResponse(
            403,
            'Origem da requisição não autorizada.'
        );
    }
}

function evaClientIp(): string
{
    $ip = trim((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));

    return $ip !== '' ? substr($ip, 0, 64) : 'unknown';
}

function evaRateLimitFile(
    string $bucket,
    string $identifier
): string {
    $directory =
        sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'eva-security-rate-limit';

    if (
        !is_dir($directory) &&
        !mkdir($directory, 0700, true) &&
        !is_dir($directory)
    ) {
        error_log(
            'EVA Security: não foi possível criar o diretório de rate limit.'
        );

        evaSecurityJsonResponse(
            503,
            'Serviço temporariamente indisponível.'
        );
    }

    @chmod($directory, 0700);

    $key = hash(
        'sha256',
        $bucket . '|' . $identifier
    );

    return $directory . DIRECTORY_SEPARATOR . $key . '.json';
}

function evaRateLimitState(
    string $bucket,
    string $identifier,
    int $windowSeconds,
    bool $recordHit = false
): array {
    $file = evaRateLimitFile($bucket, $identifier);
    $handle = fopen($file, 'c+');

    if ($handle === false || !flock($handle, LOCK_EX)) {
        if (is_resource($handle)) {
            fclose($handle);
        }

        error_log(
            'EVA Security: não foi possível acessar o controle de rate limit.'
        );

        evaSecurityJsonResponse(
            503,
            'Serviço temporariamente indisponível.'
        );
    }

    $now = time();
    $contents = stream_get_contents($handle);
    $timestamps = json_decode(
        $contents !== false ? $contents : '',
        true
    );

    $timestamps = is_array($timestamps)
        ? $timestamps
        : [];

    $timestamps = array_values(array_filter(
        $timestamps,
        static fn ($timestamp): bool =>
            is_int($timestamp) &&
            $timestamp > ($now - $windowSeconds)
    ));

    if ($recordHit) {
        $timestamps[] = $now;
    }

    rewind($handle);
    ftruncate($handle, 0);
    fwrite(
        $handle,
        json_encode($timestamps, JSON_UNESCAPED_UNICODE)
    );
    fflush($handle);
    flock($handle, LOCK_UN);
    fclose($handle);

    $retryAfter = 0;

    if ($timestamps !== []) {
        $oldest = (int) min($timestamps);
        $retryAfter = max(
            1,
            $windowSeconds - ($now - $oldest)
        );
    }

    return [
        'count' => count($timestamps),
        'retry_after' => $retryAfter,
    ];
}

function evaAssertRateLimit(
    string $bucket,
    string $identifier,
    int $maxAttempts,
    int $windowSeconds
): void {
    $state = evaRateLimitState(
        $bucket,
        $identifier,
        $windowSeconds
    );

    if ($state['count'] < $maxAttempts) {
        return;
    }

    header(
        'Retry-After: ' .
        (int) $state['retry_after']
    );

    evaSecurityJsonResponse(
        429,
        'Muitas tentativas. Aguarde alguns minutos e tente novamente.'
    );
}

function evaRecordRateLimitHit(
    string $bucket,
    string $identifier,
    int $windowSeconds
): void {
    evaRateLimitState(
        $bucket,
        $identifier,
        $windowSeconds,
        true
    );
}

function evaClearRateLimit(
    string $bucket,
    string $identifier
): void {
    $file = evaRateLimitFile($bucket, $identifier);

    if (
        is_file($file) &&
        file_put_contents($file, '[]', LOCK_EX) === false
    ) {
        error_log(
            'EVA Security: não foi possível limpar o controle de rate limit.'
        );

        evaSecurityJsonResponse(
            503,
            'Serviço temporariamente indisponível.'
        );
    }
}
