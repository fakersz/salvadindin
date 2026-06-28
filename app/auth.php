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
        'SELECT id, name, email, whatsapp_phone, is_admin, account_status, COALESCE(NULLIF(plan_name, \'\'), \'free\') AS plan_name,
                plan_price, plan_expires_at, last_payment_at, admin_notes, created_at
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

    if (!is_admin_user($user) && account_is_expired($user)) {
        mark_user_expired((int) $user['id']);
        $_SESSION = [];
        flash('error', 'Sua assinatura expirou. Fale com o administrador para renovar o acesso.');
        redirect('/login.php');
    }

    reactivate_free_user_if_needed($user);

    if (!is_admin_user($user) && $user['account_status'] !== 'active') {
        $_SESSION = [];
        flash('error', 'Sua conta nao esta ativa.');
        redirect('/login.php');
    }

    touch_user_online((int) $user['id']);

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

    if (!is_admin_user($user) && account_is_expired($user)) {
        mark_user_expired((int) $user['id']);
        return false;
    }

    reactivate_free_user_if_needed($user);

    if (!is_admin_user($user) && $user['account_status'] !== 'active') {
        return false;
    }

    touch_user_online((int) $user['id'], true);

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];

    return true;
}

function touch_user_online(int $userId, bool $force = false): void
{
    if (!user_table_has_column('last_online_at')) {
        return;
    }

    if ($force) {
        $stmt = db()->prepare('UPDATE users SET last_online_at = now() WHERE id = :id');
        $stmt->execute(['id' => $userId]);
        return;
    }

    $stmt = db()->prepare(
        'UPDATE users
         SET last_online_at = now()
         WHERE id = :id
           AND (last_online_at IS NULL OR last_online_at < now() - interval \'5 minutes\')'
    );
    $stmt->execute(['id' => $userId]);
}

function user_table_has_column(string $column): bool
{
    static $columns = null;

    if ($columns === null) {
        $stmt = db()->prepare(
            "SELECT column_name
             FROM information_schema.columns
             WHERE table_schema = 'public' AND table_name = 'users'"
        );
        $stmt->execute();
        $columns = array_flip(array_column($stmt->fetchAll(), 'column_name'));
    }

    return isset($columns[$column]);
}

function account_is_expired(array $user): bool
{
    if (is_free_plan($user)) {
        return false;
    }

    return !empty($user['plan_expires_at']) && $user['plan_expires_at'] < date('Y-m-d');
}

function is_free_plan(array $user): bool
{
    return ($user['plan_name'] ?? 'free') === 'free';
}

function reactivate_free_user_if_needed(array &$user): void
{
    if (is_admin_user($user) || !is_free_plan($user) || $user['account_status'] !== 'expired') {
        return;
    }

    $stmt = db()->prepare(
        "UPDATE users
         SET account_status = 'active',
             plan_expires_at = NULL
         WHERE id = :id
           AND is_admin = FALSE
           AND COALESCE(NULLIF(plan_name, ''), 'free') = 'free'
           AND account_status = 'expired'"
    );
    $stmt->execute(['id' => (int) $user['id']]);

    $user['account_status'] = 'active';
    $user['plan_expires_at'] = null;
}

function mark_user_expired(int $userId): void
{
    $stmt = db()->prepare(
        "UPDATE users
         SET account_status = 'expired'
         WHERE id = :id
           AND is_admin = FALSE
           AND COALESCE(NULLIF(plan_name, ''), 'free') <> 'free'
           AND plan_expires_at IS NOT NULL
           AND plan_expires_at < CURRENT_DATE"
    );
    $stmt->execute(['id' => $userId]);
}

function plan_options(): array
{
    return [
        'free' => ['name' => 'free', 'days' => null],
        'teste_gratis' => ['name' => 'Teste Gratis', 'days' => 7],
        'premium' => ['name' => 'Premium', 'days' => 30],
        'vip_plus' => ['name' => 'Vip+', 'days' => 180],
        'vip_pro_max' => ['name' => 'Vip+ Pro Max', 'days' => 365],
    ];
}

function normalize_phone_number(string $phone): string
{
    return preg_replace('/\D+/', '', $phone) ?? '';
}

function validate_whatsapp_phone(string $phone, array &$errors): string
{
    $phone = normalize_phone_number($phone);

    if ($phone === '') {
        $errors[] = 'Informe seu numero de celular.';
        return '';
    }

    if (strlen($phone) < 10 || strlen($phone) > 15) {
        $errors[] = 'Informe um celular valido com DDD.';
        return '';
    }

    if (!str_starts_with($phone, '55') && strlen($phone) <= 11) {
        $phone = '55' . $phone;
    }

    return $phone;
}

function whatsapp_phone_exists(string $phone, ?int $ignoreUserId = null): bool
{
    if (!user_table_has_column('whatsapp_phone') || $phone === '') {
        return false;
    }

    $sql = 'SELECT id FROM users WHERE whatsapp_phone = :phone';
    $params = ['phone' => $phone];

    if ($ignoreUserId !== null) {
        $sql .= ' AND id <> :ignore_user_id';
        $params['ignore_user_id'] = $ignoreUserId;
    }

    $sql .= ' LIMIT 1';

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    return (bool) $stmt->fetchColumn();
}

function register_user(string $name, string $email, string $password, string $whatsappPhone = ''): array
{
    $name = trim($name);
    $email = strtolower(trim($email));

    $errors = [];
    $whatsappPhone = validate_whatsapp_phone($whatsappPhone, $errors);

    if (strlen($name) < 2) {
        $errors[] = 'Informe seu nome completo.';
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Informe um e-mail valido.';
    }

    if (strlen($password) < 8) {
        $errors[] = 'A senha precisa ter pelo menos 8 caracteres.';
    }

    if ($whatsappPhone !== '' && whatsapp_phone_exists($whatsappPhone)) {
        $errors[] = 'Este celular ja esta vinculado a outra conta.';
    }

    if ($errors) {
        return ['ok' => false, 'errors' => $errors];
    }

    $pdo = db();

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
        $stmt->execute(['email' => $email]);

        if ($stmt->fetch()) {
            $pdo->rollBack();
            return ['ok' => false, 'errors' => ['Este e-mail ja esta cadastrado.']];
        }

        $userColumns = ['name', 'email', 'password', 'account_status', 'plan_name', 'plan_price', 'plan_expires_at'];
        $userValues = [':name', ':email', ':password', ':account_status', ':plan_name', ':plan_price', ':plan_expires_at'];
        $params = [
            'name' => $name,
            'email' => $email,
            'password' => password_hash($password, PASSWORD_DEFAULT),
            'account_status' => 'active',
            'plan_name' => 'free',
            'plan_price' => '0.00',
            'plan_expires_at' => null,
        ];

        if (user_table_has_column('whatsapp_phone')) {
            $userColumns[] = 'whatsapp_phone';
            $userValues[] = ':whatsapp_phone';
            $params['whatsapp_phone'] = $whatsappPhone;
        }

        if (user_table_has_column('last_online_at')) {
            $userColumns[] = 'last_online_at';
            $userValues[] = 'now()';
        }

        $stmt = $pdo->prepare(
            'INSERT INTO users (' . implode(', ', $userColumns) . ')
             VALUES (' . implode(', ', $userValues) . ')
             RETURNING id'
        );
        $stmt->execute($params);

        $userId = (int) $stmt->fetchColumn();

        create_default_categories($userId);
        $pdo->commit();

        session_regenerate_id(true);
        $_SESSION['user_id'] = $userId;

        return ['ok' => true, 'errors' => []];
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return ['ok' => false, 'errors' => ['Nao foi possivel criar a conta agora. Tente novamente.']];
    }
}

function create_default_categories(int $userId): void
{
    $categories = [
        ['Salario', 'income'],
        ['Freelance', 'income'],
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
