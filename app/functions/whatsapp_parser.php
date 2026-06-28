<?php

declare(strict_types=1);

function parseWhatsAppFinancialMessage(string $message): array
{
    $normalized = normalize_whatsapp_text($message);
    $amount = extract_whatsapp_amount($normalized);

    $expenseWords = ['gastei', 'paguei', 'comprei', 'despesa'];
    $incomeWords = ['recebi', 'ganhei', 'salario', 'renda'];

    foreach ($expenseWords as $word) {
        if (str_contains($normalized, $word)) {
            return [
                'detected' => $amount !== null,
                'type' => 'expense',
                'amount' => $amount,
                'reply' => $amount !== null
                    ? '✅ Despesa detectada: ' . format_whatsapp_money($amount)
                    : null,
            ];
        }
    }

    foreach ($incomeWords as $word) {
        if (str_contains($normalized, $word)) {
            return [
                'detected' => $amount !== null,
                'type' => 'income',
                'amount' => $amount,
                'reply' => $amount !== null
                    ? '✅ Receita detectada: ' . format_whatsapp_money($amount)
                    : null,
            ];
        }
    }

    return [
        'detected' => false,
        'type' => null,
        'amount' => $amount,
        'reply' => null,
    ];
}

function normalize_whatsapp_text(string $text): string
{
    $text = mb_strtolower(trim($text), 'UTF-8');
    $text = strtr($text, [
        'á' => 'a',
        'à' => 'a',
        'ã' => 'a',
        'â' => 'a',
        'é' => 'e',
        'ê' => 'e',
        'í' => 'i',
        'ó' => 'o',
        'ô' => 'o',
        'õ' => 'o',
        'ú' => 'u',
        'ç' => 'c',
    ]);

    return preg_replace('/\s+/', ' ', $text) ?? $text;
}

function extract_whatsapp_amount(string $normalizedMessage): ?float
{
    $patterns = [
        '/r\$\s*(\d{1,3}(?:\.\d{3})*,\d{2}|\d+,\d{1,2}|\d+(?:\.\d{1,2})?)/u',
        '/(\d{1,3}(?:\.\d{3})*,\d{2}|\d+,\d{1,2})/u',
        '/\b(\d+(?:\.\d{1,2})?)\b/u',
    ];

    foreach ($patterns as $pattern) {
        if (!preg_match($pattern, $normalizedMessage, $matches)) {
            continue;
        }

        $value = str_replace('.', '', $matches[1]);
        $value = str_replace(',', '.', $value);
        $amount = (float) $value;

        if ($amount > 0) {
            return $amount;
        }
    }

    return null;
}

function format_whatsapp_money(float $amount): string
{
    return 'R$ ' . number_format($amount, 2, ',', '.');
}

function defaultWhatsAppReply(): string
{
    return "✅ Mensagem recebida pelo SalvaDinDin!\n\n"
        . "Em breve vou registrar suas receitas e despesas automaticamente por aqui.\n\n"
        . "Exemplo:\n"
        . "Gastei 35 no almoço\n"
        . "Recebi 1800 de salário";
}

function whatsapp_default_buttons(): array
{
    return [
        ['id' => 'resumo_mes', 'title' => '📊 Resumo'],
        ['id' => 'saldo_atual', 'title' => '💰 Saldo'],
        ['id' => 'nova_receita', 'title' => '➕ Receita'],
    ];
}

function whatsapp_welcome_reply(): string
{
    return "💚 Olá! Eu sou o SalvaDinDin.\n\n"
        . "Seu assistente financeiro pelo WhatsApp.\n\n"
        . "Você pode me enviar mensagens como:\n\n"
        . "💸 Gastei 35 no almoço\n"
        . "⛽ Gasolina 180\n"
        . "💰 Recebi 2500 salário\n"
        . "📊 resumo\n"
        . "💰 saldo\n\n"
        . "Vou te ajudar a organizar sua vida financeira de forma simples. 🚀";
}

function whatsapp_button_or_command_reply(string $command): ?string
{
    $normalized = normalize_whatsapp_text($command);

    return match (true) {
        in_array($normalized, ['resumo_mes', 'resumo', 'resumo do mes', 'resumo do mês'], true) =>
            '📊 Resumo do mês em breve estará disponível.',
        in_array($normalized, ['saldo_atual', 'saldo'], true) =>
            '💰 Consulta de saldo em breve estará disponível.',
        in_array($normalized, ['nova_receita', 'receita'], true) =>
            '➕ Me envie algo como: Recebi 1800 salário',
        $normalized === 'despesa' =>
            '💸 Me envie algo como: Gastei 35 no almoço',
        in_array($normalized, ['oi', 'ola', 'olá', 'menu', 'ajuda'], true) =>
            whatsapp_welcome_reply(),
        default => null,
    };
}

function whatsapp_reply_uses_buttons(string $reply): bool
{
    return str_starts_with($reply, '💚 Olá!')
        || str_contains($reply, 'registrada com sucesso!');
}
