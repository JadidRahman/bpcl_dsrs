<?php
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../db.php';

$me  = require_role('CONSULTANT', 'ADMIN');
$pdo = db();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
  exit;
}

csrf_check();

$aid = (int)($_POST['id'] ?? 0);
$to  = trim((string)($_POST['to'] ?? ''));
$subject = trim((string)($_POST['subject'] ?? ''));
$body    = trim((string)($_POST['body'] ?? ''));

if ($aid <= 0) {
  echo json_encode(['ok' => false, 'error' => 'Invalid assessment id']);
  exit;
}
if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
  echo json_encode(['ok' => false, 'error' => 'Valid recipient email is required']);
  exit;
}

$subject = $subject ?: "ADHD Assessment Report (#{$aid})";
$body    = $body ?: "Assalamu Alaikum,\n\nPlease find the ADHD Assessment Report (DOCX) attached.\n\nRegards,\n" . ($me['name'] ?? 'Consultant') . "\nBangladesh Psychiatric Care Ltd.";

$autoloadCandidates = [
  __DIR__ . '/../vendor/autoload.php',
  __DIR__ . '/../../vendor/autoload.php',
  dirname(__DIR__) . '/vendor/autoload.php'
];
$autoload = null;
foreach ($autoloadCandidates as $p) { if (is_file($p)) { $autoload = $p; break; } }
if (!$autoload) {
  echo json_encode(['ok' => false, 'error' => 'Composer autoload not found']);
  exit;
}
require_once $autoload;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// ✅ 1) Ask report.php to PREPARE the DOCX into session temp and return a download URL
// We'll reuse your existing "prep_email" flow by calling it internally via include.
// Safer: directly call the same builder function if you keep it in a shared include.
// For now, we’ll generate DOCX right here by requesting report.php download token via curl.

$base = rtrim((string)BASE_URL, '/');
$prepUrl = $base . "/consultant/report.php?id={$aid}";

$postFields = http_build_query([
  'csrf' => csrf_token(),
  'action' => 'prep_email',
  'history' => (string)($_POST['history'] ?? ''),
  'impression' => (string)($_POST['impression'] ?? ''),
  'recommendations' => (string)($_POST['recommendations'] ?? ''),
  'referred_by' => (string)($_POST['referred_by'] ?? ''),
]);

$ch = curl_init($prepUrl);
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_POST => true,
  CURLOPT_POSTFIELDS => $postFields,
  CURLOPT_HTTPHEADER => [
    'X-Requested-With: XMLHttpRequest',
    'Content-Type: application/x-www-form-urlencoded'
  ],
  CURLOPT_COOKIE => $_SERVER['HTTP_COOKIE'] ?? '',
]);
$resp = curl_exec($ch);
$err  = curl_error($ch);
curl_close($ch);

if ($resp === false || $err) {
  echo json_encode(['ok' => false, 'error' => 'Failed to prepare DOCX (curl)']);
  exit;
}

$j = json_decode($resp, true);
if (!is_array($j) || empty($j['ok']) || empty($j['download_url'])) {
  echo json_encode(['ok' => false, 'error' => 'DOCX preparation failed']);
  exit;
}

// ✅ 2) Download the DOCX bytes (so we can attach them)
$docUrl = (string)$j['download_url'];

$ch2 = curl_init($docUrl);
curl_setopt_array($ch2, [
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_HTTPHEADER => ['Content-Type: application/octet-stream'],
  CURLOPT_COOKIE => $_SERVER['HTTP_COOKIE'] ?? '',
]);
$docBytes = curl_exec($ch2);
$err2 = curl_error($ch2);
$http2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
curl_close($ch2);

if ($docBytes === false || $err2 || $http2 >= 400) {
  echo json_encode(['ok' => false, 'error' => 'Failed to fetch DOCX bytes']);
  exit;
}

$filename = $j['file_name'] ?? ("ADHD_Assessment_Report_{$aid}.docx");

// ✅ 3) Send mail with attachment via SMTP
$mail = new PHPMailer(true);

try {
  // --- SMTP CONFIG (EDIT THIS) ---
  $SMTP_HOST = 'smtp.gmail.com';
  $SMTP_PORT = 587;
  $SMTP_USER = 'your_email@gmail.com';      // ✅ your sender email
  $SMTP_PASS = 'your_app_password_here';    // ✅ Gmail App Password / SMTP password

  $FROM_EMAIL = $SMTP_USER;
  $FROM_NAME  = 'BPCL DSRS';

  $mail->isSMTP();
  $mail->Host       = $SMTP_HOST;
  $mail->SMTPAuth   = true;
  $mail->Username   = $SMTP_USER;
  $mail->Password   = $SMTP_PASS;
  $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
  $mail->Port       = $SMTP_PORT;

  $mail->setFrom($FROM_EMAIL, $FROM_NAME);
  $mail->addAddress($to);

  $mail->Subject = $subject;
  $mail->Body    = $body;

  // Attach from memory (no link needed)
  $mail->addStringAttachment($docBytes, $filename, 'base64', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');

  $mail->send();

  echo json_encode(['ok' => true, 'sent_to' => $to, 'file' => $filename]);
  exit;

} catch (Exception $e) {
  echo json_encode(['ok' => false, 'error' => 'Mailer error: ' . $mail->ErrorInfo]);
  exit;
}