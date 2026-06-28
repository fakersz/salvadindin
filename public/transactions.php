<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/functions/financial_attachments.php';

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

function transaction_payload(array $paymentMethods, int $userId, array &$errors): array
{
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
            'user_id' => $userId,
            'type' => $type,
        ]);
        $category = $stmt->fetch();
    }

    if ($categoryId > 0 && !$category) {
        $errors[] = 'Categoria invalida para esta movimentacao.';
    }

    return [
        'title' => $title,
        'amount' => is_numeric($amount) ? number_format((float) $amount, 2, '.', '') : '0.00',
        'transaction_type' => $type,
        'category_id' => $categoryId > 0 ? $categoryId : null,
        'transaction_date' => $transactionDate,
        'payment_method' => $paymentMethod,
        'is_essential' => $isEssential ? 'true' : 'false',
    ];
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
        $payload = transaction_payload($paymentMethods, (int) $user['id'], $errors);

        if (!$errors) {
            $stmt = db()->prepare(
                'INSERT INTO transactions
                    (user_id, category_id, title, amount, transaction_type, transaction_date, payment_method, is_essential)
                 VALUES
                    (:user_id, :category_id, :title, :amount, :transaction_type, :transaction_date, :payment_method, :is_essential)'
            );
            $stmt->execute([
                'user_id' => $user['id'],
                'category_id' => $payload['category_id'],
                'title' => $payload['title'],
                'amount' => $payload['amount'],
                'transaction_type' => $payload['transaction_type'],
                'transaction_date' => $payload['transaction_date'],
                'payment_method' => $payload['payment_method'],
                'is_essential' => $payload['is_essential'],
            ]);

            flash('success', 'Movimentacao cadastrada.');
            redirect('/transactions.php');
        }
    }

    if ($action === 'update_transaction') {
        $transactionId = (int) ($_POST['transaction_id'] ?? 0);
        $payload = transaction_payload($paymentMethods, (int) $user['id'], $errors);

        if ($transactionId <= 0) {
            $errors[] = 'Movimentacao invalida.';
        }

        if (!$errors) {
            $stmt = db()->prepare(
                'UPDATE transactions
                 SET category_id = :category_id,
                     title = :title,
                     amount = :amount,
                     transaction_type = :transaction_type,
                     transaction_date = :transaction_date,
                     payment_method = :payment_method,
                     is_essential = :is_essential
                 WHERE id = :id AND user_id = :user_id'
            );
            $stmt->execute([
                'id' => $transactionId,
                'user_id' => $user['id'],
                'category_id' => $payload['category_id'],
                'title' => $payload['title'],
                'amount' => $payload['amount'],
                'transaction_type' => $payload['transaction_type'],
                'transaction_date' => $payload['transaction_date'],
                'payment_method' => $payload['payment_method'],
                'is_essential' => $payload['is_essential'],
            ]);

            flash('success', $stmt->rowCount() > 0 ? 'Movimentacao atualizada.' : 'Movimentacao nao encontrada.');
            redirect('/transactions.php');
        }
    }

    if ($action === 'delete_transaction') {
        $transactionId = (int) ($_POST['transaction_id'] ?? 0);

        if ($transactionId <= 0) {
            $errors[] = 'Movimentacao invalida.';
        }

        if (!$errors) {
            $stmt = db()->prepare('DELETE FROM transactions WHERE id = :id AND user_id = :user_id');
            $stmt->execute([
                'id' => $transactionId,
                'user_id' => $user['id'],
            ]);

            flash('success', $stmt->rowCount() > 0 ? 'Movimentacao apagada.' : 'Movimentacao nao encontrada.');
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
$transactionAttachments = fetch_transaction_attachments((int) $user['id']);
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Movimentacoes | Salva DinDin</title>
    <link rel="stylesheet" href="/assets/css/style.css?v=responsive-4">
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
                <img class="brand-logo" src="/assets/img/logon.png" alt="Salva DinDin Controle Financeiro">
            </a>
            <nav class="nav-list" aria-label="Principal">
                <a class="nav-item" href="/dashboard.php">Dashboard</a>
                <a class="nav-item active" href="/transactions.php">Movimentacoes</a>
                <a class="nav-item" href="/whatsapp.php"><?= whatsapp_integration_enabled() ? 'Whatsapp' : 'WhatsApp desativado' ?></a>
                <a class="nav-item" href="/graficos.php">Graficos</a>
                <a class="nav-item" href="/debts.php">Dividas</a>
                <a class="nav-item" href="/goals.php">Metas</a>
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

                <div class="table-wrap transactions-table-wrap">
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
                                <th>Comprovante</th>
                                <th class="text-right">Acoes</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$transactions): ?>
                                <tr>
                                    <td colspan="9" class="empty-state">Nenhuma movimentacao cadastrada.</td>
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
                                    <td>
                                        <?php $attachments = $transactionAttachments[(int) $transaction['id']] ?? []; ?>
                                        <?php if ($attachments): ?>
                                            <a class="receipt-link" href="<?= e(financial_attachment_url($attachments[0]['id'])) ?>" target="_blank" rel="noopener">
                                                Comprovante
                                            </a>
                                        <?php else: ?>
                                            <span class="muted-text">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-right">
                                        <button
                                            class="button button-secondary"
                                            type="button"
                                            data-edit-transaction
                                            data-target="transaction-edit-<?= e($transaction['id']) ?>"
                                        >Editar</button>
                                        <form method="post" class="inline-form" data-confirm="Apagar esta movimentacao?">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="delete_transaction">
                                            <input type="hidden" name="transaction_id" value="<?= e($transaction['id']) ?>">
                                            <button class="button button-danger" type="submit">Apagar</button>
                                        </form>
                                    </td>
                                </tr>
                                <tr class="transaction-edit-row" id="transaction-edit-<?= e($transaction['id']) ?>" hidden>
                                    <td colspan="9">
                                        <form method="post" class="transaction-edit-form" novalidate>
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="update_transaction">
                                            <input type="hidden" name="transaction_id" value="<?= e($transaction['id']) ?>">

                                            <label class="transaction-field-title">
                                                <span>Titulo</span>
                                                <input type="text" name="title" value="<?= e($transaction['title']) ?>" required>
                                            </label>

                                            <label>
                                                <span>Tipo</span>
                                                <select name="transaction_type" data-category-filter required>
                                                    <option value="expense" <?= $transaction['transaction_type'] === 'expense' ? 'selected' : '' ?>>Despesa</option>
                                                    <option value="income" <?= $transaction['transaction_type'] === 'income' ? 'selected' : '' ?>>Receita</option>
                                                </select>
                                            </label>

                                            <label>
                                                <span>Valor</span>
                                                <input type="number" name="amount" step="0.01" min="0.01" value="<?= e((string) $transaction['amount']) ?>" required>
                                            </label>

                                            <label>
                                                <span>Categoria</span>
                                                <select name="category_id" data-category-select>
                                                    <option value="">Sem categoria</option>
                                                    <?php foreach ($categories as $category): ?>
                                                        <option
                                                            value="<?= e($category['id']) ?>"
                                                            data-type="<?= e($category['type']) ?>"
                                                            <?= (int) ($transaction['category_id'] ?? 0) === (int) $category['id'] ? 'selected' : '' ?>
                                                        >
                                                            <?= e($category['name']) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </label>

                                            <label>
                                                <span>Data</span>
                                                <input type="date" name="transaction_date" value="<?= e($transaction['transaction_date']) ?>" required>
                                            </label>

                                            <label>
                                                <span>Pagamento</span>
                                                <select name="payment_method" required>
                                                    <?php foreach ($paymentMethods as $value => $label): ?>
                                                        <option value="<?= e($value) ?>" <?= $transaction['payment_method'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </label>

                                            <label class="check-row">
                                                <input type="checkbox" name="is_essential" value="1" <?= pg_truthy($transaction['is_essential']) ? 'checked' : '' ?>>
                                                <span>Essencial</span>
                                            </label>

                                            <button class="button button-secondary" type="submit">Salvar</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="transaction-mobile-list">
                    <?php if (!$transactions): ?>
                        <p class="empty-state">Nenhuma movimentacao cadastrada.</p>
                    <?php endif; ?>

                    <?php foreach ($transactions as $transaction): ?>
                        <article class="transaction-mobile-card">
                            <div class="transaction-mobile-head">
                                <div>
                                    <strong><?= e($transaction['title']) ?></strong>
                                    <span><?= e($transaction['category_name'] ?? 'Sem categoria') ?></span>
                                </div>
                                <b class="<?= $transaction['transaction_type'] === 'income' ? 'positive' : 'negative' ?>">
                                    <?= $transaction['transaction_type'] === 'income' ? '+' : '-' ?><?= money($transaction['amount']) ?>
                                </b>
                            </div>

                            <div class="transaction-mobile-meta">
                                <span><?= format_date($transaction['transaction_date']) ?></span>
                                <span><?= e($paymentMethods[$transaction['payment_method']] ?? 'Outro') ?></span>
                                <span><?= $transaction['transaction_type'] === 'income' ? 'Receita' : 'Despesa' ?></span>
                                <span><?= pg_truthy($transaction['is_essential']) ? 'Essencial' : 'Nao essencial' ?></span>
                                <?php $attachments = $transactionAttachments[(int) $transaction['id']] ?? []; ?>
                                <?php if ($attachments): ?>
                                    <a class="receipt-link" href="<?= e(financial_attachment_url($attachments[0]['id'])) ?>" target="_blank" rel="noopener">Comprovante</a>
                                <?php endif; ?>
                            </div>

                            <div class="transaction-mobile-actions">
                                <button
                                    class="button button-secondary"
                                    type="button"
                                    data-edit-transaction
                                    data-target="transaction-edit-mobile-<?= e($transaction['id']) ?>"
                                >Editar</button>
                                <form method="post" class="inline-form" data-confirm="Apagar esta movimentacao?">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete_transaction">
                                    <input type="hidden" name="transaction_id" value="<?= e($transaction['id']) ?>">
                                    <button class="button button-danger" type="submit">Apagar</button>
                                </form>
                            </div>

                            <div class="transaction-edit-panel" id="transaction-edit-mobile-<?= e($transaction['id']) ?>" hidden>
                                <form method="post" class="transaction-edit-form" novalidate>
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="update_transaction">
                                    <input type="hidden" name="transaction_id" value="<?= e($transaction['id']) ?>">

                                    <label class="transaction-field-title">
                                        <span>Titulo</span>
                                        <input type="text" name="title" value="<?= e($transaction['title']) ?>" required>
                                    </label>

                                    <label>
                                        <span>Tipo</span>
                                        <select name="transaction_type" data-category-filter required>
                                            <option value="expense" <?= $transaction['transaction_type'] === 'expense' ? 'selected' : '' ?>>Despesa</option>
                                            <option value="income" <?= $transaction['transaction_type'] === 'income' ? 'selected' : '' ?>>Receita</option>
                                        </select>
                                    </label>

                                    <label>
                                        <span>Valor</span>
                                        <input type="number" name="amount" step="0.01" min="0.01" value="<?= e((string) $transaction['amount']) ?>" required>
                                    </label>

                                    <label>
                                        <span>Categoria</span>
                                        <select name="category_id" data-category-select>
                                            <option value="">Sem categoria</option>
                                            <?php foreach ($categories as $category): ?>
                                                <option
                                                    value="<?= e($category['id']) ?>"
                                                    data-type="<?= e($category['type']) ?>"
                                                    <?= (int) ($transaction['category_id'] ?? 0) === (int) $category['id'] ? 'selected' : '' ?>
                                                >
                                                    <?= e($category['name']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </label>

                                    <label>
                                        <span>Data</span>
                                        <input type="date" name="transaction_date" value="<?= e($transaction['transaction_date']) ?>" required>
                                    </label>

                                    <label>
                                        <span>Pagamento</span>
                                        <select name="payment_method" required>
                                            <?php foreach ($paymentMethods as $value => $label): ?>
                                                <option value="<?= e($value) ?>" <?= $transaction['payment_method'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </label>

                                    <label class="check-row">
                                        <input type="checkbox" name="is_essential" value="1" <?= pg_truthy($transaction['is_essential']) ? 'checked' : '' ?>>
                                        <span>Essencial</span>
                                    </label>

                                    <button class="button button-primary" type="submit">Salvar alteracoes</button>
                                </form>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
        </main>
    </div>
    <script src="/assets/js/app.js?v=responsive-4"></script>
</body>
</html>
