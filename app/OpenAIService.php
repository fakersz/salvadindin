<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

class OpenAIService
{
    private string $apiKey;
    private string $logPath;

    public function __construct()
    {
        $this->apiKey = (string) env_value('OPENAI_API_KEY', '');
        $this->logPath = dirname(__DIR__) . '/storage/logs/whatsapp-webhook.log';
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== ''
            && $this->apiKey !== 'COLE_SUA_CHAVE_AQUI'
            && $this->apiKey !== 'YOUR_OPENAI_API_KEY';
    }

    public function transcribeAudio(string $filePath): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'error' => 'openai_not_configured'];
        }

        if (!is_readable($filePath)) {
            return ['ok' => false, 'error' => 'audio_file_not_readable'];
        }

        $preparedAudio = $this->prepareAudioForTranscription($filePath);

        if (!($preparedAudio['ok'] ?? false)) {
            return $preparedAudio;
        }

        $transcriptionPath = (string) $preparedAudio['path'];
        $curl = curl_init('https://api.openai.com/v1/audio/transcriptions');
        $payload = [
            'model' => 'gpt-4o-mini-transcribe',
            'file' => new CURLFile($transcriptionPath),
            'response_format' => 'json',
            'language' => 'pt',
        ];

        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiKey,
            ],
            CURLOPT_POSTFIELDS => $payload,
        ]);

        $responseBody = curl_exec($curl);
        $error = curl_error($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        $decoded = is_string($responseBody) ? json_decode($responseBody, true) : null;
        $ok = $status >= 200 && $status < 300 && is_array($decoded);

        $this->log($ok ? 'openai_audio_transcribed' : 'openai_audio_error', [
            'http_status' => $status,
            'error' => $ok ? '' : ($error !== '' ? $error : (string) ($decoded['error']['message'] ?? 'openai_audio_failed')),
        ]);

        if (!$ok) {
            return [
                'ok' => false,
                'status' => $status,
                'error' => $error !== '' ? $error : (string) ($decoded['error']['message'] ?? 'openai_audio_failed'),
            ];
        }

        return [
            'ok' => true,
            'text' => trim((string) ($decoded['text'] ?? '')),
            'status' => $status,
        ];
    }

    private function prepareAudioForTranscription(string $filePath): array
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $supported = ['mp3', 'mp4', 'mpeg', 'mpga', 'm4a', 'wav', 'webm'];

        if (in_array($extension, $supported, true)) {
            return ['ok' => true, 'path' => $filePath, 'converted' => false];
        }

        $ffmpeg = trim((string) shell_exec('command -v ffmpeg 2>/dev/null'));

        if ($ffmpeg === '') {
            $this->log('audio_conversion_error', [
                'error' => 'ffmpeg_not_installed',
                'source_extension' => $extension,
            ]);

            return ['ok' => false, 'error' => 'ffmpeg_not_installed'];
        }

        $targetPath = preg_replace('/\.[^.]+$/', '', $filePath) . '.wav';
        $command = escapeshellcmd($ffmpeg)
            . ' -y -i '
            . escapeshellarg($filePath)
            . ' -ar 16000 -ac 1 '
            . escapeshellarg((string) $targetPath)
            . ' 2>&1';
        exec($command, $output, $exitCode);
        $ok = $exitCode === 0 && is_readable((string) $targetPath) && filesize((string) $targetPath) > 0;

        $this->log($ok ? 'audio_converted' : 'audio_conversion_error', [
            'source_extension' => $extension,
            'target_path' => $targetPath,
            'exit_code' => $exitCode,
            'error' => $ok ? '' : mb_substr(implode("\n", $output), 0, 600, 'UTF-8'),
        ]);

        return $ok
            ? ['ok' => true, 'path' => (string) $targetPath, 'converted' => true]
            : ['ok' => false, 'error' => 'audio_conversion_failed'];
    }

    public function analyzeReceiptImage(string $filePath, string $mimeType): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'error' => 'openai_not_configured'];
        }

        if (!is_readable($filePath)) {
            return ['ok' => false, 'error' => 'image_file_not_readable'];
        }

        $base64 = base64_encode((string) file_get_contents($filePath));
        $dataUrl = 'data:' . $mimeType . ';base64,' . $base64;
        $prompt = "Analise este comprovante/recibo em português. Extraia, se estiver visível: valor, data, banco, pagador, recebedor, tipo de transação, status aparente e observação. Responda em JSON válido com as chaves: valor, data, banco, pagador, recebedor, status, observacao, texto_resumo. Use null quando não souber.";

        return $this->chatWithVision($prompt, $dataUrl, 'openai_image_analyzed', 'openai_image_error');
    }

    public function analyzeDocument(string $filePath, string $mimeType): array
    {
        if (str_starts_with($mimeType, 'image/')) {
            return $this->analyzeReceiptImage($filePath, $mimeType);
        }

        if (!$this->isConfigured()) {
            return ['ok' => false, 'error' => 'openai_not_configured'];
        }

        if ($mimeType !== 'application/pdf') {
            return ['ok' => false, 'error' => 'unsupported_document_mime'];
        }

        $base64 = base64_encode((string) file_get_contents($filePath));
        $dataUrl = 'data:application/pdf;base64,' . $base64;
        $payload = [
            'model' => 'gpt-4o-mini',
            'input' => [[
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'input_text',
                        'text' => 'Analise este PDF de comprovante em português. Extraia valor, data, banco, pagador, recebedor, status aparente e observação. Responda em JSON válido com as chaves valor, data, banco, pagador, recebedor, status, observacao, texto_resumo. Use null quando não souber.',
                    ],
                    [
                        'type' => 'input_file',
                        'filename' => basename($filePath),
                        'file_data' => $dataUrl,
                    ],
                ],
            ]],
        ];

        return $this->postJson('https://api.openai.com/v1/responses', $payload, 'openai_document_analyzed', 'openai_document_error');
    }

    private function chatWithVision(string $prompt, string $dataUrl, string $successEvent, string $errorEvent): array
    {
        $payload = [
            'model' => 'gpt-4o-mini',
            'messages' => [[
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => $prompt],
                    ['type' => 'image_url', 'image_url' => ['url' => $dataUrl]],
                ],
            ]],
            'temperature' => 0.1,
            'max_tokens' => 700,
        ];

        return $this->postJson('https://api.openai.com/v1/chat/completions', $payload, $successEvent, $errorEvent);
    }

    private function postJson(string $url, array $payload, string $successEvent, string $errorEvent): array
    {
        $curl = curl_init($url);

        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 55,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        $responseBody = curl_exec($curl);
        $curlError = curl_error($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        $decoded = is_string($responseBody) ? json_decode($responseBody, true) : null;
        $ok = $status >= 200 && $status < 300 && is_array($decoded);
        $text = $this->extractText($decoded ?? []);

        $this->log($ok ? $successEvent : $errorEvent, [
            'http_status' => $status,
            'error' => $ok ? '' : ($curlError !== '' ? $curlError : (string) ($decoded['error']['message'] ?? 'openai_request_failed')),
        ]);

        if (!$ok) {
            return [
                'ok' => false,
                'status' => $status,
                'error' => $curlError !== '' ? $curlError : (string) ($decoded['error']['message'] ?? 'openai_request_failed'),
            ];
        }

        return [
            'ok' => true,
            'status' => $status,
            'text' => $text,
            'data' => $decoded,
        ];
    }

    private function extractText(array $decoded): string
    {
        if (isset($decoded['choices'][0]['message']['content'])) {
            return trim((string) $decoded['choices'][0]['message']['content']);
        }

        if (isset($decoded['output_text'])) {
            return trim((string) $decoded['output_text']);
        }

        $chunks = [];

        foreach (($decoded['output'] ?? []) as $output) {
            foreach (($output['content'] ?? []) as $content) {
                if (($content['type'] ?? '') === 'output_text') {
                    $chunks[] = (string) ($content['text'] ?? '');
                }
            }
        }

        return trim(implode("\n", $chunks));
    }

    private function log(string $event, array $context): void
    {
        $directory = dirname($this->logPath);

        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $context['event'] = $event;
        $context['logged_at'] = date('c');
        $line = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($line !== false) {
            file_put_contents($this->logPath, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
        }
    }
}
