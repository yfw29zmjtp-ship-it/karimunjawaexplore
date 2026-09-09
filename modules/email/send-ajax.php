<?php

define('APP_ACCESS', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/EmailHelper.php';
require_once '../../includes/SmtpMailer.php';

header('Content-Type: application/json');

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Sesi login berakhir, silakan muat ulang halaman.']);
    exit;
}

$to = trim((string)($_POST['to'] ?? ''));
$subject = trim((string)($_POST['subject'] ?? ''));
$body = (string)($_POST['body'] ?? '');

if ($to === '' || $subject === '' || trim($body) === '') {
    echo json_encode(['success' => false, 'message' => 'Penerima, subjek dan isi email wajib diisi.']);
    exit;
}
if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Alamat email penerima tidak valid.']);
    exit;
}

$db = Database::getInstance();
$emailConfig = EmailHelper::resolveConfig($db);
if ($emailConfig === null) {
    echo json_encode(['success' => false, 'message' => 'Pengaturan email belum diisi. Buka menu Pengaturan Email.']);
    exit;
}

try {
    $mailer = new SmtpMailer(
        $emailConfig['host'],
        (int)($emailConfig['smtp_port'] ?? 465),
        $emailConfig['smtp_encryption'] ?? 'ssl',
        $emailConfig['user'],
        $emailConfig['pass']
    );
    $htmlBody = nl2br(htmlspecialchars($body));

    $attachments = [];
    $maxTotalBytes = 15 * 1024 * 1024;
    $totalBytes = 0;
    if (!empty($_FILES['attachments']['name'][0] ?? null)) {
        foreach ($_FILES['attachments']['name'] as $i => $name) {
            if ($_FILES['attachments']['error'][$i] !== UPLOAD_ERR_OK || $name === '') {
                continue;
            }
            $totalBytes += (int)$_FILES['attachments']['size'][$i];
            if ($totalBytes > $maxTotalBytes) {
                echo json_encode(['success' => false, 'message' => 'Total lampiran maksimal 15MB.']);
                exit;
            }
            $attachments[] = [
                'name' => basename($name),
                'type' => $_FILES['attachments']['type'][$i] ?: 'application/octet-stream',
                'data' => file_get_contents($_FILES['attachments']['tmp_name'][$i]),
            ];
        }
    }

    $rawSent = $mailer->send($to, $subject, $htmlBody, 'Karimunjawa Explore', $attachments);
    EmailHelper::appendMessageToFolder($emailConfig, 'INBOX.Sent', $rawSent);
    echo json_encode(['success' => true, 'message' => 'Email berhasil dikirim ke ' . $to . '.']);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => 'Gagal mengirim: ' . $e->getMessage()]);
}
