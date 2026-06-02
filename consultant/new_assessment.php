<?php
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/lib_scoring.php';

$me = require_role('CONSULTANT', 'ADMIN');
$pdo = db();

$versionId = get_adhd_version_id($pdo);
// Detect if assessments has referred_by column (safe for old DBs)
try {
  $hasReferredByCol = (bool) $pdo->query("SHOW COLUMNS FROM assessments LIKE 'referred_by'")->fetch();
} catch (Throwable $e) {
  $hasReferredByCol = false;
}
if ($versionId <= 0) {
  http_response_code(500);
  exit('ADHD instrument version not found. Import/seed DB first.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();

  $adhdType = trim($_POST['adhd_type'] ?? 'General');
  $category = $_POST['category'] ?? 'CHILD';
  $gender = $_POST['gender'] ?? 'M';

  $pname = trim($_POST['patient_name'] ?? '');
  $assessDate = trim($_POST['assessment_date'] ?? '');
  $dob = trim($_POST['dob'] ?? '');
  $education = trim($_POST['education'] ?? '');
  $contactNo = trim($_POST['contact_no'] ?? '');
  $referredBy = trim($_POST['referred_by'] ?? '');

  $ageYears = (int) ($_POST['age_years'] ?? 0);
  $ageMonths = (int) ($_POST['age_months'] ?? 0);
  $ageDays = (int) ($_POST['age_days'] ?? 0);

  // basic validation
  if ($pname === '' || $ageYears <= 0 || $ageYears > 99) {
    flash('warning', 'Please enter valid patient name and chronological age (years).');
    redirect('consultant/new_assessment.php');
  }
  if (!in_array($gender, ['M', 'F'], true) || !in_array($category, ['CHILD', 'ADULT'], true)) {
    flash('warning', 'Please select valid gender and category.');
    redirect('consultant/new_assessment.php');
  }

  // normalize dates (optional)
  if ($assessDate === '')
    $assessDate = date('Y-m-d');
  if ($dob === '')
    $dob = null;

  // Keep legacy "age" for your current scoring/bracket logic
  $age = $ageYears;

  $br = pick_age_bracket($pdo, $versionId, $age);
  if (($br['id'] ?? 0) <= 0) {
    flash('danger', 'No age bracket configured for this instrument version.');
    redirect('consultant/new_assessment.php');
  }

  // If column exists, store directly. Otherwise store in notes JSON (backward compatible).
  if ($hasReferredByCol) {

    $pdo->prepare("
    INSERT INTO assessments
      (instrument_version_id, adhd_type, category,
       education, contact_no, patient_name, dob,
       age_years, age_months, age_days,
       age, gender, age_bracket_id,
       assessment_date, created_by, created_at,
       referred_by,
       history_text, impression_text, recommendations_text)
    VALUES
      (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
  ")->execute([
          $versionId,
          $adhdType,
          $category,
          ($education !== '' ? $education : null),
          ($contactNo !== '' ? $contactNo : null),
          $pname,
          $dob,
          $ageYears,
          ($ageMonths >= 0 && $ageMonths <= 11 ? $ageMonths : null),
          ($ageDays >= 0 && $ageDays <= 31 ? $ageDays : null),
          $age,
          $gender,
          $br['id'],
          $assessDate,
          $me['id'],
          date('Y-m-d H:i:s'),
          ($referredBy !== '' ? $referredBy : null),
          null,
          null,
          null
        ]);

  } else {

    // store referred_by inside notes JSON so report.php can read it as fallback
    $notes = null;
    if ($referredBy !== '') {
      $notes = json_encode(['referred_by' => $referredBy], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    $pdo->prepare("
    INSERT INTO assessments
      (instrument_version_id, adhd_type, category,
       education, contact_no, patient_name, dob,
       age_years, age_months, age_days,
       age, gender, age_bracket_id,
       assessment_date, created_by, created_at,
       notes,
       history_text, impression_text, recommendations_text)
    VALUES
      (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
  ")->execute([
          $versionId,
          $adhdType,
          $category,
          ($education !== '' ? $education : null),
          ($contactNo !== '' ? $contactNo : null),
          $pname,
          $dob,
          $ageYears,
          ($ageMonths >= 0 && $ageMonths <= 11 ? $ageMonths : null),
          ($ageDays >= 0 && $ageDays <= 31 ? $ageDays : null),
          $age,
          $gender,
          $br['id'],
          $assessDate,
          $me['id'],
          date('Y-m-d H:i:s'),
          $notes,
          null,
          null,
          null
        ]);
  }
  $aid = (int) $pdo->lastInsertId();
  redirect("consultant/omr.php?id=" . $aid);
}

include __DIR__ . '/../partials/header.php';
?>

<style>
  :root {
    --dsrs-ink: #0b1220;
    --dsrs-muted: #667085;
    --dsrs-line: #e9edf5;
    --dsrs-card: #ffffff;
    --dsrs-shadow: 0 18px 55px rgba(16, 24, 40, .10);
    --dsrs-shadow2: 0 28px 90px rgba(0, 0, 0, .10);
    --dsrs-radius: 22px;

    --dsrs-orange: #f17252;
    --dsrs-blue: #2b59ff;
    --dsrs-green: #71bf44;
  }

  /* page vibe */
  .na-wrap {
    position: relative;
    max-width: 1100px;
    margin: 0 auto;
  }

  .na-geo {
    position: absolute;
    inset: -120px -60px auto -60px;
    height: 520px;
    pointer-events: none;
    opacity: .95;
    z-index: 0;
  }

  .na-geo:before {
    content: "";
    position: absolute;
    inset: 0;
    background:
      radial-gradient(680px 380px at 18% 25%, rgba(241, 114, 82, .18), transparent 60%),
      radial-gradient(560px 360px at 78% 18%, rgba(43, 89, 255, .16), transparent 62%),
      radial-gradient(560px 380px at 58% 92%, rgba(113, 191, 68, .12), transparent 62%),
      radial-gradient(520px 340px at 105% 35%, rgba(0, 0, 0, .06), transparent 60%);
    filter: blur(0px);
  }

  .na-geo:after {
    content: "";
    position: absolute;
    inset: 0;
    background-image: radial-gradient(rgba(16, 24, 40, .08) 1px, transparent 1px);
    background-size: 18px 18px;
    mask-image: radial-gradient(closest-side, rgba(0, 0, 0, .9), transparent 80%);
    opacity: .45;
  }

  /* top hero */
  .na-hero {
    position: relative;
    z-index: 1;
    border-radius: 26px;
    overflow: hidden;
    border: 1px solid rgba(255, 255, 255, .20);
    background:
      radial-gradient(1100px 600px at 15% 10%, rgba(241, 114, 82, .18), transparent 60%),
      radial-gradient(900px 520px at 92% 22%, rgba(43, 89, 255, .18), transparent 60%),
      radial-gradient(900px 620px at 50% 120%, rgba(113, 191, 68, .12), transparent 55%),
      linear-gradient(135deg, #070a12, #0a1530 55%, #101b3e);
    box-shadow: var(--dsrs-shadow2);
    color: #fff;
  }

  .na-hero-inner {
    padding: 22px 18px;
    position: relative;
  }

  @media(min-width:992px) {
    .na-hero-inner {
      padding: 30px 28px;
    }
  }

  .na-badge {
    display: inline-flex;
    align-items: center;
    gap: 10px;
    padding: 8px 12px;
    border-radius: 999px;
    background: rgba(255, 255, 255, .08);
    border: 1px solid rgba(255, 255, 255, .16);
    backdrop-filter: blur(10px);
    font-weight: 900;
    letter-spacing: .2px;
  }

  .na-dot {
    width: 10px;
    height: 10px;
    border-radius: 999px;
    background: var(--dsrs-orange);
    box-shadow: 0 0 0 7px rgba(241, 114, 82, .14), 0 0 18px rgba(241, 114, 82, .35);
  }

  .na-title {
    margin: 14px 0 6px;
    font-weight: 1000;
    letter-spacing: -.6px;
    line-height: 1.08;
    font-size: clamp(22px, 3.2vw, 34px);
  }

  .na-sub {
    margin: 0;
    color: rgba(255, 255, 255, .78);
    line-height: 1.8;
    font-size: 13.5px;
    max-width: 72ch;
  }

  /* stepper */
  .na-steps {
    margin-top: 14px;
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    align-items: center;
  }

  .na-step {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 12px;
    border-radius: 16px;
    background: rgba(255, 255, 255, .08);
    border: 1px solid rgba(255, 255, 255, .14);
    backdrop-filter: blur(10px);
  }

  .na-step .n {
    width: 28px;
    height: 28px;
    border-radius: 12px;
    display: grid;
    place-items: center;
    font-weight: 1000;
    color: #fff;
    background: rgba(255, 255, 255, .12);
    border: 1px solid rgba(255, 255, 255, .16);
  }

  .na-step .t {
    font-weight: 950;
    font-size: 13px;
  }

  .na-step .d {
    font-size: 12px;
    color: rgba(255, 255, 255, .72);
    margin: 0;
  }

  /* cards */
  .na-card {
    background: var(--dsrs-card);
    border: 1px solid var(--dsrs-line);
    border-radius: var(--dsrs-radius);
    box-shadow: var(--dsrs-shadow);
    overflow: hidden;
    position: relative;
    z-index: 1;
  }

  .na-card-head {
    padding: 16px 16px;
    border-bottom: 1px solid #eef1f7;
    background: linear-gradient(180deg, #ffffff, #fbfcff);
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 10px;
  }

  .na-card-head h5 {
    margin: 0;
    font-weight: 1000;
    letter-spacing: -.2px;
  }

  .na-card-head .small {
    color: var(--dsrs-muted);
    line-height: 1.6;
  }

  .na-chip {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 8px 11px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 900;
    background: #f4f6ff;
    border: 1px solid #e9edf5;
    white-space: nowrap;
  }

  /* form polish */
  .na-body {
    padding: 16px;
  }

  .na-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 12px;
  }

  @media(min-width:768px) {
    .na-grid {
      grid-template-columns: 1fr 1fr;
    }
  }

  .na-field {
    position: relative;
  }

  .na-field .form-control,
  .na-field .form-select {
    border-radius: 16px;
    padding: 14px 12px;
    border: 1px solid #e6eaf2;
    background: linear-gradient(180deg, #ffffff, #fbfcff);
    box-shadow: 0 10px 22px rgba(16, 24, 40, .04);
    transition: box-shadow .14s ease, transform .14s ease, border-color .14s ease;
  }

  .na-field .form-control:focus,
  .na-field .form-select:focus {
    border-color: rgba(43, 89, 255, .35);
    box-shadow: 0 18px 40px rgba(43, 89, 255, .10);
  }

  .na-field label {
    font-weight: 900;
    font-size: 12px;
    color: #111827;
    margin-bottom: 6px;
  }

  .na-help {
    margin-top: 6px;
    color: var(--dsrs-muted);
    font-size: 12.5px;
    line-height: 1.55;
  }

  /* age box */
  .na-age {
    border-radius: 18px;
    border: 1px solid #e9edf5;
    background: linear-gradient(135deg, rgba(241, 114, 82, .08), rgba(43, 89, 255, .06));
    padding: 14px;
  }

  .na-age-title {
    font-weight: 1000;
    margin-bottom: 10px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
  }

  .na-pill {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 7px 10px;
    border-radius: 999px;
    border: 1px solid #e9edf5;
    background: rgba(255, 255, 255, .75);
    font-size: 12px;
    font-weight: 900;
    color: #111827;
    white-space: nowrap;
  }

  /* actions */
  .na-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    align-items: center;
    justify-content: flex-start;
    margin-top: 2px;
  }

  .btn-na-primary {
    border-radius: 16px;
    padding: 11px 14px;
    font-weight: 950;
    border: 0;
    color: #fff;
    background: linear-gradient(135deg, var(--dsrs-orange), #ff8a6f);
    box-shadow: 0 18px 45px rgba(241, 114, 82, .25);
    transition: transform .14s ease, filter .14s ease, box-shadow .14s ease;
    display: inline-flex;
    align-items: center;
    gap: 10px;
  }

  .btn-na-primary:hover {
    transform: translateY(-1px);
    filter: brightness(.98);
  }

  .btn-na-primary:active {
    transform: translateY(0);
  }

  .btn-na-ghost {
    border-radius: 16px;
    padding: 11px 14px;
    font-weight: 900;
    background: #fff;
    border: 1px solid #e9edf5;
    box-shadow: 0 10px 22px rgba(16, 24, 40, .06);
  }

  /* subtle entrance */
  @media (prefers-reduced-motion: no-preference) {
    .pop {
      animation: pop .5s ease both;
    }

    .pop2 {
      animation: pop .65s ease both;
    }

    @keyframes pop {
      from {
        opacity: 0;
        transform: translateY(10px) scale(.99);
      }

      to {
        opacity: 1;
        transform: translateY(0) scale(1);
      }
    }
  }
</style>

<div class="na-wrap">
  <div class="na-geo"></div>

  <!-- HERO -->
  <div class="na-hero pop mb-3">
    <div class="na-hero-inner">
      <div class="d-flex flex-wrap align-items-start justify-content-between gap-2">
        <div>
          <div class="na-badge"><span class="na-dot"></span> BPCL DSRS • Assessment Setup</div>
          <div class="na-title">New ADHD Assessment</div>
          <p class="na-sub">
            Enter patient details. The system will auto-pick the correct age bracket and rating sheet by gender.
          </p>
        </div>

        <div class="na-steps">
          <div class="na-step">
            <div class="n">1</div>
            <div>
              <div class="t">Create</div>
              <p class="d">patient profile</p>
            </div>
          </div>
          <div class="na-step">
            <div class="n">2</div>
            <div>
              <div class="t">OMR</div>
              <p class="d">27 questions</p>
            </div>
          </div>
          <div class="na-step">
            <div class="n">3</div>
            <div>
              <div class="t">Report</div>
              <p class="d">print-ready</p>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- FORM CARD -->
  <div class="row justify-content-center pop2">
    <div class="col-lg-10">
      <div class="na-card">
        <div class="na-card-head">
          <div>
            <h5>Patient Information</h5>
            <div class="small">All fields remain exactly the same — only UI has been upgraded.</div>
          </div>
          <div class="na-chip">⚙ Auto: Age Bracket + Gender Sheet</div>
        </div>

        <div class="na-body">
          <form method="post">
            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

            <div class="na-grid">
              <div class="na-field">
                <label class="form-label">ADHD Type</label>
                <input class="form-control" name="adhd_type" value="General"
                  placeholder="e.g., Combined / Inattentive / Hyperactive">
                <div class="na-help">Optional — keep <b>General</b> if unsure.</div>
              </div>

              <div class="na-field">
                <label class="form-label">Date of Assessment</label>
                <input class="form-control" type="date" name="assessment_date" value="<?= e(date('Y-m-d')) ?>">
                <div class="na-help">Defaults to today if left empty (system rule unchanged).</div>
              </div>

              <div class="na-field">
                <label class="form-label">Category</label>
                <select class="form-select" name="category">
                  <option value="CHILD" selected>Child Patient</option>
                  <option value="ADULT">Adult Patient</option>
                </select>
                <div class="na-help">Used for record context (no scoring change).</div>
              </div>

              <div class="na-field">
                <label class="form-label">Gender</label>
                <select class="form-select" name="gender">
                  <option value="M" selected>Male</option>
                  <option value="F">Female</option>
                </select>
                <div class="na-help">Gender decides the rating sheet (as before).</div>
              </div>

              <div class="na-field">
                <label class="form-label">Name</label>
                <input class="form-control" name="patient_name" required placeholder="Patient full name">
              </div>

              <div class="na-field">
                <label class="form-label">Date of Birth</label>
                <input class="form-control" type="date" name="dob">
                <div class="na-help">Optional — age bracket currently uses <b>Years</b> (unchanged).</div>
              </div>

              <div class="na-field">
                <label class="form-label">Education</label>
                <input class="form-control" name="education" placeholder="e.g., Class III">
              </div>

              <div class="na-field">
                <label class="form-label">Contact No</label>
                <input class="form-control" name="contact_no" placeholder="e.g., 01XXXXXXXXX">
              </div>
            </div>
            <div class="na-field">
              <label class="form-label">Referred By</label>
              <input class="form-control" name="referred_by" placeholder="e.g., Dr. Name / Self / School / Relative">
              <div class="na-help">Optional — will appear in the Report.</div>
            </div>

            <div class="na-age mt-3">
              <div class="na-age-title">
                <div>Chronological Age</div>
                <span class="na-pill">Used for bracket: <b>Years</b></span>
              </div>

              <div class="row g-2">
                <div class="col-md-4 na-field">
                  <label class="form-label">Years</label>
                  <input class="form-control" type="number" name="age_years" min="1" max="99" required
                    placeholder="Years">
                </div>
                <div class="col-md-4 na-field">
                  <label class="form-label">Months</label>
                  <input class="form-control" type="number" name="age_months" min="0" max="11" placeholder="Months">
                </div>
                <div class="col-md-4 na-field">
                  <label class="form-label">Days</label>
                  <input class="form-control" type="number" name="age_days" min="0" max="31" placeholder="Days">
                </div>
              </div>

              <div class="na-help mt-2">
                Tip: Fill months/days for documentation. Scoring/bracket selection logic remains the same.
              </div>
            </div>

            <div class="na-actions mt-3">
              <button class="btn-na-primary" type="submit">
                <span
                  style="display:inline-grid;place-items:center;width:22px;height:22px;border-radius:8px;background:rgba(255,255,255,.18)">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none">
                    <path d="M9 18l6-6-6-6" stroke="white" stroke-width="2" stroke-linecap="round"
                      stroke-linejoin="round" />
                  </svg>
                </span>
                Continue to Questionnaires
              </button>

              <a class="btn btn-na-ghost" href="<?= e(BASE_URL) ?>/index.php">Back</a>
            </div>

          </form>
        </div>
      </div>
    </div>
  </div>

</div>

<script>
  (() => {
    const ageYearsInput = document.querySelector('input[name="age_years"]');
    const ageMonthsInput = document.querySelector('input[name="age_months"]');
    const ageDaysInput = document.querySelector('input[name="age_days"]');
    const dobInput = document.querySelector('input[name="dob"]');
    const assessmentDateInput = document.querySelector('input[name="assessment_date"]');

    if (!ageYearsInput || !dobInput) return;

    const toInt = (v) => {
      const n = parseInt(v, 10);
      return Number.isFinite(n) ? n : 0;
    };

    const formatDate = (dateObj) => {
      const y = dateObj.getFullYear();
      const m = String(dateObj.getMonth() + 1).padStart(2, '0');
      const d = String(dateObj.getDate()).padStart(2, '0');
      return `${y}-${m}-${d}`;
    };

    const getBaseDate = () => {
      if (assessmentDateInput && assessmentDateInput.value) {
        const fromAssessment = new Date(`${assessmentDateInput.value}T00:00:00`);
        if (!Number.isNaN(fromAssessment.getTime())) return fromAssessment;
      }
      const now = new Date();
      return new Date(now.getFullYear(), now.getMonth(), now.getDate());
    };

    const syncDobFromAge = () => {
      const years = Math.max(0, toInt(ageYearsInput.value));
      const months = Math.max(0, toInt(ageMonthsInput?.value ?? '0'));
      const days = Math.max(0, toInt(ageDaysInput?.value ?? '0'));

      if (years <= 0) return;

      const base = getBaseDate();
      const dob = new Date(base.getTime());
      dob.setFullYear(dob.getFullYear() - years);
      dob.setMonth(dob.getMonth() - months);
      dob.setDate(dob.getDate() - days);

      if (!Number.isNaN(dob.getTime())) {
        dobInput.value = formatDate(dob);
      }
    };

    ['input', 'change'].forEach((evtName) => {
      ageYearsInput.addEventListener(evtName, syncDobFromAge);
      ageMonthsInput?.addEventListener(evtName, syncDobFromAge);
      ageDaysInput?.addEventListener(evtName, syncDobFromAge);
      assessmentDateInput?.addEventListener(evtName, syncDobFromAge);
    });
  })();
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>