<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/WhatsAppService.php';

$user = require_auth();
$errors = [];
$success = flash('success');
$whatsappPhone = (string) ($user['whatsapp_phone'] ?? '');
$whatsappEnabled = whatsapp_integration_enabled();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    if (!$whatsappEnabled) {
        flash('success', 'WhatsApp temporariamente desativado. O sistema web continua funcionando normalmente.');
        redirect('/whatsapp.php');
    }

    $whatsappPhone = validate_whatsapp_phone((string) ($_POST['whatsapp_phone'] ?? ''), $errors);

    if ($whatsappPhone !== '' && whatsapp_phone_exists($whatsappPhone, (int) $user['id'])) {
        $errors[] = 'Este celular ja esta vinculado a outra conta.';
    }

    if (!$errors) {
        $stmt = db()->prepare(
            'UPDATE users
             SET whatsapp_phone = :whatsapp_phone
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => (int) $user['id'],
            'whatsapp_phone' => $whatsappPhone,
        ]);

        $service = new WhatsAppService();
        $sendResult = $service->sendText(
            $whatsappPhone,
            "✅ WhatsApp sincronizado com sua conta SalvaDinDin!\n\n"
            . "Agora você pode mandar mensagens como:\n"
            . "Gastei 35 no almoço\n"
            . "Recebi 1800 salário"
        );

        if (($sendResult['ok'] ?? false) === true) {
            flash('success', 'WhatsApp sincronizado com sua conta. Enviei uma mensagem de confirmacao para seu numero.');
        } else {
            flash('success', 'WhatsApp salvo na sua conta. Nao consegui enviar a mensagem de confirmacao agora, mas o vinculo foi registrado.');
        }

        redirect('/whatsapp.php');
    }
}

$isLinked = $whatsappPhone !== '';
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>WhatsApp | Salva DinDin</title>
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
                <a class="nav-item active" href="/whatsapp.php"><?= $whatsappEnabled ? 'Whatsapp' : 'WhatsApp desativado' ?></a>
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
                    <p class="eyebrow">Sincronizacao</p>
                    <h1>Whatsapp</h1>
                </div>
                <span class="badge <?= $whatsappEnabled && $isLinked ? 'active' : 'expired' ?>">
                    <?= $whatsappEnabled ? ($isLinked ? 'Vinculado' : 'Pendente') : 'Temporariamente desativado' ?>
                </span>
            </header>

            <?php if ($success): ?>
                <div class="alert alert-success"><?= e($success) ?></div>
            <?php endif; ?>

            <?php foreach ($errors as $error): ?>
                <div class="alert alert-error"><?= e($error) ?></div>
            <?php endforeach; ?>

            <section class="panel">
                <div class="panel-heading">
                    <h2><?= $whatsappEnabled ? 'Conectar WhatsApp' : 'WhatsApp temporariamente desativado' ?></h2>
                </div>

                <?php if (!$whatsappEnabled): ?>
                    <div class="alert alert-warning">
                        WhatsApp temporariamente desativado. Login, dashboard, receitas, despesas, metas e relatorios seguem funcionando normalmente.
                    </div>
                <?php endif; ?>

                <form method="post" class="form-grid" novalidate>
                    <?= csrf_field() ?>

                    <label class="full">
                        <span>Numero do WhatsApp</span>
                        <input
                            type="tel"
                            name="whatsapp_phone"
                            inputmode="tel"
                            autocomplete="tel"
                            value="<?= e($whatsappPhone) ?>"
                            placeholder="Ex.: 28992526083"
                            <?= $whatsappEnabled ? 'required' : 'disabled' ?>
                        >
                    </label>

                    <div class="full">
                        <div class="panel-heading">
                            <h2>ID sincronizado</h2>
                        </div>
                        <div class="mini-metrics">
                            <div>
                                <span>ID do cliente</span>
                                <strong><?= e($user['id']) ?></strong>
                            </div>
                            <div>
                                <span>WhatsApp ID</span>
                                <strong><?= e($whatsappEnabled && $whatsappPhone !== '' ? $whatsappPhone : 'WhatsApp temporariamente desativado') ?></strong>
                            </div>
                        </div>
                    </div>

                    <button class="button button-primary full" type="submit" <?= $whatsappEnabled ? '' : 'disabled' ?>>
                        <?= $whatsappEnabled ? 'Sincronizar WhatsApp' : 'WhatsApp temporariamente desativado' ?>
                    </button>
                </form>
            </section>
        </main>
    </div>
    <script src="/assets/js/app.js?v=responsive-3"></script>
</body>
</html>
