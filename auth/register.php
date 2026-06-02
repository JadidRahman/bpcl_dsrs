<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers.php';
start_session();

if (!empty($_SESSION['user'])) redirect('index.php');

function otp_code(): string {
  return strval(random_int(100000, 999999));
}
function otp_hash(string $email, string $code): string {
  $pepper = defined('APP_KEY') ? APP_KEY : CSRF_KEY;
  return hash_hmac('sha256', strtolower(trim($email)) . '|' . $code, $pepper);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();

  $name = trim($_POST['name'] ?? '');
  $email = strtolower(trim($_POST['email'] ?? ''));
  $pass = $_POST['password'] ?? '';
  $role = $_POST['role'] ?? 'CONSULTANT';

  if ($name==='' || $email==='' || $pass==='') {
    flash('warning','Please fill all fields.');
    redirect('auth/register.php');
  }
  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    flash('warning','Invalid email.');
    redirect('auth/register.php');
  }
  if (!in_array($role, ['CONSULTANT','ADMIN'], true)) $role = 'CONSULTANT';

  $pdo = db();
  $stmt = $pdo->prepare("SELECT id FROM users WHERE email=? LIMIT 1");
  $stmt->execute([$email]);
  if ($stmt->fetch()) {
    flash('danger','Email already registered.');
    redirect('auth/register.php');
  }

  $hash = password_hash($pass, PASSWORD_DEFAULT);

  $pdo->prepare("
    INSERT INTO users(name,email,password_hash,role,email_verified,created_at)
    VALUES (?,?,?,?,0,NOW())
  ")->execute([$name,$email,$hash,$role]);

  $code = otp_code();
  $ttl = defined('EMAIL_OTP_TTL_MIN') ? (int)EMAIL_OTP_TTL_MIN : 10;

  $pdo->prepare("
    UPDATE users
    SET email_otp_hash = ?,
        email_otp_expires_at = DATE_ADD(NOW(), INTERVAL ? MINUTE),
        email_otp_sent_at = NOW()
    WHERE email = ?
    LIMIT 1
  ")->execute([otp_hash($email, $code), $ttl, $email]);

  $subj = "Your verification code — " . APP_NAME;
  $body = "
    <div style='font-family:Arial,sans-serif;line-height:1.6'>
      <h2 style='margin:0 0 8px'>Email Verification</h2>
      <p>Hi ".e($name).",</p>
      <p>Your verification code is:</p>
      <div style='font-size:26px;font-weight:700;letter-spacing:6px;
                  padding:12px 16px;border:1px solid #e5e7eb;border-radius:12px;
                  display:inline-block;background:#f8fafc'>
        ".e($code)."
      </div>
      <p style='margin-top:14px'>This code will expire in <b>{$ttl} minutes</b>.</p>
      <p style='color:#6b7280'>If you didn’t request this, you can ignore this email.</p>
    </div>
  ";

  if (!send_mail($email, $subj, $body)) {
    flash('warning', 'Account created, but email could not be sent. Please check SMTP settings.');
  } else {
    flash('success', 'Account created. Please check your email for the verification code.');
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
    width: min(980px, 100%);
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
  .auth-title{ margin-top:18px; font-weight:900; font-size: clamp(24px, 3.2vw, 34px); line-height: 1.12; }
  .auth-sub{ color: rgba(255,255,255,.78); margin-top:8px; font-size: 14.5px; line-height: 1.7; }

  .auth-right{ padding: 34px; background: rgba(255,255,255,.04); }
  .form-label{ color: rgba(255,255,255,.86); font-weight: 800; font-size: 13px; }
  .form-control, .form-select{
    border-radius: 14px;
    border: 1px solid rgba(255,255,255,.18);
    background: rgba(0,0,0,.20);
    color:#fff;
    padding: 12px 14px;
  }
  .form-control:focus, .form-select:focus{
    box-shadow: 0 0 0 .25rem rgba(241,114,82,.20);
    border-color: rgba(241,114,82,.55);
    background: rgba(0,0,0,.20);
    color:#fff;
  }
  .form-control::placeholder{ color: rgba(255,255,255,.55); }
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
  .input-group-text{
    border-radius: 14px;
    border: 1px solid rgba(255,255,255,.18);
    background: rgba(0,0,0,.20);
    color: rgba(255,255,255,.75);
  }
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
          <div class="auth-title">Create Account ✨</div>
          <div class="auth-sub">Verification code will be sent to your email.</div>
        </div>
      </div>

      <div class="col-lg-7">
        <div class="auth-right">
          <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
              <h4 class="mb-0 fw-bold">Sign up</h4>
              <div class="text-white-50 small">Create your DSRS account.</div>
            </div>
            <a class="auth-link" href="<?= e(BASE_URL) ?>/auth/login.php">Back to login</a>
          </div>

          <form method="post" class="needs-validation" novalidate>
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

            <div class="mb-3">
              <label class="form-label">Full Name</label>
              <input class="form-control" name="name" placeholder="Your name" required>
              <div class="invalid-feedback">Name is required.</div>
            </div>

            <div class="mb-3">
              <label class="form-label">Email</label>
              <input class="form-control" type="email" name="email" placeholder="name@example.com" required>
              <div class="invalid-feedback">Valid email is required.</div>
            </div>

            <div class="mb-3">
              <label class="form-label">Password</label>
              <div class="input-group">
                <input id="pw" class="form-control" type="password" name="password" minlength="6" placeholder="Min 6 characters" required>
                <button class="btn input-group-text" type="button" id="togglePw"><i class="bi bi-eye"></i></button>
                <div class="invalid-feedback">Minimum 6 characters.</div>
              </div>
            </div>

            <div class="mb-3">
              <label class="form-label">Role</label>
              <select class="form-select" name="role">
                <option value="CONSULTANT" selected>Consultant</option>
                <option value="ADMIN">Admin</option>
              </select>
              <div class="text-white-50 small mt-1">Use Admin only for system managers.</div>
            </div>

            <button class="btn btn-auth text-white w-100">
              <i class="bi bi-person-plus me-2"></i> Sign up
            </button>

            <div class="mt-3 text-center">
              <a class="auth-link" href="<?= e(BASE_URL) ?>/auth/login.php">Already have an account? Login</a>
            </div>
          </form>

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
    const pw = document.getElementById('pw');
    const btn = document.getElementById('togglePw');
    if(!pw || !btn) return;
    btn.addEventListener('click', ()=>{
      const isText = pw.type === 'text';
      pw.type = isText ? 'password' : 'text';
      btn.innerHTML = isText ? '<i class="bi bi-eye"></i>' : '<i class="bi bi-eye-slash"></i>';
    });
  })();
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>