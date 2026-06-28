<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';

$user = require_auth();

$chartColors = ['#22C55E', '#0EA5E9', '#F59E0B', '#EF4444', '#8B5CF6', '#14B8A6', '#64748B', '#84CC16'];
function chart_total(array $rows): float
{
    return array_reduce(
        $rows,
        static fn (float $total, array $row): float => $total + (float) $row['total'],
        0.0
    );
}

function chart_style(array $rows, array $colors): string
{
    $total = chart_total($rows);

    if ($total <= 0) {
        return '';
    }

    $stops = [];
    $cursor = 0.0;

    foreach ($rows as $index => $row) {
        $percent = ((float) $row['total'] / $total) * 100;
        $next = $cursor + $percent;
        $stops[] = sprintf('%s %.2f%% %.2f%%', $colors[$index % count($colors)], $cursor, $next);
        $cursor = $next;
    }

    return 'background: conic-gradient(' . implode(', ', $stops) . ');';
}

$stmt = db()->prepare(
    "SELECT COALESCE(c.name, 'Sem categoria') AS label, COALESCE(SUM(t.amount), 0) AS total
     FROM transactions t
     LEFT JOIN categories c ON c.id = t.category_id
     WHERE t.user_id = :user_id
       AND t.transaction_type = 'expense'
     GROUP BY COALESCE(c.name, 'Sem categoria')
     ORDER BY total DESC
     LIMIT 8"
);
$stmt->execute(['user_id' => $user['id']]);
$expenseRows = $stmt->fetchAll();

$stmt = db()->prepare(
    "SELECT COALESCE(c.name, 'Sem categoria') AS label, COALESCE(SUM(t.amount), 0) AS total
     FROM transactions t
     LEFT JOIN categories c ON c.id = t.category_id
     WHERE t.user_id = :user_id
       AND t.transaction_type = 'income'
     GROUP BY COALESCE(c.name, 'Sem categoria')
     ORDER BY total DESC
     LIMIT 8"
);
$stmt->execute(['user_id' => $user['id']]);
$incomeRows = $stmt->fetchAll();

$stmt = db()->prepare(
    "SELECT
        COALESCE(SUM(CASE WHEN transaction_type = 'income' THEN amount ELSE 0 END), 0) AS income,
        COALESCE(SUM(CASE WHEN transaction_type = 'expense' THEN amount ELSE 0 END), 0) AS expense
     FROM transactions
     WHERE user_id = :user_id"
);
$stmt->execute(['user_id' => $user['id']]);
$transactionSummary = $stmt->fetch();
$incomeExpenseRows = [
    ['label' => 'Receitas', 'total' => (float) $transactionSummary['income']],
    ['label' => 'Despesas', 'total' => (float) $transactionSummary['expense']],
];
$incomeExpenseRows = array_values(array_filter(
    $incomeExpenseRows,
    static fn (array $row): bool => (float) $row['total'] > 0
));

$stmt = db()->prepare(
    "SELECT
        COALESCE(SUM(LEAST(current_amount, target_amount)), 0) AS completed,
        COALESCE(SUM(GREATEST(target_amount - current_amount, 0)), 0) AS remaining
     FROM goals
     WHERE user_id = :user_id"
);
$stmt->execute(['user_id' => $user['id']]);
$goalSummary = $stmt->fetch();
$goalRows = [
    ['label' => 'Concluido', 'total' => (float) $goalSummary['completed']],
    ['label' => 'Falta atingir', 'total' => (float) $goalSummary['remaining']],
];
$goalRows = array_values(array_filter(
    $goalRows,
    static fn (array $row): bool => (float) $row['total'] > 0
));

$charts = [
    [
        'title' => 'Gastos e despesas',
        'subtitle' => 'Por categoria em todo o historico',
        'rows' => $expenseRows,
        'total_label' => 'Total gasto',
    ],
    [
        'title' => 'Ganhos e receitas',
        'subtitle' => 'Por categoria em todo o historico',
        'rows' => $incomeRows,
        'total_label' => 'Total recebido',
    ],
    [
        'title' => 'Ganhos x gastos',
        'subtitle' => 'Comparativo de todo o historico',
        'rows' => $incomeExpenseRows,
        'total_label' => 'Movimentado',
    ],
    [
        'title' => 'Metas financeiras',
        'subtitle' => 'Progresso total das metas cadastradas',
        'rows' => $goalRows,
        'total_label' => 'Total planejado',
    ],
];
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Graficos | Salva DinDin</title>
    <link rel="stylesheet" href="/assets/css/style.css?v=responsive-3">
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
                <a class="nav-item" href="/transactions.php">Movimentacoes</a>
                <a class="nav-item" href="/whatsapp.php"><?= whatsapp_integration_enabled() ? 'Whatsapp' : 'WhatsApp desativado' ?></a>
                <a class="nav-item active" href="/graficos.php">Graficos</a>
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
                    <p class="eyebrow">Analise visual</p>
                    <h1>Graficos</h1>
                </div>
            </header>

            <section class="chart-grid" aria-label="Graficos financeiros">
                <?php foreach ($charts as $chart): ?>
                    <?php
                        $rows = $chart['rows'];
                        $total = chart_total($rows);
                        $style = chart_style($rows, $chartColors);
                    ?>
                    <article class="panel chart-panel">
                        <div class="panel-heading">
                            <div>
                                <h2><?= e($chart['title']) ?></h2>
                                <span><?= e($chart['subtitle']) ?></span>
                            </div>
                        </div>

                        <?php if ($total <= 0): ?>
                            <p class="empty-state">Sem dados para este grafico.</p>
                        <?php else: ?>
                            <div class="chart-visual chart-large">
                                <div class="donut-chart" style="<?= e($style) ?>">
                                    <span><?= money($total) ?></span>
                                </div>
                                <div class="chart-list">
                                    <strong class="chart-total"><?= e($chart['total_label']) ?>: <?= money($total) ?></strong>
                                    <?php foreach ($rows as $index => $row): ?>
                                        <?php $percent = $total > 0 ? ((float) $row['total'] / $total) * 100 : 0; ?>
                                        <div class="chart-row">
                                            <span class="chart-dot" style="background: <?= e($chartColors[$index % count($chartColors)]) ?>"></span>
                                            <strong><?= e($row['label']) ?></strong>
                                            <small><?= money($row['total']) ?> · <?= number_format($percent, 1, ',', '.') ?>%</small>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </section>
        </main>
    </div>
    <script src="/assets/js/app.js?v=responsive-3"></script>
</body>
</html>
