<?php

declare(strict_types=1);

const EVA_SESSION_IDLE_TIMEOUT = 7200;
const EVA_SESSION_ABSOLUTE_TIMEOUT = 43200;

function evaStartSecureSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $documentRoot = realpath(
        (string) ($_SERVER['DOCUMENT_ROOT'] ?? '')
    );

    $projectRoot = realpath(dirname(__DIR__));

    if ($documentRoot !== false) {
        $sessionBase = dirname($documentRoot);
    } elseif ($projectRoot !== false) {
        $sessionBase = dirname($projectRoot);
    } else {
        $sessionBase = sys_get_temp_dir();
    }

    $sessionPath =
        $sessionBase . DIRECTORY_SEPARATOR . 'eva_sessions';

    if (
        !is_dir($sessionPath) &&
        !mkdir($sessionPath, 0700, true) &&
        !is_dir($sessionPath)
    ) {
        throw new RuntimeException(
            'Não foi possível inicializar o armazenamento de sessão.'
        );
    }

    @chmod($sessionPath, 0700);

    if (!is_writable($sessionPath)) {
        throw new RuntimeException(
            'O diretório de sessão não possui permissão de escrita.'
        );
    }

    session_save_path($sessionPath);

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_secure', '1');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.gc_maxlifetime', (string) EVA_SESSION_ABSOLUTE_TIMEOUT);

    session_name('EVA_SESSION');

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();

    $now = time();
    $createdAt = isset($_SESSION['created_at'])
        ? (int) $_SESSION['created_at']
        : $now;
    $lastActivity = isset($_SESSION['last_activity'])
        ? (int) $_SESSION['last_activity']
        : $now;

    $expiredByIdle =
        ($now - $lastActivity) > EVA_SESSION_IDLE_TIMEOUT;
    $expiredByAge =
        ($now - $createdAt) > EVA_SESSION_ABSOLUTE_TIMEOUT;

    if ($expiredByIdle || $expiredByAge) {
        evaDestroySession();
        session_start();
        $createdAt = $now;
    }

    $_SESSION['created_at'] = $createdAt;
    $_SESSION['last_activity'] = $now;
}

function evaDestroySession(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }

    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();

        setcookie(
            session_name(),
            '',
            [
                'expires' => time() - 42000,
                'path' => $params['path'],
                'domain' => $params['domain'],
                'secure' => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'] ?? 'Lax',
            ]
        );
    }

    session_destroy();
    session_id('');
}

function evaValidateAuthenticatedSessionVersion(): void
{
    $userId = $_SESSION['user_id'] ?? null;

    if ($userId === null) {
        return;
    }

    $sessionVersion = $_SESSION['session_version'] ?? null;

    if (
        (!is_int($userId) && !ctype_digit((string) $userId)) ||
        (!is_int($sessionVersion) && !ctype_digit((string) $sessionVersion))
    ) {
        evaDestroySession();
        return;
    }

    $pdo = require __DIR__ . '/database.php';
    $statement = $pdo->prepare(
        'SELECT session_version FROM users WHERE id = :id LIMIT 1'
    );
    $statement->execute(['id' => (int) $userId]);
    $account = $statement->fetch();

    if (
        $account === false ||
        (int) $account['session_version'] !== (int) $sessionVersion
    ) {
        evaDestroySession();
    }
}

evaStartSecureSession();
evaValidateAuthenticatedSessionVersion();
