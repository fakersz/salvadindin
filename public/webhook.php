<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/config.php';
require_once __DIR__ . '/../app/database.php';
require_once __DIR__ . '/../app/WhatsAppService.php';
require_once __DIR__ . '/../app/OpenAIService.php';
require_once __DIR__ . '/../app/functions/whatsapp_parser.php';
require_once __DIR__ . '/../app/functions/whatsapp_finance.php';
require_once __DIR__ . '/../app/functions/whatsapp_media.php';

function whatsapp_log(array $data): void
{
    $directory = dirname(__DIR__) . '/storage/logs';

    if (!is_dir($directory)) {
        mkdir($directory, 0755, true);
    }

    $data['logged_at'] = date('c');
    $line = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    if ($line !== false) {
        file_put_contents($directory . '/whatsapp-webhook.log', $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}

function whatsapp_sanitize_payload_for_log(mixed $value): mixed
{
    if (!is_array($value)) {
        return $value;
    }

    $sanitized = [];

    foreach ($value as $key => $item) {
        $keyString = is_string($key) ? strtolower($key) : $key;

        if (in_array($keyString, [
            'url',
            'hash',
            'access_token',
            'token',
            'verify_token',
            'hub_verify_token',
            'hub.verify_token',
            'phone_number_id',
            'display_phone_number',
        ], true)) {
            $sanitized[$key] = '[redacted]';
            continue;
        }

        $sanitized[$key] = whatsapp_sanitize_payload_for_log($item);
    }

    return $sanitized;
}

function whatsapp_disabled_webhook_response(mixed $payload = null): void
{
    whatsapp_log([
        'event' => 'webhook_received_whatsapp_disabled',
        'whatsapp_enabled' => false,
        'payload' => is_array($payload) ? whatsapp_sanitize_payload_for_log($payload) : null,
        'json_valid' => is_array($payload),
    ]);

    http_response_code(200);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'ok' => true,
        'whatsapp_enabled' => false,
        'message' => 'WhatsApp integration disabled',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function whatsapp_text_reply(string $message): string
{
    $parsed = parseWhatsAppFinancialMessage($message);

    if (($parsed['detected'] ?? false) && is_string($parsed['reply'] ?? null)) {
        return $parsed['reply'];
    }

    return defaultWhatsAppReply();
}

function whatsapp_unlinked_user_reply(): string
{
    return '⚠️ Seu número ainda não está vinculado a uma conta SalvaDinDin. '
        . "Crie sua conta gratis ou acesse o painel para sincronizar seu WhatsApp:\n\n"
        . "Cadastro: https://salvadindin.online/register.php\n"
        . "Sincronizar: https://salvadindin.online/whatsapp.php";
}

function whatsapp_message_already_processed(PDO $pdo, string $messageId): bool
{
    if ($messageId === '') {
        return false;
    }

    $stmt = $pdo->prepare('SELECT 1 FROM whatsapp_message_logs WHERE message_id = :message_id LIMIT 1');
    $stmt->execute(['message_id' => $messageId]);

    return (bool) $stmt->fetchColumn();
}

function whatsapp_mark_message_processed(
    PDO $pdo,
    array $event,
    string $status,
    ?int $userId = null,
    ?int $transactionId = null
): void {
    $messageId = (string) ($event['message_id'] ?? '');

    if ($messageId === '') {
        return;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO whatsapp_message_logs (message_id, wa_id, user_id, transaction_id, status)
         VALUES (:message_id, :wa_id, :user_id, :transaction_id, :status)
         ON CONFLICT (message_id) DO NOTHING'
    );
    $stmt->execute([
        'message_id' => $messageId,
        'wa_id' => (string) $event['wa_id'],
        'user_id' => $userId,
        'transaction_id' => $transactionId,
        'status' => $status,
    ]);
}

function whatsapp_send_response(WhatsAppService $service, array $event, string $reply): array
{
    if (whatsapp_reply_uses_buttons($reply)) {
        $buttonResult = $service->sendButtons((string) $event['wa_id'], $reply, whatsapp_default_buttons());

        if (($buttonResult['ok'] ?? false) === true) {
            return $buttonResult;
        }

        whatsapp_log([
            'event' => 'fallback_text_sent',
            'wa_id' => $event['wa_id'],
            'message_id' => $event['message_id'],
            'interactive_status' => (int) ($buttonResult['status'] ?? 0),
            'interactive_error' => (string) ($buttonResult['error'] ?? ''),
        ]);
    }

    return $service->sendText((string) $event['wa_id'], $reply);
}

function whatsapp_command_reply_for_user(PDO $pdo, int $userId, string $command): ?string
{
    $normalized = normalize_whatsapp_text($command);

    if (in_array($normalized, ['resumo_mes', 'resumo', 'resumo do mes', 'resumo do mês'], true)) {
        whatsapp_log([
            'event' => 'summary_requested',
            'user_id' => $userId,
        ]);

        return whatsappMonthSummaryReply($pdo, $userId);
    }

    if (in_array($normalized, ['saldo_atual', 'saldo'], true)) {
        whatsapp_log([
            'event' => 'balance_requested',
            'user_id' => $userId,
        ]);

        return whatsappCurrentBalanceReply($pdo, $userId);
    }

    return whatsapp_button_or_command_reply($command);
}

function whatsapp_save_transaction_reply(array $event, PDO $pdo): string
{
    try {
        $user = findUserByWhatsAppPhone($pdo, (string) $event['wa_id']);
    } catch (Throwable $exception) {
        whatsapp_mark_message_processed($pdo, $event, 'user_lookup_failed');

        whatsapp_log([
            'event' => 'transaction_error',
            'reason' => 'user_lookup_failed',
            'wa_id' => $event['wa_id'],
            'message_id' => $event['message_id'],
            'error' => $exception->getMessage(),
        ]);

        return 'Não consegui consultar seu vínculo agora. Tente novamente em instantes.';
    }

    if (!$user) {
        whatsapp_mark_message_processed($pdo, $event, 'user_not_found');

        whatsapp_log([
            'event' => 'user_not_found',
            'wa_id' => $event['wa_id'],
            'message_id' => $event['message_id'],
        ]);

        return whatsapp_unlinked_user_reply();
    }

    whatsapp_log([
        'event' => 'user_found',
        'wa_id' => $event['wa_id'],
        'message_id' => $event['message_id'],
        'user_id' => (int) $user['id'],
    ]);

    $buttonId = (string) ($event['button_id'] ?? '');

    if ($buttonId !== '') {
        whatsapp_log([
            'event' => 'button_reply_received',
            'wa_id' => $event['wa_id'],
            'message_id' => $event['message_id'],
            'button_id' => $buttonId,
            'button_title' => (string) ($event['button_title'] ?? ''),
        ]);

        $commandReply = whatsapp_command_reply_for_user($pdo, (int) $user['id'], $buttonId);
        whatsapp_mark_message_processed($pdo, $event, 'button_reply', (int) $user['id']);

        return $commandReply ?? whatsapp_welcome_reply();
    }

    $commandReply = whatsapp_command_reply_for_user($pdo, (int) $user['id'], (string) $event['text']);

    if ($commandReply !== null) {
        whatsapp_mark_message_processed($pdo, $event, 'command_replied', (int) $user['id']);

        return $commandReply;
    }

    $transaction = parseWhatsAppTransaction((string) $event['text']);

    if (!($transaction['ok'] ?? false)) {
        whatsapp_mark_message_processed($pdo, $event, 'parse_failed', (int) $user['id']);

        whatsapp_log([
            'event' => 'transaction_error',
            'reason' => 'parse_failed',
            'wa_id' => $event['wa_id'],
            'message_id' => $event['message_id'],
            'has_amount' => $transaction['amount'] !== null,
            'has_type' => $transaction['type'] !== null,
        ]);

        return whatsappTransactionHelpReply();
    }

    whatsapp_log([
        'event' => 'transaction_detected',
        'wa_id' => $event['wa_id'],
        'message_id' => $event['message_id'],
        'type' => $transaction['type'],
        'amount' => $transaction['amount'],
        'category' => $transaction['category_name'],
        'description' => $transaction['description'],
    ]);

    try {
        $pdo->beginTransaction();
        $transactionId = saveWhatsAppTransaction($pdo, (int) $user['id'], $transaction);
        whatsapp_mark_message_processed($pdo, $event, 'transaction_saved', (int) $user['id'], $transactionId);
        $pdo->commit();

        whatsapp_log([
            'event' => 'transaction_saved',
            'wa_id' => $event['wa_id'],
            'message_id' => $event['message_id'],
            'user_id' => (int) $user['id'],
            'transaction_id' => $transactionId,
            'type' => $transaction['type'],
            'amount' => $transaction['amount'],
            'category' => $transaction['category_name'],
        ]);

        return whatsappTransactionSuccessReply($transaction);
    } catch (Throwable $exception) {
        if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }

        whatsapp_mark_message_processed($pdo, $event, 'save_failed');

        whatsapp_log([
            'event' => 'transaction_error',
            'reason' => 'save_failed',
            'wa_id' => $event['wa_id'],
            'message_id' => $event['message_id'],
            'error' => $exception->getMessage(),
        ]);

        return 'Não consegui salvar a movimentação agora. Tente novamente em instantes.';
    }
}

function whatsapp_media_reply(array $event, PDO $pdo, WhatsAppService $service, OpenAIService $openai): string
{
    try {
        $user = findUserByWhatsAppPhone($pdo, (string) $event['wa_id']);
    } catch (Throwable $exception) {
        whatsapp_mark_message_processed($pdo, $event, 'user_lookup_failed');
        whatsapp_log([
            'event' => 'media_error',
            'reason' => 'user_lookup_failed',
            'wa_id' => $event['wa_id'],
            'message_id' => $event['message_id'],
            'message_type' => $event['type'],
            'error' => $exception->getMessage(),
        ]);

        return 'Não consegui consultar seu vínculo agora. Tente novamente em instantes.';
    }

    if (!$user) {
        whatsapp_mark_message_processed($pdo, $event, 'user_not_found');
        whatsapp_log([
            'event' => 'user_not_found',
            'wa_id' => $event['wa_id'],
            'message_id' => $event['message_id'],
            'message_type' => $event['type'],
        ]);

        return whatsapp_unlinked_user_reply();
    }

    $download = whatsapp_download_event_media($service, $event);

    whatsapp_log([
        'event' => 'media_download_attempted',
        'wa_id' => $event['wa_id'],
        'message_id' => $event['message_id'],
        'message_type' => $event['type'],
        'media_id' => $event['media_id'],
        'ok' => (bool) ($download['ok'] ?? false),
        'mime_type' => (string) ($download['mime_type'] ?? ''),
        'file_size' => (int) ($download['file_size'] ?? 0),
        'error' => (string) ($download['error'] ?? ''),
    ]);

    if (!($download['ok'] ?? false)) {
        whatsapp_mark_message_processed($pdo, $event, 'media_download_failed', (int) $user['id']);

        return whatsapp_media_failure_reply((string) $event['type']);
    }

    $type = (string) $event['type'];
    $filePath = (string) $download['file_path'];
    $mimeType = (string) $download['mime_type'];

    if ($type === 'audio' || $type === 'voice') {
        $transcription = $openai->transcribeAudio($filePath);

        whatsapp_log([
            'event' => 'audio_transcription_attempted',
            'wa_id' => $event['wa_id'],
            'message_id' => $event['message_id'],
            'media_id' => $event['media_id'],
            'ok' => (bool) ($transcription['ok'] ?? false),
            'error' => (string) ($transcription['error'] ?? ''),
        ]);

        if (!($transcription['ok'] ?? false) || trim((string) ($transcription['text'] ?? '')) === '') {
            whatsapp_mark_message_processed($pdo, $event, 'audio_transcription_failed', (int) $user['id']);

            return whatsapp_media_failure_reply($type);
        }

        $textEvent = $event;
        $textEvent['type'] = 'text';
        $textEvent['text'] = (string) $transcription['text'];
        $textEvent['button_id'] = '';
        $textEvent['button_title'] = '';

        whatsapp_log([
            'event' => 'audio_text_extracted',
            'wa_id' => $event['wa_id'],
            'message_id' => $event['message_id'],
            'text' => $textEvent['text'],
        ]);

        return whatsapp_save_transaction_reply($textEvent, $pdo);
    }

    if ($type === 'image') {
        $analysis = $openai->analyzeReceiptImage($filePath, $mimeType);

        if (!($analysis['ok'] ?? false)) {
            whatsapp_mark_message_processed($pdo, $event, 'image_analysis_failed', (int) $user['id']);
            whatsapp_log([
                'event' => 'media_analysis_error',
                'message_type' => $type,
                'wa_id' => $event['wa_id'],
                'message_id' => $event['message_id'],
                'media_id' => $event['media_id'],
                'error' => (string) ($analysis['error'] ?? ''),
            ]);

            return whatsapp_media_failure_reply($type);
        }

        whatsapp_mark_message_processed($pdo, $event, 'image_analyzed', (int) $user['id']);

        return whatsapp_receipt_analysis_reply($analysis);
    }

    if ($type === 'document') {
        $analysis = $openai->analyzeDocument($filePath, $mimeType);

        if (!($analysis['ok'] ?? false)) {
            whatsapp_mark_message_processed($pdo, $event, 'document_analysis_failed', (int) $user['id']);
            whatsapp_log([
                'event' => 'media_analysis_error',
                'message_type' => $type,
                'wa_id' => $event['wa_id'],
                'message_id' => $event['message_id'],
                'media_id' => $event['media_id'],
                'mime_type' => $mimeType,
                'error' => (string) ($analysis['error'] ?? ''),
            ]);

            return whatsapp_media_failure_reply($type);
        }

        whatsapp_mark_message_processed($pdo, $event, 'document_analyzed', (int) $user['id']);

        return whatsapp_receipt_analysis_reply($analysis);
    }

    whatsapp_mark_message_processed($pdo, $event, 'unsupported_media', (int) $user['id']);

    return whatsapp_media_failure_reply($type);
}

function handle_whatsapp_get_verification(): void
{
    $mode = (string) ($_GET['hub_mode'] ?? $_GET['hub.mode'] ?? '');
    $verifyToken = (string) ($_GET['hub_verify_token'] ?? $_GET['hub.verify_token'] ?? '');
    $challenge = (string) ($_GET['hub_challenge'] ?? $_GET['hub.challenge'] ?? '');
    $verifyTokenEnv = (string) env_value('META_VERIFY_TOKEN', '');
    $expectedToken = $verifyTokenEnv !== ''
        ? $verifyTokenEnv
        : (string) env_value('VERIFY_TOKEN', env_value('WHATSAPP_VERIFY_TOKEN', ''));

    if ($mode === 'subscribe' && hash_equals($expectedToken, $verifyToken)) {
        http_response_code(200);
        header('Content-Type: text/plain; charset=UTF-8');
        echo $challenge;
        return;
    }

    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Forbidden';
}

function extract_whatsapp_message_events(array $payload): array
{
    $events = [];

    foreach (($payload['entry'] ?? []) as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        foreach (($entry['changes'] ?? []) as $change) {
            if (!is_array($change)) {
                continue;
            }

            $value = $change['value'] ?? [];

            if (!is_array($value)) {
                continue;
            }

            $contactsByWaId = [];

            foreach (($value['contacts'] ?? []) as $contact) {
                if (!is_array($contact)) {
                    continue;
                }

                $waId = (string) ($contact['wa_id'] ?? '');
                $contactsByWaId[$waId] = [
                    'wa_id' => $waId,
                    'name' => (string) ($contact['profile']['name'] ?? ''),
                ];
            }

            foreach (($value['messages'] ?? []) as $message) {
                if (!is_array($message)) {
                    continue;
                }

                $from = (string) ($message['from'] ?? '');
                $contact = $contactsByWaId[$from] ?? ['wa_id' => $from, 'name' => ''];
                $type = (string) ($message['type'] ?? '');
                $text = '';
                $buttonId = '';
                $buttonTitle = '';
                $mediaId = '';
                $mimeType = '';
                $filename = '';

                if ($type === 'text') {
                    $text = (string) ($message['text']['body'] ?? '');
                } elseif (
                    $type === 'interactive'
                    && (($message['interactive']['type'] ?? '') === 'button_reply')
                ) {
                    $buttonId = (string) ($message['interactive']['button_reply']['id'] ?? '');
                    $buttonTitle = (string) ($message['interactive']['button_reply']['title'] ?? '');
                    $text = $buttonId !== '' ? $buttonId : $buttonTitle;
                } elseif ($type === 'image') {
                    $mediaId = (string) ($message['image']['id'] ?? '');
                    $mimeType = (string) ($message['image']['mime_type'] ?? '');
                    $text = (string) ($message['image']['caption'] ?? '[imagem]');
                } elseif ($type === 'audio') {
                    $mediaId = (string) ($message['audio']['id'] ?? '');
                    $mimeType = (string) ($message['audio']['mime_type'] ?? '');
                    $text = '[audio]';

                    if (($message['audio']['voice'] ?? false) === true) {
                        $type = 'voice';
                        $text = '[voz]';
                    }
                } elseif ($type === 'document') {
                    $mediaId = (string) ($message['document']['id'] ?? '');
                    $mimeType = (string) ($message['document']['mime_type'] ?? '');
                    $filename = (string) ($message['document']['filename'] ?? '');
                    $text = (string) ($message['document']['caption'] ?? ($filename !== '' ? $filename : '[documento]'));
                } else {
                    continue;
                }

                if ($from === '' || $text === '') {
                    continue;
                }

                $events[] = [
                    'wa_id' => $contact['wa_id'],
                    'name' => $contact['name'],
                    'text' => $text,
                    'type' => $type,
                    'button_id' => $buttonId,
                    'button_title' => $buttonTitle,
                    'media_id' => $mediaId,
                    'mime_type' => $mimeType,
                    'filename' => $filename,
                    'message_id' => (string) ($message['id'] ?? ''),
                ];
            }
        }
    }

    return $events;
}

function process_whatsapp_payload(array $payload): void
{
    $service = new WhatsAppService();
    $openai = new OpenAIService();
    $pdo = db();

    foreach (extract_whatsapp_message_events($payload) as $event) {
        try {
            whatsapp_log([
                'event' => 'message_extracted',
                'wa_id' => $event['wa_id'],
                'name' => $event['name'],
                'message_id' => $event['message_id'],
                'type' => $event['type'],
                'text' => $event['text'],
                'button_id' => $event['button_id'],
                'media_id' => $event['media_id'],
                'mime_type' => $event['mime_type'],
            ]);

            if (whatsapp_message_already_processed($pdo, (string) $event['message_id'])) {
                whatsapp_log([
                    'event' => 'message_duplicate_ignored',
                    'wa_id' => $event['wa_id'],
                    'message_id' => $event['message_id'],
                ]);

                continue;
            }

            $reply = in_array((string) $event['type'], ['image', 'audio', 'voice', 'document'], true)
                ? whatsapp_media_reply($event, $pdo, $service, $openai)
                : whatsapp_save_transaction_reply($event, $pdo);

            $sendResult = whatsapp_send_response($service, $event, $reply);

            whatsapp_log([
                'event' => 'reply_attempted',
                'wa_id' => $event['wa_id'],
                'message_id' => $event['message_id'],
                'message_type' => $event['type'],
                'configured' => $service->isConfigured(),
                'openai_configured' => $openai->isConfigured(),
                'ok' => (bool) ($sendResult['ok'] ?? false),
                'status' => (int) ($sendResult['status'] ?? 0),
                'error' => (string) ($sendResult['error'] ?? ''),
            ]);
        } catch (Throwable $exception) {
            whatsapp_log([
                'event' => 'message_processing_error',
                'wa_id' => $event['wa_id'] ?? '',
                'message_id' => $event['message_id'] ?? '',
                'message_type' => $event['type'] ?? '',
                'error' => $exception->getMessage(),
            ]);

            if (!empty($event['wa_id'])) {
                $service->sendText((string) $event['wa_id'], 'Recebi sua mensagem, mas não consegui processar agora. Tente novamente em instantes.');
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!whatsapp_integration_enabled()) {
        whatsapp_disabled_webhook_response($_GET);
        exit;
    }

    handle_whatsapp_get_verification();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: GET, POST');
    exit;
}

try {
    $rawPayload = file_get_contents('php://input') ?: '';
    $payload = json_decode($rawPayload, true);

    if (!whatsapp_integration_enabled()) {
        whatsapp_disabled_webhook_response($payload);
        exit;
    }

    whatsapp_log([
        'event' => 'webhook_received',
        'payload' => is_array($payload) ? whatsapp_sanitize_payload_for_log($payload) : null,
        'json_valid' => is_array($payload),
    ]);

    if (is_array($payload)) {
        if (function_exists('fastcgi_finish_request')) {
            http_response_code(200);
            header('Content-Type: text/plain; charset=UTF-8');
            echo 'EVENT_RECEIVED';
            fastcgi_finish_request();

            process_whatsapp_payload($payload);
            exit;
        }

        process_whatsapp_payload($payload);
    }
} catch (Throwable $exception) {
    whatsapp_log([
        'event' => 'webhook_error',
        'error' => $exception->getMessage(),
    ]);
}

http_response_code(200);
header('Content-Type: text/plain; charset=UTF-8');
echo 'EVENT_RECEIVED';
