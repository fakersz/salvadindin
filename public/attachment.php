<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/functions/financial_attachments.php';

$user = require_auth();
$attachmentId = (int) ($_GET['id'] ?? 0);

if ($attachmentId <= 0) {
    http_response_code(404);
    exit('Comprovante nao encontrado.');
}

$stmt = db()->prepare(
    'SELECT *
     FROM financial_attachments
     WHERE id = :id AND user_id = :user_id
     LIMIT 1'
);
$stmt->execute([
    'id' => $attachmentId,
    'user_id' => $user['id'],
]);
$attachment = $stmt->fetch();

if (!$attachment) {
    http_response_code(404);
    exit('Comprovante nao encontrado.');
}

$uploadRoot = realpath(financial_upload_root());
$filePath = realpath(financial_upload_root() . '/' . $attachment['file_path']);

if (!$uploadRoot || !$filePath || !str_starts_with($filePath, $uploadRoot . DIRECTORY_SEPARATOR) || !is_file($filePath)) {
    http_response_code(404);
    exit('Comprovante nao encontrado.');
}

$mimeType = (string) $attachment['mime_type'];
$fileName = (string) $attachment['original_name'];

header('Content-Type: ' . $mimeType);
header('Content-Length: ' . filesize($filePath));
header('Content-Disposition: inline; filename="' . addcslashes($fileName, "\\\"") . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=300');

readfile($filePath);
