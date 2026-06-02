<?php
define('APP_ROOT', realpath(__DIR__));

require_once APP_ROOT . '/config/config.php';

function start_session(): void
{
  if (session_status() === PHP_SESSION_NONE) {
    session_name(SESSION_NAME);
    session_start();
  }
}

function e(string $s): string
{
  return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function redirect(string $path): void
{
  header("Location: " . rtrim(BASE_URL, '/') . "/" . ltrim($path, '/'));
  exit;
}

function flash(string $type, string $msg): void
{
  start_session();
  $_SESSION['flash'][] = ['type' => $type, 'msg' => $msg];
}

function get_flashes(): array
{
  start_session();
  $f = $_SESSION['flash'] ?? [];
  unset($_SESSION['flash']);
  return $f;
}

function csrf_token(): string
{
  start_session();
  if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = hash_hmac('sha256', bin2hex(random_bytes(16)), CSRF_KEY);
  }
  return $_SESSION['csrf'];
}

function csrf_check(): void
{
  start_session();
  $token = $_POST['csrf'] ?? '';
  if (!$token || !hash_equals($_SESSION['csrf'] ?? '', $token)) {
    http_response_code(403);
    exit('CSRF validation failed.');
  }
}

function require_login(): array
{
  start_session();
  if (empty($_SESSION['user'])) {
    redirect('auth/login.php');
  }
  return $_SESSION['user'];
}

function require_role(string ...$roles): array
{
  $u = require_login();
  if (!in_array($u['role'] ?? '', $roles, true)) {
    http_response_code(403);
    exit('Forbidden');
  }
  return $u;
}

function rand_token(int $len = 48): string
{
  return bin2hex(random_bytes(intdiv($len, 2)));
}

function require_first(array $paths): void
{
  foreach ($paths as $p) {
    $full = APP_ROOT . '/' . ltrim($p, '/');
    if (is_file($full)) {
      require_once $full;
      return;
    }
  }
  throw new RuntimeException("Required file not found. Tried: " . implode(', ', $paths));
}

function send_mail(string $to, string $subject, string $htmlBody): bool
{
  $root = __DIR__;

  // Load SMTP config
  $cfgPath = $root . '/config/mail.php';
  if (!is_file($cfgPath)) {
    error_log("MAIL ERROR: config not found at {$cfgPath}");
    return false;
  }
  $cfg = require $cfgPath;

  $phpmailerBase = $root . '/PHPMailer/src/';
  $req = [
    $phpmailerBase . 'Exception.php',
    $phpmailerBase . 'PHPMailer.php',
    $phpmailerBase . 'SMTP.php',
  ];
  foreach ($req as $f) {
    if (!is_file($f)) {
      error_log("MAIL ERROR: PHPMailer file missing: {$f}");
      return false;
    }
    require_once $f;
  }

  $mail = new PHPMailer\PHPMailer\PHPMailer(true);

  try {
    $mail->SMTPDebug = 0;

    $mail->isSMTP();
    $mail->Host = (string) $cfg['host'];
    $mail->SMTPAuth = true;
    $mail->Username = (string) $cfg['username'];
    $mail->Password = (string) $cfg['password'];
    $mail->Port = (int) $cfg['port'];
    $secure = strtolower((string) ($cfg['secure'] ?? 'tls'));
    if ($secure === 'tls') {
      $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
    } elseif ($secure === 'ssl') {
      $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
    } else {
      $mail->SMTPSecure = '';
    }

    $mail->SMTPOptions = [
      'ssl' => [
        'verify_peer' => false,
        'verify_peer_name' => false,
        'allow_self_signed' => true,
      ]
    ];

    $mail->CharSet = 'UTF-8';
    $mail->setFrom((string) $cfg['from_email'], (string) $cfg['from_name']);
    $mail->addAddress($to);

    $mail->isHTML(true);
    $mail->Subject = $subject;
    $mail->Body = $htmlBody;
    $mail->AltBody = trim(strip_tags($htmlBody));

    $mail->send();
    return true;

  } catch (Throwable $e) {
    error_log("MAIL ERROR: " . $e->getMessage());
    return false;
  }
}
