<?php

declare(strict_types=1);

const FINANCIAL_ATTACHMENT_MAX_BYTES = 5242880;

function financial_attachment_allowed_types(): array
{
    return [
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'webp' => ['image/webp'],
        'pdf' => ['application/pdf'],
    ];
}

function financial_upload_root(): string
{
    return dirname(__DIR__, 2) . '/uploads';
}

function financial_receipts_root(): string
{
    return financial_upload_root() . '/receipts';
}

function has_financial_attachment_upload(array $files): bool
{
    return isset($files['receipt']) && is_array($files['receipt']) && ($files['receipt']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
}

function validate_financial_attachment_upload(array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return ['ok' => false, 'error' => 'Nenhum comprovante enviado.'];
    }

    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'error' => 'Nao foi possivel receber o comprovante.'];
    }

    $size = (int) ($file['size'] ?? 0);

    if ($size <= 0 || $size > FINANCIAL_ATTACHMENT_MAX_BYTES) {
        return ['ok' => false, 'error' => 'O comprovante precisa ter ate 5MB.'];
    }

    $originalName = basename((string) ($file['name'] ?? 'comprovante'));
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $allowedTypes = financial_attachment_allowed_types();

    if (!isset($allowedTypes[$extension])) {
        return ['ok' => false, 'error' => 'Envie apenas jpg, jpeg, png, webp ou pdf.'];
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');

    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        return ['ok' => false, 'error' => 'Arquivo de comprovante invalido.'];
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = (string) $finfo->file($tmpName);

    if (!in_array($mimeType, $allowedTypes[$extension], true)) {
        return ['ok' => false, 'error' => 'O tipo do comprovante nao confere com a extensao enviada.'];
    }

    return [
        'ok' => true,
        'original_name' => $originalName,
        'extension' => $extension,
        'mime_type' => $mimeType,
        'size' => $size,
        'tmp_name' => $tmpName,
    ];
}

function save_financial_attachment(PDO $pdo, int $userId, ?int $movementId, array $validatedUpload): array
{
    $userDirectory = financial_receipts_root() . '/' . $userId;

    if (!is_dir($userDirectory) && !mkdir($userDirectory, 0755, true) && !is_dir($userDirectory)) {
        return ['ok' => false, 'error' => 'Nao foi possivel preparar a pasta de comprovantes.'];
    }

    $fileName = bin2hex(random_bytes(16)) . '.' . $validatedUpload['extension'];
    $absolutePath = $userDirectory . '/' . $fileName;
    $relativePath = 'receipts/' . $userId . '/' . $fileName;

    if (!move_uploaded_file((string) $validatedUpload['tmp_name'], $absolutePath)) {
        return ['ok' => false, 'error' => 'Nao foi possivel salvar o comprovante.'];
    }

    chmod($absolutePath, 0640);
    $extractedText = extract_financial_attachment_text($absolutePath, (string) $validatedUpload['mime_type']);

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO financial_attachments
                (user_id, movement_id, original_name, file_path, mime_type, file_size, extracted_text)
             VALUES
                (:user_id, :movement_id, :original_name, :file_path, :mime_type, :file_size, :extracted_text)
             RETURNING id, user_id, movement_id, original_name, file_path, mime_type, file_size, extracted_text, created_at'
        );
        $stmt->execute([
            'user_id' => $userId,
            'movement_id' => $movementId,
            'original_name' => $validatedUpload['original_name'],
            'file_path' => $relativePath,
            'mime_type' => $validatedUpload['mime_type'],
            'file_size' => $validatedUpload['size'],
            'extracted_text' => $extractedText !== '' ? $extractedText : null,
        ]);

        return ['ok' => true, 'attachment' => $stmt->fetch()];
    } catch (Throwable $exception) {
        if (is_file($absolutePath)) {
            unlink($absolutePath);
        }

        throw $exception;
    }
}

function financial_attachment_url(int|string $attachmentId): string
{
    return '/attachment.php?id=' . rawurlencode((string) $attachmentId);
}

function financial_attachment_is_image(array $attachment): bool
{
    return str_starts_with((string) ($attachment['mime_type'] ?? ''), 'image/');
}

function financial_attachment_absolute_path(array $attachment): string
{
    return financial_upload_root() . '/' . $attachment['file_path'];
}

function extract_financial_attachment_text(string $absolutePath, string $mimeType): string
{
    if (!is_file($absolutePath)) {
        return '';
    }

    if (str_starts_with($mimeType, 'image/')) {
        return normalize_extracted_receipt_text(run_receipt_ocr($absolutePath));
    }

    if ($mimeType === 'application/pdf') {
        $text = normalize_extracted_receipt_text(run_pdf_text_extraction($absolutePath));

        if ($text !== '') {
            return $text;
        }

        return normalize_extracted_receipt_text(run_pdf_first_page_ocr($absolutePath));
    }

    return '';
}

function run_receipt_ocr(string $absolutePath): string
{
    if (!command_exists_for_receipts('tesseract')) {
        return '';
    }

    $command = 'timeout 12s tesseract ' . escapeshellarg($absolutePath) . ' stdout -l por+eng --psm 6 2>/dev/null';
    return (string) shell_exec($command);
}

function run_pdf_text_extraction(string $absolutePath): string
{
    if (!command_exists_for_receipts('pdftotext')) {
        return '';
    }

    $command = 'timeout 8s pdftotext -f 1 -l 2 -layout ' . escapeshellarg($absolutePath) . ' - 2>/dev/null';
    return (string) shell_exec($command);
}

function run_pdf_first_page_ocr(string $absolutePath): string
{
    if (!command_exists_for_receipts('pdftoppm') || !command_exists_for_receipts('tesseract')) {
        return '';
    }

    $temporaryPrefix = tempnam(sys_get_temp_dir(), 'receipt_pdf_');

    if ($temporaryPrefix === false) {
        return '';
    }

    @unlink($temporaryPrefix);

    $command = 'timeout 10s pdftoppm -f 1 -singlefile -png -r 180 '
        . escapeshellarg($absolutePath) . ' '
        . escapeshellarg($temporaryPrefix)
        . ' 2>/dev/null';
    shell_exec($command);

    $imagePath = $temporaryPrefix . '.png';

    if (!is_file($imagePath)) {
        return '';
    }

    $text = run_receipt_ocr($imagePath);
    @unlink($imagePath);

    return $text;
}

function normalize_extracted_receipt_text(string $text): string
{
    $text = preg_replace('/[^\P{C}\n\t]+/u', ' ', $text) ?? $text;
    $text = preg_replace('/[ \t]+/', ' ', $text) ?? $text;
    $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

    return trim($text);
}

function command_exists_for_receipts(string $command): bool
{
    static $cache = [];

    if (array_key_exists($command, $cache)) {
        return $cache[$command];
    }

    $result = trim((string) shell_exec('command -v ' . escapeshellarg($command) . ' 2>/dev/null'));
    $cache[$command] = $result !== '';

    return $cache[$command];
}

function fetch_transaction_attachments(int $userId): array
{
    $stmt = db()->prepare(
        'SELECT *
         FROM financial_attachments
         WHERE user_id = :user_id AND movement_id IS NOT NULL
         ORDER BY created_at DESC'
    );
    $stmt->execute(['user_id' => $userId]);

    $grouped = [];

    foreach ($stmt->fetchAll() as $attachment) {
        $movementId = (int) $attachment['movement_id'];
        $grouped[$movementId][] = $attachment;
    }

    return $grouped;
}
