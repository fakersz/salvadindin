<?php

declare(strict_types=1);

function parseWhatsAppTransaction(string $message): array
{
    $normalized = normalize_whatsapp_text($message);
    $amount = extract_whatsapp_amount($normalized);
    $type = detect_whatsapp_transaction_type($normalized);

    if ($amount === null || $type === null) {
        return [
            'ok' => false,
            'amount' => $amount,
            'type' => $type,
        ];
    }

    $categoryName = detect_whatsapp_category($normalized, $type);
    $description = build_whatsapp_description($message, $categoryName);

    return [
        'ok' => true,
        'type' => $type,
        'amount' => number_format($amount, 2, '.', ''),
        'description' => $description,
        'category_name' => $categoryName,
        'transaction_date' => date('Y-m-d'),
    ];
}

function detect_whatsapp_transaction_type(string $normalized): ?string
{
    if (
        str_contains($normalized, 'cliente pagou') ||
        str_contains($normalized, 'recebi') ||
        str_contains($normalized, 'ganhei') ||
        str_contains($normalized, 'salario') ||
        str_contains($normalized, 'renda')
    ) {
        return 'income';
    }

    $expenseWords = ['gastei', 'paguei', 'comprei', 'despesa'];

    foreach ($expenseWords as $word) {
        if (str_contains($normalized, $word)) {
            return 'expense';
        }
    }

    $expenseContextWords = [
        'almoco',
        'padaria',
        'mercado',
        'ifood',
        'comida',
        'lanche',
        'restaurante',
        'gasolina',
        'uber',
        'onibus',
        'combustivel',
        'farmacia',
        'remedio',
        'aluguel',
        'luz',
        'agua',
        'internet',
    ];

    foreach ($expenseContextWords as $word) {
        if (str_contains($normalized, $word)) {
            return 'expense';
        }
    }

    return null;
}

function detect_whatsapp_category(string $normalized, string $type): string
{
    $categories = [
        'Alimentacao' => ['almoco', 'padaria', 'mercado', 'ifood', 'comida', 'lanche', 'restaurante', 'pao'],
        'Transporte' => ['gasolina', 'uber', 'onibus', 'combustivel'],
        'Saude' => ['farmacia', 'remedio'],
        'Casa' => ['aluguel', 'luz', 'agua', 'internet'],
        'Trabalho' => ['cliente', 'servico', 'site', 'manutencao'],
        'Salario' => ['salario', 'pagamento'],
    ];

    foreach ($categories as $category => $words) {
        if ($type === 'expense' && $category === 'Salario') {
            continue;
        }

        foreach ($words as $word) {
            if (str_contains($normalized, $word)) {
                return $category;
            }
        }
    }

    return 'Outros';
}

function build_whatsapp_description(string $message, string $fallback): string
{
    $description = normalize_whatsapp_text($message);
    $normalizedOriginal = $description;
    $patterns = [
        '/r\$\s*\d{1,3}(?:\.\d{3})*,\d{2}|r\$\s*\d+,\d{1,2}|r\$\s*\d+(?:\.\d{1,2})?/u',
        '/\b\d{1,3}(?:\.\d{3})*,\d{2}\b|\b\d+,\d{1,2}\b|\b\d+(?:\.\d{1,2})?\b/u',
        '/\b(gastei|paguei|comprei|despesa|recebi|ganhei|renda|cliente|pagou|reais|real|no|na|em|de|do|da|com)\b/u',
    ];

    foreach ($patterns as $pattern) {
        $description = preg_replace($pattern, ' ', $description) ?? $description;
    }

    $description = preg_replace('/\s+/', ' ', trim($description)) ?? '';

    if ($description === '' && str_contains($normalizedOriginal, 'cliente')) {
        return 'Cliente';
    }

    if ($description === '') {
        return $fallback;
    }

    return mb_convert_case($description, MB_CASE_TITLE, 'UTF-8');
}

function findUserByWhatsAppPhone(PDO $pdo, string $waId): ?array
{
    $phone = preg_replace('/\D+/', '', $waId) ?? '';

    if ($phone === '') {
        return null;
    }

    $columns = existing_user_phone_columns($pdo);

    if (!$columns) {
        return null;
    }

    $where = [];
    $params = ['phone' => $phone];

    foreach ($columns as $index => $column) {
        $where[] = "regexp_replace(COALESCE({$column}, ''), '\\D', '', 'g') = :phone";
    }

    $stmt = $pdo->prepare(
        'SELECT *
         FROM users
         WHERE ' . implode(' OR ', $where) . '
         LIMIT 1'
    );
    $stmt->execute($params);

    return $stmt->fetch() ?: null;
}

function existing_user_phone_columns(PDO $pdo): array
{
    $wanted = ['whatsapp_phone', 'whatsapp', 'phone', 'telefone', 'celular'];

    $stmt = $pdo->prepare(
        "SELECT column_name
         FROM information_schema.columns
         WHERE table_schema = 'public' AND table_name = 'users'"
    );
    $stmt->execute();
    $existing = array_column($stmt->fetchAll(), 'column_name');

    return array_values(array_intersect($wanted, $existing));
}

function findOrCreateWhatsAppCategory(PDO $pdo, int $userId, string $name, string $type): int
{
    $stmt = $pdo->prepare(
        'SELECT id FROM categories WHERE user_id = :user_id AND name = :name AND type = :type LIMIT 1'
    );
    $stmt->execute([
        'user_id' => $userId,
        'name' => $name,
        'type' => $type,
    ]);
    $categoryId = $stmt->fetchColumn();

    if ($categoryId !== false) {
        return (int) $categoryId;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO categories (user_id, name, type)
         VALUES (:user_id, :name, :type)
         ON CONFLICT (user_id, name, type) DO UPDATE SET name = EXCLUDED.name
         RETURNING id'
    );
    $stmt->execute([
        'user_id' => $userId,
        'name' => $name,
        'type' => $type,
    ]);

    return (int) $stmt->fetchColumn();
}

function saveWhatsAppTransaction(PDO $pdo, int $userId, array $transaction): int
{
    $categoryId = findOrCreateWhatsAppCategory(
        $pdo,
        $userId,
        (string) $transaction['category_name'],
        (string) $transaction['type']
    );

    $stmt = $pdo->prepare(
        'INSERT INTO transactions
            (user_id, category_id, title, amount, transaction_type, transaction_date, payment_method, is_essential)
         VALUES
            (:user_id, :category_id, :title, :amount, :transaction_type, :transaction_date, :payment_method, :is_essential)
         RETURNING id'
    );
    $stmt->execute([
        'user_id' => $userId,
        'category_id' => $categoryId,
        'title' => $transaction['description'],
        'amount' => $transaction['amount'],
        'transaction_type' => $transaction['type'],
        'transaction_date' => $transaction['transaction_date'],
        'payment_method' => 'pix_cash',
        'is_essential' => 'false',
    ]);

    return (int) $stmt->fetchColumn();
}

function whatsappTransactionSuccessReply(array $transaction): string
{
    $isIncome = $transaction['type'] === 'income';
    $label = $isIncome ? 'Receita' : 'Despesa';
    $valueIcon = $isIncome ? '💰' : '💸';

    return sprintf(
        "💚 SalvaDinDin\n\n"
        . "✅ %s registrada com sucesso!\n\n"
        . "━━━━━━━━━━━━━━\n\n"
        . "%s Valor\n%s\n\n"
        . "🏷️ Categoria\n%s\n\n"
        . "📝 Descrição\n%s\n\n"
        . "━━━━━━━━━━━━━━\n\n"
        . "Digite ou toque em uma opção abaixo:\n"
        . "📊 resumo\n"
        . "💰 saldo\n"
        . "➕ receita\n"
        . "💸 despesa",
        $label,
        $valueIcon,
        format_whatsapp_money((float) $transaction['amount']),
        whatsapp_display_category((string) $transaction['category_name']),
        $transaction['description']
    );
}

function whatsapp_display_category(string $category): string
{
    return match ($category) {
        'Alimentacao' => 'Alimentação',
        'Saude' => 'Saúde',
        'Salario' => 'Salário',
        default => $category,
    };
}

function whatsappCurrentBalanceReply(PDO $pdo, int $userId): string
{
    $stmt = $pdo->prepare(
        "SELECT
            COALESCE(SUM(CASE WHEN transaction_type = 'income' THEN amount ELSE -amount END), 0) AS balance,
            COALESCE(SUM(CASE WHEN transaction_type = 'income' THEN amount ELSE 0 END), 0) AS income,
            COALESCE(SUM(CASE WHEN transaction_type = 'expense' THEN amount ELSE 0 END), 0) AS expense
         FROM transactions
         WHERE user_id = :user_id"
    );
    $stmt->execute(['user_id' => $userId]);
    $summary = $stmt->fetch() ?: [];

    return sprintf(
        "💚 SalvaDinDin\n\n"
        . "💰 Saldo atual\n\n"
        . "━━━━━━━━━━━━━━\n\n"
        . "Saldo\n%s\n\n"
        . "💰 Receitas\n%s\n\n"
        . "💸 Despesas\n%s\n\n"
        . "━━━━━━━━━━━━━━",
        format_whatsapp_money((float) ($summary['balance'] ?? 0)),
        format_whatsapp_money((float) ($summary['income'] ?? 0)),
        format_whatsapp_money((float) ($summary['expense'] ?? 0))
    );
}

function whatsappMonthSummaryReply(PDO $pdo, int $userId): string
{
    $start = date('Y-m-01');
    $end = date('Y-m-t');

    $stmt = $pdo->prepare(
        "SELECT
            COALESCE(SUM(CASE WHEN transaction_type = 'income' THEN amount ELSE 0 END), 0) AS income,
            COALESCE(SUM(CASE WHEN transaction_type = 'expense' THEN amount ELSE 0 END), 0) AS expense,
            COUNT(*) AS total_transactions
         FROM transactions
         WHERE user_id = :user_id
           AND transaction_date BETWEEN :start_date AND :end_date"
    );
    $stmt->execute([
        'user_id' => $userId,
        'start_date' => $start,
        'end_date' => $end,
    ]);
    $summary = $stmt->fetch() ?: [];

    $categoryStmt = $pdo->prepare(
        "SELECT COALESCE(c.name, 'Sem categoria') AS category_name, COALESCE(SUM(t.amount), 0) AS total
         FROM transactions t
         LEFT JOIN categories c ON c.id = t.category_id
         WHERE t.user_id = :user_id
           AND t.transaction_type = 'expense'
           AND t.transaction_date BETWEEN :start_date AND :end_date
         GROUP BY c.name
         ORDER BY total DESC
         LIMIT 1"
    );
    $categoryStmt->execute([
        'user_id' => $userId,
        'start_date' => $start,
        'end_date' => $end,
    ]);
    $topCategory = $categoryStmt->fetch() ?: null;

    $income = (float) ($summary['income'] ?? 0);
    $expense = (float) ($summary['expense'] ?? 0);
    $result = $income - $expense;
    $topCategoryText = 'Nenhuma despesa no mês';

    if (is_array($topCategory) && (float) ($topCategory['total'] ?? 0) > 0) {
        $topCategoryText = whatsapp_display_category((string) $topCategory['category_name'])
            . ' - '
            . format_whatsapp_money((float) $topCategory['total']);
    }

    return sprintf(
        "💚 SalvaDinDin\n\n"
        . "📊 Resumo do mês\n\n"
        . "━━━━━━━━━━━━━━\n\n"
        . "💰 Receitas\n%s\n\n"
        . "💸 Despesas\n%s\n\n"
        . "🧾 Resultado\n%s\n\n"
        . "🏷️ Maior categoria\n%s\n\n"
        . "📌 Movimentações\n%d\n\n"
        . "━━━━━━━━━━━━━━",
        format_whatsapp_money($income),
        format_whatsapp_money($expense),
        format_whatsapp_money($result),
        $topCategoryText,
        (int) ($summary['total_transactions'] ?? 0)
    );
}

function whatsappTransactionHelpReply(): string
{
    return "Não consegui identificar o valor ou o tipo da movimentação. Tente assim:\n\n"
        . "Gastei 35 no almoço\n"
        . "Recebi 1800 salário";
}
