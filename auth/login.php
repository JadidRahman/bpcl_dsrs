<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers.php';
start_session();

if (!empty($_SESSION['user'])) redirect('index.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();
  $email = strtolower(trim($_POST['email'] ?? ''));
  $pass = $_POST['password'] ?? '';

  $pdo = db();
  $st = $pdo->prepare("SELECT id,name,email,password_hash,role,email_verified FROM users WHERE email=? LIMIT 1");
  $st->execute([$email]);
  $u = $st->fetch();

  if (!$u || !password_verify($pass, $u['password_hash'])) {
    flash('danger','Invalid email or password.');
    redirect('auth/login.php');
  }
  if (empty($u['email_verified'])) {
    flash('warning','Please verify your email before login. We sent you a code during signup.');
    redirect('auth/verify.php?email=' . urlencode($u['email']));
  }

  $_SESSION['user'] = [
    'id' => (int)$u['id'],
    'name' => $u['name'],
    'email' => $u['email'],
    'role' => $u['role'],
  ];
  flash('success','Welcome back!');
  redirect('index.php');
}

include __DIR__ . '/../partials/header.php';
?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">

<style>
  :root{
    --auth-brand:#f17252;
    --auth-ink:#0b1220;
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
    padding: 34px 34px 28px;
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
    letter-spacing:.2px;
  }
  .auth-dot{
    width:10px; height:10px; border-radius:999px;
    background: var(--auth-brand);
    box-shadow: 0 0 0 6px rgba(241,114,82,.15);
  }
  .auth-title{
    margin-top: 18px;
    font-weight: 900;
    font-size: clamp(24px, 3.2vw, 34px);
    line-height: 1.12;
  }
  .auth-sub{ color: rgba(255,255,255,.78); margin-top:8px; font-size: 14.5px; line-height: 1.7; }
  .auth-pills{ display:flex; flex-wrap:wrap; gap:8px; margin-top: 18px; }
  .auth-pill{
    display:inline-flex; gap:8px; align-items:center;
    border: 1px solid rgba(255,255,255,.16);
    background: rgba(0,0,0,.18);
    padding: 8px 10px;
    border-radius: 999px;
    font-size: 12.5px;
    color: rgba(255,255,255,.88);
  }

  .auth-right{ padding: 34px 34px 28px; background: rgba(255,255,255,.04); }
  .auth-h{ font-weight: 900; margin:0; }
  .auth-muted{ color: rgba(255,255,255,.65); font-size: 13px; }

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

  .input-group-text{
    border-radius: 14px;
    border: 1px solid rgba(255,255,255,.18);
    background: rgba(0,0,0,.20);
    color: rgba(255,255,255,.75);
  }

  .btn-auth{
    background: var(--auth-brand);
    border:0;
    border-radius: 14px;
    padding: 12px 14px;
    font-weight: 900;
    box-shadow: 0 14px 30px rgba(241,114,82,.22);
  }
  .btn-auth:hover{ filter: brightness(.98); transform: translateY(-1px); }
  .btn-auth:active{ transform: translateY(0); }

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
          <div class="auth-title">Welcome back 👋</div>
          <div class="auth-sub">Login with your <b>verified</b> email to continue.</div>

          <div class="auth-pills">
            <span class="auth-pill"><i class="bi bi-shield-check"></i> Secure</span>
            <span class="auth-pill"><i class="bi bi-lightning-charge"></i> Fast workflow</span>
            <span class="auth-pill"><i class="bi bi-printer"></i> Print-ready</span>
          </div>
        </div>
      </div>

      <div class="col-lg-7">
        <div class="auth-right">
          <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
              <h4 class="auth-h">Login</h4>
              <div class="auth-muted">Use your verified email.</div>
            </div>
            <a class="auth-link" href="<?= e(BASE_URL) ?>/auth/register.php">Create account</a>
          </div>

          <form method="post" class="needs-validation" novalidate>
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

            <div class="mb-3">
              <label class="form-label">Email</label>
              <input class="form-control" type="email" name="email" placeholder="name@example.com" required>
              <div class="invalid-feedback">Please enter a valid email.</div>
            </div>

            <div class="mb-3">
              <label class="form-label">Password</label>
              <div class="input-group">
                <input id="pw" class="form-control" type="password" name="password" placeholder="••••••••" required>
                <button class="btn input-group-text" type="button" id="togglePw" aria-label="Toggle password">
                  <i class="bi bi-eye"></i>
                </button>
                <div class="invalid-feedback">Password is required.</div>
              </div>
            </div>

            <button class="btn btn-auth text-white w-100">
              <i class="bi bi-box-arrow-in-right me-2"></i> Login
            </button>

            <div class="mt-3 text-center">
              <a class="auth-link" href="<?= e(BASE_URL) ?>/auth/register.php">Create new account</a>
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