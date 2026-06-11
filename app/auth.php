<?php

declare(strict_types=1);

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/helpers.php';

boot_session();

function current_user(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }

    static $user = null;

    if ($user !== null && (int) $user['id'] === (int) $_SESSION['user_id']) {
        return $user;
    }

    $stmt = db()->prepare(
        'SELECT id, name, email, is_admin, account_status, plan_name, plan_price, plan_expires_at, last_payment_at, admin_notes, created_at
         FROM users
         WHERE id = :id
         LIMIT 1'
    );
    $stmt->execute(['id' => (int) $_SESSION['user_id']]);
    $user = $stmt->fetch() ?: null;

    return $user;
}

function require_auth(): array
{
    $user = current_user();

    if (!$user) {
        flash('error', 'Entre para acessar sua conta.');
        redirect('/login.php');
    }

    return $user;
}

function is_admin_user(array $user): bool
{
    return in_array($user['is_admin'] ?? false, [true, 1, '1', 't', 'true'], true);
}

function require_admin(): array
{
    $user = require_auth();

    if (!is_admin_user($user)) {
        flash('error', 'Acesso restrito ao administrador.');
        redirect('/dashboard.php');
    }

    return $user;
}

function login_user(string $email, string $password): bool
{
    $stmt = db()->prepare('SELECT * FROM users WHERE email = :email LIMIT 1');
    $stmt->execute(['email' => strtolower(trim($email))]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password'])) {
        return false;
    }

    if (!is_admin_user($user) && $user['account_status'] !== 'active') {
        return false;
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];

    return true;
}

function register_user(string $name, string $email, string $password): array
{
    $name = trim($name);
    $email = strtolower(trim($email));

    $errors = [];

    if (strlen($name) < 2) {
        $errors[] = 'Informe seu nome completo.';
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Informe um e-mail valido.';
    }

    if (strlen($password) < 8) {
        $errors[] = 'A senha precisa ter pelo menos 8 caracteres.';
    }

    if ($errors) {
        return ['ok' => false, 'errors' => $errors];
    }

    $stmt = db()->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
    $stmt->execute(['email' => $email]);

    if ($stmt->fetch()) {
        return ['ok' => false, 'errors' => ['Este e-mail ja esta cadastrado.']];
    }

    $stmt = db()->prepare(
        'INSERT INTO users (name, email, password) VALUES (:name, :email, :password) RETURNING id'
    );
    $stmt->execute([
        'name' => $name,
        'email' => $email,
        'password' => password_hash($password, PASSWORD_DEFAULT),
    ]);

    $userId = (int) $stmt->fetchColumn();
    create_default_categories($userId);

    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;

    return ['ok' => true, 'errors' => []];
}

function create_default_categories(int $userId): void
{
    $categories = [
        ['Salario', 'income'],
        ['Freelance', 'income'],
        ['Investimentos', 'income'],
        ['Moradia', 'expense'],
        ['Alimentacao', 'expense'],
        ['Transporte', 'expense'],
        ['Saude', 'expense'],
        ['Lazer', 'expense'],
    ];

    $stmt = db()->prepare(
        'INSERT INTO categories (user_id, name, type) VALUES (:user_id, :name, :type)
         ON CONFLICT (user_id, name, type) DO NOTHING'
    );

    foreach ($categories as [$name, $type]) {
        $stmt->execute([
            'user_id' => $userId,
            'name' => $name,
            'type' => $type,
        ]);
    }
}

function logout_user(): never
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }

    session_destroy();
    redirect('/login.php');
}
