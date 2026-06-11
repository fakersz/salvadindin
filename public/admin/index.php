<?php

declare(strict_types=1);

require_once __DIR__ . '/../../app/auth.php';

$admin = require_admin();
$errors = [];
$success = flash('success');

function post_date_or_null(string $key): ?string
{
    $value = trim((string) ($_POST[$key] ?? ''));
    return $value !== '' ? $value : null;
}

function pg_bool(bool $value): string
{
    return $value ? 'true' : 'false';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'create_user') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $password = (string) ($_POST['password'] ?? '');
        $planName = trim((string) ($_POST['plan_name'] ?? 'Teste'));
        $planPrice = str_replace(',', '.', (string) ($_POST['plan_price'] ?? '0'));
        $expiresAt = post_date_or_null('plan_expires_at');
        $isAdmin = isset($_POST['is_admin']);

        if (strlen($name) < 2) {
            $errors[] = 'Informe o nome do cliente.';
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Informe um e-mail valido.';
        }

        if (strlen($password) < 8) {
            $errors[] = 'A senha precisa ter pelo menos 8 caracteres.';
        }

        if (!is_numeric($planPrice) || (float) $planPrice < 0) {
            $errors[] = 'Informe um valor de plano valido.';
        }

        if (!$errors) {
            $stmt = db()->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
            $stmt->execute(['email' => $email]);

            if ($stmt->fetch()) {
                $errors[] = 'Este e-mail ja esta cadastrado.';
            }
        }

        if (!$errors) {
            $stmt = db()->prepare(
                'INSERT INTO users
                    (name, email, password, is_admin, account_status, plan_name, plan_price, plan_expires_at)
                 VALUES
                    (:name, :email, :password, :is_admin, :account_status, :plan_name, :plan_price, :plan_expires_at)
                 RETURNING id'
            );
            $stmt->execute([
                'name' => $name,
                'email' => $email,
                'password' => password_hash($password, PASSWORD_DEFAULT),
                'is_admin' => pg_bool($isAdmin),
                'account_status' => 'active',
                'plan_name' => $planName !== '' ? $planName : 'Teste',
                'plan_price' => number_format((float) $planPrice, 2, '.', ''),
                'plan_expires_at' => $expiresAt,
            ]);

            create_default_categories((int) $stmt->fetchColumn());
            flash('success', 'Conta criada com sucesso.');
            redirect('/admin/');
        }
    }

    if ($action === 'update_user') {
        $userId = (int) ($_POST['user_id'] ?? 0);
        $status = (string) ($_POST['account_status'] ?? 'active');
        $planName = trim((string) ($_POST['plan_name'] ?? 'Teste'));
        $planPrice = str_replace(',', '.', (string) ($_POST['plan_price'] ?? '0'));
        $expiresAt = post_date_or_null('plan_expires_at');
        $lastPaymentAt = post_date_or_null('last_payment_at');
        $adminNotes = trim((string) ($_POST['admin_notes'] ?? ''));
        $isAdmin = isset($_POST['is_admin']);

        if ($userId <= 0) {
            $errors[] = 'Cliente invalido.';
        }

        if (!in_array($status, ['active', 'blocked', 'expired'], true)) {
            $errors[] = 'Status invalido.';
        }

        if (!is_numeric($planPrice) || (float) $planPrice < 0) {
            $errors[] = 'Informe um valor de plano valido.';
        }

        if (!$errors) {
            $stmt = db()->prepare(
                'UPDATE users
                 SET is_admin = :is_admin,
                     account_status = :account_status,
                     plan_name = :plan_name,
                     plan_price = :plan_price,
                     plan_expires_at = :plan_expires_at,
                     last_payment_at = :last_payment_at,
                     admin_notes = :admin_notes
                 WHERE id = :id'
            );
            $stmt->execute([
                'id' => $userId,
                'is_admin' => pg_bool($isAdmin),
                'account_status' => $status,
                'plan_name' => $planName !== '' ? $planName : 'Teste',
                'plan_price' => number_format((float) $planPrice, 2, '.', ''),
                'plan_expires_at' => $expiresAt,
                'last_payment_at' => $lastPaymentAt,
                'admin_notes' => $adminNotes !== '' ? $adminNotes : null,
            ]);

            flash('success', 'Conta atualizada.');
            redirect('/admin/');
        }
    }

    if ($action === 'create_billing') {
        $userId = (int) ($_POST['billing_user_id'] ?? 0);
        $description = trim((string) ($_POST['description'] ?? ''));
        $amount = str_replace(',', '.', (string) ($_POST['amount'] ?? '0'));
        $dueDate = post_date_or_null('due_date');

        if ($userId <= 0) {
            $errors[] = 'Escolha o cliente da cobranca.';
        }

        if (strlen($description) < 2) {
            $errors[] = 'Informe a descricao da cobranca.';
        }

        if (!is_numeric($amount) || (float) $amount < 0) {
            $errors[] = 'Informe um valor de cobranca valido.';
        }

        if (!$dueDate) {
            $errors[] = 'Informe o vencimento.';
        }

        if (!$errors) {
            $stmt = db()->prepare(
                'INSERT INTO billing_records (user_id, description, amount, due_date)
                 VALUES (:user_id, :description, :amount, :due_date)'
            );
            $stmt->execute([
                'user_id' => $userId,
                'description' => $description,
                'amount' => number_format((float) $amount, 2, '.', ''),
                'due_date' => $dueDate,
            ]);

            flash('success', 'Cobranca criada.');
            redirect('/admin/');
        }
    }

    if ($action === 'mark_paid') {
        $billingId = (int) ($_POST['billing_id'] ?? 0);

        $stmt = db()->prepare(
            'UPDATE billing_records
             SET status = :status, paid_at = CURRENT_DATE
             WHERE id = :id
             RETURNING user_id'
        );
        $stmt->execute(['id' => $billingId, 'status' => 'paid']);
        $paidUserId = $stmt->fetchColumn();

        if ($paidUserId) {
            $stmt = db()->prepare(
                'UPDATE users
                 SET last_payment_at = CURRENT_DATE, account_status = :status
                 WHERE id = :id'
            );
            $stmt->execute(['id' => (int) $paidUserId, 'status' => 'active']);
        }

        flash('success', 'Pagamento marcado como pago.');
        redirect('/admin/');
    }

    if ($action === 'cancel_billing') {
        $billingId = (int) ($_POST['billing_id'] ?? 0);
        $stmt = db()->prepare('UPDATE billing_records SET status = :status WHERE id = :id');
        $stmt->execute(['id' => $billingId, 'status' => 'canceled']);

        flash('success', 'Cobranca cancelada.');
        redirect('/admin/');
    }
}

$stmt = db()->query(
    "SELECT
        COUNT(*) AS total_users,
        COUNT(*) FILTER (WHERE account_status = 'active') AS active_users,
        COUNT(*) FILTER (WHERE account_status = 'blocked') AS blocked_users,
        COUNT(*) FILTER (WHERE plan_expires_at IS NOT NULL AND plan_expires_at < CURRENT_DATE) AS expired_users
     FROM users"
);
$stats = $stmt->fetch();

$users = db()->query(
    'SELECT *
     FROM users
     ORDER BY is_admin DESC, created_at DESC'
)->fetchAll();

$billings = db()->query(
    'SELECT b.*, u.name AS user_name, u.email AS user_email
     FROM billing_records b
     JOIN users u ON u.id = b.user_id
     ORDER BY b.created_at DESC
     LIMIT 30'
)->fetchAll();

$statusLabels = [
    'active' => 'Ativa',
    'blocked' => 'Bloqueada',
    'expired' => 'Expirada',
];

$billingLabels = [
    'pending' => 'Pendente',
    'paid' => 'Pago',
    'canceled' => 'Cancelado',
];
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Admin | Salva DinDin</title>
    <link rel="stylesheet" href="/assets/css/style.css?v=responsive-1">
    <script>
        (() => {
            const savedTheme = localStorage.getItem('salvadindin-theme');
            const systemTheme = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
            document.documentElement.dataset.theme = savedTheme || systemTheme;
        })();
    </script>
</head>
<body>
    <div class="app-shell">
        <aside class="sidebar">
            <a class="brand-lockup" href="/admin/">
                <img class="brand-logo" src="/assets/img/logo2.png" alt="Salva DinDin Controle Financeiro">
            </a>
            <nav class="nav-list" aria-label="Admin">
                <a class="nav-item active" href="/admin/">Admin</a>
                <a class="nav-item" href="/dashboard.php">Minha conta</a>
                <a class="nav-item" href="/transactions.php">Movimentacoes</a>
                <a class="nav-item" href="/goals.php">Metas</a>
                <a class="nav-item" href="/investments.php">Investimentos</a>
            </nav>
            <a class="nav-item muted" href="/logout.php">Sair</a>
        </aside>

        <main class="content">
            <header class="topbar">
                <div>
                    <p class="eyebrow">Painel administrativo</p>
                    <h1>Contas e planos</h1>
                </div>
                <a class="button button-secondary" href="https://salvadindin.online/" target="_blank" rel="noreferrer">Ver site</a>
            </header>

            <?php if ($success): ?>
                <div class="alert alert-success"><?= e($success) ?></div>
            <?php endif; ?>

            <?php foreach ($errors as $error): ?>
                <div class="alert alert-error"><?= e($error) ?></div>
            <?php endforeach; ?>

            <section class="metrics-grid" aria-label="Resumo administrativo">
                <article class="metric-card highlight">
                    <span>Total de contas</span>
                    <strong><?= e($stats['total_users'] ?? 0) ?></strong>
                    <small>Clientes e admins</small>
                </article>
                <article class="metric-card">
                    <span>Ativas</span>
                    <strong class="positive"><?= e($stats['active_users'] ?? 0) ?></strong>
                    <small>Acesso liberado</small>
                </article>
                <article class="metric-card">
                    <span>Bloqueadas</span>
                    <strong class="negative"><?= e($stats['blocked_users'] ?? 0) ?></strong>
                    <small>Sem acesso ao painel</small>
                </article>
                <article class="metric-card">
                    <span>Expiradas</span>
                    <strong><?= e($stats['expired_users'] ?? 0) ?></strong>
                    <small>Plano vencido</small>
                </article>
            </section>

            <section class="two-column align-start">
                <div class="panel">
                    <div class="panel-heading">
                        <h2>Criar conta</h2>
                    </div>

                    <form method="post" class="form-grid" novalidate>
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="create_user">

                        <label>
                            <span>Nome</span>
                            <input type="text" name="name" required>
                        </label>
                        <label>
                            <span>E-mail</span>
                            <input type="email" name="email" required>
                        </label>
                        <label>
                            <span>Senha</span>
                            <input type="password" name="password" minlength="8" required>
                        </label>
                        <label>
                            <span>Plano</span>
                            <input type="text" name="plan_name" value="Mensal">
                        </label>
                        <label>
                            <span>Valor</span>
                            <input type="number" name="plan_price" step="0.01" min="0" value="0">
                        </label>
                        <label>
                            <span>Expira em</span>
                            <input type="date" name="plan_expires_at">
                        </label>
                        <label class="check-row full">
                            <input type="checkbox" name="is_admin" value="1">
                            <span>Dar acesso ao admin</span>
                        </label>

                        <button class="button button-primary full" type="submit">Criar conta</button>
                    </form>
                </div>

                <div class="panel">
                    <div class="panel-heading">
                        <h2>Criar cobranca</h2>
                    </div>

                    <form method="post" class="form-grid" novalidate>
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="create_billing">

                        <label class="full">
                            <span>Cliente</span>
                            <select name="billing_user_id" required>
                                <option value="">Escolha uma conta</option>
                                <?php foreach ($users as $user): ?>
                                    <option value="<?= e($user['id']) ?>"><?= e($user['name']) ?> - <?= e($user['email']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label class="full">
                            <span>Descricao</span>
                            <input type="text" name="description" value="Mensalidade" required>
                        </label>
                        <label>
                            <span>Valor</span>
                            <input type="number" name="amount" step="0.01" min="0" value="0" required>
                        </label>
                        <label>
                            <span>Vencimento</span>
                            <input type="date" name="due_date" value="<?= e(date('Y-m-d')) ?>" required>
                        </label>

                        <button class="button button-secondary full" type="submit">Criar cobranca</button>
                    </form>
                </div>
            </section>

            <section class="panel">
                <div class="panel-heading">
                    <h2>Contas</h2>
                    <span><?= count($users) ?> registros</span>
                </div>

                <div class="admin-list">
                    <?php foreach ($users as $user): ?>
                        <form method="post" class="admin-account" novalidate>
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="update_user">
                            <input type="hidden" name="user_id" value="<?= e($user['id']) ?>">

                            <div class="admin-account-title">
                                <div>
                                    <strong><?= e($user['name']) ?></strong>
                                    <span><?= e($user['email']) ?></span>
                                </div>
                                <span class="badge <?= e($user['account_status']) ?>"><?= e($statusLabels[$user['account_status']] ?? $user['account_status']) ?></span>
                            </div>

                            <div class="form-grid compact">
                                <label>
                                    <span>Status</span>
                                    <select name="account_status">
                                        <?php foreach ($statusLabels as $value => $label): ?>
                                            <option value="<?= e($value) ?>" <?= $user['account_status'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <label>
                                    <span>Plano</span>
                                    <input type="text" name="plan_name" value="<?= e($user['plan_name']) ?>">
                                </label>
                                <label>
                                    <span>Valor</span>
                                    <input type="number" name="plan_price" step="0.01" min="0" value="<?= e($user['plan_price']) ?>">
                                </label>
                                <label>
                                    <span>Expira em</span>
                                    <input type="date" name="plan_expires_at" value="<?= e($user['plan_expires_at']) ?>">
                                </label>
                                <label>
                                    <span>Ultimo pagamento</span>
                                    <input type="date" name="last_payment_at" value="<?= e($user['last_payment_at']) ?>">
                                </label>
                                <label class="check-row">
                                    <input type="checkbox" name="is_admin" value="1" <?= is_admin_user($user) ? 'checked' : '' ?>>
                                    <span>Admin</span>
                                </label>
                                <label class="full">
                                    <span>Observacoes</span>
                                    <input type="text" name="admin_notes" value="<?= e($user['admin_notes']) ?>">
                                </label>
                            </div>

                            <button class="button button-secondary" type="submit">Salvar alteracoes</button>
                        </form>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="panel">
                <div class="panel-heading">
                    <h2>Cobrancas recentes</h2>
                    <span><?= count($billings) ?> registros</span>
                </div>

                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Cliente</th>
                                <th>Descricao</th>
                                <th>Vencimento</th>
                                <th>Status</th>
                                <th class="text-right">Valor</th>
                                <th>Acoes</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$billings): ?>
                                <tr>
                                    <td colspan="6" class="empty-state">Nenhuma cobranca criada ainda.</td>
                                </tr>
                            <?php endif; ?>

                            <?php foreach ($billings as $billing): ?>
                                <tr>
                                    <td>
                                        <?= e($billing['user_name']) ?><br>
                                        <small><?= e($billing['user_email']) ?></small>
                                    </td>
                                    <td><?= e($billing['description']) ?></td>
                                    <td><?= format_date($billing['due_date']) ?></td>
                                    <td><span class="badge <?= e($billing['status']) ?>"><?= e($billingLabels[$billing['status']] ?? $billing['status']) ?></span></td>
                                    <td class="text-right"><?= money($billing['amount']) ?></td>
                                    <td>
                                        <?php if ($billing['status'] === 'pending'): ?>
                                            <form method="post" class="inline-form">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="mark_paid">
                                                <input type="hidden" name="billing_id" value="<?= e($billing['id']) ?>">
                                                <button class="button button-secondary" type="submit">Pago</button>
                                            </form>
                                            <form method="post" class="inline-form">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="cancel_billing">
                                                <input type="hidden" name="billing_id" value="<?= e($billing['id']) ?>">
                                                <button class="button button-danger" type="submit">Cancelar</button>
                                            </form>
                                        <?php else: ?>
                                            <span><?= $billing['paid_at'] ? 'Pago em ' . format_date($billing['paid_at']) : '-' ?></span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </main>
    </div>
    <script src="/assets/js/app.js?v=responsive-1"></script>
</body>
</html>
