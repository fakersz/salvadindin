<?php

declare(strict_types=1);

function whatsapp_media_extension(string $mimeType, string $fallback = 'bin'): string
{
    return match (strtolower($mimeType)) {
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
        'audio/ogg', 'audio/ogg; codecs=opus' => 'ogg',
        'audio/mpeg' => 'mp3',
        'audio/mp4' => 'm4a',
        'audio/aac' => 'aac',
        'audio/amr' => 'amr',
        default => $fallback,
    };
}

function whatsapp_download_event_media(WhatsAppService $service, array $event): array
{
    $mediaId = (string) ($event['media_id'] ?? '');

    if ($mediaId === '') {
        return ['ok' => false, 'error' => 'media_id_missing'];
    }

    $info = $service->getMediaInfo($mediaId);

    if (!($info['ok'] ?? false)) {
        return [
            'ok' => false,
            'error' => (string) ($info['error'] ?? 'media_info_failed'),
            'status' => (int) ($info['status'] ?? 0),
        ];
    }

    $response = $info['response'] ?? [];
    $url = (string) ($response['url'] ?? '');
    $mimeType = (string) ($response['mime_type'] ?? ($event['mime_type'] ?? 'application/octet-stream'));
    $extension = whatsapp_media_extension($mimeType);
    $dateDirectory = date('Y-m-d');
    $safeMediaId = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $mediaId) ?: bin2hex(random_bytes(8));
    $targetPath = dirname(__DIR__, 2) . '/storage/whatsapp_media/' . $dateDirectory . '/' . $safeMediaId . '.' . $extension;
    $download = $service->downloadMedia($url, $targetPath);

    if (!($download['ok'] ?? false)) {
        return [
            'ok' => false,
            'error' => (string) ($download['error'] ?? 'media_download_failed'),
            'status' => (int) ($download['status'] ?? 0),
            'media_id' => $mediaId,
            'mime_type' => $mimeType,
        ];
    }

    return [
        'ok' => true,
        'media_id' => $mediaId,
        'mime_type' => $mimeType,
        'file_path' => (string) $download['path'],
        'file_size' => (int) ($download['file_size'] ?? 0),
        'sha256' => (string) ($response['sha256'] ?? ''),
    ];
}

function whatsapp_receipt_analysis_reply(array $analysis): string
{
    $parsed = whatsapp_parse_openai_json((string) ($analysis['text'] ?? ''));

    if (!$parsed) {
        return "✅ Comprovante analisado\n\n"
            . "Observação:\n"
            . trim((string) ($analysis['text'] ?? 'Consegui receber o arquivo, mas preciso confirmar os dados manualmente.'));
    }

    return "✅ Comprovante analisado\n\n"
        . 'Valor: ' . whatsapp_value_or_unknown($parsed['valor'] ?? null) . "\n"
        . 'Data: ' . whatsapp_value_or_unknown($parsed['data'] ?? null) . "\n"
        . 'Banco: ' . whatsapp_value_or_unknown($parsed['banco'] ?? null) . "\n"
        . 'Pagador: ' . whatsapp_value_or_unknown($parsed['pagador'] ?? null) . "\n"
        . 'Recebedor: ' . whatsapp_value_or_unknown($parsed['recebedor'] ?? null) . "\n"
        . 'Status: ' . whatsapp_value_or_unknown($parsed['status'] ?? null) . "\n\n"
        . 'Observação: ' . whatsapp_value_or_unknown($parsed['observacao'] ?? ($parsed['texto_resumo'] ?? null));
}

function whatsapp_parse_openai_json(string $text): ?array
{
    $text = trim($text);

    if ($text === '') {
        return null;
    }

    $text = preg_replace('/^```(?:json)?\s*|\s*```$/u', '', $text) ?? $text;
    $decoded = json_decode($text, true);

    if (is_array($decoded)) {
        return $decoded;
    }

    if (preg_match('/\{.*\}/us', $text, $matches)) {
        $decoded = json_decode($matches[0], true);

        if (is_array($decoded)) {
            return $decoded;
        }
    }

    return null;
}

function whatsapp_value_or_unknown(mixed $value): string
{
    $value = is_scalar($value) ? trim((string) $value) : '';

    if ($value === '' || strtolower($value) === 'null') {
        return 'não identificado';
    }

    return $value;
}

function whatsapp_media_failure_reply(string $type): string
{
    return match ($type) {
        'audio', 'voice' => "🎙️ Recebi seu áudio, mas não consegui transcrever agora.\n\nTente enviar novamente ou escreva algo como: gastei 35 no almoço.",
        'image' => "🧾 Recebi sua imagem, mas não consegui analisar agora.\n\nTente enviar uma foto mais nítida ou escreva o valor e o que foi.",
        'document' => "📄 Recebi seu documento, mas não consegui analisar esse arquivo.\n\nSe for comprovante, envie uma imagem mais nítida ou descreva o gasto/receita.",
        default => "Recebi o arquivo, mas não consegui processar esse formato agora.",
    };
}
