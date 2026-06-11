<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';

$user = require_auth();
$errors = [];
$success = flash('success');
$paymentMethods = [
    'pix_cash' => 'Pix / Dinheiro',
    'debit' => 'Debito',
    'credit' => 'Credito',
    'transfer' => 'Transferencia',
    'other' => 'Outro',
];

function pg_truthy(mixed $value): bool
{
    return in_array($value, [true, 1, '1', 't', 'true'], true);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'category') {
        $name = trim((string) ($_POST['category_name'] ?? ''));
        $type = (string) ($_POST['category_type'] ?? '');

        if (strlen($name) < 2) {
            $errors[] = 'Informe um nome de categoria.';
        }

        if (!in_array($type, ['income', 'expense'], true)) {
            $errors[] = 'Escolha o tipo da categoria.';
        }

        if (!$errors) {
            $stmt = db()->prepare(
                'INSERT INTO categories (user_id, name, type) VALUES (:user_id, :name, :type)
                 ON CONFLICT (user_id, name, type) DO NOTHING'
            );
            $stmt->execute([
                'user_id' => $user['id'],
                'name' => $name,
                'type' => $type,
            ]);

            flash('success', 'Categoria salva com sucesso.');
            redirect('/transactions.php');
        }
    }

    if ($action === 'transaction') {
        $title = trim((string) ($_POST['title'] ?? ''));
        $amount = str_replace(',', '.', (string) ($_POST['amount'] ?? '0'));
        $type = (string) ($_POST['transaction_type'] ?? '');
        $categoryId = (int) ($_POST['category_id'] ?? 0);
        $transactionDate = (string) ($_POST['transaction_date'] ?? '');
        $paymentMethod = (string) ($_POST['payment_method'] ?? 'pix_cash');
        $isEssential = isset($_POST['is_essential']);

        if (strlen($title) < 2) {
            $errors[] = 'Informe um titulo para a movimentacao.';
        }

        if (!is_numeric($amount) || (float) $amount <= 0) {
            $errors[] = 'Informe um valor maior que zero.';
        }

        if (!in_array($type, ['income', 'expense'], true)) {
            $errors[] = 'Escolha receita ou despesa.';
        }

        if (!$transactionDate || strtotime($transactionDate) === false) {
            $errors[] = 'Informe uma data valida.';
        }

        if (!array_key_exists($paymentMethod, $paymentMethods)) {
            $errors[] = 'Escolha uma forma de pagamento valida.';
        }

        $category = null;
        if ($categoryId > 0 && in_array($type, ['income', 'expense'], true)) {
            $stmt = db()->prepare('SELECT id FROM categories WHERE id = :id AND user_id = :user_id AND type = :type LIMIT 1');
            $stmt->execute([
                'id' => $categoryId,
                'user_id' => $user['id'],
                'type' => $type,
            ]);
            $category = $stmt->fetch();
        }

        if ($categoryId > 0 && !$category) {
            $errors[] = 'Categoria invalida para esta movimentacao.';
        }

        if (!$errors) {
            $stmt = db()->prepare(
                'INSERT INTO transactions
                    (user_id, category_id, title, amount, transaction_type, transaction_date, payment_method, is_essential)
                 VALUES
                    (:user_id, :category_id, :title, :amount, :transaction_type, :transaction_date, :payment_method, :is_essential)'
            );
            $stmt->execute([
                'user_id' => $user['id'],
                'category_id' => $categoryId > 0 ? $categoryId : null,
                'title' => $title,
                'amount' => number_format((float) $amount, 2, '.', ''),
                'transaction_type' => $type,
                'transaction_date' => $transactionDate,
                'payment_method' => $paymentMethod,
                'is_essential' => $isEssential ? 'true' : 'false',
            ]);

            flash('success', 'Movimentacao cadastrada.');
            redirect('/transactions.php');
        }
    }
}

$categories = fetch_categories((int) $user['id']);

$stmt = db()->prepare(
    'SELECT t.*, c.name AS category_name
     FROM transactions t
     LEFT JOIN categories c ON c.id = t.category_id
     WHERE t.user_id = :user_id
     ORDER BY t.transaction_date DESC, t.created_at DESC'
);
$stmt->execute(['user_id' => $user['id']]);
$transactions = $stmt->fetchAll();
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Movimentacoes | Salva DinDin</title>
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
            <a class="brand-lockup" href="/dashboard.php">
                <img class="brand-logo" src="/assets/img/logo2.png" alt="Salva DinDin Controle Financeiro">
            </a>
            <nav class="nav-list" aria-label="Principal">
                <a class="nav-item" href="/dashboard.php">Dashboard</a>
                <a class="nav-item active" href="/transactions.php">Movimentacoes</a>
                <a class="nav-item" href="/goals.php">Metas</a>
                <a class="nav-item" href="/investments.php">Investimentos</a>
                <?php if (is_admin_user($user)): ?>
                    <a class="nav-item" href="/admin/">Admin</a>
                <?php endif; ?>
            </nav>
            <a class="nav-item muted" href="/logout.php">Sair</a>
        </aside>

        <main class="content">
            <header class="topbar">
                <div>
                    <p class="eyebrow">Receitas e despesas</p>
                    <h1>Movimentacoes</h1>
                </div>
            </header>

            <?php if ($success): ?>
                <div class="alert alert-success"><?= e($success) ?></div>
            <?php endif; ?>

            <?php foreach ($errors as $error): ?>
                <div class="alert alert-error"><?= e($error) ?></div>
            <?php endforeach; ?>

            <section class="two-column align-start">
                <div class="panel">
                    <div class="panel-heading">
                        <h2>Nova movimentacao</h2>
                    </div>

                    <form method="post" class="form-grid" novalidate>
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="transaction">

                        <label class="full">
                            <span>Titulo</span>
                            <input type="text" name="title" placeholder="Ex.: Mercado, salario, aluguel" required>
                        </label>

                        <label>
                            <span>Tipo</span>
                            <select name="transaction_type" data-category-filter required>
                                <option value="expense">Despesa</option>
                                <option value="income">Receita</option>
                            </select>
                        </label>

                        <label>
                            <span>Valor</span>
                            <input type="number" name="amount" step="0.01" min="0.01" placeholder="0,00" required>
                        </label>

                        <label>
                            <span>Categoria</span>
                            <select name="category_id" data-category-select>
                                <option value="">Sem categoria</option>
                                <?php foreach ($categories as $category): ?>
                                    <option value="<?= e($category['id']) ?>" data-type="<?= e($category['type']) ?>">
                                        <?= e($category['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>

                        <label>
                            <span>Data</span>
                            <input type="date" name="transaction_date" value="<?= e(date('Y-m-d')) ?>" required>
                        </label>

                        <label>
                            <span>Pagamento</span>
                            <select name="payment_method" required>
                                <?php foreach ($paymentMethods as $value => $label): ?>
                                    <option value="<?= e($value) ?>"><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>

                        <label class="check-row">
                            <input type="checkbox" name="is_essential" value="1">
                            <span>Despesa essencial</span>
                        </label>

                        <button class="button button-primary full" type="submit">Cadastrar movimentacao</button>
                    </form>
                </div>

                <div class="panel">
                    <div class="panel-heading">
                        <h2>Categorias</h2>
                    </div>

                    <form method="post" class="form-grid compact" novalidate>
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="category">

                        <label>
                            <span>Nome</span>
                            <input type="text" name="category_name" placeholder="Ex.: Educacao" required>
                        </label>

                        <label>
                            <span>Tipo</span>
                            <select name="category_type" required>
                                <option value="expense">Despesa</option>
                                <option value="income">Receita</option>
                            </select>
                        </label>

                        <button class="button button-secondary full" type="submit">Salvar categoria</button>
                    </form>

                    <div class="chips">
                        <?php foreach ($categories as $category): ?>
                            <span class="chip <?= e($category['type']) ?>"><?= e($category['name']) ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>

            <section class="panel">
                <div class="panel-heading">
                    <h2>Historico</h2>
                    <span><?= count($transactions) ?> registros</span>
                </div>

                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Titulo</th>
                                <th>Categoria</th>
                                <th>Data</th>
                                <th>Pagamento</th>
                                <th>Essencial</th>
                                <th>Tipo</th>
                                <th class="text-right">Valor</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$transactions): ?>
                                <tr>
                                    <td colspan="7" class="empty-state">Nenhuma movimentacao cadastrada.</td>
                                </tr>
                            <?php endif; ?>

                            <?php foreach ($transactions as $transaction): ?>
                                <tr>
                                    <td><?= e($transaction['title']) ?></td>
                                    <td><?= e($transaction['category_name'] ?? 'Sem categoria') ?></td>
                                    <td><?= format_date($transaction['transaction_date']) ?></td>
                                    <td><?= e($paymentMethods[$transaction['payment_method']] ?? 'Outro') ?></td>
                                    <td><?= pg_truthy($transaction['is_essential']) ? 'Sim' : 'Nao' ?></td>
                                    <td><?= $transaction['transaction_type'] === 'income' ? 'Receita' : 'Despesa' ?></td>
                                    <td class="text-right <?= $transaction['transaction_type'] === 'income' ? 'positive' : 'negative' ?>">
                                        <?= $transaction['transaction_type'] === 'income' ? '+' : '-' ?><?= money($transaction['amount']) ?>
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
