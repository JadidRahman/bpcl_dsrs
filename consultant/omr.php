<?php
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/lib_scoring.php';

$me = require_role('CONSULTANT','ADMIN');
$pdo = db();

$aid = (int)($_GET['id'] ?? 0);
if ($aid <= 0) { redirect('index.php'); }

$st = $pdo->prepare("SELECT a.*, ab.label AS age_label
                     FROM assessments a
                     JOIN age_brackets ab ON ab.id=a.age_bracket_id
                     WHERE a.id=? LIMIT 1");
$st->execute([$aid]);
$ass = $st->fetch();
if (!$ass) { flash('danger','Assessment not found.'); redirect('index.php'); }

// Load existing answers (if any)
$ans = [];
$st2 = $pdo->prepare("SELECT q_no, value FROM assessment_answers WHERE assessment_id=?");
$st2->execute([$aid]);
foreach ($st2->fetchAll() as $r) $ans[(int)$r['q_no']] = (int)$r['value'];

/**
 * Generate scores into assessment_scores for this assessment_id.
 * Uses your existing helpers in lib_scoring.php.
 */
function generate_conners_scores(PDO $pdo, array $ass, int $aid): void {
  $versionId = (int)($ass['instrument_version_id'] ?? 0);
  if ($versionId <= 0) {
    $versionId = get_adhd_version_id($pdo);
  }
  if ($versionId <= 0) throw new Exception("Instrument version not found.");

  $gender = (string)($ass['gender'] ?? 'M');
  if (!in_array($gender, ['M','F'], true)) $gender = 'M';

  $ageBracketId = (int)($ass['age_bracket_id'] ?? 0);
  if ($ageBracketId <= 0) throw new Exception("Age bracket not found for assessment.");

  // Pull answers back from DB (truth source)
  $answers = [];
  $st = $pdo->prepare("SELECT q_no, value FROM assessment_answers WHERE assessment_id=?");
  $st->execute([$aid]);
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $answers[(int)$row['q_no']] = (int)$row['value'];
  }

  if (count($answers) < 27) {
    throw new Exception("Please complete all 27 answers.");
  }

  // Compute raw totals
  $rawTotals = compute_raw_totals($answers);

  // Replace existing scores (if recalculating)
  $pdo->prepare("DELETE FROM assessment_scores WHERE assessment_id=?")->execute([$aid]);

  $ins = $pdo->prepare("
    INSERT INTO assessment_scores
      (assessment_id, subscale, raw_total, t_score, percentile_label, guideline)
    VALUES
      (?,?,?,?,?,?)
  ");

  foreach ($rawTotals as $subscale => $rawTotal) {
    $t = lookup_t_score($pdo, $versionId, $gender, $ageBracketId, $subscale, (int)$rawTotal);
    $interp = interpret_t($pdo, $versionId, (int)$t);

    $ins->execute([
      $aid,
      $subscale,
      (int)$rawTotal,
      (int)$t,
      (string)($interp['percentile_label'] ?? ''),
      (string)($interp['guideline'] ?? '')
    ]);
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();

  $values = [];
  for ($q=1; $q<=27; $q++) {
    if (!isset($_POST['q'][$q])) {
      flash('warning','Please complete all 27 answers.');
      redirect("consultant/omr.php?id=".$aid);
    }
    $v = (int)$_POST['q'][$q];
    if ($v < 0 || $v > 3) $v = 0;
    $values[$q] = $v;
  }

  // Save answers + generate scores in ONE transaction (safer)
  try {
    $pdo->beginTransaction();

    $pdo->prepare("DELETE FROM assessment_answers WHERE assessment_id=?")->execute([$aid]);
    $ins = $pdo->prepare("INSERT INTO assessment_answers(assessment_id,q_no,value) VALUES (?,?,?)");
    foreach ($values as $q=>$v) $ins->execute([$aid,$q,$v]);

    // ✅ Generate Conners/ADHD scores
    generate_conners_scores($pdo, $ass, $aid);

    $pdo->commit();
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    flash('danger', 'Result generation failed: '.$e->getMessage());
    redirect("consultant/omr.php?id=".$aid);
  }

  // ✅ Now report.php will show the Conners table
  redirect("consultant/report.php?id=".$aid);
}

include __DIR__ . '/../partials/header.php';

// progress calc (UI only)
$answered = 0;
for ($i=1;$i<=27;$i++) if (isset($ans[$i])) $answered++;
$progress = (int)round(($answered/27)*100);
?>
<!-- ✅ REPLACE your <style> ... </style> block with this (full corrected CSS) -->
<style>
  :root{
    --dsrs-ink:#0b1220;
    --dsrs-muted:#667085;
    --dsrs-line:#e9edf5;
    --dsrs-card:#ffffff;
    --dsrs-shadow: 0 18px 55px rgba(16,24,40,.10);
    --dsrs-shadow2: 0 28px 90px rgba(0,0,0,.10);
    --dsrs-radius: 22px;

    --dsrs-orange:#f17252;
    --dsrs-blue:#2b59ff;
    --dsrs-green:#71bf44;
  }

  .omr-wrap{ position:relative; max-width: 1180px; margin: 0 auto; }
  .omr-geo{
    position:absolute; inset:-120px -60px auto -60px;
    height: 520px; pointer-events:none; opacity:.95; z-index:0;
  }
  .omr-geo:before{
    content:""; position:absolute; inset:0;
    background:
      radial-gradient(720px 420px at 12% 25%, rgba(241,114,82,.18), transparent 60%),
      radial-gradient(620px 380px at 78% 18%, rgba(43,89,255,.16), transparent 62%),
      radial-gradient(560px 380px at 56% 92%, rgba(113,191,68,.12), transparent 62%),
      radial-gradient(520px 340px at 105% 35%, rgba(0,0,0,.06), transparent 60%);
  }
  .omr-geo:after{
    content:""; position:absolute; inset:0;
    background-image: radial-gradient(rgba(16,24,40,.08) 1px, transparent 1px);
    background-size: 18px 18px;
    mask-image: radial-gradient(closest-side, rgba(0,0,0,.9), transparent 80%);
    opacity:.45;
  }

  .omr-hero{
    position:relative; z-index:1;
    border-radius: 26px;
    overflow:hidden;
    border:1px solid rgba(255,255,255,.20);
    background:
      radial-gradient(1100px 600px at 15% 10%, rgba(241,114,82,.18), transparent 60%),
      radial-gradient(900px 520px at 92% 22%, rgba(43,89,255,.18), transparent 60%),
      radial-gradient(900px 620px at 50% 120%, rgba(113,191,68,.12), transparent 55%),
      linear-gradient(135deg, #070a12, #0a1530 55%, #101b3e);
    box-shadow: var(--dsrs-shadow2);
    color:#fff;
  }
  .omr-hero-inner{ padding: 22px 18px; }
  @media(min-width:992px){ .omr-hero-inner{ padding: 28px 26px; } }

  .omr-badge{
    display:inline-flex; align-items:center; gap:10px;
    padding: 8px 12px;
    border-radius: 999px;
    background: rgba(255,255,255,.08);
    border:1px solid rgba(255,255,255,.16);
    backdrop-filter: blur(10px);
    font-weight: 900;
    letter-spacing:.2px;
  }
  .omr-dot{
    width:10px; height:10px; border-radius:999px;
    background: var(--dsrs-green);
    box-shadow: 0 0 0 7px rgba(113,191,68,.14), 0 0 18px rgba(113,191,68,.35);
  }
  .omr-title{
    margin: 14px 0 6px;
    font-weight: 1000;
    letter-spacing: -.6px;
    line-height: 1.08;
    font-size: clamp(22px, 3.2vw, 34px);
  }
  .omr-sub{
    margin:0;
    color: rgba(255,255,255,.78);
    line-height: 1.8;
    font-size: 13.5px;
    max-width: 86ch;
  }

  .omr-meta{
    margin-top: 12px;
    display:flex; flex-wrap:wrap; gap:10px;
  }
  .omr-chip{
    display:inline-flex; align-items:center; gap:8px;
    padding: 9px 12px;
    border-radius: 16px;
    background: rgba(255,255,255,.08);
    border:1px solid rgba(255,255,255,.14);
    backdrop-filter: blur(10px);
    font-size: 12.5px;
    font-weight: 850;
  }
  .omr-chip b{ font-weight: 1000; color:#fff; }

  .omr-card{
    background: var(--dsrs-card);
    border: 1px solid var(--dsrs-line);
    border-radius: var(--dsrs-radius);
    box-shadow: var(--dsrs-shadow);
    overflow:hidden;
    position:relative;
    z-index:1;
  }

  .omr-card-head{
    padding: 14px 14px;
    border-bottom: 1px solid #eef1f7;
    background: linear-gradient(180deg, #ffffff, #fbfcff);
    display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap;
  }
  .omr-card-head h5{
    margin:0;
    font-weight: 1000;
    letter-spacing: -.2px;
  }
  .omr-card-head .hint{
    color: var(--dsrs-muted);
    font-size: 12.5px;
  }

  .omr-progress{
    display:flex; align-items:center; gap:10px;
    padding: 8px 10px;
    border-radius: 999px;
    border: 1px solid #e9edf5;
    background: #ffffff;
    box-shadow: 0 10px 22px rgba(16,24,40,.06);
    font-size: 12px;
    font-weight: 900;
  }
  .omr-bar{
    width: 140px; height: 8px;
    border-radius: 999px;
    background: #eef2ff;
    overflow:hidden;
  }
  .omr-bar > span{
    display:block; height:100%;
    width: 0%;
    background: linear-gradient(90deg, var(--dsrs-blue), var(--dsrs-orange));
    transition: width .2s ease;
  }

  .omr-done-pill{
    display:inline-flex; align-items:center; gap:8px;
    padding: 10px 12px;
    border-radius: 999px;
    font-weight: 1000;
    color: #0b1220;
    border: 1px solid rgba(113,191,68,.55);
    background: linear-gradient(135deg, rgba(113,191,68,.26), rgba(43,89,255,.12));
    box-shadow: 0 18px 40px rgba(16,24,40,.12);
  }
  .omr-done-pill b{ color:#0b1220; }

  /* layout */
  .omr-layout{ display:grid; grid-template-columns: 1fr; gap: 14px; margin-top: 14px; }
  @media(min-width:992px){
    .omr-layout{ grid-template-columns: 1fr 310px; align-items:start; }
    .omr-aside{ position: sticky; top: 92px; }
  }

  /* OMR grid enhanced */
  .omr-body{ padding: 14px; }
  .omr-grid2{
    display:grid;
    grid-template-columns: 62px repeat(4, minmax(0,1fr));
    gap: 10px;
    align-items: stretch;
  }
  @media(max-width: 520px){
    .omr-grid2{ grid-template-columns: 54px repeat(4, minmax(0,1fr)); gap:8px; }
  }

  .omr-h{
    font-size: 12px;
    font-weight: 950;
    color: var(--dsrs-muted);
    text-align:center;
    padding: 6px 0;
  }
  .omr-h:first-child{ text-align:left; }

  .qcell{
    display:flex; align-items:center; justify-content:flex-start;
    padding: 10px 12px;
    border-radius: 16px;
    border:1px solid #eef1f7;
    background: linear-gradient(180deg, #ffffff, #fbfcff);
    font-weight: 1000;
    color:#111827;
    box-shadow: 0 10px 22px rgba(16,24,40,.05);
    cursor: pointer;
  }

  .opt{
    position:relative;
    border-radius: 16px;
    border:1px solid #eef1f7;
    background: #fff;
    box-shadow: 0 10px 22px rgba(16,24,40,.05);
    overflow:hidden;
    transition: transform .12s ease, border-color .12s ease, box-shadow .12s ease;
  }
  .opt:hover{ transform: translateY(-1px); border-color: rgba(43,89,255,.30); }
  .opt input{ position:absolute; opacity:0; pointer-events:none; }
  .opt label{
    display:flex; align-items:center; justify-content:center;
    gap:10px;
    padding: 12px 8px;
    cursor:pointer;
    font-weight: 1000;
    color:#111827;
    user-select:none;
  }
  .opt label:before{
    content:"";
    width: 14px; height:14px;
    border-radius: 6px;
    border: 2px solid #cfd6e5;
    display:inline-block;
    box-shadow: inset 0 0 0 3px #fff;
    background: #fff;
  }
  .opt input:checked + label{
    background: linear-gradient(135deg, rgba(43,89,255,.14), rgba(241,114,82,.10));
  }
  .opt input:checked + label:before{
    border-color: rgba(43,89,255,.55);
    background: radial-gradient(circle at 35% 35%, #fff, rgba(43,89,255,.25));
    box-shadow: inset 0 0 0 3px #fff, 0 0 0 6px rgba(43,89,255,.10);
  }

  /* ===== Quick Jump (buttons) ===== */
  .nav-card{ padding: 14px; }

  .qnav{
    display:grid;
    grid-template-columns: repeat(6, 1fr);
    gap: 8px;
  }

  .qnav button{
    width: 42px; height: 42px;
    border-radius: 14px;
    border: 1px solid rgba(233,237,245,.95);
    background: #fff;
    font-weight: 950;
    font-size: 12px;
    box-shadow: 0 10px 24px rgba(16,24,40,.08);
    transition: transform .15s ease, box-shadow .2s ease, border-color .2s ease, background .2s ease, color .2s ease;
    position: relative;
    outline: none;
  }

  .qnav button:hover{
    transform: translateY(-1px);
    box-shadow: 0 16px 34px rgba(16,24,40,.12);
  }

  /* ✅ DONE = green filled */
  .qnav button.is-done{
    background: linear-gradient(135deg, rgba(113,191,68,.26), rgba(113,191,68,.16));
    border-color: rgba(113,191,68,.62);
    color: #0b1220;
  }

  /* 🟠 ACTIVE = orange glow ring */
  .qnav button.is-active{
    border-color: rgba(241,114,82,.80);
    box-shadow: 0 0 0 3px rgba(241,114,82,.22), 0 18px 40px rgba(16,24,40,.14);
  }

  /* If active AND done, keep both */
  .qnav button.is-active.is-done{
    box-shadow: 0 0 0 3px rgba(241,114,82,.22), 0 18px 40px rgba(16,24,40,.14);
  }

  /* ✨ ACTIVE + UNANSWERED = pulse */
  @keyframes omrPulse {
    0%   { box-shadow: 0 0 0 0 rgba(241,114,82,.20), 0 18px 40px rgba(16,24,40,.12); }
    70%  { box-shadow: 0 0 0 10px rgba(241,114,82,0), 0 18px 40px rgba(16,24,40,.12); }
    100% { box-shadow: 0 0 0 0 rgba(241,114,82,0), 0 18px 40px rgba(16,24,40,.12); }
  }
  .qnav button.is-active.is-missing{
    animation: omrPulse 1.25s ease-out infinite;
  }

  /* Small “check dot” on done buttons */
  .qnav button.is-done::after{
    content:"";
    position:absolute;
    top: 8px; right: 8px;
    width: 10px; height: 10px;
    border-radius: 999px;
    background: rgba(113,191,68,.98);
    box-shadow: 0 6px 14px rgba(113,191,68,.25);
  }

  /* sticky actions */
  .omr-actions{
    position: sticky;
    bottom: 12px;
    z-index: 5;
    margin-top: 14px;
    display:flex;
    flex-wrap:wrap;
    gap:10px;
    align-items:center;
    justify-content:space-between;
    padding: 12px;
    border-radius: 18px;
    background: rgba(255,255,255,.88);
    border:1px solid #e9edf5;
    box-shadow: 0 22px 70px rgba(16,24,40,.12);
    backdrop-filter: blur(10px);
  }
  .btn-omr-primary{
    border-radius: 16px;
    padding: 11px 14px;
    font-weight: 950;
    border:0;
    color:#fff;
    background: linear-gradient(135deg, var(--dsrs-orange), #ff8a6f);
    box-shadow: 0 18px 45px rgba(241,114,82,.25);
    transition: transform .14s ease, filter .14s ease, box-shadow .14s ease;
    display:inline-flex; align-items:center; gap:10px;
  }
  .btn-omr-primary:hover{ transform: translateY(-1px); filter: brightness(.98); }
  .btn-omr-ghost{
    border-radius: 16px;
    padding: 11px 14px;
    font-weight: 900;
    background: #fff;
    border: 1px solid #e9edf5;
    box-shadow: 0 10px 22px rgba(16,24,40,.06);
  }

  .kbd{
    font-size: 12px;
    color: var(--dsrs-muted);
    display:flex; gap:8px; flex-wrap:wrap; align-items:center;
  }
  .kbd span{
    border:1px solid #e9edf5;
    background:#fff;
    border-radius: 10px;
    padding: 6px 8px;
    font-weight: 900;
    box-shadow: 0 10px 22px rgba(16,24,40,.05);
  }

  @media (prefers-reduced-motion: no-preference){
    .pop{ animation: pop .5s ease both; }
    .pop2{ animation: pop .65s ease both; }
    @keyframes pop{ from{ opacity:0; transform: translateY(10px) scale(.99);} to{ opacity:1; transform: translateY(0) scale(1);} }
  }
</style>

<div class="omr-wrap">
  <div class="omr-geo"></div>

  <!-- HERO -->
  <div class="omr-hero pop mb-3">
    <div class="omr-hero-inner">
      <div class="d-flex flex-wrap align-items-start justify-content-between gap-2">
        <div class="min-w-0">
          <div class="omr-badge"><span class="omr-dot"></span> BPCL DSRS • OMR Entry</div>
          <div class="omr-title">OMR Sheet (27 Questions)</div>
          <p class="omr-sub">
            Select one option (0–3) per question. When ready, click <b>Calculate Result</b> to generate Conners domains and report.
          </p>

          <div class="omr-meta">
            <div class="omr-chip">Patient: <b><?= e($ass['patient_name']) ?></b></div>
            <div class="omr-chip">Age: <b><?= (int)$ass['age'] ?> (<?= e($ass['age_label']) ?>)</b></div>
            <div class="omr-chip">Gender: <b><?= e($ass['gender']) ?></b></div>
            <div class="omr-chip">Category: <b><?= e($ass['category']) ?></b></div>
          </div>
        </div>

        <div class="d-flex gap-2 flex-wrap align-items-start justify-content-end">
          <a class="btn btn-outline-light btn-sm" style="border-radius:14px" href="<?= e(BASE_URL) ?>/consultant/new_assessment.php">+ New</a>
        </div>
      </div>
    </div>
  </div>

  <div class="omr-layout pop2">
    <!-- MAIN -->
    <div class="omr-card">
      <div class="omr-card-head">
        <div class="min-w-0">
          <h5 class="mb-1">Mark Answers</h5>
          <div class="hint">Tip: Use keyboard: <b>1/2/3/4</b> to select option 0/1/2/3 for the focused question card.</div>
        </div>

     <!-- ✅ In your progress HTML, ensure progBar has initial width from PHP (recommended) -->
<div class="omr-progress" title="Progress">
  <span id="progText"><?= (int)$answered ?>/27</span>
  <div class="omr-bar">
    <span id="progBar" style="width: <?= (int)$progress ?>%;"></span>
  </div>
  <span id="progPct"><?= (int)$progress ?>%</span>
</div>
      </div>

      <div class="omr-body">
        <form method="post" id="omrForm">
          <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

          <div class="omr-grid2 mb-2">
            <div class="omr-h">Q</div>
            <div class="omr-h">0</div>
            <div class="omr-h">1</div>
            <div class="omr-h">2</div>
            <div class="omr-h">3</div>

            <?php for ($q=1;$q<=27;$q++): $cur = $ans[$q] ?? null; ?>
              <div class="qcell" id="qrow<?= (int)$q ?>" data-q="<?= (int)$q ?>">Q<?= (int)$q ?></div>

              <?php for ($v=0;$v<=3;$v++): $id="q{$q}_{$v}"; ?>
                <div class="opt" data-q="<?= (int)$q ?>" data-v="<?= (int)$v ?>">
                  <input
                    id="<?= e($id) ?>"
                    type="radio"
                    name="q[<?= (int)$q ?>]"
                    value="<?= (int)$v ?>"
                    <?= ($cur === $v ? 'checked' : '') ?>
                    required
                  >
                  <label for="<?= e($id) ?>"><?= (int)$v ?></label>
                </div>
              <?php endfor; ?>
            <?php endfor; ?>
          </div>

          <div class="omr-actions">
            <div class="d-flex gap-2 flex-wrap align-items-center">
              <button class="btn-omr-primary" type="submit" id="btnCalc">
                <span style="display:inline-grid;place-items:center;width:22px;height:22px;border-radius:8px;background:rgba(255,255,255,.18)">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none">
                    <path d="M9 18l6-6-6-6" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                  </svg>
                </span>
                Calculate Result
              </button>

              <a class="btn btn-omr-ghost" href="<?= e(BASE_URL) ?>/index.php">Back Home</a>
            </div>

            <div class="kbd">
              <span>Shortcut</span>
              <span>1→0</span><span>2→1</span><span>3→2</span><span>4→3</span>
              <span>↑/↓ jump</span>
            </div>
          </div>
        </form>
      </div>
    </div>

    <!-- ASIDE -->
    <div class="omr-aside">
      <div class="omr-card nav-card">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <div>
            <div style="font-weight:1000;">Quick Jump</div>
            <div class="hint">Tap a number to scroll.</div>
          </div>
          <div class="omr-done-pill">
  Done: <b id="doneCount"><?= (int)$answered ?></b>/27
</div>
        </div>

        <div class="qnav" id="qnav">
          <?php for ($q=1;$q<=27;$q++): ?>
            <button
              type="button"
              data-go="<?= (int)$q ?>"
              class="<?= isset($ans[$q]) ? 'is-done' : '' ?>"
              aria-label="Go to question <?= (int)$q ?>"
            ><?= (int)$q ?></button>
          <?php endfor; ?>
        </div>

        <hr class="my-3">

        <div class="hint">
          <b>Quality tip:</b> If any question is missed, result generation will stop and show an error (same rule as before).
        </div>
      </div>
    </div>
  </div>
</div>

<!-- ✅ REPLACE your <script> ... </script> block with this (full corrected JS) -->
<script>
(function(){
  const form = document.getElementById('omrForm');
  const nav = document.getElementById('qnav');
  const doneCount = document.getElementById('doneCount');
  const progText = document.getElementById('progText');
  const progPct  = document.getElementById('progPct');
  const progBar  = document.getElementById('progBar');

  // ✅ IMPORTANT: declare activeQ
  let activeQ = 1;

  function setActiveButton(q){
    if(!nav) return;

    [...nav.querySelectorAll('button[data-go]')].forEach(b=>{
      b.classList.remove('is-active','is-missing');
    });

    const btn = nav.querySelector('button[data-go="'+q+'"]');
    if(btn){
      btn.classList.add('is-active');
      const checked = !!form.querySelector('input[name="q['+q+']"]:checked');
      btn.classList.toggle('is-missing', !checked);
    }
  }

  function scrollToQ(q){
    q = parseInt(q,10) || 1;
    activeQ = q;

    const row = document.getElementById('qrow'+q);
    if(row) row.scrollIntoView({behavior:'smooth', block:'center'});

    setActiveButton(q);
  }

  function refreshDone(){
    let count = 0;

    for(let q=1;q<=27;q++){
      const checked = !!form.querySelector('input[name="q['+q+']"]:checked');
      if(checked) count++;

      const btn = nav?.querySelector('button[data-go="'+q+'"]');
      if(!btn) continue;

      btn.classList.toggle('is-done', checked);

      // if active + missing => pulse
      const isActive = btn.classList.contains('is-active');
      btn.classList.toggle('is-missing', isActive && !checked);
    }

    // right pill
    if(doneCount) doneCount.textContent = count;

    // top progress
    const pct = Math.round((count/27)*100);
    if(progText) progText.textContent = count + "/27";
    if(progPct)  progPct.textContent  = pct + "%";
    if(progBar)  progBar.style.width  = pct + "%";
  }

  // Quick jump click
  nav?.addEventListener('click', (e)=>{
    const b = e.target.closest('button[data-go]');
    if(!b) return;
    scrollToQ(b.getAttribute('data-go'));
  });

  // Change updates
  form?.addEventListener('change', (e)=>{
    if(e.target && e.target.matches('input[type="radio"]')){
      // on any answer, refresh + keep activeQ highlighted
      refreshDone();
      setActiveButton(activeQ);
    }
  });

  // Clicking on option cell / question label sets active question
  document.addEventListener('click', (e)=>{
    const opt = e.target.closest('.opt[data-q]');
    if(opt){
      const q = parseInt(opt.getAttribute('data-q'),10);
      if(q) scrollToQ(q);
      return;
    }
    const qcell = e.target.closest('.qcell[data-q]');
    if(qcell){
      const q = parseInt(qcell.getAttribute('data-q'),10);
      if(q) scrollToQ(q);
    }
  });

  // Keyboard shortcuts
  document.addEventListener('keydown', (e)=>{
    const tag = (e.target && e.target.tagName) ? e.target.tagName.toLowerCase() : '';
    if(tag === 'input' || tag === 'textarea' || tag === 'select') return;

    const key = e.key;

    if(key === 'ArrowDown'){
      e.preventDefault();
      scrollToQ(Math.min(27, activeQ + 1));
      return;
    }
    if(key === 'ArrowUp'){
      e.preventDefault();
      scrollToQ(Math.max(1, activeQ - 1));
      return;
    }

    const map = { '1':0, '2':1, '3':2, '4':3 };
    if(Object.prototype.hasOwnProperty.call(map, key)){
      e.preventDefault();
      const v = map[key];
      const radio = form.querySelector('#q'+activeQ+'_'+v);
      if(radio){
        radio.checked = true;
        radio.dispatchEvent(new Event('change', {bubbles:true}));
        refreshDone();
        setActiveButton(activeQ);
      }
    }
  });

  // init
  refreshDone();

  // set initial active question: first unanswered else 1
  for(let q=1;q<=27;q++){
    const checked = !!form.querySelector('input[name="q['+q+']"]:checked');
    if(!checked){ activeQ = q; break; }
  }
  scrollToQ(activeQ);
})();
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>