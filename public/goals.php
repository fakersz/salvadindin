<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';

$user = require_auth();
$errors = [];
$success = flash('success');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $action = (string) ($_POST['action'] ?? 'create');

    if ($action === 'create') {
        $title = trim((string) ($_POST['title'] ?? ''));
        $targetAmount = str_replace(',', '.', (string) ($_POST['target_amount'] ?? '0'));
        $currentAmount = str_replace(',', '.', (string) ($_POST['current_amount'] ?? '0'));
        $deadline = trim((string) ($_POST['deadline'] ?? ''));

        if (strlen($title) < 2) {
            $errors[] = 'Informe um titulo para a meta.';
        }

        if (!is_numeric($targetAmount) || (float) $targetAmount <= 0) {
            $errors[] = 'A meta precisa ter um valor alvo maior que zero.';
        }

        if (!is_numeric($currentAmount) || (float) $currentAmount < 0) {
            $errors[] = 'O valor atual nao pode ser negativo.';
        }

        if ($deadline !== '' && strtotime($deadline) === false) {
            $errors[] = 'Informe uma data limite valida.';
        }

        if (!$errors) {
            $stmt = db()->prepare(
                'INSERT INTO goals (user_id, title, target_amount, current_amount, deadline)
                 VALUES (:user_id, :title, :target_amount, :current_amount, :deadline)'
            );
            $stmt->execute([
                'user_id' => $user['id'],
                'title' => $title,
                'target_amount' => number_format((float) $targetAmount, 2, '.', ''),
                'current_amount' => number_format((float) $currentAmount, 2, '.', ''),
                'deadline' => $deadline !== '' ? $deadline : null,
            ]);

            flash('success', 'Meta criada com sucesso.');
            redirect('/goals.php');
        }
    }

    if ($action === 'adjust') {
        $goalId = (int) ($_POST['goal_id'] ?? 0);
        $adjustmentType = (string) ($_POST['adjustment_type'] ?? '');
        $amount = str_replace(',', '.', (string) ($_POST['amount'] ?? '0'));

        if ($goalId <= 0) {
            $errors[] = 'Meta invalida.';
        }

        if (!in_array($adjustmentType, ['add', 'remove'], true)) {
            $errors[] = 'Escolha adicionar ou remover valor.';
        }

        if (!is_numeric($amount) || (float) $amount <= 0) {
            $errors[] = 'Informe um valor maior que zero.';
        }

        if (!$errors) {
            $stmt = db()->prepare('SELECT id, current_amount, target_amount FROM goals WHERE id = :id AND user_id = :user_id LIMIT 1');
            $stmt->execute([
                'id' => $goalId,
                'user_id' => $user['id'],
            ]);
            $goal = $stmt->fetch();

            if (!$goal) {
                $errors[] = 'Meta nao encontrada.';
            } else {
                $currentAmount = (float) $goal['current_amount'];
                $targetAmount = (float) $goal['target_amount'];
                $adjustmentAmount = (float) $amount;

                $newAmount = $adjustmentType === 'add'
                    ? min($currentAmount + $adjustmentAmount, $targetAmount)
                    : max($currentAmount - $adjustmentAmount, 0);

                $stmt = db()->prepare('UPDATE goals SET current_amount = :current_amount WHERE id = :id AND user_id = :user_id');
                $stmt->execute([
                    'id' => $goalId,
                    'user_id' => $user['id'],
                    'current_amount' => number_format($newAmount, 2, '.', ''),
                ]);

                flash('success', $adjustmentType === 'add' ? 'Valor adicionado a meta.' : 'Valor removido da meta.');
                redirect('/goals.php');
            }
        }
    }

    if ($action === 'update') {
        $goalId = (int) ($_POST['goal_id'] ?? 0);
        $title = trim((string) ($_POST['title'] ?? ''));
        $targetAmount = str_replace(',', '.', (string) ($_POST['target_amount'] ?? '0'));
        $currentAmount = str_replace(',', '.', (string) ($_POST['current_amount'] ?? '0'));
        $deadline = trim((string) ($_POST['deadline'] ?? ''));

        if ($goalId <= 0) {
            $errors[] = 'Meta invalida.';
        }

        if (strlen($title) < 2) {
            $errors[] = 'Informe um titulo para a meta.';
        }

        if (!is_numeric($targetAmount) || (float) $targetAmount <= 0) {
            $errors[] = 'A meta precisa ter um valor alvo maior que zero.';
        }

        if (!is_numeric($currentAmount) || (float) $currentAmount < 0) {
            $errors[] = 'O valor atual nao pode ser negativo.';
        }

        if (is_numeric($targetAmount) && is_numeric($currentAmount) && (float) $currentAmount > (float) $targetAmount) {
            $errors[] = 'O valor atual nao pode ser maior que o valor alvo.';
        }

        if ($deadline !== '' && strtotime($deadline) === false) {
            $errors[] = 'Informe uma data limite valida.';
        }

        if (!$errors) {
            $stmt = db()->prepare(
                'UPDATE goals
                 SET title = :title,
                     target_amount = :target_amount,
                     current_amount = :current_amount,
                     deadline = :deadline
                 WHERE id = :id AND user_id = :user_id'
            );
            $stmt->execute([
                'id' => $goalId,
                'user_id' => $user['id'],
                'title' => $title,
                'target_amount' => number_format((float) $targetAmount, 2, '.', ''),
                'current_amount' => number_format((float) $currentAmount, 2, '.', ''),
                'deadline' => $deadline !== '' ? $deadline : null,
            ]);

            flash('success', 'Meta atualizada.');
            redirect('/goals.php');
        }
    }

    if ($action === 'delete') {
        $goalId = (int) ($_POST['goal_id'] ?? 0);

        if ($goalId <= 0) {
            $errors[] = 'Meta invalida.';
        }

        if (!$errors) {
            $stmt = db()->prepare('DELETE FROM goals WHERE id = :id AND user_id = :user_id');
            $stmt->execute([
                'id' => $goalId,
                'user_id' => $user['id'],
            ]);

            flash('success', $stmt->rowCount() > 0 ? 'Meta apagada.' : 'Meta nao encontrada.');
            redirect('/goals.php');
        }
    }
}

$stmt = db()->prepare(
    'SELECT *, CASE WHEN target_amount > 0 THEN LEAST((current_amount / target_amount) * 100, 100) ELSE 0 END AS progress
     FROM goals
     WHERE user_id = :user_id
     ORDER BY deadline ASC NULLS LAST, created_at DESC'
);
$stmt->execute(['user_id' => $user['id']]);
$goals = $stmt->fetchAll();
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Metas | Salva DinDin</title>
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
                <a class="nav-item active" href="/goals.php">Metas</a>
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
                    <p class="eyebrow">Planejamento</p>
                    <h1>Metas financeiras</h1>
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
                        <h2>Nova meta</h2>
                    </div>

                    <form method="post" class="form-grid" novalidate>
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="create">
                        <label class="full">
                            <span>Titulo</span>
                            <input type="text" name="title" placeholder="Ex.: Reserva de emergencia" required>
                        </label>

                        <label>
                            <span>Valor alvo</span>
                            <input type="number" name="target_amount" step="0.01" min="0.01" placeholder="0,00" required>
                        </label>

                        <label>
                            <span>Valor atual</span>
                            <input type="number" name="current_amount" step="0.01" min="0" value="0" required>
                        </label>

                        <label class="full">
                            <span>Prazo</span>
                            <input type="date" name="deadline">
                        </label>

                        <button class="button button-primary full" type="submit">Criar meta</button>
                    </form>
                </div>

                <div class="panel">
                    <div class="panel-heading">
                        <h2>Suas metas</h2>
                        <span><?= count($goals) ?> ativas</span>
                    </div>

                    <div class="goal-list">
                        <?php if (!$goals): ?>
                            <p class="empty-state">Nenhuma meta cadastrada ainda.</p>
                        <?php endif; ?>

                        <?php foreach ($goals as $goal): ?>
                            <article class="goal-item">
                                <div class="goal-title">
                                    <strong><?= e($goal['title']) ?></strong>
                                    <span><?= number_format((float) $goal['progress'], 0) ?>%</span>
                                </div>
                                <div class="progress-track">
                                    <span style="width: <?= e((string) min((float) $goal['progress'], 100)) ?>%"></span>
                                </div>
                                <small>
                                    <?= money($goal['current_amount']) ?> de <?= money($goal['target_amount']) ?>
                                    <?php if ($goal['deadline']): ?>
                                        · ate <?= format_date($goal['deadline']) ?>
                                    <?php endif; ?>
                                </small>

                                <form method="post" class="goal-edit-form" novalidate>
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="update">
                                    <input type="hidden" name="goal_id" value="<?= e($goal['id']) ?>">

                                    <label class="goal-field-title">
                                        <span>Titulo</span>
                                        <input type="text" name="title" value="<?= e($goal['title']) ?>" required>
                                    </label>

                                    <label>
                                        <span>Alvo</span>
                                        <input type="number" name="target_amount" step="0.01" min="0.01" value="<?= e((string) $goal['target_amount']) ?>" required>
                                    </label>

                                    <label>
                                        <span>Atual</span>
                                        <input type="number" name="current_amount" step="0.01" min="0" value="<?= e((string) $goal['current_amount']) ?>" required>
                                    </label>

                                    <label>
                                        <span>Prazo</span>
                                        <input type="date" name="deadline" value="<?= e($goal['deadline'] ?? '') ?>">
                                    </label>

                                    <button class="button button-secondary" type="submit">Salvar</button>
                                </form>

                                <form method="post" class="goal-adjust-form" novalidate>
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="adjust">
                                    <input type="hidden" name="goal_id" value="<?= e($goal['id']) ?>">

                                    <label>
                                        <span>Valor</span>
                                        <input type="number" name="amount" step="0.01" min="0.01" placeholder="0,00" required>
                                    </label>

                                    <button class="button button-secondary" type="submit" name="adjustment_type" value="remove">Remover</button>
                                    <button class="button button-primary" type="submit" name="adjustment_type" value="add">Adicionar</button>
                                </form>

                                <form method="post" class="goal-delete-form" data-confirm="Apagar esta meta?">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="goal_id" value="<?= e($goal['id']) ?>">
                                    <button class="button button-danger" type="submit">Apagar meta</button>
                                </form>
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
