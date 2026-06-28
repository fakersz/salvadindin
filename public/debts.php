<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';

$user = require_auth();
$today = date('Y-m-d');
[$monthStart, $monthEnd] = month_bounds();
$errors = [];
$success = flash('success');

$debtTypes = [
    'monthly' => 'Mensal',
    'installment' => 'Parcelamento',
    'single' => 'Unica',
];

$debtPanels = [
    'monthly' => 'Dividas mensais',
    'installment' => 'Parcelamentos',
    'single' => 'Dividas unicas',
];

function monthly_due_date(string $firstDueDate, int $monthOffset): string
{
    $first = new DateTimeImmutable($firstDueDate);
    $targetMonth = $first->modify('first day of this month')->modify('+' . $monthOffset . ' months');
    $day = min((int) $first->format('d'), (int) $targetMonth->format('t'));

    return $targetMonth->setDate(
        (int) $targetMonth->format('Y'),
        (int) $targetMonth->format('m'),
        $day
    )->format('Y-m-d');
}

function financing_month_count(string $startDate, string $endDate): int
{
    $start = new DateTimeImmutable($startDate);
    $end = new DateTimeImmutable($endDate);

    return (((int) $end->format('Y') - (int) $start->format('Y')) * 12)
        + ((int) $end->format('m') - (int) $start->format('m'))
        + 1;
}

function financing_due_date(string $startDate, int $monthOffset, int $dueDay): string
{
    $start = new DateTimeImmutable($startDate);
    $targetMonth = $start->modify('first day of this month')->modify('+' . $monthOffset . ' months');
    $day = min($dueDay, (int) $targetMonth->format('t'));

    return $targetMonth->setDate(
        (int) $targetMonth->format('Y'),
        (int) $targetMonth->format('m'),
        $day
    )->format('Y-m-d');
}

function overdue_days(string $dueDate, string $today): int
{
    if ($dueDate >= $today) {
        return 0;
    }

    $due = new DateTimeImmutable($dueDate);
    $now = new DateTimeImmutable($today);

    return max(0, (int) $due->diff($now)->format('%a'));
}

function debt_amount_with_interest(array $debt, string $today): float
{
    $amount = (float) $debt['amount'];
    $lateFeeRate = (float) ($debt['late_fee_rate'] ?? 0);
    $monthlyInterestRate = (float) ($debt['monthly_interest_rate'] ?? 0);

    if ($debt['status'] !== 'pending' || $debt['debt_type'] !== 'installment') {
        return $amount;
    }

    $days = overdue_days((string) $debt['due_date'], $today);

    if ($days <= 0) {
        return $amount;
    }

    $lateFee = $lateFeeRate > 0 ? $amount * ($lateFeeRate / 100) : 0;
    $proRataInterest = $monthlyInterestRate > 0 ? $amount * ($monthlyInterestRate / 100) * ($days / 30) : 0;

    return $amount + $lateFee + $proRataInterest;
}

function installment_group_key(array $debt): string
{
    if (!empty($debt['debt_group_key'])) {
        return 'group:' . (string) $debt['debt_group_key'];
    }

    return implode('|', [
        (string) $debt['title'],
        (string) ($debt['financing_start_date'] ?? ''),
        (string) ($debt['financing_end_date'] ?? ''),
        (string) ($debt['financing_due_day'] ?? ''),
        (string) ($debt['financing_total'] ?? ''),
        (string) ($debt['late_fee_rate'] ?? ''),
        (string) ($debt['monthly_interest_rate'] ?? ''),
        (string) ($debt['amount'] ?? ''),
        (string) ($debt['notes'] ?? ''),
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'create') {
        $title = trim((string) ($_POST['title'] ?? ''));
        $amount = str_replace(',', '.', (string) ($_POST['amount'] ?? '0'));
        $type = (string) ($_POST['debt_type'] ?? '');
        $dueDate = (string) ($_POST['due_date'] ?? '');
        $installments = (int) ($_POST['installments_total'] ?? 1);
        $paidInstallments = (int) ($_POST['paid_installments'] ?? 0);
        $financingStartDate = (string) ($_POST['financing_start_date'] ?? '');
        $financingEndDate = (string) ($_POST['financing_end_date'] ?? '');
        $financingDueDay = (int) ($_POST['financing_due_day'] ?? 1);
        $financingTotal = str_replace(',', '.', (string) ($_POST['financing_total'] ?? '0'));
        $lateFeeRate = str_replace(',', '.', trim((string) ($_POST['late_fee_rate'] ?? '')));
        $monthlyInterestRate = str_replace(',', '.', trim((string) ($_POST['monthly_interest_rate'] ?? '')));
        $notes = trim((string) ($_POST['notes'] ?? ''));
        $debtGroupKey = $type === 'installment' ? bin2hex(random_bytes(16)) : null;

        if (strlen($title) < 2) {
            $errors[] = 'Informe o nome da divida.';
        }

        if (!is_numeric($amount) || (float) $amount <= 0) {
            $errors[] = 'Informe um valor maior que zero.';
        }

        if (!array_key_exists($type, $debtTypes)) {
            $errors[] = 'Escolha o tipo da divida.';
        }

        if ($type !== 'installment' && (!$dueDate || strtotime($dueDate) === false)) {
            $errors[] = 'Informe uma data de vencimento valida.';
        }

        if ($type === 'monthly' && ($installments < 1 || $installments > 240)) {
            $errors[] = 'Informe uma quantidade de parcelas entre 1 e 240.';
        }

        if ($type === 'monthly' && ($paidInstallments < 0 || $paidInstallments > $installments)) {
            $errors[] = 'Informe uma quantidade de parcelas ja pagas entre 0 e o total de parcelas.';
        }

        if ($type === 'installment') {
            if (!$financingStartDate || strtotime($financingStartDate) === false) {
                $errors[] = 'Informe a data de inicio do parcelamento.';
            }

            if (!$financingEndDate || strtotime($financingEndDate) === false) {
                $errors[] = 'Informe a data de fim do parcelamento.';
            }

            if ($financingStartDate && $financingEndDate && $financingEndDate < $financingStartDate) {
                $errors[] = 'A data de fim precisa ser depois da data de inicio.';
            }

            if ($financingDueDay < 1 || $financingDueDay > 31) {
                $errors[] = 'Informe um dia de vencimento entre 1 e 31.';
            }

            if (!is_numeric($financingTotal) || (float) $financingTotal <= 0) {
                $errors[] = 'Informe o valor total parcelado.';
            }

            if ($lateFeeRate !== '' && (!is_numeric($lateFeeRate) || (float) $lateFeeRate < 0)) {
                $errors[] = 'Informe uma multa valida.';
            }

            if ($monthlyInterestRate !== '' && (!is_numeric($monthlyInterestRate) || (float) $monthlyInterestRate < 0)) {
                $errors[] = 'Informe um juros ao mes valido.';
            }
        }

        if (!$errors) {
            $stmt = db()->prepare(
                'INSERT INTO debts
                    (user_id, title, amount, debt_type, due_date, status, paid_at, installment_number, installments_total, notes, financing_total, financing_start_date, financing_end_date, financing_due_day, late_fee_rate, monthly_interest_rate, debt_group_key)
                 VALUES
                    (:user_id, :title, :amount, :debt_type, :due_date, :status, :paid_at, :installment_number, :installments_total, :notes, :financing_total, :financing_start_date, :financing_end_date, :financing_due_day, :late_fee_rate, :monthly_interest_rate, :debt_group_key)'
            );

            $totalInstallments = match ($type) {
                'monthly' => $installments,
                'installment' => financing_month_count($financingStartDate, $financingEndDate),
                default => 1,
            };

            for ($index = 1; $index <= $totalInstallments; $index++) {
                $installmentDueDate = match ($type) {
                    'monthly' => monthly_due_date($dueDate, $index - 1),
                    'installment' => financing_due_date($financingStartDate, $index - 1, $financingDueDay),
                    default => $dueDate,
                };
                $isAlreadyPaid = ($type === 'monthly' && $index <= $paidInstallments)
                    || ($type === 'installment' && $installmentDueDate < $monthStart);

                $stmt->execute([
                    'user_id' => $user['id'],
                    'title' => $title,
                    'amount' => number_format((float) $amount, 2, '.', ''),
                    'debt_type' => $type,
                    'due_date' => $installmentDueDate,
                    'status' => $isAlreadyPaid ? 'paid' : 'pending',
                    'paid_at' => $isAlreadyPaid ? min($installmentDueDate, $today) : null,
                    'installment_number' => $index,
                    'installments_total' => $totalInstallments,
                    'notes' => $notes !== '' ? $notes : null,
                    'financing_total' => $type === 'installment' ? number_format((float) $financingTotal, 2, '.', '') : null,
                    'financing_start_date' => $type === 'installment' ? $financingStartDate : null,
                    'financing_end_date' => $type === 'installment' ? $financingEndDate : null,
                    'financing_due_day' => $type === 'installment' ? $financingDueDay : null,
                    'late_fee_rate' => $type === 'installment' && $lateFeeRate !== '' ? number_format((float) $lateFeeRate, 3, '.', '') : '0',
                    'monthly_interest_rate' => $type === 'installment' && $monthlyInterestRate !== '' ? number_format((float) $monthlyInterestRate, 3, '.', '') : '0',
                    'debt_group_key' => $debtGroupKey,
                ]);
            }

            flash('success', $totalInstallments > 1 ? 'Parcelas cadastradas.' : 'Divida cadastrada.');
            redirect('/debts.php');
        }
    }

    if ($action === 'mark_paid') {
        $debtId = (int) ($_POST['debt_id'] ?? 0);

        if ($debtId <= 0) {
            $errors[] = 'Divida invalida.';
        }

        if (!$errors) {
            $stmt = db()->prepare(
                'UPDATE debts
                 SET status = :status, paid_at = CURRENT_DATE
                 WHERE id = :id AND user_id = :user_id'
            );
            $stmt->execute([
                'id' => $debtId,
                'user_id' => $user['id'],
                'status' => 'paid',
            ]);

            flash('success', $stmt->rowCount() > 0 ? 'Divida marcada como paga.' : 'Divida nao encontrada.');
            redirect('/debts.php');
        }
    }

    if ($action === 'mark_pending') {
        $debtId = (int) ($_POST['debt_id'] ?? 0);

        if ($debtId <= 0) {
            $errors[] = 'Divida invalida.';
        }

        if (!$errors) {
            $stmt = db()->prepare(
                'UPDATE debts
                 SET status = :status, paid_at = NULL
                 WHERE id = :id AND user_id = :user_id'
            );
            $stmt->execute([
                'id' => $debtId,
                'user_id' => $user['id'],
                'status' => 'pending',
            ]);

            flash('success', $stmt->rowCount() > 0 ? 'Divida reaberta.' : 'Divida nao encontrada.');
            redirect('/debts.php');
        }
    }

    if ($action === 'delete') {
        $debtId = (int) ($_POST['debt_id'] ?? 0);

        if ($debtId <= 0) {
            $errors[] = 'Divida invalida.';
        }

        if (!$errors) {
            $stmt = db()->prepare('DELETE FROM debts WHERE id = :id AND user_id = :user_id');
            $stmt->execute([
                'id' => $debtId,
                'user_id' => $user['id'],
            ]);

            flash('success', $stmt->rowCount() > 0 ? 'Divida apagada.' : 'Divida nao encontrada.');
            redirect('/debts.php');
        }
    }

    if ($action === 'delete_group') {
        $debtId = (int) ($_POST['debt_id'] ?? 0);

        if ($debtId <= 0) {
            $errors[] = 'Parcelamento invalido.';
        }

        if (!$errors) {
            $stmt = db()->prepare(
                'SELECT *
                 FROM debts
                 WHERE id = :id AND user_id = :user_id AND debt_type = :debt_type
                 LIMIT 1'
            );
            $stmt->execute([
                'id' => $debtId,
                'user_id' => $user['id'],
                'debt_type' => 'installment',
            ]);
            $debt = $stmt->fetch();

            if (!$debt) {
                flash('success', 'Parcelamento nao encontrado.');
                redirect('/debts.php');
            }

            if (!empty($debt['debt_group_key'])) {
                $deleteStmt = db()->prepare(
                    'DELETE FROM debts
                     WHERE user_id = :user_id
                       AND debt_type = :debt_type
                       AND debt_group_key = :debt_group_key'
                );
                $deleteStmt->execute([
                    'user_id' => $user['id'],
                    'debt_type' => 'installment',
                    'debt_group_key' => $debt['debt_group_key'],
                ]);
            } else {
                $deleteStmt = db()->prepare(
                    'DELETE FROM debts
                     WHERE user_id = :user_id
                       AND debt_type = :debt_type
                       AND title = :title
                       AND amount = :amount
                       AND COALESCE(notes, \'\') = COALESCE(:notes, \'\')
                       AND COALESCE(financing_total, 0::numeric) = COALESCE(CAST(:financing_total AS numeric), 0::numeric)
                       AND COALESCE(financing_start_date, DATE \'1900-01-01\') = COALESCE(CAST(:financing_start_date AS date), DATE \'1900-01-01\')
                       AND COALESCE(financing_end_date, DATE \'1900-01-01\') = COALESCE(CAST(:financing_end_date AS date), DATE \'1900-01-01\')
                       AND COALESCE(financing_due_day, 0) = COALESCE(CAST(:financing_due_day AS integer), 0)
                       AND COALESCE(late_fee_rate, 0::numeric) = COALESCE(CAST(:late_fee_rate AS numeric), 0::numeric)
                       AND COALESCE(monthly_interest_rate, 0::numeric) = COALESCE(CAST(:monthly_interest_rate AS numeric), 0::numeric)
                       AND installments_total = :installments_total'
                );
                $deleteStmt->execute([
                    'user_id' => $user['id'],
                    'debt_type' => 'installment',
                    'title' => $debt['title'],
                    'amount' => $debt['amount'],
                    'notes' => $debt['notes'],
                    'financing_total' => $debt['financing_total'],
                    'financing_start_date' => $debt['financing_start_date'],
                    'financing_end_date' => $debt['financing_end_date'],
                    'financing_due_day' => $debt['financing_due_day'],
                    'late_fee_rate' => $debt['late_fee_rate'],
                    'monthly_interest_rate' => $debt['monthly_interest_rate'],
                    'installments_total' => $debt['installments_total'],
                ]);
            }

            flash('success', $deleteStmt->rowCount() > 0 ? 'Parcelamento apagado com todas as parcelas.' : 'Parcelamento nao encontrado.');
            redirect('/debts.php');
        }
    }
}

$stmt = db()->prepare(
    'SELECT *
     FROM debts
     WHERE user_id = :user_id
       AND (
            debt_type = :installment_type
            OR debt_type NOT IN (:monthly_type, :installment_type)
            OR due_date <= :month_end
       )
     ORDER BY due_date ASC, status ASC, created_at DESC'
);
$stmt->execute([
    'user_id' => $user['id'],
    'monthly_type' => 'monthly',
    'installment_type' => 'installment',
    'month_end' => $monthEnd,
]);
$debts = $stmt->fetchAll();

$debtsByType = [
    'monthly' => [],
    'installment' => [],
    'single' => [],
];
$installmentGroups = [];

$summary = [
    'pending_total' => 0.0,
    'monthly_total' => 0.0,
    'installment_total' => 0.0,
    'single_total' => 0.0,
    'overdue_count' => 0,
];

foreach ($debts as $debt) {
    $debt['amount_with_interest'] = debt_amount_with_interest($debt, $today);

    if (isset($debtsByType[$debt['debt_type']])) {
        $debtsByType[$debt['debt_type']][] = $debt;
    }

    if ($debt['debt_type'] === 'installment') {
        $groupKey = installment_group_key($debt);
        if (!isset($installmentGroups[$groupKey])) {
            $installmentGroups[$groupKey] = [
                'sample' => $debt,
                'items' => [],
                'pending_total' => 0.0,
                'paid_count' => 0,
                'pending_count' => 0,
                'overdue_count' => 0,
                'next_due_date' => null,
            ];
        }

        $installmentGroups[$groupKey]['items'][] = $debt;

        if ($debt['status'] === 'paid') {
            $installmentGroups[$groupKey]['paid_count']++;
        } else {
            $installmentGroups[$groupKey]['pending_count']++;
            $installmentGroups[$groupKey]['pending_total'] += (float) $debt['amount_with_interest'];

            if ($debt['due_date'] < $today) {
                $installmentGroups[$groupKey]['overdue_count']++;
            }

            if (
                $installmentGroups[$groupKey]['next_due_date'] === null
                || $debt['due_date'] < $installmentGroups[$groupKey]['next_due_date']
            ) {
                $installmentGroups[$groupKey]['next_due_date'] = $debt['due_date'];
            }
        }
    }

    if ($debt['status'] !== 'pending') {
        continue;
    }

    if (in_array($debt['debt_type'], ['monthly', 'installment'], true) && $debt['due_date'] > $monthEnd) {
        continue;
    }

    $amountWithInterest = debt_amount_with_interest($debt, $today);
    $summary['pending_total'] += $amountWithInterest;

    if (isset($summary[$debt['debt_type'] . '_total'])) {
        $summary[$debt['debt_type'] . '_total'] += $amountWithInterest;
    }

    if ($debt['due_date'] < $today) {
        $summary['overdue_count']++;
    }
}

foreach ($installmentGroups as &$group) {
    usort($group['items'], static fn (array $a, array $b): int => strcmp((string) $a['due_date'], (string) $b['due_date']));
}
unset($group);
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dividas | Salva DinDin</title>
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
                <a class="nav-item" href="/graficos.php">Graficos</a>
                <a class="nav-item active" href="/debts.php">Dividas</a>
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
                    <p class="eyebrow">Compromissos</p>
                    <h1>Dividas</h1>
                </div>
            </header>

            <?php if ($success): ?>
                <div class="alert alert-success"><?= e($success) ?></div>
            <?php endif; ?>

            <?php foreach ($errors as $error): ?>
                <div class="alert alert-error"><?= e($error) ?></div>
            <?php endforeach; ?>

            <section class="metrics-grid" aria-label="Resumo de dividas">
                <article class="metric-card highlight">
                    <span>Total pendente</span>
                    <strong><?= money($summary['pending_total'] ?? 0) ?></strong>
                    <small>Mensais do mes, unicas e parcelamentos</small>
                </article>
                <article class="metric-card">
                    <span>Mensais</span>
                    <strong><?= money($summary['monthly_total'] ?? 0) ?></strong>
                    <small>Parcela do mes e atrasadas</small>
                </article>
                <article class="metric-card">
                    <span>Parcelamentos</span>
                    <strong><?= money($summary['installment_total'] ?? 0) ?></strong>
                    <small>Parcela atual, atraso e juros</small>
                </article>
                <article class="metric-card">
                    <span>Unicas</span>
                    <strong><?= money($summary['single_total'] ?? 0) ?></strong>
                    <small>Pagamentos avulsos</small>
                </article>
                <article class="metric-card">
                    <span>Vencidas</span>
                    <strong class="<?= (int) ($summary['overdue_count'] ?? 0) > 0 ? 'negative' : 'positive' ?>"><?= e($summary['overdue_count'] ?? 0) ?></strong>
                    <small>Pendentes fora do prazo</small>
                </article>
            </section>

            <section class="panel">
                <div class="panel-heading">
                    <h2>Nova divida</h2>
                </div>

                <form method="post" class="form-grid" novalidate>
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="create">

                    <label class="full">
                        <span>Nome</span>
                        <input type="text" name="title" placeholder="Ex.: Cartao, parcelamento, consulta" required>
                    </label>
                    <label>
                        <span>Tipo</span>
                        <select name="debt_type" data-debt-type required>
                            <?php foreach ($debtTypes as $value => $label): ?>
                                <option value="<?= e($value) ?>"><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>
                        <span>Valor</span>
                        <input type="number" name="amount" step="0.01" min="0.01" placeholder="0,00" required>
                    </label>
                    <label data-standard-due-date>
                        <span>Vencimento</span>
                        <input type="date" name="due_date" value="<?= e(date('Y-m-d')) ?>" required>
                    </label>
                    <label data-monthly-installments>
                        <span>Parcelas</span>
                        <input type="number" name="installments_total" min="1" max="240" step="1" value="1" required>
                    </label>
                    <label data-monthly-paid-installments>
                        <span>Parcelas ja pagas</span>
                        <input type="number" name="paid_installments" min="0" max="240" step="1" value="0" required>
                    </label>
                    <label data-financing-field>
                        <span>Inicio</span>
                        <input type="date" name="financing_start_date" value="<?= e(date('Y-m-d')) ?>">
                    </label>
                    <label data-financing-field>
                        <span>Fim</span>
                        <input type="date" name="financing_end_date" value="<?= e(date('Y-m-d', strtotime('+59 months'))) ?>">
                    </label>
                    <label data-financing-field>
                        <span>Dia venc.</span>
                        <input type="number" name="financing_due_day" min="1" max="31" step="1" value="<?= e(date('j')) ?>">
                    </label>
                    <label data-financing-field>
                        <span>Total parcelado</span>
                        <input type="number" name="financing_total" step="0.01" min="0.01" placeholder="0,00">
                    </label>
                    <label data-financing-field>
                        <span>Multa (%)</span>
                        <input type="number" name="late_fee_rate" step="0.001" min="0" placeholder="Ex.: 2">
                    </label>
                    <label data-financing-field>
                        <span>Juros ao mes (%)</span>
                        <input type="number" name="monthly_interest_rate" step="0.001" min="0" placeholder="Ex.: 6">
                    </label>
                    <label class="full">
                        <span>Observacao</span>
                        <input type="text" name="notes" placeholder="Ex.: vence todo dia 10, contrato, cartao">
                    </label>

                    <button class="button button-primary full" type="submit">Cadastrar divida</button>
                </form>
            </section>

            <section class="debt-grid" aria-label="Listas de dividas">
                <?php foreach ($debtPanels as $type => $title): ?>
                    <article class="panel debt-panel">
                        <div class="panel-heading">
                            <h2><?= e($title) ?></h2>
                            <span><?= $type === 'installment' ? count($installmentGroups) : count($debtsByType[$type]) ?> registros</span>
                        </div>

                        <div class="debt-list">
                            <?php if ($type === 'installment'): ?>
                                <?php if (!$installmentGroups): ?>
                                    <p class="empty-state">Nenhuma divida cadastrada.</p>
                                <?php endif; ?>

                                <?php foreach ($installmentGroups as $group): ?>
                                    <?php
                                        $sample = $group['sample'];
                                        $items = $group['items'];
                                        $firstItem = $items[0] ?? $sample;
                                        $lastItem = $items[array_key_last($items)] ?? $sample;
                                        $progress = (int) $group['paid_count'] . '/' . (int) ($sample['installments_total'] ?? count($items));
                                    ?>
                                    <details class="debt-folder">
                                        <summary class="debt-folder-summary">
                                            <span class="folder-icon" aria-hidden="true">▸</span>
                                            <span class="debt-folder-title">
                                                <strong><?= e($sample['title']) ?></strong>
                                                <small>
                                                    <?= e($progress) ?> parcelas pagas
                                                    · <?= money($sample['amount']) ?> por parcela
                                                    <?php if ($group['next_due_date']): ?>
                                                        · proxima <?= format_date($group['next_due_date']) ?>
                                                    <?php endif; ?>
                                                </small>
                                            </span>
                                            <span class="debt-folder-total">
                                                <b><?= money($group['pending_total']) ?></b>
                                                <small>pendente</small>
                                            </span>
                                        </summary>

                                        <div class="debt-folder-body">
                                            <div class="debt-folder-info">
                                                <span>Total parcelado: <?= money($sample['financing_total'] ?? 0) ?></span>
                                                <span><?= e($sample['installments_total']) ?> parcelas</span>
                                                <span><?= format_date($firstItem['due_date']) ?> ate <?= format_date($lastItem['due_date']) ?></span>
                                                <span>Multa <?= number_format((float) ($sample['late_fee_rate'] ?? 0), 3, ',', '.') ?>%</span>
                                                <span>Juros <?= number_format((float) ($sample['monthly_interest_rate'] ?? 0), 3, ',', '.') ?>% ao mes</span>
                                            </div>

                                            <form method="post" class="inline-form" data-confirm="Apagar este parcelamento com todas as parcelas antigas, atuais e futuras?">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="delete_group">
                                                <input type="hidden" name="debt_id" value="<?= e($sample['id']) ?>">
                                                <button class="button button-danger" type="submit">Apagar todas parcelas</button>
                                            </form>

                                            <div class="debt-installment-list">
                                                <?php foreach ($items as $debt): ?>
                                                    <?php
                                                        $isPaid = $debt['status'] === 'paid';
                                                        $isOverdue = !$isPaid && $debt['due_date'] < $today;
                                                    ?>
                                                    <article class="debt-item debt-installment-item <?= $isPaid ? 'is-paid' : '' ?>">
                                                        <?php if ($isPaid): ?>
                                                            <span class="paid-stamp" aria-label="Pago">✓ PAGO</span>
                                                        <?php endif; ?>
                                                        <div class="debt-title">
                                                            <div>
                                                                <strong>Parcela <?= e($debt['installment_number']) ?>/<?= e($debt['installments_total']) ?></strong>
                                                                <span><?= e($debt['notes'] ?? 'Sem observacao') ?></span>
                                                            </div>
                                                            <b><?= money($debt['amount']) ?></b>
                                                        </div>

                                                        <div class="debt-meta">
                                                            <span class="badge <?= $isPaid ? 'paid' : ($isOverdue ? 'blocked' : 'pending') ?>">
                                                                <?= $isPaid ? '✓ Pago' : ($isOverdue ? 'Vencida' : 'Pendente') ?>
                                                            </span>
                                                            <small>
                                                                Vence em <?= format_date($debt['due_date']) ?>
                                                                <?php if ($debt['paid_at']): ?>
                                                                    · paga em <?= format_date($debt['paid_at']) ?>
                                                                <?php endif; ?>
                                                                <?php if (!$isPaid && (float) ($debt['amount_with_interest'] ?? $debt['amount']) > (float) $debt['amount']): ?>
                                                                    · <?= overdue_days((string) $debt['due_date'], $today) ?> dias em atraso
                                                                    · com multa e juros: <?= money($debt['amount_with_interest']) ?>
                                                                <?php endif; ?>
                                                            </small>
                                                        </div>

                                                        <div class="debt-actions">
                                                            <?php if ($isPaid): ?>
                                                                <form method="post" class="inline-form">
                                                                    <?= csrf_field() ?>
                                                                    <input type="hidden" name="action" value="mark_pending">
                                                                    <input type="hidden" name="debt_id" value="<?= e($debt['id']) ?>">
                                                                    <button class="button button-secondary" type="submit">Reabrir</button>
                                                                </form>
                                                            <?php else: ?>
                                                                <form method="post" class="inline-form">
                                                                    <?= csrf_field() ?>
                                                                    <input type="hidden" name="action" value="mark_paid">
                                                                    <input type="hidden" name="debt_id" value="<?= e($debt['id']) ?>">
                                                                    <button class="button button-secondary" type="submit">Pagar</button>
                                                                </form>
                                                            <?php endif; ?>
                                                            <form method="post" class="inline-form" data-confirm="Apagar esta parcela?">
                                                                <?= csrf_field() ?>
                                                                <input type="hidden" name="action" value="delete">
                                                                <input type="hidden" name="debt_id" value="<?= e($debt['id']) ?>">
                                                                <button class="button button-danger" type="submit">Apagar</button>
                                                            </form>
                                                        </div>
                                                    </article>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    </details>
                                <?php endforeach; ?>
                            <?php elseif (!$debtsByType[$type]): ?>
                                <p class="empty-state">Nenhuma divida cadastrada.</p>
                            <?php else: ?>
                                <?php foreach ($debtsByType[$type] as $debt): ?>
                                <?php
                                    $isPaid = $debt['status'] === 'paid';
                                    $isOverdue = !$isPaid && $debt['due_date'] < $today;
                                ?>
                                <article class="debt-item <?= $isPaid ? 'is-paid' : '' ?>">
                                    <?php if ($isPaid): ?>
                                        <span class="paid-stamp" aria-label="Pago">✓ PAGO</span>
                                    <?php endif; ?>
                                    <div class="debt-title">
                                        <div>
                                            <strong><?= e($debt['title']) ?></strong>
                                            <?php if ((int) ($debt['installments_total'] ?? 1) > 1): ?>
                                                <small>Parcela <?= e($debt['installment_number']) ?>/<?= e($debt['installments_total']) ?></small>
                                            <?php endif; ?>
                                            <?php if ($debt['debt_type'] === 'installment'): ?>
                                                <small>
                                                    Total parcelado: <?= money($debt['financing_total'] ?? 0) ?>
                                                    · multa <?= number_format((float) ($debt['late_fee_rate'] ?? 0), 3, ',', '.') ?>%
                                                    · juros <?= number_format((float) ($debt['monthly_interest_rate'] ?? 0), 3, ',', '.') ?>% ao mes
                                                </small>
                                            <?php endif; ?>
                                            <span><?= e($debt['notes'] ?? 'Sem observacao') ?></span>
                                        </div>
                                        <b><?= money($debt['amount']) ?></b>
                                    </div>

                                    <div class="debt-meta">
                                        <span class="badge <?= $isPaid ? 'paid' : ($isOverdue ? 'blocked' : 'pending') ?>">
                                            <?= $isPaid ? '✓ Pago' : ($isOverdue ? 'Vencida' : 'Pendente') ?>
                                        </span>
                                        <small>
                                            Vence em <?= format_date($debt['due_date']) ?>
                                            <?php if ($debt['paid_at']): ?>
                                                · paga em <?= format_date($debt['paid_at']) ?>
                                            <?php endif; ?>
                                            <?php if (!$isPaid && (float) ($debt['amount_with_interest'] ?? $debt['amount']) > (float) $debt['amount']): ?>
                                                · <?= overdue_days((string) $debt['due_date'], $today) ?> dias em atraso
                                                · com multa e juros: <?= money($debt['amount_with_interest']) ?>
                                            <?php endif; ?>
                                        </small>
                                    </div>

                                    <div class="debt-actions">
                                        <?php if ($isPaid): ?>
                                            <form method="post" class="inline-form">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="mark_pending">
                                                <input type="hidden" name="debt_id" value="<?= e($debt['id']) ?>">
                                                <button class="button button-secondary" type="submit">Reabrir</button>
                                            </form>
                                        <?php else: ?>
                                            <form method="post" class="inline-form">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="mark_paid">
                                                <input type="hidden" name="debt_id" value="<?= e($debt['id']) ?>">
                                                <button class="button button-secondary" type="submit">Pagar</button>
                                            </form>
                                        <?php endif; ?>
                                        <form method="post" class="inline-form" data-confirm="Apagar esta divida?">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="debt_id" value="<?= e($debt['id']) ?>">
                                            <button class="button button-danger" type="submit">Apagar</button>
                                        </form>
                                    </div>
                                </article>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </section>
        </main>
    </div>
    <script src="/assets/js/app.js?v=responsive-3"></script>
</body>
</html>
