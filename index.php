<?php
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
start_session();

$u = $_SESSION['user'] ?? null;

include __DIR__ . '/partials/header.php';
?>

<style>
  :root{
    --ink:#0b1220;
    --muted:#667085;
    --line: rgba(255,255,255,.14);
    --line2:#e9edf5;

    --orange:#f17252;
    --green:#71bf44;
    --blue:#2b59ff;

    --bg0:#070a12;
    --bg1:#0a1530;
    --bg2:#101b3e;

    --shadow: 0 28px 90px rgba(0,0,0,.38);
    --shadow2: 0 18px 55px rgba(10,20,40,.14);
    --radius: 22px;
  }

  /* ===== Page background vibes ===== */
  .dsrs-wrap{
    position: relative;
    padding: 6px 0 14px;
  }
  .dsrs-geo{
    position:absolute;
    inset:-120px -80px auto -80px;
    height: 520px;
    pointer-events:none;
    filter: blur(0px);
    opacity:.95;
    z-index:0;
  }
  .dsrs-geo:before{
    content:"";
    position:absolute; inset:0;
    background:
      radial-gradient(650px 380px at 18% 25%, rgba(241,114,82,.22), transparent 60%),
      radial-gradient(540px 360px at 72% 18%, rgba(43,89,255,.22), transparent 62%),
      radial-gradient(520px 360px at 58% 85%, rgba(113,191,68,.18), transparent 62%),
      radial-gradient(520px 340px at 105% 35%, rgba(255,255,255,.07), transparent 60%);
  }
  .dsrs-geo:after{
    content:"";
    position:absolute; inset:0;
    background-image: radial-gradient(rgba(255,255,255,.06) 1px, transparent 1px);
    background-size: 18px 18px;
    mask-image: radial-gradient(closest-side, rgba(0,0,0,.9), transparent 80%);
    opacity:.55;
  }

  /* ===== Hero ===== */
  .dsrs-hero{
    position: relative;
    z-index: 1;
    border-radius: 26px;
    overflow: hidden;
    color:#fff;
    border: 1px solid rgba(255,255,255,.14);
    background:
      radial-gradient(1200px 650px at 20% 10%, rgba(241,114,82,.22), transparent 60%),
      radial-gradient(900px 520px at 95% 25%, rgba(43,89,255,.22), transparent 60%),
      radial-gradient(900px 650px at 45% 110%, rgba(113,191,68,.16), transparent 55%),
      linear-gradient(135deg, var(--bg0), var(--bg1) 55%, var(--bg2));
    box-shadow: var(--shadow);
  }
  .dsrs-hero::before{
    content:"";
    position:absolute; inset:-2px;
    background:
      radial-gradient(900px 520px at 30% 30%, rgba(255,255,255,.10), transparent 60%),
      radial-gradient(600px 340px at 80% 10%, rgba(255,255,255,.06), transparent 65%);
    pointer-events:none;
  }
  .dsrs-hero::after{
    content:"";
    position:absolute; inset:0;
    background: linear-gradient(90deg, rgba(0,0,0,.18), transparent 35%, rgba(0,0,0,.22));
    pointer-events:none;
  }
  .dsrs-hero-inner{
    position: relative;
    padding: 28px 22px;
  }
  @media(min-width:992px){
    .dsrs-hero-inner{ padding: 38px 36px; }
  }

  /* badge */
  .dsrs-badge{
    display:inline-flex;
    align-items:center;
    gap:10px;
    padding: 9px 13px;
    border-radius: 999px;
    background: rgba(255,255,255,.08);
    border: 1px solid rgba(255,255,255,.16);
    backdrop-filter: blur(10px);
    font-weight: 900;
    letter-spacing: .2px;
  }
  .dsrs-dot{
    width:10px; height:10px; border-radius:999px;
    background: var(--orange);
    box-shadow: 0 0 0 7px rgba(241,114,82,.14), 0 0 18px rgba(241,114,82,.35);
  }

  /* title */
  .dsrs-title{
    margin: 14px 0 8px;
    font-weight: 1000;
    line-height: 1.06;
    font-size: clamp(26px, 3.6vw, 44px);
    letter-spacing: -.6px;
    text-shadow: 0 10px 28px rgba(0,0,0,.35);
  }
  .dsrs-sub{
    margin:0;
    max-width: 62ch;
    color: rgba(255,255,255,.80);
    line-height: 1.85;
    font-size: 14px;
  }

  /* hero actions */
  .dsrs-actions{
    margin-top: 18px;
    display:flex;
    flex-wrap:wrap;
    gap:10px;
    align-items:center;
  }
  .btn-prem{
    border-radius: 16px;
    padding: 11px 14px;
    font-weight: 900;
    border: 0;
    display:inline-flex;
    align-items:center;
    gap:10px;
    transition: transform .14s ease, filter .14s ease, box-shadow .14s ease, background .14s ease;
  }
  .btn-prem-primary{
    background: linear-gradient(135deg, var(--orange), #ff8a6f);
    color:#fff;
    box-shadow: 0 18px 40px rgba(241,114,82,.28);
  }
  .btn-prem-primary:hover{ filter: brightness(.98); transform: translateY(-1px); }
  .btn-prem-primary:active{ transform: translateY(0); }

  .btn-prem-ghost{
    background: rgba(255,255,255,.10);
    color:#fff;
    border: 1px solid rgba(255,255,255,.18);
    backdrop-filter: blur(10px);
  }
  .btn-prem-ghost:hover{ background: rgba(255,255,255,.14); transform: translateY(-1px); }

  .dsrs-note{
    margin-top: 14px;
    color: rgba(255,255,255,.75);
    font-size: 13.5px;
    display:flex;
    align-items:center;
    gap:10px;
  }

  /* ===== Right KPI pills inside hero ===== */
  .dsrs-kpis{
    display:grid;
    grid-template-columns: repeat(3, minmax(0,1fr));
    gap: 12px;
  }
  @media(max-width: 991px){
    .dsrs-kpis{ grid-template-columns: 1fr; }
  }
  .dsrs-kpi{
    position: relative;
    border-radius: 18px;
    padding: 14px 14px;
    background: rgba(255,255,255,.08);
    border: 1px solid rgba(255,255,255,.16);
    backdrop-filter: blur(12px);
    overflow:hidden;
    min-height: 82px;
  }
  .dsrs-kpi::before{
    content:"";
    position:absolute; inset:-2px;
    background:
      radial-gradient(240px 120px at 15% 20%, rgba(255,255,255,.10), transparent 60%),
      radial-gradient(240px 120px at 85% 80%, rgba(255,255,255,.06), transparent 60%);
    pointer-events:none;
  }
  .dsrs-kpi .k{
    font-size: 12px;
    color: rgba(255,255,255,.75);
    display:flex;
    align-items:center;
    gap:8px;
  }
  .dsrs-kpi .v{
    margin-top: 4px;
    font-size: 15px;
    font-weight: 950;
    letter-spacing: -.2px;
  }

  /* ===== Lower cards (3D look) ===== */
  .dsrs-grid{ position:relative; z-index:1; margin-top: 14px; }

  .glass-card{
    background: #fff;
    border: 1px solid #e9edf5;
    border-radius: var(--radius);
    box-shadow: var(--shadow2);
    overflow:hidden;
  }
  .glass-head{
    padding: 15px 16px;
    border-bottom: 1px solid #eef1f7;
    background: linear-gradient(180deg, #ffffff, #fbfcff);
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
  }
  .glass-title{
    font-weight: 950;
    margin:0;
    font-size: 15px;
    letter-spacing: -.2px;
  }
  .glass-sub{
    margin: 0;
    font-size: 12.5px;
    color: var(--muted);
    line-height: 1.5;
  }
  .glass-body{ padding: 16px; }

  .pill{
    display:inline-flex;
    align-items:center;
    gap:8px;
    padding: 8px 11px;
    border-radius: 999px;
    background: #f4f6ff;
    border: 1px solid #e9edf5;
    color:#111;
    font-size: 12.5px;
    font-weight: 900;
    white-space: nowrap;
  }

  .step{
    border-radius: 18px;
    border: 1px solid #e9edf5;
    background: linear-gradient(180deg, #ffffff, #fbfcff);
    padding: 14px;
    height: 100%;
    transition: transform .14s ease, box-shadow .14s ease;
  }
  .step:hover{
    transform: translateY(-2px);
    box-shadow: 0 18px 55px rgba(20,30,60,.10);
  }
  .step .h{
    display:flex; align-items:center; gap:10px;
    font-weight: 950;
    margin-bottom: 6px;
  }
  .step .ico{
    width: 36px; height: 36px;
    border-radius: 12px;
    display:grid; place-items:center;
    color:#fff;
    background: linear-gradient(135deg, var(--blue), #6a86ff);
    box-shadow: 0 10px 24px rgba(43,89,255,.20);
  }
  .step p{
    margin:0;
    color: var(--muted);
    font-size: 13.5px;
    line-height: 1.7;
  }

  .rule{
    margin:0;
    padding-left: 18px;
    color: var(--muted);
    font-size: 13.5px;
    line-height: 1.85;
  }
  .tip{
    margin-top: 12px;
    border-radius: 18px;
    padding: 13px 14px;
    border: 1px solid #e9edf5;
    background: linear-gradient(135deg, rgba(241,114,82,.12), rgba(43,89,255,.08));
  }
  .tip .t{ font-weight: 950; margin-bottom: 4px; }
  .tip .d{ color: var(--muted); font-size: 13.5px; line-height:1.7; margin:0; }

  /* subtle entrance animation */
  @media (prefers-reduced-motion: no-preference){
    .pop{ animation: pop .45s ease both; }
    .pop2{ animation: pop .55s ease both; }
    .pop3{ animation: pop .65s ease both; }
    @keyframes pop{
      from{ opacity:0; transform: translateY(8px) scale(.99); }
      to{ opacity:1; transform: translateY(0) scale(1); }
    }
  }
</style>

<div class="dsrs-wrap">
  <div class="dsrs-geo"></div>

  <!-- HERO -->
  <div class="dsrs-hero pop">
    <div class="dsrs-hero-inner">
      <div class="row g-3 align-items-start">
        <div class="col-lg-7">
          <div class="dsrs-badge"><span class="dsrs-dot"></span> BPCL DSRS</div>
          <div class="dsrs-title">Digital Scale & Report System</div>
          <p class="dsrs-sub">
            Phase-1: ADHD OMR scoring → T-Score mapping → Interpretation → Report generation (print-ready).
          </p>

          <div class="dsrs-actions">
            <?php if (!$u): ?>
              <a class="btn-prem btn-prem-primary" href="auth/register.php">
                <span style="display:inline-grid;place-items:center;width:22px;height:22px;border-radius:8px;background:rgba(255,255,255,.18)">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none"><path d="M12 12a4 4 0 1 0-4-4 4 4 0 0 0 4 4Zm7 9a7 7 0 0 0-14 0" stroke="white" stroke-width="2" stroke-linecap="round"/><path d="M19 8v6M22 11h-6" stroke="white" stroke-width="2" stroke-linecap="round"/></svg>
                </span>
                Create account
              </a>
              <a class="btn-prem btn-prem-ghost" href="auth/login.php">
                <span style="display:inline-grid;place-items:center;width:22px;height:22px;border-radius:8px;background:rgba(255,255,255,.10);border:1px solid rgba(255,255,255,.16)">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none"><path d="M10 17l5-5-5-5" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M15 12H3" stroke="white" stroke-width="2" stroke-linecap="round"/><path d="M21 3v18" stroke="white" stroke-width="2" stroke-linecap="round"/></svg>
                </span>
                Login
              </a>
            <?php else: ?>
              <a class="btn-prem btn-prem-primary" href="consultant/new_assessment.php">
                <span style="display:inline-grid;place-items:center;width:22px;height:22px;border-radius:8px;background:rgba(255,255,255,.18)">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none"><path d="M12 5v14M5 12h14" stroke="white" stroke-width="2" stroke-linecap="round"/></svg>
                </span>
                New ADHD Assessment
              </a>
              <a class="btn-prem btn-prem-ghost" href="consultant/history.php">
                <span style="display:inline-grid;place-items:center;width:22px;height:22px;border-radius:8px;background:rgba(255,255,255,.10);border:1px solid rgba(255,255,255,.16)">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none"><path d="M12 8v5l3 2" stroke="white" stroke-width="2" stroke-linecap="round"/><path d="M21 12a9 9 0 1 1-3-6.7" stroke="white" stroke-width="2" stroke-linecap="round"/></svg>
                </span>
                Assessment History
              </a>
              <?php if ($u['role']==='ADMIN'): ?>
                <a class="btn-prem btn-prem-ghost" href="admin/scoring_tables.php">
                  <span style="display:inline-grid;place-items:center;width:22px;height:22px;border-radius:8px;background:rgba(255,255,255,.10);border:1px solid rgba(255,255,255,.16)">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none"><path d="M4 6h16M4 12h16M4 18h16" stroke="white" stroke-width="2" stroke-linecap="round"/></svg>
                  </span>
                  Scoring Tables
                </a>
              <?php endif; ?>
            <?php endif; ?>
          </div>

          <?php if (!$u): ?>
            <div class="dsrs-note">
              <span style="width:28px;height:28px;border-radius:12px;display:grid;place-items:center;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.14)">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none"><path d="M12 2l7 4v6c0 5-3 9-7 10-4-1-7-5-7-10V6l7-4Z" stroke="white" stroke-width="2"/></svg>
              </span>
              Email verification required for login.
            </div>
          <?php else: ?>
            <div class="dsrs-note">
              <span style="width:28px;height:28px;border-radius:12px;display:grid;place-items:center;background:rgba(255,255,255,.08);border:1px solid rgba(255,255,255,.14)">
                <svg width="15" height="15" viewBox="0 0 24 24" fill="none"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2" stroke="white" stroke-width="2" stroke-linecap="round"/><path d="M12 11a4 4 0 1 0-4-4 4 4 0 0 0 4 4Z" stroke="white" stroke-width="2" stroke-linecap="round"/></svg>
              </span>
              Logged in as <b><?= e($u['name'] ?? 'User') ?></b> (<?= e($u['role'] ?? '') ?>)
            </div>
          <?php endif; ?>
        </div>

        <div class="col-lg-5">
          <div class="dsrs-kpis pop2">
            <div class="dsrs-kpi">
              <div class="k">
                <span style="width:26px;height:26px;border-radius:12px;display:grid;place-items:center;background:rgba(43,89,255,.22);border:1px solid rgba(255,255,255,.14)">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none"><path d="M4 5h16v14H4z" stroke="white" stroke-width="2"/><path d="M8 9h8M8 13h6" stroke="white" stroke-width="2" stroke-linecap="round"/></svg>
                </span>
                Instrument
              </div>
              <div class="v">ADHD (Conners-style)</div>
            </div>

            <div class="dsrs-kpi">
              <div class="k">
                <span style="width:26px;height:26px;border-radius:12px;display:grid;place-items:center;background:rgba(113,191,68,.22);border:1px solid rgba(255,255,255,.14)">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none"><path d="M12 12a4 4 0 1 0-4-4 4 4 0 0 0 4 4Z" stroke="white" stroke-width="2"/><path d="M20 21a8 8 0 0 0-16 0" stroke="white" stroke-width="2" stroke-linecap="round"/></svg>
                </span>
                Sheets
              </div>
              <div class="v">Gender-specific</div>
            </div>

            <div class="dsrs-kpi">
              <div class="k">
                <span style="width:26px;height:26px;border-radius:12px;display:grid;place-items:center;background:rgba(241,114,82,.22);border:1px solid rgba(255,255,255,.14)">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none"><path d="M4 19V5" stroke="white" stroke-width="2" stroke-linecap="round"/><path d="M4 19h16" stroke="white" stroke-width="2" stroke-linecap="round"/><path d="M7 14l3-3 3 2 4-6" stroke="white" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                </span>
                Fallback
              </div>
              <div class="v">Not found → T-Score 90</div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- LOWER CARDS -->
  <div class="dsrs-grid row g-3">
    <div class="col-lg-8 pop2">
      <div class="glass-card">
        <div class="glass-head">
          <div>
            <p class="glass-title">Workflow Overview</p>
            <p class="glass-sub">How DSRS generates the final report</p>
          </div>
          <span class="pill">⚡ Fast & structured</span>
        </div>

        <div class="glass-body">
          <div class="row g-3">
            <div class="col-md-6">
              <div class="step">
                <div class="h">
                  <div class="ico">1</div>
                  OMR Entry
                </div>
                <p>Enter 27 answers, calculate raw totals automatically.</p>
              </div>
            </div>

            <div class="col-md-6">
              <div class="step">
                <div class="h">
                  <div class="ico" style="background:linear-gradient(135deg,var(--orange),#ff8a6f);box-shadow:0 10px 24px rgba(241,114,82,.20);">2</div>
                  T-Score Mapping
                </div>
                <p>Maps raw scores to T-Scores using rating tables.</p>
              </div>
            </div>

            <div class="col-md-6">
              <div class="step">
                <div class="h">
                  <div class="ico" style="background:linear-gradient(135deg,var(--green),#9ae66b);box-shadow:0 10px 24px rgba(113,191,68,.18);">3</div>
                  Interpretation
                </div>
                <p>Generates percentile label & guideline per domain.</p>
              </div>
            </div>

            <div class="col-md-6">
              <div class="step">
                <div class="h">
                  <div class="ico" style="background:linear-gradient(135deg,#6a86ff,var(--blue));">4</div>
                  Report (Print-Ready)
                </div>
                <p>Clinical layout optimized for printing and documentation.</p>
              </div>
            </div>
          </div>

          <div class="d-flex gap-2 flex-wrap mt-3">
            <span class="pill">📝 Autosave notes</span>
            <span class="pill">📄 Structured report</span>
            <span class="pill">🔒 Role-based access</span>
          </div>
        </div>
      </div>
    </div>

    <div class="col-lg-4 pop3">
      <div class="glass-card">
        <div class="glass-head">
          <div>
            <p class="glass-title">Instrument Rules</p>
            <p class="glass-sub">ADHD (Conners-style mapping)</p>
          </div>
          <span class="pill">ⓘ Reference</span>
        </div>

        <div class="glass-body">
          <ul class="rule">
            <li>Gender-specific rating sheets</li>
            <li>Age-bracket columns: 3–5, 6–8, 9–11, 12–14, 15–17</li>
            <li>Out-of-range / not found ⇒ T-Score 90</li>
          </ul>

          <div class="tip">
            <div class="t">✅ Tip</div>
            <p class="d">Ensure all 27 OMR answers are filled before generating the report.</p>
          </div>
        </div>
      </div>
    </div>
  </div>

</div>

<?php include __DIR__ . '/partials/footer.php'; ?>