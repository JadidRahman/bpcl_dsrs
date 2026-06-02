<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers.php';
start_session();

function otp_hash(string $email, string $otp): string {
  $key = defined('APP_KEY') ? APP_KEY : CSRF_KEY;
  return hash_hmac('sha256', strtolower(trim($email)) . '|' . $otp, $key);
}

$email = strtolower(trim($_GET['email'] ?? ($_POST['email'] ?? '')));

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
  flash('warning', 'Valid email is required for verification.');
  redirect('auth/login.php');
}

$pdo = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();

  $otp = preg_replace('/\D+/', '', (string)($_POST['otp'] ?? ''));
  if (strlen($otp) !== 6) {
    flash('warning', 'Enter the 6-digit code.');
    redirect('auth/verify.php?email=' . urlencode($email));
  }

  $stmt = $pdo->prepare("SELECT id, name, email_verified, email_otp_hash, email_otp_expires_at FROM users WHERE email=? LIMIT 1");
  $stmt->execute([$email]);
  $u = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$u) {
    flash('danger', 'Account not found.');
    redirect('auth/register.php');
  }

  if ((int)$u['email_verified'] === 1) {
    flash('success', 'Email already verified. Please login.');
    redirect('auth/login.php');
  }

  if (empty($u['email_otp_hash']) || empty($u['email_otp_expires_at'])) {
    flash('danger', 'No verification code found. Please request a new code.');
    redirect('auth/verify.php?email=' . urlencode($email));
  }

  $expTs = strtotime($u['email_otp_expires_at']);
  if (!$expTs || $expTs < time()) {
    flash('danger', 'Code expired. Please resend a new code.');
    redirect('auth/verify.php?email=' . urlencode($email));
  }

  $calc = otp_hash($email, $otp);
  if (!hash_equals($u['email_otp_hash'], $calc)) {
    flash('danger', 'Invalid code. Please try again.');
    redirect('auth/verify.php?email=' . urlencode($email));
  }

  $pdo->prepare("
    UPDATE users
    SET email_verified=1, email_verified_at=NOW(),
        email_otp_hash=NULL, email_otp_expires_at=NULL, email_otp_sent_at=NULL,
        updated_at=NOW()
    WHERE id=?
  ")->execute([(int)$u['id']]);

  flash('success', 'Email verified successfully. You can login now.');
  redirect('auth/login.php');
}

if (isset($_GET['resend'])) {
  $stmt = $pdo->prepare("SELECT id, name, email_verified FROM users WHERE email=? LIMIT 1");
  $stmt->execute([$email]);
  $u = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$u) {
    flash('danger', 'Account not found.');
    redirect('auth/register.php');
  }
  if ((int)$u['email_verified'] === 1) {
    flash('success', 'Email already verified. Please login.');
    redirect('auth/login.php');
  }

  $otp = (string)random_int(100000, 999999);
  $otpHash = otp_hash($email, $otp);
  $expMinutes = 10;

  $pdo->prepare("
    UPDATE users
    SET email_otp_hash=?, email_otp_expires_at=DATE_ADD(NOW(), INTERVAL ? MINUTE), email_otp_sent_at=NOW(), updated_at=NOW()
    WHERE id=?
  ")->execute([$otpHash, $expMinutes, (int)$u['id']]);

  $subj = "Your verification code — " . (defined('APP_NAME') ? APP_NAME : 'BPCL DSRS');
  $body = "
  <div style='font-family:Arial,sans-serif;line-height:1.6'>
    <p>Hi ".e($u['name']).",</p>
    <p>Your new verification code is:</p>
    <div style='font-size:28px;font-weight:800;letter-spacing:4px;margin:10px 0'>".e($otp)."</div>
    <p>This code expires in <b>{$expMinutes} minutes</b>.</p>
  </div>";

  if (!send_mail($email, $subj, $body)) {
    flash('danger', 'Could not send email. Check SMTP settings.');
  } else {
    flash('success', 'New code sent. Please check your email.');
  }
  redirect('auth/verify.php?email=' . urlencode($email));
}

include __DIR__ . '/../partials/header.php';
?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">

<style>
  :root{
    --auth-brand:#f17252;
    --auth-card: rgba(255,255,255,.10);
    --auth-line: rgba(255,255,255,.18);
    --auth-shadow: 0 26px 70px rgba(0,0,0,.30);
  }
  .auth-bg{
    min-height: calc(100vh - 120px);
    display:flex;
    align-items:center;
    justify-content:center;
    padding: 22px 12px;
    background:
      radial-gradient(1200px 700px at 20% 10%, rgba(241,114,82,.22), transparent 60%),
      radial-gradient(1100px 700px at 95% 30%, rgba(113,191,68,.16), transparent 60%),
      radial-gradient(900px 600px at 40% 100%, rgba(43,89,255,.16), transparent 55%),
      linear-gradient(135deg, #0b1220, #1b2a4a);
    border-radius: 18px;
  }
  .auth-card{
    width: min(820px, 100%);
    border: 1px solid var(--auth-line);
    background: var(--auth-card);
    box-shadow: var(--auth-shadow);
    border-radius: 22px;
    overflow:hidden;
    backdrop-filter: blur(14px);
    -webkit-backdrop-filter: blur(14px);
    color:#fff;
  }
  .auth-left{
    padding: 34px;
    background:
      radial-gradient(900px 520px at 30% 20%, rgba(255,255,255,.10), transparent 60%),
      linear-gradient(180deg, rgba(255,255,255,.08), rgba(255,255,255,.03));
    border-right: 1px solid rgba(255,255,255,.12);
    height:100%;
  }
  .auth-badge{
    display:inline-flex; align-items:center; gap:10px;
    padding: 8px 12px; border-radius: 999px;
    border: 1px solid rgba(255,255,255,.18);
    background: rgba(0,0,0,.20);
    font-weight: 800;
  }
  .auth-dot{
    width:10px; height:10px; border-radius:999px;
    background: var(--auth-brand);
    box-shadow: 0 0 0 6px rgba(241,114,82,.15);
  }
  .auth-title{ margin-top:18px; font-weight:900; font-size: clamp(22px, 3vw, 32px); line-height: 1.12; }
  .auth-sub{ color: rgba(255,255,255,.78); margin-top:8px; font-size: 14.5px; line-height: 1.7; }

  .auth-right{ padding: 34px; background: rgba(255,255,255,.04); }
  .form-label{ color: rgba(255,255,255,.86); font-weight: 800; font-size: 13px; }
  .form-control{
    border-radius: 14px;
    border: 1px solid rgba(255,255,255,.18);
    background: rgba(0,0,0,.20);
    color:#fff;
    padding: 12px 14px;
    letter-spacing: 3px;
    font-weight: 900;
    text-align: center;
  }
  .form-control:focus{
    box-shadow: 0 0 0 .25rem rgba(241,114,82,.20);
    border-color: rgba(241,114,82,.55);
    background: rgba(0,0,0,.20);
    color:#fff;
  }
  .btn-auth{
    background: var(--auth-brand);
    border:0;
    border-radius: 14px;
    padding: 12px 14px;
    font-weight: 900;
    box-shadow: 0 14px 30px rgba(241,114,82,.22);
  }
  .auth-link{ color: rgba(255,255,255,.75); text-decoration: none; }
  .auth-link:hover{ color:#fff; text-decoration: underline; }
  @media (max-width: 991px){
    .auth-left{ border-right:0; border-bottom: 1px solid rgba(255,255,255,.12); }
  }
</style>

<div class="auth-bg">
  <div class="auth-card">
    <div class="row g-0">
      <div class="col-lg-5">
        <div class="auth-left">
          <div class="auth-badge"><span class="auth-dot"></span> BPCL DSRS</div>
          <div class="auth-title">Verify Email</div>
          <div class="auth-sub">Enter the 6-digit code sent to:</div>
          <div class="mt-2 fw-bold" style="word-break:break-all;"><?= e($email) ?></div>
        </div>
      </div>

      <div class="col-lg-7">
        <div class="auth-right">
          <h4 class="mb-1 fw-bold">Verification Code</h4>
          <div class="text-white-50 small mb-3">Paste or type your code below.</div>

          <form method="post" class="needs-validation" novalidate>
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="email" value="<?= e($email) ?>">

            <div class="mb-3">
              <label class="form-label">6-digit code</label>
              <input class="form-control" name="otp" inputmode="numeric" pattern="\d{6}" maxlength="6" placeholder="123456" required>
              <div class="invalid-feedback" style="letter-spacing:0;font-weight:700;text-align:left;">
                Enter a valid 6-digit code.
              </div>
            </div>

            <button class="btn btn-auth text-white w-100">
              <i class="bi bi-check2-circle me-2"></i> Verify
            </button>
          </form>

          <div class="d-flex justify-content-between mt-3">
            <a class="auth-link" href="<?= e(BASE_URL) ?>/auth/verify.php?email=<?= urlencode($email) ?>&resend=1">Resend code</a>
            <a class="auth-link" href="<?= e(BASE_URL) ?>/auth/login.php">Back to login</a>
          </div>

        </div>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
  (() => {
    const forms = document.querySelectorAll('.needs-validation');
    Array.from(forms).forEach(form => {
      form.addEventListener('submit', event => {
        if (!form.checkValidity()) {
          event.preventDefault();
          event.stopPropagation();
        }
        form.classList.add('was-validated');
      }, false);
    });
  })();

  (()=>{
    const el = document.querySelector('input[name="otp"]');
    if(!el) return;
    el.addEventListener('input', ()=>{
      el.value = (el.value || '').replace(/\D+/g,'').slice(0,6);
    });
  })();
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>