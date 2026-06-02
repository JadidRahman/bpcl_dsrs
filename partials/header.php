<?php
require_once __DIR__ . '/../helpers.php';
$flashes = get_flashes();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e(APP_NAME) ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    :root{ --bpcl-orange:#f17252; --bpcl-green:#3c8f49; --ink:#0b1220; }
    body{ background: #f6f8fb; color: var(--ink); }
    .brand-badge{ background: linear-gradient(135deg,var(--bpcl-orange),#ff9b7f); color:#fff; }
    .card-soft{ border:1px solid #e8edf6; box-shadow: 0 16px 50px rgba(10,20,40,.08); border-radius: 16px; }
    .btn-brand{ background: var(--bpcl-orange); border-color: var(--bpcl-orange); }
    .btn-brand:hover{ filter: brightness(.95); }
    .nav-pill{ background:#fff; border:1px solid #e8edf6; border-radius:999px; padding:.35rem .75rem; }
    .omr-grid{ display:grid; grid-template-columns: 70px repeat(4, 1fr); gap: .5rem; }
    .omr-row{ background:#fff; border:1px solid #e8edf6; border-radius: 12px; padding:.6rem; }
    .omr-row .qno{ font-weight:700; }
    .omr-opt label{ width:100%; border:1px solid #e8edf6; border-radius: 10px; padding:.35rem .5rem; text-align:center; cursor:pointer; }
    .omr-opt input{ display:none; }
    .omr-opt input:checked + label{ border-color: var(--bpcl-green); box-shadow: 0 0 0 3px rgba(60,143,73,.15); }
    .small-muted{ color:#64748b; font-size:.9rem; }
    .global-back-wrap{
      padding-top: .85rem;
      padding-bottom: .15rem;
      display: flex;
      align-items: center;
    }
    .global-back-btn{
      display: inline-flex;
      align-items: center;
      gap: .5rem;
      border: 1px solid #dbe4f1;
      background: linear-gradient(180deg, #ffffff 0%, #f7faff 100%);
      color: #1e2b3d;
      font-weight: 700;
      border-radius: 999px;
      padding: .42rem .9rem;
      transition: all .18s ease;
      box-shadow: 0 8px 24px rgba(17, 24, 39, .06);
    }
    .global-back-btn .back-icon{
      width: 1.25rem;
      height: 1.25rem;
      border-radius: 999px;
      display: inline-grid;
      place-items: center;
      background: rgba(60,143,73,.12);
      color: var(--bpcl-green);
      font-size: .85rem;
      line-height: 1;
    }
    .global-back-btn:hover{
      border-color: #bdd0ec;
      transform: translateY(-1px);
      box-shadow: 0 12px 26px rgba(17, 24, 39, .10);
      color: #122033;
    }
    .global-back-btn:focus-visible{
      outline: 0;
      box-shadow: 0 0 0 3px rgba(60,143,73,.18), 0 12px 26px rgba(17,24,39,.10);
    }
  </style>
</head>
<body>
<nav class="navbar navbar-expand-lg bg-white border-bottom">
  <div class="container">
    <a class="navbar-brand fw-bold" href="<?= e(BASE_URL) ?>/index.php">
      <span class="badge brand-badge me-2">DSRS</span><?= e(APP_NAME) ?>
    </a>
    <div class="ms-auto d-flex gap-2">
      <?php start_session(); if (!empty($_SESSION['user'])): ?>
        <span class="nav-pill">Signed in: <strong><?= e($_SESSION['user']['name']) ?></strong> (<?= e($_SESSION['user']['role']) ?>)</span>
        <a class="btn btn-outline-secondary btn-sm" href="<?= e(BASE_URL) ?>/auth/logout.php">Logout</a>
      <?php else: ?>
        <a class="btn btn-outline-secondary btn-sm" href="<?= e(BASE_URL) ?>/auth/login.php">Login</a>
      <?php endif; ?>
    </div>
  </div>
</nav>

<div class="container global-back-wrap">
  <button
    type="button"
    class="global-back-btn"
    aria-label="Go back to previous page"
    title="Go back"
    onclick="if (window.history.length > 1) { window.history.back(); } else { window.location.href='<?= e(BASE_URL) ?>/index.php'; }"
  >
    <span class="back-icon" aria-hidden="true">&larr;</span>
    <span>Back</span>
  </button>
</div>

<div class="container py-4">
<?php foreach ($flashes as $f): ?>
  <div class="alert alert-<?= e($f['type']) ?>"><?= e($f['msg']) ?></div>
<?php endforeach; ?>
