<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';

if (current_user()) {
    redirect('/dashboard.php');
}

$errors = [];
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if (login_user($email, $password)) {
        redirect('/dashboard.php');
    }

    $errors[] = 'E-mail ou senha invalidos.';
}

$flashError = flash('error');
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Entrar | Salva Din Din!</title>
    <link rel="stylesheet" href="/assets/css/style.css?v=responsive-1">
    <script>
        (() => {
            const savedTheme = localStorage.getItem('salvadindin-theme');
            const systemTheme = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
            document.documentElement.dataset.theme = savedTheme || systemTheme;
        })();
    </script>
</head>
<body class="auth-page">
    <button type="button" class="theme-toggle theme-toggle-floating auth-theme-toggle" data-theme-toggle aria-label="Alternar tema"></button>
    <main class="auth-shell">
        <section class="auth-showcase" aria-label="Salva DinDin">
            <img src="/assets/img/logo.png" alt="Salva DinDin Controle Financeiro">
            <div class="showcase-note">
                <span aria-hidden="true">↗</span>
                <p>Organize suas receitas, despesas e metas em um painel simples para <strong>decidir melhor.</strong></p>
            </div>
        </section>

        <section class="auth-panel">
            <div class="auth-brand">
                <img src="/assets/img/logo2.png" alt="Salva DinDin Controle Financeiro">
            </div>

            <div class="auth-copy">
                <p class="eyebrow">Bem-vindo de volta</p>
                <h1>Bem-vindo de volta</h1>
                <p>Controle suas finanças de forma simples e inteligente.</p>
            </div>

            <?php if ($flashError): ?>
                <div class="alert alert-error"><?= e($flashError) ?></div>
            <?php endif; ?>

            <?php foreach ($errors as $error): ?>
                <div class="alert alert-error"><?= e($error) ?></div>
            <?php endforeach; ?>

            <form method="post" class="form-stack" novalidate>
                <?= csrf_field() ?>
                <label>
                    <span>E-mail</span>
                    <span class="input-shell">
                        <svg aria-hidden="true" viewBox="0 0 24 24">
                            <path d="M4 6h16v12H4z"></path>
                            <path d="m4 7 8 6 8-6"></path>
                        </svg>
                        <input type="email" name="email" value="<?= e($email) ?>" autocomplete="email" placeholder="Digite seu e-mail" required>
                    </span>
                </label>

                <label>
                    <span>Senha</span>
                    <span class="input-shell">
                        <svg aria-hidden="true" viewBox="0 0 24 24">
                            <rect x="5" y="10" width="14" height="10" rx="2"></rect>
                            <path d="M8 10V7a4 4 0 0 1 8 0v3"></path>
                        </svg>
                        <input type="password" name="password" autocomplete="current-password" placeholder="Digite sua senha" required>
                        <button type="button" class="password-toggle" data-password-toggle aria-label="Mostrar senha">
                            <svg aria-hidden="true" viewBox="0 0 24 24">
                                <path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12z"></path>
                                <circle cx="12" cy="12" r="3"></circle>
                            </svg>
                        </button>
                    </span>
                </label>

                <button class="button button-primary" type="submit">
                    <svg aria-hidden="true" viewBox="0 0 24 24">
                        <rect x="5" y="10" width="14" height="10" rx="2"></rect>
                        <path d="M8 10V7a4 4 0 0 1 8 0v3"></path>
                    </svg>
                    <span>Entrar</span>
                </button>
            </form>

            <p class="auth-switch">Ainda nao tem conta? <a href="/register.php">Criar cadastro</a></p>
        </section>
    </main>
    <footer class="auth-footer">© 2024 Salva DinDin. Todos os direitos reservados.</footer>
    <script src="/assets/js/app.js?v=responsive-1"></script>
</body>
</html>
