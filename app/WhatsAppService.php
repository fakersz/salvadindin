<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

class WhatsAppService
{
    private bool $enabled;
    private string $token;
    private string $phoneNumberId;
    private string $apiVersion;
    private string $logPath;

    public function __construct()
    {
        $this->enabled = whatsapp_integration_enabled();

        if ($this->enabled) {
            $metaToken = (string) env_value('META_ACCESS_TOKEN', '');
            $phoneNumberId = (string) env_value('META_PHONE_NUMBER_ID', '');

            $this->token = $metaToken !== '' ? $metaToken : (string) env_value('WHATSAPP_TOKEN', '');
            $this->phoneNumberId = $phoneNumberId !== ''
                ? $phoneNumberId
                : (string) env_value('PHONE_NUMBER_ID', env_value('WHATSAPP_PHONE_NUMBER_ID', ''));
        } else {
            $this->token = '';
            $this->phoneNumberId = '';
        }

        $this->apiVersion = (string) env_value('WHATSAPP_API_VERSION', 'v25.0');
        $this->logPath = dirname(__DIR__) . '/storage/logs/whatsapp-webhook.log';
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function isConfigured(): bool
    {
        return $this->enabled
            && $this->token !== ''
            && $this->token !== 'COLE_O_TOKEN_AQUI'
            && $this->token !== 'SEU_TOKEN_DA_META'
            && $this->phoneNumberId !== '';
    }

    public function sendText(string $to, string $body): array
    {
        if (!$this->enabled) {
            return $this->disabledResponse('reply_disabled');
        }

        $to = $this->normalizePhoneNumber($to);
        $endpoint = $this->messagesEndpoint();
        $payload = $this->textPayload($to, $body);

        return $this->sendPayload($endpoint, $payload, 'reply_success', 'reply_error');
    }

    public function sendButtons(string $to, string $body, array $buttons): array
    {
        if (!$this->enabled) {
            return $this->disabledResponse('interactive_button_disabled');
        }

        $to = $this->normalizePhoneNumber($to);
        $endpoint = $this->messagesEndpoint();
        $payload = $this->buttonsPayload($to, $body, $buttons);

        return $this->sendPayload($endpoint, $payload, 'interactive_button_sent', 'interactive_button_failed');
    }

    public function getMediaInfo(string $mediaId): array
    {
        if (!$this->enabled) {
            return $this->disabledResponse('media_info_disabled');
        }

        if (!$this->isConfigured() || $mediaId === '') {
            return ['ok' => false, 'error' => 'whatsapp_not_configured_or_media_missing'];
        }

        $endpoint = 'https://graph.facebook.com/' . $this->apiVersion . '/' . rawurlencode($mediaId);
        $result = $this->requestJson($endpoint);

        $this->log(($result['ok'] ?? false) ? 'media_info_success' : 'media_info_error', [
            'url' => $endpoint,
            'media_id' => $mediaId,
            'http_status' => (int) ($result['status'] ?? 0),
            'meta_response' => $result['response'] ?? null,
            'error' => (string) ($result['error'] ?? ''),
        ]);

        return $result;
    }

    public function downloadMedia(string $mediaUrl, string $targetPath): array
    {
        if (!$this->enabled) {
            return $this->disabledResponse('media_download_disabled');
        }

        if (!$this->isConfigured() || $mediaUrl === '') {
            return ['ok' => false, 'error' => 'whatsapp_not_configured_or_url_missing'];
        }

        $directory = dirname($targetPath);

        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $handle = fopen($targetPath, 'wb');

        if ($handle === false) {
            return ['ok' => false, 'error' => 'target_not_writable'];
        }

        $curl = curl_init($mediaUrl);
        curl_setopt_array($curl, [
            CURLOPT_FILE => $handle,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->token,
            ],
        ]);

        $success = curl_exec($curl);
        $curlError = curl_error($curl);
        $statusCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        fclose($handle);

        $ok = $success !== false && $statusCode >= 200 && $statusCode < 300 && is_file($targetPath) && filesize($targetPath) > 0;

        if (!$ok && is_file($targetPath)) {
            unlink($targetPath);
        }

        $this->log($ok ? 'media_download_success' : 'media_download_error', [
            'url' => $this->redactMediaUrl($mediaUrl),
            'target_path' => $targetPath,
            'http_status' => $statusCode,
            'file_size' => $ok ? filesize($targetPath) : 0,
            'error' => $curlError,
        ]);

        return [
            'ok' => $ok,
            'status' => $statusCode,
            'path' => $ok ? $targetPath : null,
            'file_size' => $ok ? filesize($targetPath) : 0,
            'error' => $ok ? '' : ($curlError !== '' ? $curlError : 'media_download_failed'),
        ];
    }

    private function sendPayload(string $endpoint, array $payload, string $successEvent, string $errorEvent): array
    {
        if (!$this->isConfigured()) {
            $this->log($errorEvent, [
                'reason' => 'whatsapp_not_configured',
                'url' => $endpoint,
                'payload' => $payload,
                'http_status' => 0,
                'meta_response' => null,
            ]);

            return [
                'ok' => false,
                'status' => 0,
                'error' => 'whatsapp_not_configured',
            ];
        }

        $curl = curl_init($endpoint);

        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->token,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);

        $responseBody = curl_exec($curl);
        $curlError = curl_error($curl);
        $statusCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        if ($responseBody === false) {
            $this->log($errorEvent, [
                'url' => $endpoint,
                'payload' => $payload,
                'http_status' => $statusCode,
                'curl_error' => $curlError !== '' ? $curlError : 'curl_error',
                'meta_response' => null,
            ]);

            return [
                'ok' => false,
                'status' => $statusCode,
                'error' => $curlError !== '' ? $curlError : 'curl_error',
            ];
        }

        $decoded = json_decode((string) $responseBody, true);
        $metaResponse = is_array($decoded) ? $decoded : $responseBody;
        $ok = $statusCode >= 200 && $statusCode < 300;

        $this->log('meta_response', [
            'url' => $endpoint,
            'payload' => $payload,
            'http_status' => $statusCode,
            'meta_response' => $metaResponse,
        ]);

        $this->log($ok ? $successEvent : $errorEvent, [
            'url' => $endpoint,
            'payload' => $payload,
            'http_status' => $statusCode,
            'meta_response' => $metaResponse,
        ]);

        return [
            'ok' => $ok,
            'status' => $statusCode,
            'response' => is_array($decoded) ? $decoded : null,
        ];
    }

    private function disabledResponse(string $event): array
    {
        $this->log($event, [
            'reason' => 'whatsapp_disabled',
            'whatsapp_enabled' => false,
            'http_status' => 0,
        ]);

        return [
            'ok' => false,
            'status' => 0,
            'error' => 'whatsapp_disabled',
        ];
    }

    private function requestJson(string $endpoint): array
    {
        $curl = curl_init($endpoint);

        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->token,
            ],
        ]);

        $responseBody = curl_exec($curl);
        $curlError = curl_error($curl);
        $statusCode = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        if ($responseBody === false) {
            return [
                'ok' => false,
                'status' => $statusCode,
                'error' => $curlError !== '' ? $curlError : 'curl_error',
            ];
        }

        $decoded = json_decode((string) $responseBody, true);
        $ok = $statusCode >= 200 && $statusCode < 300 && is_array($decoded);

        return [
            'ok' => $ok,
            'status' => $statusCode,
            'response' => is_array($decoded) ? $decoded : null,
            'error' => $ok ? '' : (string) ($decoded['error']['message'] ?? 'meta_request_failed'),
        ];
    }

    private function messagesEndpoint(): string
    {
        return 'https://graph.facebook.com/'
            . $this->apiVersion
            . '/'
            . $this->phoneNumberId
            . '/messages';
    }

    private function textPayload(string $to, string $body): array
    {
        return [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'text',
            'text' => [
                'body' => $body,
            ],
        ];
    }

    private function buttonsPayload(string $to, string $body, array $buttons): array
    {
        $formattedButtons = [];

        foreach (array_slice($buttons, 0, 3) as $button) {
            $id = (string) ($button['id'] ?? '');
            $title = (string) ($button['title'] ?? '');

            if ($id === '' || $title === '') {
                continue;
            }

            $formattedButtons[] = [
                'type' => 'reply',
                'reply' => [
                    'id' => mb_substr($id, 0, 256, 'UTF-8'),
                    'title' => mb_substr($title, 0, 20, 'UTF-8'),
                ],
            ];
        }

        return [
            'messaging_product' => 'whatsapp',
            'to' => $to,
            'type' => 'interactive',
            'interactive' => [
                'type' => 'button',
                'body' => [
                    'text' => $body,
                ],
                'action' => [
                    'buttons' => $formattedButtons,
                ],
            ],
        ];
    }

    private function normalizePhoneNumber(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?? '';
    }

    private function redactMediaUrl(string $url): string
    {
        $parts = parse_url($url);

        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return '[media_url]';
        }

        return $parts['scheme'] . '://' . $parts['host'] . ($parts['path'] ?? '');
    }

    private function log(string $event, array $context): void
    {
        $directory = dirname($this->logPath);

        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $context['event'] = $event;
        $context['headers'] = [
            'Authorization' => 'Bearer ***',
            'Content-Type' => 'application/json',
        ];
        $context['logged_at'] = date('c');

        $line = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($line !== false) {
            file_put_contents($this->logPath, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
        }
    }
}
