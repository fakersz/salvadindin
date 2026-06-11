<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';

$user = require_auth();
[$monthStart, $monthEnd] = month_bounds();
$previousMonthStart = date('Y-m-01', strtotime($monthStart . ' -1 month'));
$previousMonthEnd = date('Y-m-t', strtotime($monthStart . ' -1 month'));

$stmt = db()->prepare(
    "SELECT
        COALESCE(SUM(CASE WHEN transaction_type = 'income' THEN amount ELSE -amount END), 0) AS balance,
        COALESCE(SUM(CASE WHEN transaction_type = 'income' AND transaction_date BETWEEN :income_start AND :income_end THEN amount ELSE 0 END), 0) AS month_income,
        COALESCE(SUM(CASE WHEN transaction_type = 'expense' AND transaction_date BETWEEN :expense_start AND :expense_end THEN amount ELSE 0 END), 0) AS month_expense,
        COALESCE(SUM(CASE WHEN transaction_type = 'expense' AND payment_method = 'credit' AND transaction_date BETWEEN :credit_start AND :credit_end THEN amount ELSE 0 END), 0) AS month_credit,
        COALESCE(SUM(CASE WHEN transaction_type = 'expense' AND payment_method = 'debit' AND transaction_date BETWEEN :debit_start AND :debit_end THEN amount ELSE 0 END), 0) AS month_debit,
        COALESCE(SUM(CASE WHEN transaction_type = 'expense' AND is_essential = TRUE AND transaction_date BETWEEN :essential_start AND :essential_end THEN amount ELSE 0 END), 0) AS essential_expense,
        COALESCE(SUM(CASE WHEN transaction_type = 'expense' AND is_essential = FALSE AND transaction_date BETWEEN :variable_start AND :variable_end THEN amount ELSE 0 END), 0) AS variable_expense
     FROM transactions
     WHERE user_id = :user_id"
);
$stmt->execute([
    'user_id' => $user['id'],
    'income_start' => $monthStart,
    'income_end' => $monthEnd,
    'expense_start' => $monthStart,
    'expense_end' => $monthEnd,
    'credit_start' => $monthStart,
    'credit_end' => $monthEnd,
    'debit_start' => $monthStart,
    'debit_end' => $monthEnd,
    'essential_start' => $monthStart,
    'essential_end' => $monthEnd,
    'variable_start' => $monthStart,
    'variable_end' => $monthEnd,
]);
$summary = $stmt->fetch();

$balance = (float) $summary['balance'];
$monthIncome = (float) $summary['month_income'];
$monthExpense = (float) $summary['month_expense'];
$monthCredit = (float) $summary['month_credit'];
$monthDebit = (float) $summary['month_debit'];
$essentialExpense = (float) $summary['essential_expense'];
$variableExpense = (float) $summary['variable_expense'];
$monthSavings = $monthIncome - $monthExpense;

$stmt = db()->prepare(
    "SELECT
        COALESCE(SUM(CASE WHEN transaction_type = 'income' AND transaction_date BETWEEN :income_start AND :income_end THEN amount ELSE 0 END), 0) AS income,
        COALESCE(SUM(CASE WHEN transaction_type = 'expense' AND transaction_date BETWEEN :expense_start AND :expense_end THEN amount ELSE 0 END), 0) AS expense
     FROM transactions
     WHERE user_id = :user_id"
);
$stmt->execute([
    'user_id' => $user['id'],
    'income_start' => $previousMonthStart,
    'income_end' => $previousMonthEnd,
    'expense_start' => $previousMonthStart,
    'expense_end' => $previousMonthEnd,
]);
$previousSummary = $stmt->fetch();
$previousIncome = (float) $previousSummary['income'];
$previousExpense = (float) $previousSummary['expense'];

$stmt = db()->prepare(
    "SELECT COALESCE(SUM(CASE WHEN movement_type = 'sell' THEN -amount ELSE amount END), 0)
     FROM investments
     WHERE user_id = :user_id AND movement_date BETWEEN :start AND :end"
);
$stmt->execute([
    'user_id' => $user['id'],
    'start' => $monthStart,
    'end' => $monthEnd,
]);
$monthInvestments = (float) $stmt->fetchColumn();
$remainingToSpend = $monthIncome - $monthExpense - $monthInvestments;

$stmt = db()->prepare(
    'SELECT t.*, c.name AS category_name
     FROM transactions t
     LEFT JOIN categories c ON c.id = t.category_id
     WHERE t.user_id = :user_id
     ORDER BY t.transaction_date DESC, t.created_at DESC
     LIMIT 6'
);
$stmt->execute(['user_id' => $user['id']]);
$recentTransactions = $stmt->fetchAll();

$stmt = db()->prepare(
    "SELECT c.name AS category_name, COALESCE(SUM(t.amount), 0) AS total
     FROM transactions t
     LEFT JOIN categories c ON c.id = t.category_id
     WHERE t.user_id = :user_id
       AND t.transaction_type = 'expense'
       AND t.transaction_date BETWEEN :start AND :end
     GROUP BY c.name
     ORDER BY total DESC
     LIMIT 6"
);
$stmt->execute([
    'user_id' => $user['id'],
    'start' => $monthStart,
    'end' => $monthEnd,
]);
$categoryAllocation = $stmt->fetchAll();
$maxCategoryTotal = max(array_map(static fn (array $row): float => (float) $row['total'], $categoryAllocation) ?: [0]);

$stmt = db()->prepare(
    'SELECT *, CASE WHEN target_amount > 0 THEN LEAST((current_amount / target_amount) * 100, 100) ELSE 0 END AS progress
     FROM goals
     WHERE user_id = :user_id
     ORDER BY deadline ASC NULLS LAST, created_at DESC
     LIMIT 4'
);
$stmt->execute(['user_id' => $user['id']]);
$goals = $stmt->fetchAll();
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dashboard | Salva DinDin</title>
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
                <a class="nav-item active" href="/dashboard.php">Dashboard</a>
                <a class="nav-item" href="/transactions.php">Movimentacoes</a>
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
                    <p class="eyebrow">Visao geral</p>
                    <h1>Ola, <?= e(explode(' ', $user['name'])[0]) ?></h1>
                </div>
                <a class="button button-secondary" href="/transactions.php">Nova movimentacao</a>
            </header>

            <section class="metrics-grid" aria-label="Resumo financeiro">
                <article class="metric-card highlight">
                    <span>Saldo atual</span>
                    <strong><?= money($balance) ?></strong>
                    <small>Total consolidado</small>
                </article>
                <article class="metric-card">
                    <span>Receitas do mes</span>
                    <strong><?= money($monthIncome) ?></strong>
                    <small>Mês anterior: <?= money($previousIncome) ?></small>
                </article>
                <article class="metric-card">
                    <span>Despesas do mes</span>
                    <strong><?= money($monthExpense) ?></strong>
                    <small>Mês anterior: <?= money($previousExpense) ?></small>
                </article>
                <article class="metric-card">
                    <span>Restante para gastar</span>
                    <strong class="<?= $remainingToSpend >= 0 ? 'positive' : 'negative' ?>"><?= money($remainingToSpend) ?></strong>
                    <small>Receitas - despesas - investimentos</small>
                </article>
            </section>

            <section class="insights-grid" aria-label="Indicadores da planilha">
                <article class="panel insight-card">
                    <div class="panel-heading">
                        <h2>Uso por pagamento</h2>
                    </div>
                    <div class="mini-metrics">
                        <div>
                            <span>Credito</span>
                            <strong><?= money($monthCredit) ?></strong>
                        </div>
                        <div>
                            <span>Debito</span>
                            <strong><?= money($monthDebit) ?></strong>
                        </div>
                    </div>
                    <?php if ($monthCredit > 0): ?>
                        <div class="alert alert-error compact-alert">Acompanhe estes gastos para nao esquecer na proxima fatura.</div>
                    <?php endif; ?>
                </article>

                <article class="panel insight-card">
                    <div class="panel-heading">
                        <h2>Essencial x variavel</h2>
                    </div>
                    <div class="mini-metrics">
                        <div>
                            <span>Essencial</span>
                            <strong><?= money($essentialExpense) ?></strong>
                        </div>
                        <div>
                            <span>Variavel</span>
                            <strong><?= money($variableExpense) ?></strong>
                        </div>
                    </div>
                </article>

                <article class="panel insight-card">
                    <div class="panel-heading">
                        <h2>Reservas & investimentos</h2>
                    </div>
                    <strong class="insight-value"><?= money($monthInvestments) ?></strong>
                    <small>Movimentos registrados no modulo de investimentos</small>
                </article>
            </section>

            <section class="two-column">
                <div class="panel">
                    <div class="panel-heading">
                        <h2>Ultimas movimentacoes</h2>
                        <a href="/transactions.php">Ver todas</a>
                    </div>

                    <div class="list">
                        <?php if (!$recentTransactions): ?>
                            <p class="empty-state">Nenhuma movimentacao cadastrada ainda.</p>
                        <?php endif; ?>

                        <?php foreach ($recentTransactions as $transaction): ?>
                            <article class="list-row">
                                <div>
                                    <strong><?= e($transaction['title']) ?></strong>
                                    <span><?= e($transaction['category_name'] ?? 'Sem categoria') ?> · <?= format_date($transaction['transaction_date']) ?></span>
                                </div>
                                <b class="<?= $transaction['transaction_type'] === 'income' ? 'positive' : 'negative' ?>">
                                    <?= $transaction['transaction_type'] === 'income' ? '+' : '-' ?><?= money($transaction['amount']) ?>
                                </b>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="panel">
                    <div class="panel-heading">
                        <h2>Alocacao de categorias</h2>
                        <span><?= count($categoryAllocation) ?> categorias</span>
                    </div>

                    <div class="bar-list">
                        <?php if (!$categoryAllocation): ?>
                            <p class="empty-state">Sem despesas no mes.</p>
                        <?php endif; ?>

                        <?php foreach ($categoryAllocation as $category): ?>
                            <?php $width = $maxCategoryTotal > 0 ? ((float) $category['total'] / $maxCategoryTotal) * 100 : 0; ?>
                            <article class="bar-row">
                                <div>
                                    <strong><?= e($category['category_name'] ?? 'Sem categoria') ?></strong>
                                    <span><?= money($category['total']) ?></span>
                                </div>
                                <div class="bar-track"><span style="width: <?= e((string) $width) ?>%"></span></div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>
        </main>
    </div>
    <script src="/assets/js/app.js?v=responsive-1"></script>
</body>
</html>
