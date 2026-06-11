<?php

declare(strict_types=1);

function boot_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['SERVER_PORT'] ?? null) === '443');

    session_name('salvadindin_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function redirect(string $path): never
{
    header('Location: ' . $path);
    exit;
}

function current_path(): string
{
    return basename(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: 'index.php');
}

function csrf_token(): string
{
    boot_session();

    if (empty($_SESSION['_csrf_token'])) {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['_csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf_token" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): void
{
    boot_session();
    $token = $_POST['_csrf_token'] ?? '';

    if (!is_string($token) || !hash_equals($_SESSION['_csrf_token'] ?? '', $token)) {
        http_response_code(419);
        exit('Sessao expirada. Volte e tente novamente.');
    }
}

function flash(string $key, ?string $message = null): ?string
{
    boot_session();

    if ($message !== null) {
        $_SESSION['_flash'][$key] = $message;
        return null;
    }

    if (!isset($_SESSION['_flash'][$key])) {
        return null;
    }

    $value = $_SESSION['_flash'][$key];
    unset($_SESSION['_flash'][$key]);

    return $value;
}

function money(float|int|string|null $amount): string
{
    return 'R$ ' . number_format((float) $amount, 2, ',', '.');
}

function format_date(?string $date): string
{
    if (!$date) {
        return '-';
    }

    return date('d/m/Y', strtotime($date));
}

function month_bounds(): array
{
    return [
        date('Y-m-01'),
        date('Y-m-t'),
    ];
}

function fetch_categories(int $userId, ?string $type = null): array
{
    if ($type !== null) {
        $stmt = db()->prepare('SELECT * FROM categories WHERE user_id = :user_id AND type = :type ORDER BY name ASC');
        $stmt->execute(['user_id' => $userId, 'type' => $type]);
        return $stmt->fetchAll();
    }

    $stmt = db()->prepare('SELECT * FROM categories WHERE user_id = :user_id ORDER BY type ASC, name ASC');
    $stmt->execute(['user_id' => $userId]);

    return $stmt->fetchAll();
}
