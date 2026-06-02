<?php
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../db.php';

$me = require_role('CONSULTANT', 'ADMIN');
$pdo = db();

$q = trim((string) ($_GET['q'] ?? ''));
$isAdmin = in_array(($me['role'] ?? ''), ['ADMIN', 'SUPER_ADMIN'], true);

$cols = [];
try {
  $cols = $pdo->query("SHOW COLUMNS FROM assessments")->fetchAll(PDO::FETCH_COLUMN, 0);
} catch (Throwable $e) {
  $cols = [];
}
$hasHistoryCol = in_array('history_text', $cols, true);
$hasImpCol = in_array('impression_text', $cols, true);
$hasRecCol = in_array('recommendations_text', $cols, true);

$selHistory = $hasHistoryCol ? "a.history_text" : "NULL AS history_text";
$selImp = $hasImpCol ? "a.impression_text" : "NULL AS impression_text";
$selRec = $hasRecCol ? "a.recommendations_text" : "NULL AS recommendations_text";


$where = $isAdmin ? "1=1" : "a.created_by = ?";
$sql = "
  SELECT
    a.id,
    a.patient_name,
    a.age,
    a.gender,
    a.category,
    a.notes,
    {$selHistory},
    {$selImp},
    {$selRec},
    a.created_at,
    ab.label AS age_label,
    COALESCE(sc.score_count, 0) AS score_count
  FROM assessments a
  LEFT JOIN age_brackets ab ON ab.id = a.age_bracket_id
  LEFT JOIN (
    SELECT assessment_id, COUNT(*) AS score_count
    FROM assessment_scores
    GROUP BY assessment_id
  ) sc ON sc.assessment_id = a.id
  WHERE {$where}
  " . ($q !== '' ? " AND a.patient_name LIKE ? " : "") . "
  ORDER BY a.id DESC
  LIMIT 200
";
$st = $pdo->prepare($sql);

$params = [];
if (!$isAdmin)
  $params[] = $me['id'];
if ($q !== '')
  $params[] = "%{$q}%";

$st->execute($params);
$rows = $st->fetchAll();

function norm_preview(string $t, int $max = 90): string
{
  $t = preg_replace("/\s+/", " ", $t);
  $t = trim($t);
  if ($t === '')
    return '';
  return mb_substr($t, 0, $max) . (mb_strlen($t) > $max ? "…" : "");
}

function notes_state(array $r): array
{
  $history = trim((string) ($r['history_text'] ?? ''));
  $imp = trim((string) ($r['impression_text'] ?? ''));
  $rec = trim((string) ($r['recommendations_text'] ?? ''));
  $raw = trim((string) ($r['notes'] ?? ''));
  if ($raw !== '') {
    $j = json_decode($raw, true);
    if (is_array($j)) {
      if ($history === '')
        $history = trim((string) ($j['history'] ?? ''));
      if ($imp === '')
        $imp = trim((string) ($j['impression'] ?? ''));
      if ($rec === '')
        $rec = trim((string) ($j['recommendations'] ?? ''));
    } else {
      if ($imp === '')
        $imp = $raw;
    }
  }

  $has = ($history !== '' || $imp !== '' || $rec !== '');

  $pv = $imp !== '' ? $imp : ($rec !== '' ? $rec : $history);

  return [
    'has' => $has,
    'history' => $history,
    'impression' => $imp,
    'recommendations' => $rec,
    'preview' => norm_preview($pv, 88),
  ];
}

include __DIR__ . '/../partials/header.php';
?>

<style>
  :root {
    --hx-ink: #0b1220;
    --hx-muted: #6b7280;
    --hx-line: #e9edf5;
    --hx-card: #ffffff;

    --hx-orange: #f17252;
    --hx-blue: #2b59ff;
    --hx-green: #71bf44;

    --hx-shadow: 0 18px 55px rgba(16, 24, 40, .08);
  }

  .hx-shell {
    max-width: 1200px;
    margin: 0 auto;
    position: relative;
  }

  .hx-geo {
    position: absolute;
    inset: -120px -40px auto -40px;
    height: 360px;
    pointer-events: none;
    z-index: 0;
    opacity: .95;
  }

  .hx-geo:before {
    content: "";
    position: absolute;
    inset: 0;
    background:
      radial-gradient(620px 320px at 12% 20%, rgba(241, 114, 82, .14), transparent 62%),
      radial-gradient(620px 320px at 92% 18%, rgba(43, 89, 255, .14), transparent 62%),
      radial-gradient(520px 320px at 58% 120%, rgba(113, 191, 68, .10), transparent 60%);
  }

  .hx-geo:after {
    content: "";
    position: absolute;
    inset: 0;
    background-image: radial-gradient(rgba(16, 24, 40, .08) 1px, transparent 1px);
    background-size: 18px 18px;
    mask-image: radial-gradient(closest-side, rgba(0, 0, 0, .85), transparent 78%);
    opacity: .35;
  }

  .hx-card {
    position: relative;
    z-index: 1;
    background: var(--hx-card);
    border: 1px solid var(--hx-line);
    border-radius: 22px;
    overflow: hidden;
    box-shadow: var(--hx-shadow);
  }

  .hx-hero {
    padding: 18px 18px 14px;
    border-bottom: 1px solid rgba(233, 237, 245, .9);
    background:
      radial-gradient(900px 520px at 15% 0%, rgba(241, 114, 82, .08), transparent 60%),
      radial-gradient(900px 520px at 92% 10%, rgba(43, 89, 255, .08), transparent 62%),
      linear-gradient(180deg, #ffffff, #fbfcff);
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 14px;
    flex-wrap: wrap;
  }

  .hx-title {
    margin: 0;
    font-weight: 1000;
    letter-spacing: -.4px;
    color: #111827;
    font-size: clamp(18px, 2.2vw, 22px);
    line-height: 1.1;
  }

  .hx-sub {
    margin: 6px 0 0;
    color: var(--hx-muted);
    font-size: 13px;
    line-height: 1.7;
  }

  .hx-badges {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 10px;
  }

  .hx-badge {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 8px 10px;
    border-radius: 999px;
    border: 1px solid rgba(233, 237, 245, .95);
    background: #fff;
    box-shadow: 0 10px 24px rgba(16, 24, 40, .06);
    font-weight: 900;
    font-size: 12px;
    color: #111827;
  }

  .hx-actions {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    justify-content: flex-end;
    align-items: center;
  }

  .hx-body {
    padding: 16px 18px 18px;
  }

  @media(min-width:992px) {
    .hx-body {
      padding: 18px 22px 20px;
    }
  }

  .hx-search {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 12px;
  }

  .hx-search .input-group {
    max-width: 520px;
    border-radius: 16px;
    overflow: hidden;
    box-shadow: 0 14px 40px rgba(16, 24, 40, .06);
    border: 1px solid rgba(233, 237, 245, .95);
  }

  .hx-search .form-control {
    border: 0 !important;
  }

  .hx-search .form-control:focus {
    box-shadow: none;
  }

  .hx-search .btn {
    border: 0 !important;
    font-weight: 900;
  }

  .hx-search-meta {
    font-size: 12px;
    color: var(--hx-muted);
    font-weight: 700;
  }

  .hx-table {
    width: 100%;
    border: 1px solid rgba(233, 237, 245, .95);
    border-radius: 18px;
    overflow: hidden;
    border-collapse: separate;
    border-spacing: 0;
    background: #fff;
    box-shadow: 0 16px 46px rgba(16, 24, 40, .06);
  }

  .hx-table thead th {
    background: linear-gradient(180deg, #f7f9ff, #ffffff);
    font-weight: 1000;
    font-size: 13px;
    padding: 11px 12px;
    border-bottom: 1px solid rgba(233, 237, 245, .95);
    text-align: left;
    color: #111827;
    white-space: nowrap;
  }

  .hx-table tbody td {
    padding: 12px 12px;
    border-bottom: 1px solid rgba(233, 237, 245, .95);
    font-size: 13.5px;
    vertical-align: middle;
    color: #111827;
  }

  .hx-table tbody tr:last-child td {
    border-bottom: 0;
  }

  .hx-table tbody tr {
    transition: transform .12s ease, background .12s ease;
  }

  .hx-table tbody tr:hover {
    background: rgba(43, 89, 255, .035);
    transform: translateY(-1px);
  }

  .hx-patient {
    font-weight: 950;
  }

  .hx-meta {
    color: var(--hx-muted);
    font-size: 12px;
    margin-top: 2px;
  }

  .hx-pill {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 7px 10px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 900;
    border: 1px solid rgba(233, 237, 245, .95);
    background: #fff;
    white-space: nowrap;
  }

  .hx-pill.ok {
    background: rgba(34, 197, 94, .10);
    border-color: rgba(34, 197, 94, .22);
    color: #065f46;
  }

  .hx-pill.wait {
    background: rgba(241, 114, 82, .10);
    border-color: rgba(241, 114, 82, .22);
    color: #9a3412;
  }

  .hx-pill.ready {
    background: rgba(43, 89, 255, .10);
    border-color: rgba(43, 89, 255, .22);
    color: #1d4ed8;
  }

  .hx-pill.pending {
    background: rgba(148, 163, 184, .14);
    border-color: rgba(148, 163, 184, .25);
    color: #334155;
  }

  .hx-notes {
    min-width: 260px;
    max-width: 520px;
  }

  .hx-preview {
    display: block;
    margin-top: 6px;
    font-size: 12px;
    color: var(--hx-muted);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  .hx-btn {
    border-radius: 14px !important;
    font-weight: 900 !important;
    box-shadow: 0 10px 22px rgba(16, 24, 40, .06);
  }

  .hx-btn-primary {
    border: 0 !important;
    color: #fff !important;
    background: linear-gradient(135deg, var(--hx-orange), #ff8a6f) !important;
    box-shadow: 0 18px 45px rgba(241, 114, 82, .22) !important;
  }

  @media (max-width: 768px) {
    .hx-notes {
      min-width: 200px;
      max-width: 240px;
    }
  }
</style>

<div class="hx-shell">
  <div class="hx-geo"></div>

  <div class="hx-card">
    <div class="hx-hero">
      <div class="min-w-0">
        <h4 class="hx-title">Assessment History</h4>
        <div class="hx-sub">Your latest 200 assessments • cleaner overview • report readiness + notes status</div>

        <div class="hx-badges">
          <span class="hx-badge"><i class="bi bi-person-badge"></i> Signed in:
            <b><?= e($me['name'] ?? 'User') ?></b></span>
          <span class="hx-badge"><i class="bi bi-list-check"></i> Total shown: <b><?= (int) count($rows) ?></b></span>
          <span class="hx-badge"><i class="bi bi-search"></i> Search: <b><?= e($q === '' ? 'All' : $q) ?></b></span>
        </div>
      </div>

      <div class="hx-actions">
        <a class="btn hx-btn hx-btn-primary" href="<?= e(BASE_URL) ?>/consultant/new_assessment.php">
          <i class="bi bi-plus-circle"></i> New Assessment
        </a>
      </div>
    </div>

    <div class="hx-body">
      <form class="hx-search" method="get">
        <div class="input-group">
          <span class="input-group-text bg-white border-0"><i class="bi bi-search"></i></span>
          <input id="historySearch" class="form-control" name="q" value="<?= e($q) ?>"
            placeholder="Type to search (name, id, gender, category, notes)..." autocomplete="off"
            list="historySearchHints">
          <datalist id="historySearchHints"></datalist>
          <button class="btn btn-outline-secondary" type="submit">Search</button>
        </div>
        <div id="historySearchMeta" class="hx-search-meta">Live search is enabled (word matching).</div>
      </form>

      <div class="table-responsive">
        <table class="hx-table table align-middle mb-0">
          <thead>
            <tr>
              <th style="width:70px;">ID</th>
              <th>Patient</th>
              <th style="width:140px;">Age</th>
              <th style="width:90px;">Gender</th>
              <th style="width:130px;">Assessment</th>
              <th style="width:170px;">Created</th>
              <th style="width:220px;">Status</th>
              <th style="width:260px;">Notes</th>
              <th style="width:90px;"></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $r): ?>
              <?php
              $ns = notes_state($r);
              $scoreCount = (int) ($r['score_count'] ?? 0);
              ?>
              <?php
              $rowSearch = mb_strtolower(trim(implode(' ', [
                (string) ($r['id'] ?? ''),
                (string) ($r['patient_name'] ?? ''),
                (string) ($r['age'] ?? ''),
                (string) ($r['gender'] ?? ''),
                (string) ($r['category'] ?? ''),
                (string) ($r['age_label'] ?? ''),
                (string) ($r['created_at'] ?? ''),
                (string) ($ns['history'] ?? ''),
                (string) ($ns['impression'] ?? ''),
                (string) ($ns['recommendations'] ?? ''),
                (string) ($ns['preview'] ?? ''),
              ])));
              ?>
              <tr class="hx-row" data-search="<?= e($rowSearch) ?>">
                <td class="fw-bold"><?= (int) $r['id'] ?></td>

                <td>
                  <div class="hx-patient"><?= e($r['patient_name']) ?></div>
                  <div class="hx-meta">Category: <?= e($r['category']) ?></div>
                </td>

                <td>
                  <?= (int) $r['age'] ?>
                  <?php if (!empty($r['age_label'])): ?>
                    <span class="hx-meta">(<?= e($r['age_label']) ?>)</span>
                  <?php endif; ?>
                </td>

                <td><?= e($r['gender']) ?></td>

                <td>
                  <div class="fw-bold">ADHD</div>
                  <div class="hx-meta">Conners’ PRS</div>
                </td>

                <td><?= e($r['created_at']) ?></td>

                <td>
                  <?php if ($scoreCount > 0): ?>
                    <span class="hx-pill ready"><i class="bi bi-file-earmark-check"></i> Report Ready</span>
                  <?php else: ?>
                    <span class="hx-pill pending"><i class="bi bi-hourglass-split"></i> Pending Scores</span>
                  <?php endif; ?>
                </td>

                <td class="hx-notes">
                  <?php if ($ns['has']): ?>
                    <!-- ✅ EXACT wording requested -->
                    <span class="hx-pill ok"><i class="bi bi-check2-circle"></i> Impression/Recommendations Added</span>
                    <?php if ($ns['preview'] !== ''): ?>
                      <span class="hx-preview"><?= e($ns['preview']) ?></span>
                    <?php endif; ?>
                  <?php else: ?>
                    <span class="hx-pill wait"><i class="bi bi-pencil-square"></i> Not Written Yet</span>
                    <span class="hx-preview">History / Impression / Recommendations not written yet</span>
                  <?php endif; ?>
                </td>

                <td class="text-end">
                  <a class="btn btn-outline-secondary btn-sm hx-btn"
                    href="<?= e(BASE_URL) ?>/consultant/report.php?id=<?= (int) $r['id'] ?>">
                    Report
                  </a>
                </td>
              </tr>
            <?php endforeach; ?>

            <?php if (!$rows): ?>
              <tr id="noDataRow">
                <td colspan="9" class="text-center text-muted py-4">No assessments yet.</td>
              </tr>
            <?php endif; ?>
            <tr id="noMatchRow" style="display:none;">
              <td colspan="9" class="text-center text-muted py-4">No match found for your search.</td>
            </tr>
          </tbody>
        </table>
      </div>

    </div>
  </div>
</div>

<script>
  (function () {
    const searchInput = document.getElementById('historySearch');
    const hintList = document.getElementById('historySearchHints');
    const meta = document.getElementById('historySearchMeta');
    const rows = Array.from(document.querySelectorAll('tr.hx-row'));
    const noMatchRow = document.getElementById('noMatchRow');

    if (!searchInput || !rows.length) {
      return;
    }

    const tokenize = (value) => value
      .toLowerCase()
      .trim()
      .split(/\s+/)
      .filter(Boolean);

    const updateHints = (query) => {
      if (!hintList) return;

      const tokens = tokenize(query);
      const startsWithMatches = [];
      const containsMatches = [];
      const seen = new Set();

      for (const row of rows) {
        const text = row.dataset.search || '';
        const nameCell = row.querySelector('.hx-patient');
        const name = nameCell ? nameCell.textContent.trim() : '';
        if (!name || seen.has(name.toLowerCase())) continue;

        const lowered = name.toLowerCase();
        if (tokens.length === 0 || tokens.every(t => lowered.includes(t) || text.includes(t))) {
          if (lowered.startsWith((tokens[0] || '').toLowerCase())) {
            startsWithMatches.push(name);
          } else {
            containsMatches.push(name);
          }
          seen.add(lowered);
        }
      }

      const picks = startsWithMatches.concat(containsMatches).slice(0, 12);
      hintList.innerHTML = picks.map(v => '<option value="' + v.replace(/"/g, '&quot;') + '"></option>').join('');
    };

    const applyFilter = () => {
      const query = searchInput.value || '';
      const terms = tokenize(query);
      let visible = 0;

      rows.forEach((row) => {
        const hay = row.dataset.search || '';
        const matched = terms.every(term => hay.includes(term));
        row.style.display = matched ? '' : 'none';
        if (matched) visible++;
      });

      if (noMatchRow) {
        noMatchRow.style.display = visible === 0 ? '' : 'none';
      }

      if (meta) {
        meta.textContent = visible + ' result' + (visible === 1 ? '' : 's') + ' shown';
      }

      updateHints(query);
    };

    searchInput.addEventListener('input', applyFilter);
    applyFilter();
  })();
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>
