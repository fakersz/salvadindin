<?php

declare(strict_types=1);

function parseFinancialMessage(string $message, string $context = ''): array
{
    $originalMessage = trim($message);
    $originalContext = trim($context);
    $analysisText = trim($originalMessage . ' ' . $originalContext);
    $normalizedMessage = normalize_financial_text($analysisText);

    $amount = extract_financial_amount($normalizedMessage);
    $type = detect_financial_type($normalizedMessage);

    if ($amount === null || $type === null) {
        return [
            'ok' => false,
            'error' => 'missing_type_or_amount',
            'type' => $type,
            'amount' => $amount,
        ];
    }

    $categoryName = detect_financial_category($normalizedMessage, $type);
    $descriptionSource = $originalMessage !== '' ? $originalMessage : $analysisText;
    $description = build_financial_description($descriptionSource, normalize_financial_text($descriptionSource), $amount);

    if ($description === '') {
        $description = $categoryName;
    }

    return [
        'ok' => true,
        'type' => $type,
        'amount' => number_format($amount, 2, '.', ''),
        'description' => $description,
        'category_name' => $categoryName,
        'transaction_date' => date('Y-m-d'),
        'payment_method' => 'pix_cash',
        'is_essential' => false,
    ];
}

function normalize_financial_text(string $text): string
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
        'ü' => 'u',
        'ç' => 'c',
    ]);

    return preg_replace('/\s+/', ' ', $text) ?? $text;
}

function extract_financial_amount(string $normalizedMessage): ?float
{
    $patterns = [
        '/(?:r\$\s*)(\d{1,3}(?:\.\d{3})*,\d{2}|\d+,\d{1,2}|\d+(?:\.\d{1,2})?)(?:\s*(?:reais|real))?/u',
        '/(?:valor|total|vlr|amount)\D{0,12}(\d{1,3}(?:\.\d{3})*,\d{2}|\d+,\d{1,2})/u',
        '/(\d+(?:\.\d{1,2})?)\s*(?:reais|real)/u',
        '/(?:por|de)\s+(\d+(?:\.\d{1,2})?)(?!\w)/u',
        '/(\d{1,3}(?:\.\d{3})*,\d{2}|\d+,\d{1,2})/u',
        '/\b(\d+(?:\.\d{1,2})?)\b/u',
    ];

    foreach ($patterns as $pattern) {
        if (!preg_match($pattern, $normalizedMessage, $matches)) {
            continue;
        }

        $value = normalize_financial_amount($matches[1]);

        if ($value > 0) {
            return $value;
        }
    }

    return null;
}

function normalize_financial_amount(string $value): float
{
    $value = trim($value);

    if (str_contains($value, ',')) {
        $value = str_replace('.', '', $value);
        $value = str_replace(',', '.', $value);
    }

    return (float) $value;
}

function detect_financial_type(string $normalizedMessage): ?string
{
    $expenseWords = [
        'comprei',
        'gastei',
        'paguei',
        'saida',
        'despesa',
        'pago',
        'compra',
        'troquei',
        'troca',
        'abasteci',
        'abastecimento',
        'coloquei',
        'consertei',
        'arrumei',
        'manutencao',
        'mecanico',
        'oficina',
        'levei',
        'custou',
        'deu',
        'saiu',
    ];

    $incomeWords = [
        'recebi',
        'ganhei',
        'entrou',
        'caiu',
        'depositou',
        'depositaram',
        'pix recebido',
        'salario',
        'receita',
        'entrada',
        'vendi',
        'venda',
        'rendeu',
        'cliente',
    ];

    foreach ($expenseWords as $word) {
        if (str_contains($normalizedMessage, $word)) {
            return 'expense';
        }
    }

    foreach ($incomeWords as $word) {
        if (str_contains($normalizedMessage, $word)) {
            return 'income';
        }
    }

    return null;
}

function detect_financial_category(string $normalizedMessage, string $type): string
{
    $categories = [
        'Alimentacao' => [
            'padaria',
            'mercado',
            'supermercado',
            'almoco',
            'janta',
            'jantar',
            'lanche',
            'cafe',
            'coca',
            'comida',
            'restaurante',
            'ifood',
            'pizza',
            'salgado',
            'marmita',
        ],
        'Transporte' => [
            'uber',
            '99',
            'gasolina',
            'alcool',
            'etanol',
            'diesel',
            'onibus',
            'metro',
            'trem',
            'taxi',
            'posto',
            'carro',
            'moto',
            'oleo',
            'mecanico',
            'oficina',
            'pneu',
            'estacionamento',
            'pedagio',
            'abasteci',
            'abastecimento',
        ],
        'Contas' => [
            'luz',
            'energia',
            'agua',
            'internet',
            'wifi',
            'telefone',
            'celular',
            'aluguel',
            'condominio',
            'boleto',
            'fatura',
        ],
        'Saude' => [
            'consulta',
            'medico',
            'dentista',
            'remedio',
            'farmacia',
            'exame',
            'hospital',
        ],
        'Lazer' => [
            'cinema',
            'bar',
            'show',
            'viagem',
            'hotel',
            'festa',
        ],
        'Receita' => [
            'salario',
            'pix',
            'cliente',
            'venda',
            'vendi',
            'freela',
            'freelance',
            'comissao',
            'bonus',
            'rendimento',
        ],
    ];

    foreach ($categories as $category => $words) {
        if ($type === 'expense' && $category === 'Receita') {
            continue;
        }

        foreach ($words as $word) {
            if (str_contains($normalizedMessage, $word)) {
                return $category;
            }
        }
    }

    return 'Outros';
}

function build_financial_description(string $originalMessage, string $normalizedMessage, float $amount): string
{
    $description = normalize_financial_text($originalMessage);

    $removals = [
        '/\br\$\s*\d{1,3}(?:\.\d{3})*,\d{2}\b/u',
        '/\br\$\s*\d+(?:[\.,]\d{1,2})?\b/u',
        '/\b\d{1,3}(?:\.\d{3})*,\d{2}\s*(?:reais|real)?\b/u',
        '/\b\d+(?:[\.,]\d{1,2})?\s*(?:reais|real)?\b/u',
        '/\b(comprei|gastei|paguei|saida|despesa|pago|compra|troquei|troca|abasteci|abastecimento|coloquei|consertei|arrumei|manutencao|levei|custou|deu|saiu|recebi|ganhei|entrou|caiu|depositou|depositaram|pix recebido|salario|receita|entrada|vendi|venda|rendeu)\b/u',
        '/\b(uma|um|a|o|as|os|de|do|da|dos|das|por|com|em)\b/u',
    ];

    foreach ($removals as $pattern) {
        $description = preg_replace($pattern, ' ', $description) ?? $description;
    }

    $description = preg_replace('/\s+/', ' ', trim($description)) ?? '';

    if ($description === '' && str_contains($normalizedMessage, 'salario')) {
        return 'Salario';
    }

    return financial_title_case($description);
}

function financial_title_case(string $text): string
{
    if ($text === '') {
        return '';
    }

    return mb_convert_case($text, MB_CASE_TITLE, 'UTF-8');
}
