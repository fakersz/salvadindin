<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';

$user = require_auth();
$errors = [];
$success = flash('success');

$assetClasses = [
    'fixed_income' => 'Renda fixa',
    'stocks' => 'Acoes',
    'funds' => 'Fundos',
    'crypto' => 'Cripto',
    'real_estate' => 'Imobiliario',
    'other' => 'Outros',
];

$movementLabels = [
    'buy' => 'Aporte',
    'sell' => 'Retirada',
    'yield' => 'Rendimento',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $action = (string) ($_POST['action'] ?? 'create');

    if ($action === 'delete') {
        $investmentId = (int) ($_POST['investment_id'] ?? 0);

        if ($investmentId <= 0) {
            $errors[] = 'Investimento invalido.';
        }

        if (!$errors) {
            $stmt = db()->prepare('DELETE FROM investments WHERE id = :id AND user_id = :user_id');
            $stmt->execute([
                'id' => $investmentId,
                'user_id' => $user['id'],
            ]);

            flash('success', $stmt->rowCount() > 0 ? 'Investimento apagado.' : 'Investimento nao encontrado.');
            redirect('/investments.php');
        }
    }

    if ($action === 'create') {
        $assetName = trim((string) ($_POST['asset_name'] ?? ''));
        $assetClass = (string) ($_POST['asset_class'] ?? '');
        $movementType = (string) ($_POST['movement_type'] ?? '');
        $amount = str_replace(',', '.', (string) ($_POST['amount'] ?? '0'));
        $movementDate = (string) ($_POST['movement_date'] ?? '');
        $notes = trim((string) ($_POST['notes'] ?? ''));

        if (strlen($assetName) < 2) {
            $errors[] = 'Informe o nome do investimento.';
        }

        if (!array_key_exists($assetClass, $assetClasses)) {
            $errors[] = 'Escolha uma classe de ativo.';
        }

        if (!array_key_exists($movementType, $movementLabels)) {
            $errors[] = 'Escolha o tipo de movimentacao.';
        }

        if (!is_numeric($amount) || (float) $amount <= 0) {
            $errors[] = 'Informe um valor maior que zero.';
        }

        if (!$movementDate || strtotime($movementDate) === false) {
            $errors[] = 'Informe uma data valida.';
        }

        if (!$errors) {
            $stmt = db()->prepare(
                'INSERT INTO investments (user_id, asset_name, asset_class, movement_type, amount, movement_date, notes)
                 VALUES (:user_id, :asset_name, :asset_class, :movement_type, :amount, :movement_date, :notes)'
            );
            $stmt->execute([
                'user_id' => $user['id'],
                'asset_name' => $assetName,
                'asset_class' => $assetClass,
                'movement_type' => $movementType,
                'amount' => number_format((float) $amount, 2, '.', ''),
                'movement_date' => $movementDate,
                'notes' => $notes !== '' ? $notes : null,
            ]);

            flash('success', 'Investimento registrado.');
            redirect('/investments.php');
        }
    }
}

$stmt = db()->prepare(
    "SELECT
        COALESCE(SUM(CASE WHEN movement_type = 'buy' THEN amount WHEN movement_type = 'sell' THEN -amount ELSE 0 END), 0) AS invested_total,
        COALESCE(SUM(CASE WHEN movement_type = 'yield' THEN amount ELSE 0 END), 0) AS yield_total,
        COALESCE(SUM(CASE WHEN movement_type = 'buy' THEN amount ELSE 0 END), 0) AS total_buys,
        COALESCE(SUM(CASE WHEN movement_type = 'sell' THEN amount ELSE 0 END), 0) AS total_sells
     FROM investments
     WHERE user_id = :user_id"
);
$stmt->execute(['user_id' => $user['id']]);
$summary = $stmt->fetch();

$investedTotal = (float) $summary['invested_total'];
$yieldTotal = (float) $summary['yield_total'];
$portfolioTotal = $investedTotal + $yieldTotal;

$stmt = db()->prepare(
    "SELECT
        asset_class,
        COALESCE(SUM(CASE WHEN movement_type = 'buy' THEN amount WHEN movement_type = 'sell' THEN -amount WHEN movement_type = 'yield' THEN amount ELSE 0 END), 0) AS total
     FROM investments
     WHERE user_id = :user_id
     GROUP BY asset_class
     HAVING COALESCE(SUM(CASE WHEN movement_type = 'buy' THEN amount WHEN movement_type = 'sell' THEN -amount WHEN movement_type = 'yield' THEN amount ELSE 0 END), 0) > 0
     ORDER BY total DESC"
);
$stmt->execute(['user_id' => $user['id']]);
$allocation = $stmt->fetchAll();

$stmt = db()->prepare(
    "SELECT
        asset_name,
        asset_class,
        COALESCE(SUM(CASE WHEN movement_type = 'buy' THEN amount WHEN movement_type = 'sell' THEN -amount WHEN movement_type = 'yield' THEN amount ELSE 0 END), 0) AS total
     FROM investments
     WHERE user_id = :user_id
     GROUP BY asset_name, asset_class
     HAVING COALESCE(SUM(CASE WHEN movement_type = 'buy' THEN amount WHEN movement_type = 'sell' THEN -amount WHEN movement_type = 'yield' THEN amount ELSE 0 END), 0) > 0
     ORDER BY total DESC
     LIMIT 8"
);
$stmt->execute(['user_id' => $user['id']]);
$assets = $stmt->fetchAll();

$stmt = db()->prepare(
    "SELECT
        TO_CHAR(DATE_TRUNC('month', movement_date), 'YYYY-MM') AS month,
        COALESCE(SUM(CASE WHEN movement_type = 'buy' THEN amount WHEN movement_type = 'sell' THEN -amount WHEN movement_type = 'yield' THEN amount ELSE 0 END), 0) AS total
     FROM investments
     WHERE user_id = :user_id
     GROUP BY DATE_TRUNC('month', movement_date)
     ORDER BY DATE_TRUNC('month', movement_date) ASC
     LIMIT 12"
);
$stmt->execute(['user_id' => $user['id']]);
$monthly = $stmt->fetchAll();

$stmt = db()->prepare(
    'SELECT *
     FROM investments
     WHERE user_id = :user_id
     ORDER BY movement_date DESC, created_at DESC
     LIMIT 20'
);
$stmt->execute(['user_id' => $user['id']]);
$movements = $stmt->fetchAll();

$maxAllocation = max(array_map(static fn (array $row): float => (float) $row['total'], $allocation) ?: [0]);
$maxMonthly = max(array_map(static fn (array $row): float => abs((float) $row['total']), $monthly) ?: [0]);
$pieStops = [];
$pieCursor = 0.0;
$pieColors = ['#22C55E', '#15803D', '#86EFAC', '#b7791f', '#c24137', '#1F2937'];

foreach ($allocation as $index => $row) {
    $percent = $portfolioTotal > 0 ? ((float) $row['total'] / $portfolioTotal) * 100 : 0;
    $next = $pieCursor + $percent;
    $color = $pieColors[$index % count($pieColors)];
    $pieStops[] = sprintf('%s %.2f%% %.2f%%', $color, $pieCursor, $next);
    $pieCursor = $next;
}

$pieStyle = $pieStops ? 'background: conic-gradient(' . implode(', ', $pieStops) . ');' : '';
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Investimentos | Salva DinDin</title>
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
                <a class="nav-item" href="/transactions.php">Movimentacoes</a>
                <a class="nav-item" href="/goals.php">Metas</a>
                <a class="nav-item active" href="/investments.php">Investimentos</a>
                <?php if (is_admin_user($user)): ?>
                    <a class="nav-item" href="/admin/">Admin</a>
                <?php endif; ?>
            </nav>
            <a class="nav-item muted" href="/logout.php">Sair</a>
        </aside>

        <main class="content">
            <header class="topbar">
                <div>
                    <p class="eyebrow">Carteira</p>
                    <h1>Investimentos</h1>
                </div>
            </header>

            <?php if ($success): ?>
                <div class="alert alert-success"><?= e($success) ?></div>
            <?php endif; ?>

            <?php foreach ($errors as $error): ?>
                <div class="alert alert-error"><?= e($error) ?></div>
            <?php endforeach; ?>

            <section class="metrics-grid" aria-label="Resumo de investimentos">
                <article class="metric-card highlight">
                    <span>Total da carteira</span>
                    <strong><?= money($portfolioTotal) ?></strong>
                    <small>Aportes liquidos + rendimentos</small>
                </article>
                <article class="metric-card">
                    <span>Aportado liquido</span>
                    <strong><?= money($investedTotal) ?></strong>
                    <small>Aportes menos retiradas</small>
                </article>
                <article class="metric-card">
                    <span>Rendimentos</span>
                    <strong class="positive"><?= money($yieldTotal) ?></strong>
                    <small>Dividendos, juros e ganhos</small>
                </article>
                <article class="metric-card">
                    <span>Movimentacoes</span>
                    <strong><?= count($movements) ?></strong>
                    <small>Ultimos registros listados</small>
                </article>
            </section>

            <section class="two-column align-start">
                <div class="panel">
                    <div class="panel-heading">
                        <h2>Novo registro</h2>
                    </div>

                    <form method="post" class="form-grid" novalidate>
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="create">
                        <label class="full">
                            <span>Investimento</span>
                            <input type="text" name="asset_name" placeholder="Ex.: Tesouro Selic, PETR4, Bitcoin" required>
                        </label>

                        <label>
                            <span>Classe</span>
                            <select name="asset_class" required>
                                <?php foreach ($assetClasses as $value => $label): ?>
                                    <option value="<?= e($value) ?>"><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>

                        <label>
                            <span>Tipo</span>
                            <select name="movement_type" required>
                                <?php foreach ($movementLabels as $value => $label): ?>
                                    <option value="<?= e($value) ?>"><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>

                        <label>
                            <span>Valor</span>
                            <input type="number" name="amount" step="0.01" min="0.01" placeholder="0,00" required>
                        </label>

                        <label>
                            <span>Data</span>
                            <input type="date" name="movement_date" value="<?= e(date('Y-m-d')) ?>" required>
                        </label>

                        <label class="full">
                            <span>Observacao</span>
                            <input type="text" name="notes" placeholder="Ex.: aporte mensal, dividendo, resgate parcial">
                        </label>

                        <button class="button button-primary full" type="submit">Salvar investimento</button>
                    </form>
                </div>

                <div class="panel">
                    <div class="panel-heading">
                        <h2>Distribuicao</h2>
                        <span><?= count($allocation) ?> classes</span>
                    </div>

                    <?php if (!$allocation): ?>
                        <p class="empty-state">Cadastre seu primeiro investimento.</p>
                    <?php else: ?>
                        <div class="investment-chart">
                            <div class="donut-chart" style="<?= e($pieStyle) ?>">
                                <span><?= number_format($portfolioTotal > 0 ? 100 : 0, 0) ?>%</span>
                            </div>
                            <div class="chart-list">
                                <?php foreach ($allocation as $index => $row): ?>
                                    <?php $percent = $portfolioTotal > 0 ? ((float) $row['total'] / $portfolioTotal) * 100 : 0; ?>
                                    <div class="chart-row">
                                        <span class="chart-dot" style="background: <?= e($pieColors[$index % count($pieColors)]) ?>"></span>
                                        <strong><?= e($assetClasses[$row['asset_class']] ?? $row['asset_class']) ?></strong>
                                        <small><?= money($row['total']) ?> · <?= number_format($percent, 1, ',', '.') ?>%</small>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <section class="two-column align-start">
                <div class="panel">
                    <div class="panel-heading">
                        <h2>Por ativo</h2>
                        <span><?= count($assets) ?> ativos</span>
                    </div>

                    <div class="bar-list">
                        <?php if (!$assets): ?>
                            <p class="empty-state">Nenhum ativo cadastrado ainda.</p>
                        <?php endif; ?>

                        <?php foreach ($assets as $asset): ?>
                            <?php $width = $portfolioTotal > 0 ? min(((float) $asset['total'] / $portfolioTotal) * 100, 100) : 0; ?>
                            <article class="bar-row">
                                <div>
                                    <strong><?= e($asset['asset_name']) ?></strong>
                                    <span><?= e($assetClasses[$asset['asset_class']] ?? $asset['asset_class']) ?> · <?= money($asset['total']) ?></span>
                                </div>
                                <div class="bar-track"><span style="width: <?= e((string) $width) ?>%"></span></div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="panel">
                    <div class="panel-heading">
                        <h2>Evolucao mensal</h2>
                        <span><?= count($monthly) ?> meses</span>
                    </div>

                    <div class="monthly-chart">
                        <?php if (!$monthly): ?>
                            <p class="empty-state">Sem dados para o grafico.</p>
                        <?php endif; ?>

                        <?php foreach ($monthly as $month): ?>
                            <?php $height = $maxMonthly > 0 ? max((abs((float) $month['total']) / $maxMonthly) * 100, 6) : 0; ?>
                            <div class="month-bar">
                                <span style="height: <?= e((string) $height) ?>%"></span>
                                <small><?= e(date('m/y', strtotime($month['month'] . '-01'))) ?></small>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>

            <section class="panel">
                <div class="panel-heading">
                    <h2>Historico</h2>
                    <span><?= count($movements) ?> registros</span>
                </div>

                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Investimento</th>
                                <th>Classe</th>
                                <th>Tipo</th>
                                <th>Data</th>
                                <th>Observacao</th>
                                <th class="text-right">Valor</th>
                                <th class="text-right">Acoes</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!$movements): ?>
                                <tr>
                                    <td colspan="7" class="empty-state">Nenhum investimento registrado.</td>
                                </tr>
                            <?php endif; ?>

                            <?php foreach ($movements as $movement): ?>
                                <tr>
                                    <td><?= e($movement['asset_name']) ?></td>
                                    <td><?= e($assetClasses[$movement['asset_class']] ?? $movement['asset_class']) ?></td>
                                    <td><?= e($movementLabels[$movement['movement_type']] ?? $movement['movement_type']) ?></td>
                                    <td><?= format_date($movement['movement_date']) ?></td>
                                    <td><?= e($movement['notes'] ?? '-') ?></td>
                                    <td class="text-right <?= $movement['movement_type'] === 'sell' ? 'negative' : 'positive' ?>">
                                        <?= $movement['movement_type'] === 'sell' ? '-' : '+' ?><?= money($movement['amount']) ?>
                                    </td>
                                    <td class="text-right">
                                        <form method="post" class="inline-form" data-confirm="Apagar este investimento?">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="investment_id" value="<?= e($movement['id']) ?>">
                                            <button class="button button-danger" type="submit">Apagar</button>
                                        </form>
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
