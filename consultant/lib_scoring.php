<?php
require_once __DIR__ . '/../db.php';

function get_adhd_version_id(PDO $pdo): int {
  $st = $pdo->prepare("
    SELECT iv.id
    FROM instrument_versions iv
    JOIN instruments i ON i.id = iv.instrument_id
    WHERE i.code = ? AND iv.active = 1
    ORDER BY iv.id DESC
    LIMIT 1
  ");
  $st->execute(['ADHD_CONNERS']);
  $r = $st->fetch();
  return (int)($r['id'] ?? 0);
}

function pick_age_bracket(PDO $pdo, int $versionId, int $age): array {
  $st = $pdo->prepare("SELECT id,label,min_age,max_age
                       FROM age_brackets
                       WHERE instrument_version_id=?
                         AND ? BETWEEN min_age AND max_age
                       ORDER BY sort_order ASC LIMIT 1");
  $st->execute([$versionId, $age]);
  $r = $st->fetch();
  if (!$r) {
    $st2 = $pdo->prepare("SELECT id,label,min_age,max_age
                          FROM age_brackets
                          WHERE instrument_version_id=?
                          ORDER BY ABS(?-min_age) ASC, sort_order ASC LIMIT 1");
    $st2->execute([$versionId, $age]);
    $r = $st2->fetch();
  }
  return [
    'id' => (int)($r['id'] ?? 0),
    'label' => (string)($r['label'] ?? 'Unknown'),
  ];
}

function subscale_map(): array {
  return [
    'OPPOSITIONAL'   => [2,6,11,16,20,24],
    'COGNITION'      => [3,8,12,17,21,25],
    'HYPERACTIVITY'  => [4,9,14,18,22,26],
    'ADHD_INDEX'     => [1,5,7,10,13,15,17,19,21,23,25,27],
  ];
}

/**
 * Compute raw totals per subscale from answers (q_no => value).
 */
function compute_raw_totals(array $answers): array {
  $map = subscale_map();
  $totals = [];
  foreach ($map as $sub => $qs) {
    $sum = 0;
    foreach ($qs as $q) {
      $sum += (int)($answers[$q] ?? 0);
    }
    $totals[$sub] = $sum;
  }
  return $totals;
}

function lookup_t_score(PDO $pdo, int $versionId, string $gender, int $ageBracketId, string $subscale, int $rawTotal): int {
  $sql = "SELECT rtr.t_score
          FROM rating_table_rows rtr
          JOIN rating_tables rt ON rt.id = rtr.rating_table_id
          WHERE rt.instrument_version_id = ?
            AND rt.gender = ?
            AND rt.age_bracket_id = ?
            AND rtr.subscale = ?
            AND rtr.raw_total = ?
          LIMIT 1";
  $st = $pdo->prepare($sql);
  $st->execute([$versionId, $gender, $ageBracketId, $subscale, $rawTotal]);
  $r = $st->fetch();
  if ($r) return (int)$r['t_score'];
  return 90; // fallback
}

function interpret_t(PDO $pdo, int $versionId, int $t): array {
  $sql = "SELECT percentile_label, guideline
          FROM interpretation_rules
          WHERE instrument_version_id=?
            AND (t_min IS NULL OR ? >= t_min)
            AND (t_max IS NULL OR ? <= t_max)
          ORDER BY sort_order ASC
          LIMIT 1";
  $st = $pdo->prepare($sql);
  $st->execute([$versionId, $t, $t]);
  $r = $st->fetch();
  return [
    'percentile_label' => (string)($r['percentile_label'] ?? ''),
    'guideline'        => (string)($r['guideline'] ?? ''),
  ];
}

function generate_assessment_scores(PDO $pdo, int $assessmentId): void {

  // Load assessment core info
  $st = $pdo->prepare("SELECT id, instrument_version_id, gender, age_bracket_id
                       FROM assessments
                       WHERE id=? LIMIT 1");
  $st->execute([$assessmentId]);
  $ass = $st->fetch(PDO::FETCH_ASSOC);
  if (!$ass) {
    throw new RuntimeException("Assessment not found.");
  }

  $versionId    = (int)($ass['instrument_version_id'] ?? 0);
  $gender       = (string)($ass['gender'] ?? 'M');
  $ageBracketId = (int)($ass['age_bracket_id'] ?? 0);

  if ($versionId <= 0 || $ageBracketId <= 0) {
    throw new RuntimeException("Assessment missing instrument_version_id or age_bracket_id.");
  }
  if (!in_array($gender, ['M','F'], true)) $gender = 'M';

  // Load answers (1..27)
  $answers = [];
  $stA = $pdo->prepare("SELECT q_no, value FROM assessment_answers WHERE assessment_id=?");
  $stA->execute([$assessmentId]);
  foreach ($stA->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $answers[(int)$r['q_no']] = (int)$r['value'];
  }

  for ($q=1; $q<=27; $q++) {
    if (!array_key_exists($q, $answers)) {
      throw new RuntimeException("OMR incomplete. Missing Q{$q}.");
    }
  }


  $totals = compute_raw_totals($answers);

  $pdo->beginTransaction();

  $pdo->prepare("DELETE FROM assessment_scores WHERE assessment_id=?")->execute([$assessmentId]);

  $ins = $pdo->prepare("
    INSERT INTO assessment_scores
      (assessment_id, subscale, raw_total, t_score, percentile_label, guideline)
    VALUES
      (?,?,?,?,?,?)
  ");

  foreach ($totals as $subscale => $rawTotal) {
    $t = lookup_t_score($pdo, $versionId, $gender, $ageBracketId, $subscale, (int)$rawTotal);
    $interp = interpret_t($pdo, $versionId, (int)$t);

    $ins->execute([
      $assessmentId,
      $subscale,
      (int)$rawTotal,
      (int)$t,
      (string)($interp['percentile_label'] ?? ''),
      (string)($interp['guideline'] ?? ''),
    ]);
  }

  $pdo->commit();
}