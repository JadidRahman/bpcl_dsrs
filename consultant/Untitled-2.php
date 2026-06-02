<?php

$autoloadCandidates = [
  __DIR__ . '/../vendor/autoload.php',
  __DIR__ . '/../../vendor/autoload.php',
  dirname(__DIR__) . '/vendor/autoload.php'
];

$autoload = null;
foreach ($autoloadCandidates as $p) {
  if (is_file($p)) {
    $autoload = $p;
    break;
  }
}

if (!$autoload) {
  http_response_code(500);
  exit("Composer autoload not found. Expected vendor/autoload.php. Install PhpWord: composer require phpoffice/phpword");
}
require_once $autoload;

use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\IOFactory;

require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../db.php';

$me  = require_role('CONSULTANT', 'ADMIN');
$pdo = db();

$aid = (int)($_GET['id'] ?? 0);
if ($aid <= 0) {
  redirect('index.php');
}

/* =========================
   Load assessment
========================= */
$st = $pdo->prepare("
  SELECT a.*, ab.label AS age_label
  FROM assessments a
  LEFT JOIN age_brackets ab ON ab.id = a.age_bracket_id
  WHERE a.id=? LIMIT 1
");
$st->execute([$aid]);
$ass = $st->fetch();
if (!$ass) {
  flash('danger', 'Assessment not found.');
  redirect('index.php');
}

/* =========================
   Load scores
========================= */
$st2 = $pdo->prepare("
  SELECT subscale, raw_total, t_score, percentile_label, guideline
  FROM assessment_scores
  WHERE assessment_id=?
  ORDER BY FIELD(subscale,'OPPOSITIONAL','COGNITION','HYPERACTIVITY','ADHD_INDEX')
");
$st2->execute([$aid]);
$scores = $st2->fetchAll();

$labels = [
  'OPPOSITIONAL'    => 'Oppositional',
  'COGNITION'       => 'Cognition',
  'HYPERACTIVITY'   => 'Hyperactivity',
  'ADHD_INDEX'      => 'ADHD Index',
];

/* =========================
   Helpers: parse dates safely
   Accepts: Y-m-d, d.m.Y, d-m-Y
========================= */
function parse_date_any($s): ?DateTime {
  $s = trim((string)$s);
  if ($s === '' || $s === '0000-00-00') return null;

  $fmts = ['Y-m-d', 'd.m.Y', 'd-m-Y'];
  foreach ($fmts as $f) {
    $dt = DateTime::createFromFormat($f, $s);
    if ($dt && $dt->format($f) === $s) return $dt;
  }

  $ts = strtotime($s);
  if ($ts !== false) {
    $dt = new DateTime();
    $dt->setTimestamp($ts);
    return $dt;
  }
  return null;
}
function fmt_dmY(?DateTime $dt): string {
  return $dt ? $dt->format('d.m.Y') : '-';
}

/* =========================
   Text fields (NEW DB columns)
   + Backward compatibility with old notes JSON
========================= */
$hasHistoryCol    = array_key_exists('history_text', $ass);
$hasReferredByCol = array_key_exists('referred_by', $ass);
$referred_by      = trim((string)($hasReferredByCol ? ($ass['referred_by'] ?? '') : ''));

$history_text         = trim((string)($hasHistoryCol ? ($ass['history_text'] ?? '') : ''));
$impression_text      = trim((string)($ass['impression_text'] ?? ''));
$recommendations_text = trim((string)($ass['recommendations_text'] ?? ''));

if ($history_text === '' && $impression_text === '' && $recommendations_text === '') {
  $rawNotes = (string)($ass['notes'] ?? '');
  if ($rawNotes !== '') {
    $decoded = json_decode($rawNotes, true);
    if (is_array($decoded)) {
      $history_text         = (string)($decoded['history'] ?? $history_text);
      $impression_text      = (string)($decoded['impression'] ?? $impression_text);
      $recommendations_text = (string)($decoded['recommendations'] ?? $recommendations_text);
      $referred_by          = (string)($decoded['referred_by'] ?? $referred_by);
    } else {
      $impression_text = $impression_text ?: $rawNotes;
    }
  }
}

/* =========================
   Display helpers (pattern)
========================= */
$patientName = (string)($ass['patient_name'] ?? '');
$gender      = (string)($ass['gender'] ?? '');
$category    = (string)($ass['category'] ?? '');
$assessedBy  = (string)($me['name'] ?? '');
// ✅ Dynamic report name (uses chosen category like "ADHD(Child)" if present)
$reportName = trim((string)($category ?? ''));
if ($reportName !== '') {
  // Example: "ADHD(Child)" -> "ADHD(Child) Assessment Report"
  $reportTitle = $reportName . " Assessment Report";
} else {
  $reportTitle = "ADHD Assessment Report";
}

$education = (string)($ass['education'] ?? '');
$contactNo = (string)($ass['contact_no'] ?? '');

$genderDisplay = ($gender === 'M') ? 'Male' : (($gender === 'F') ? 'Female' : ($gender ?: '-'));

$assessDt = parse_date_any($ass['assessment_date'] ?? '');
if (!$assessDt) $assessDt = parse_date_any(substr((string)($ass['created_at'] ?? ''), 0, 10));
if (!$assessDt) $assessDt = new DateTime();
$assessDateDisplay = fmt_dmY($assessDt);

$ageYears  = (int)($ass['age_years'] ?? 0);
$ageMonths = (int)($ass['age_months'] ?? 0);
$ageDays   = (int)($ass['age_days'] ?? 0);
if ($ageYears <= 0) $ageYears = (int)($ass['age'] ?? 0);
if ($ageYears < 0)  $ageYears = 0;
if ($ageMonths < 0) $ageMonths = 0;
if ($ageDays < 0)   $ageDays = 0;

$dobDt = parse_date_any($ass['dob'] ?? '');
if (!$dobDt && ($ageYears || $ageMonths || $ageDays)) {
  $dobDt = clone $assessDt;
  if ($ageYears)  $dobDt->modify("-{$ageYears} years");
  if ($ageMonths) $dobDt->modify("-{$ageMonths} months");
  if ($ageDays)   $dobDt->modify("-{$ageDays} days");
}
$dobDisplay = fmt_dmY($dobDt);

/* =========================
   PRINT (PDF) letterhead assets
========================= */
$printFooterLine1 = "Office Address: Shimanto Shambhar Shopping Complex, 6th Floor, Dhanmondi, Road-2, Dhaka-1205, Bangladesh";
$printFooterLine2 = "Phone No: 09604604604, +8801872863002,E-mail: bdpsycare@gmail.com, Web: www.bdpsychiatriccare.com, fb.com/bdpsychiatric.care";

/* Use BASE_URL for absolute path (safer in print) */
$logoFile = 'BPCL Logo.png';
$logoUrl  = rtrim((string)BASE_URL, '/') . '/assets/img/' . rawurlencode($logoFile);

/* Appendix guidelines table */
$appendixGuidelines = [
  ['70+',   '98+',  'Markedly Atypical (Indicate Significant Problem)'],
  ['66-70', '95-98','Moderately Atypical (Indicate Significant Problem)'],
  ['61-65', '86-94','Mildly Atypical (Possible Significant Problem)'],
  ['56-60', '74-85','Slightly Atypical (Borderline; Should Raise Concern)'],
  ['45-55', '27-73','Average (Typical Score; Should not Raise concern)'],
  ['40-44', '16-26','Slightly Atypical (Low Score, not a concern)'],
  ['35-39', '6-15', 'Mildly Atypical (Low Score, Not concern)'],
  ['30-34', '2-5',  'Moderate Atypical (Low Score, Not concern)'],
  ['<30',   '<2',   'Markedly Atypical (Low Score, are good, not concern)'],
];

/* =========================================================
   DOCX builder (shared by download + email prep)
   Keeps the DOCX content identical
========================================================= */
$W = function ($v) { return (string)$v; };

function addJustifiedBlock($section, $text, $spaceAfter = 240) {
  $text = (string)$text;
  $text = str_replace(["\r\n", "\r"], "\n", $text);
  $text = trim($text);
  if ($text === '') $text = '-';

  $p = [
    'alignment'   => \PhpOffice\PhpWord\SimpleType\Jc::BOTH,
    'spaceBefore' => 0,
    'spaceAfter'  => $spaceAfter,
    'lineHeight'  => 1.15,
  ];

  $run   = $section->addTextRun($p);
  $lines = explode("\n", $text);
  $last  = count($lines) - 1;

  foreach ($lines as $i => $line) {
    $line = str_replace("\t", " ", $line);
    $line = preg_replace('/[ ]{2,}/', ' ', $line);
    $line = preg_replace('/([.!?])([A-Za-z])/', '$1 $2', $line);

    $run->addText(rtrim($line));
    if ($i !== $last) $run->addTextBreak(1);
  }
}

function addRecommendationsAsParagraphs($section, $text) {
  $text = (string)$text;
  $text = str_replace(["\r\n", "\r"], "\n", $text);
  $text = trim($text);

  if ($text === '') {
    $section->addText('-', [], ['spaceBefore' => 0, 'spaceAfter' => 240]);
    return;
  }

  $lines = array_values(array_filter(array_map('trim', explode("\n", $text)), fn($v) => $v !== ''));

  $p = [
    'spaceBefore' => 0,
    'spaceAfter'  => 0,
    'lineHeight'  => 1.15,
    'alignment'   => \PhpOffice\PhpWord\SimpleType\Jc::LEFT,
  ];

  foreach ($lines as $line) {
    $line = str_replace("\t", " ", $line);
    $line = preg_replace('/[ ]{2,}/', ' ', $line);
    $line = preg_replace('/([.!?])([A-Za-z])/', '$1 $2', $line);
    $section->addText($line, [], $p);
  }

  $section->addText('', [], ['spaceBefore' => 0, 'spaceAfter' => 240]);
}

function build_report_phpword($ctx) {
  // $ctx keys:
  // logoFsPath, footerLine1, footerLine2, patientName, assessDateDisplay, dobDisplay,
  // ageYears, ageMonths, ageDays, genderDisplay, education, contactNo, referred_by,
  // scores, labels, history_text, impression_text, recommendations_text, assessedBy,
  // appendixGuidelines

  $phpWord = new \PhpOffice\PhpWord\PhpWord();
  $phpWord->setDefaultFontName('Times New Roman');
  $phpWord->setDefaultFontSize(11);

  $C = '\PhpOffice\PhpWord\Shared\Converter';
  \PhpOffice\PhpWord\Settings::setOutputEscapingEnabled(true);

  $section = $phpWord->addSection([
    'marginTop'    => $C::inchToTwip(1.4),
    'marginBottom' => $C::inchToTwip(0.6),
    'marginLeft'   => $C::inchToTwip(0.55),
    'marginRight'  => $C::inchToTwip(0.55),
    'headerHeight' => $C::inchToTwip(0.35),
    'footerHeight' => $C::inchToTwip(0.25),
  ]);

  $header = $section->addHeader();

  $hdrTbl = $header->addTable([
    'borderSize'       => 0,
    'borderColor'      => 'FFFFFF',
    'cellMarginTop'    => 0,
    'cellMarginBottom' => 0,
    'cellMarginLeft'   => 0,
    'cellMarginRight'  => 0,
  ]);

  $hdrTbl->addRow(\PhpOffice\PhpWord\Shared\Converter::inchToTwip(0.75));

  $noCellBorder = [
    'valign'            => 'center',
    'borderTopSize'     => 0,
    'borderLeftSize'    => 0,
    'borderRightSize'   => 0,
    'borderBottomSize'  => 0,
    'borderTopColor'    => 'FFFFFF',
    'borderLeftColor'   => 'FFFFFF',
    'borderRightColor'  => 'FFFFFF',
    'borderBottomColor' => 'FFFFFF',
  ];

  $cellLogo = $hdrTbl->addCell(\PhpOffice\PhpWord\Shared\Converter::inchToTwip(2.0), $noCellBorder);
  $hdrTbl->addCell(\PhpOffice\PhpWord\Shared\Converter::inchToTwip(6.1), $noCellBorder);

  if (!empty($ctx['logoFsPath']) && is_file($ctx['logoFsPath']) && is_readable($ctx['logoFsPath'])) {
    $cellLogo->addImage($ctx['logoFsPath'], [
      'width'     => \PhpOffice\PhpWord\Shared\Converter::inchToPixel(1.35),
      'alignment' => \PhpOffice\PhpWord\SimpleType\Jc::LEFT,
    ]);
  }

  $footer   = $section->addFooter();
  $jcCenter = \PhpOffice\PhpWord\SimpleType\Jc::CENTER;

  $footer->addText(' ', [], [
    'alignment'         => $jcCenter,
    'spaceBefore'       => 0,
    'spaceAfter'        => 120,
    'borderBottomSize'  => 12,
    'borderBottomColor' => '71BF44',
  ]);

  $footP = ['alignment' => $jcCenter, 'spaceBefore' => 0, 'spaceAfter' => 0];
  $footer->addText((string)$ctx['footerLine1'], ['size' => 9], $footP);
  $footer->addText((string)$ctx['footerLine2'], ['size' => 9], $footP);

  $section->addText(
    'ADHD Assessment Report',
    ['name' => 'Times New Roman', 'size' => 18, 'bold' => true],
    [
      'alignment'   => $jcCenter,
      'spaceBefore' => $C::inchToTwip(3.2),
      'spaceAfter'  => 120,
    ]
  );

  $section->addText(
    "Conners' Parent Rating Scale",
    ['name' => 'Times New Roman', 'size' => 14, 'bold' => false],
    [
      'alignment'   => $jcCenter,
      'spaceBefore' => 0,
      'spaceAfter'  => 0,
    ]
  );

  $section->addPageBreak();

  $section->addText('ADHD Assessment Report', [
    'bold' => true,
    'underline' => 'single',
  ], [
    'alignment'  => $jcCenter,
    'spaceAfter' => 240,
  ]);

  $meta = $section->addTextRun([
    'spaceBefore' => 0,
    'spaceAfter'  => 0,
    'lineHeight'  => 1.0,
  ]);

  $meta->addText("Name: " . (string)$ctx['patientName']);
  $meta->addTextBreak(1);
  $meta->addText("Date of Assessment: " . (string)$ctx['assessDateDisplay']);
  $meta->addTextBreak(1);
  $meta->addText("Date of Birth: " . (string)$ctx['dobDisplay']);
  $meta->addTextBreak(1);
  $meta->addText("Chronological Age: " . (int)$ctx['ageYears'] . " years, " . (int)$ctx['ageMonths'] . " months, " . (int)$ctx['ageDays'] . " days");
  $meta->addTextBreak(1);
  $meta->addText("Gender: " . (string)$ctx['genderDisplay']);
  $meta->addTextBreak(1);
  $meta->addText("Education: " . ((string)($ctx['education'] ?: '-')));
  $meta->addTextBreak(1);
  $meta->addText("Contact No: " . ((string)($ctx['contactNo'] ?: '-')));
  $meta->addTextBreak(1);
  $meta->addText("Referred By: " . ((string)($ctx['referred_by'] ?: '-')));

  $section->addTextBreak(1);

  $section->addText(
    "Conners’ Parent Rating Scale was applied to assess whether the patient has ADHD. His score is given below:",
    [],
    ['spaceAfter' => 240]
  );

  $table = $section->addTable([
    'borderSize'       => 6,
    'borderColor'      => '111111',
    'cellMarginTop'    => 80,
    'cellMarginBottom' => 80,
    'cellMarginLeft'   => 120,
    'cellMarginRight'  => 120,
  ]);

  $table->addRow();
  $hdrCellStyle = ['bgColor' => 'F2F2F2'];
  $table->addCell(2200, $hdrCellStyle)->addText('Domain', ['bold' => true]);
  $table->addCell(900,  $hdrCellStyle)->addText('R. Total', ['bold' => true]);
  $table->addCell(900,  $hdrCellStyle)->addText('T-Score', ['bold' => true]);
  $table->addCell(1200, $hdrCellStyle)->addText('Percentile', ['bold' => true]);
  $table->addCell(5200, $hdrCellStyle)->addText('Guideline', ['bold' => true]);

  $scores = $ctx['scores'] ?? [];
  $labels = $ctx['labels'] ?? [];

  if (!empty($scores)) {
    foreach ($scores as $s) {
      $sub    = (string)($s['subscale'] ?? '');
      $domain = $labels[$sub] ?? $sub;

      $table->addRow();
      $table->addCell(2200)->addText($domain);
      $table->addCell(900)->addText((string)(int)($s['raw_total'] ?? 0));
      $table->addCell(900)->addText((string)(int)($s['t_score'] ?? 0));
      $table->addCell(1200)->addText((string)($s['percentile_label'] ?? ''));
      $table->addCell(5200)->addText((string)($s['guideline'] ?? ''));
    }
  } else {
    $table->addRow();
    $table->addCell(10200)->addText('No scores found. Please complete OMR and calculate result.');
  }

  $section->addTextBreak(1);

  $section->addText('History', ['bold' => true], ['spaceAfter' => 120]);
  addJustifiedBlock($section, (string)$ctx['history_text'], 240);

  $section->addText('Impression / Diagnosis', ['bold' => true], ['spaceAfter' => 120]);
  addJustifiedBlock($section, (string)$ctx['impression_text'], 240);

  $section->addText('Recommendations', ['bold' => true], ['spaceAfter' => 120]);
  addRecommendationsAsParagraphs($section, (string)$ctx['recommendations_text']);

  $section->addTextBreak(1);

  $section->addText('Assessed By', ['bold' => true], ['spaceAfter' => 120]);
  $section->addText('', [], ['spaceAfter' => 80]);

  $section->addText(' ', [], [
    'spaceBefore'       => 0,
    'spaceAfter'        => 160,
    'borderBottomSize'  => 12,
    'borderBottomColor' => '111111',
    'alignment'         => \PhpOffice\PhpWord\SimpleType\Jc::LEFT,
    'indentation'       => [
      'left'  => 0,
      'right' => \PhpOffice\PhpWord\Shared\Converter::inchToTwip(4.0),
    ],
  ]);

  $assP = ['spaceBefore' => 0, 'spaceAfter' => 0, 'lineHeight' => 1.15];
  $section->addText((string)$ctx['assessedBy'], [], $assP);
  $section->addText('Clinical Psychologist', [], $assP);
  $section->addText('Bangladesh Psychiatric Care Ltd.', [], $assP);

  $section->addPageBreak();

  $section->addText(
    'Appendix',
    ['name' => 'Times New Roman', 'size' => 18, 'bold' => true],
    [
      'alignment'   => $jcCenter,
      'spaceBefore' => $C::inchToTwip(1.2),
      'spaceAfter'  => 240,
    ]
  );

  $section->addText(
    "Conners’ Parent Rating Scale:",
    ['name' => 'Times New Roman', 'size' => 12, 'bold' => true, 'underline' => 'single'],
    ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::CENTER, 'spaceBefore' => 0, 'spaceAfter' => 160]
  );

  $section->addText(
    "Interpretative Guidelines for T-Score and Percentiles",
    ['name' => 'Times New Roman', 'size' => 11, 'bold' => true],
    ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::CENTER, 'spaceBefore' => 0, 'spaceAfter' => 160]
  );

  $gTbl = $section->addTable([
    'borderSize'       => 6,
    'borderColor'      => '111111',
    'cellMarginTop'    => 80,
    'cellMarginBottom' => 80,
    'cellMarginLeft'   => 120,
    'cellMarginRight'  => 120,
  ]);

  $gTbl->addRow();
  $hdrCellStyle = ['bgColor' => 'F2F2F2'];
  $gTbl->addCell(1700, $hdrCellStyle)->addText('T-Score', ['bold' => true]);
  $gTbl->addCell(1700, $hdrCellStyle)->addText('Percentile', ['bold' => true]);
  $gTbl->addCell(6800, $hdrCellStyle)->addText('Guideline', ['bold' => true]);

  foreach (($ctx['appendixGuidelines'] ?? []) as $r) {
    $gTbl->addRow();
    $gTbl->addCell(1700)->addText($r[0]);
    $gTbl->addCell(1700)->addText($r[1]);
    $gTbl->addCell(6800)->addText($r[2]);
  }

  return $phpWord;
}

/* =========================================================
   AUTO-SAVE + EMAIL PREP (same endpoint)
   - action=prep_email generates DOCX into a temp file
   - returns JSON with a secure download URL
========================================================= */
function ensure_dir($path) {
  if (!is_dir($path)) @mkdir($path, 0775, true);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_check();

  $history_text         = trim((string)($_POST['history'] ?? ''));
  $impression_text      = trim((string)($_POST['impression'] ?? ''));
  $recommendations_text = trim((string)($_POST['recommendations'] ?? ''));
  $referred_by          = trim((string)($_POST['referred_by'] ?? ''));

  if ($hasHistoryCol) {
    $pdo->prepare("
      UPDATE assessments
      SET history_text=?, impression_text=?, recommendations_text=?, referred_by=?
      WHERE id=?
    ")->execute([$history_text, $impression_text, $recommendations_text, $referred_by, $aid]);

    $payload = [
      'history'         => $history_text,
      'impression'      => $impression_text,
      'recommendations' => $recommendations_text,
      'referred_by'     => $referred_by,
    ];
    $pdo->prepare("UPDATE assessments SET notes=? WHERE id=?")->execute([
      json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
      $aid
    ]);
  }

  $isAjax = (strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest');

// ✅ NEW: Send email with DOCX attachment (no link)
if (($_POST['action'] ?? '') === 'send_email') {

  // Recipient + subject/body from modal
  $to      = trim((string)($_POST['email_to'] ?? ''));
  $subject = trim((string)($_POST['email_subject'] ?? ''));
  $body    = (string)($_POST['email_body'] ?? '');

  if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Please enter a valid recipient email.']);
    exit;
  }

  // Build DOCX (same content as your download)
  $logoFsPath = dirname(__DIR__) . '/assets/img/BPCL Logo.png';

  $ctx = [
    'logoFsPath'           => $logoFsPath,
    'footerLine1'          => $printFooterLine1,
    'footerLine2'          => $printFooterLine2,
    'patientName'          => $patientName,
    'assessDateDisplay'    => $assessDateDisplay,
    'dobDisplay'           => $dobDisplay,
    'ageYears'             => $ageYears,
    'ageMonths'            => $ageMonths,
    'ageDays'              => $ageDays,
    'genderDisplay'        => $genderDisplay,
    'education'            => $education,
    'contactNo'            => $contactNo,
    'referred_by'          => $referred_by,
    'scores'               => $scores,
    'labels'               => $labels,
    'history_text'         => $history_text,
    'impression_text'      => $impression_text,
    'recommendations_text' => $recommendations_text,
    'assessedBy'           => $assessedBy,
    'appendixGuidelines'   => $appendixGuidelines,
  ];

  $phpWord = build_report_phpword($ctx);

  $safePatient = preg_replace('/[^a-zA-Z0-9_\-]+/', '_', ($patientName ?: 'Patient'));
  $safeTitle   = preg_replace('/[^a-zA-Z0-9_\-]+/', '_', ($reportTitle ?: 'Report'));
  $fname       = "{$safeTitle}_{$safePatient}_{$aid}.docx";

  // Save to temp file for attachment
  $tmpDir = dirname(__DIR__) . '/storage/tmp_mail';
  if (!is_dir($tmpDir)) @mkdir($tmpDir, 0775, true);
  $tmpPath = $tmpDir . '/' . bin2hex(random_bytes(10)) . '__' . $fname;

  try {
    $writer = \PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'Word2007');
    $writer->save($tmpPath);
  } catch (Throwable $e) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Failed to generate DOCX for email.']);
    exit;
  }

  // Send email via your common Gmail (separate config file, not your main config.php)
  try {
    require_once __DIR__ . '/../helpers/report_mailer.php';

    // Default subject/body if empty
    if ($subject === '') {
      $subject = "{$reportTitle} - " . ($patientName ?: 'Patient') . " (#{$aid})";
    }
    if (trim($body) === '') {
      $body = "Assalamu Alaikum,\n\n"
            . "Please find the attached {$reportTitle} (DOCX).\n\n"
            . "Patient: " . ($patientName ?: 'Patient') . "\n"
            . "Assessment ID: #{$aid}\n"
            . "Date: {$assessDateDisplay}\n\n"
            . "Regards,\n"
            . ($assessedBy ?: 'Consultant') . "\n"
            . "Bangladesh Psychiatric Care Ltd.";
    }

    $send = send_report_email_with_attachment([
      'to' => $to,
      'subject' => $subject,
      'bodyText' => $body,
      'attachmentPath' => $tmpPath,
      'attachmentName' => $fname,
    ]);

    // cleanup
    if (is_file($tmpPath)) @unlink($tmpPath);

    header('Content-Type: application/json; charset=utf-8');
    if (!empty($send['ok'])) {
      echo json_encode(['ok' => true, 'sent' => true, 'sent_at' => date('Y-m-d H:i:s')]);
    } else {
      echo json_encode(['ok' => false, 'sent' => false, 'error' => ($send['error'] ?? 'Email send failed.')]);
    }
    exit;

  } catch (Throwable $e) {
    if (is_file($tmpPath)) @unlink($tmpPath);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'Mailer error. Please check SMTP config.']);
    exit;
  }
}

  // existing autosave response
  if ($isAjax) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'saved_at' => date('Y-m-d H:i:s')]);
    exit;
  }

  flash('success', 'Report saved.');
  redirect("consultant/report.php?id=" . $aid);
}

/* =========================================================
   Serve email-prepared DOCX by token (session-bound)
========================================================= */
if (($_GET['download'] ?? '') === 'email_docx') {
  $token = (string)($_GET['token'] ?? '');

  if ($token === '') {
    http_response_code(400);
    exit('Missing token.');
  }

  if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
  $meta = $_SESSION['email_doc_tokens'][$token] ?? null;

  if (!is_array($meta)) {
    http_response_code(403);
    exit('Invalid or expired token.');
  }

  $path = (string)($meta['path'] ?? '');
  $name = (string)($meta['name'] ?? 'report.docx');

  if ($path === '' || !is_file($path)) {
    http_response_code(404);
    exit('File not found.');
  }

  header("Content-Description: File Transfer");
  header('Content-Disposition: attachment; filename="' . $name . '"');
  header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
  header('Content-Transfer-Encoding: binary');
  header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
  header('Expires: 0');
  header('Content-Length: ' . filesize($path));

  readfile($path);
  exit;
}

/* =========================
   DOWNLOAD AS WORD (.docx)
   (UNCHANGED behavior, same URL)
========================= */
if (($_GET['download'] ?? '') === 'word') {

  $logoFsPath = dirname(__DIR__) . '/assets/img/BPCL Logo.png';

  $ctx = [
    'logoFsPath'           => $logoFsPath,
    'footerLine1'          => $printFooterLine1,
    'footerLine2'          => $printFooterLine2,
    'patientName'          => $patientName,
    'assessDateDisplay'    => $assessDateDisplay,
    'dobDisplay'           => $dobDisplay,
    'ageYears'             => $ageYears,
    'ageMonths'            => $ageMonths,
    'ageDays'              => $ageDays,
    'genderDisplay'        => $genderDisplay,
    'education'            => $education,
    'contactNo'            => $contactNo,
    'referred_by'          => $referred_by,
    'scores'               => $scores,
    'labels'               => $labels,
    'history_text'         => $history_text,
    'impression_text'      => $impression_text,
    'recommendations_text' => $recommendations_text,
    'assessedBy'           => $assessedBy,
    'appendixGuidelines'   => $appendixGuidelines,
  ];

  $phpWord = build_report_phpword($ctx);

  $safeName = preg_replace('/[^a-zA-Z0-9_\-]+/', '_', ($patientName ?: 'Patient'));
  $fname    = "ADHD_Assessment_Report_{$safeName}_{$aid}.docx";

  header("Content-Description: File Transfer");
  header('Content-Disposition: attachment; filename="' . $fname . '"');
  header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
  header('Content-Transfer-Encoding: binary');
  header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
  header('Expires: 0');

  $writer = \PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'Word2007');
  $writer->save("php://output");
  exit;
}

include __DIR__ . '/../partials/header.php';
?>

<style>
  :root {
    --ink: #0b1220;
    --muted: #5b6473;
    --line: #e9edf5;
    --soft: #f7f9ff;
    --card: #ffffff;

    --b-orange: #f17252;
    --b-blue: #2b59ff;
    --b-green: #71bf44;

    /* ===== PRINT MARGINS =====
       Bottom = 0.6" exactly (15.24mm)
    */
    --print-margin-top: 40mm;
    --print-margin-right: 14mm;
    --print-margin-bottom: 15.24mm;
    --print-margin-left: 14mm;

    --print-font-size: 11pt;
    --print-line-height: 1.55;

    /* header/footer safe zones inside margins */
    --print-header-top: 10mm;
    --print-header-height: 18mm;

    --print-footer-bottom: 5mm;
    --print-footer-height: 12mm;

    --digital-sign-space: 10mm;
  }

  .rep-shell { max-width: 1120px; margin: 0 auto; position: relative; }
  .rep-geo { position: absolute; inset: -120px -40px auto -40px; height: 420px; pointer-events: none; z-index: 0; opacity: .95; }
  .rep-geo:before {
    content: ""; position: absolute; inset: 0;
    background:
      radial-gradient(640px 360px at 10% 20%, rgba(241,114,82,.14), transparent 62%),
      radial-gradient(620px 360px at 92% 22%, rgba(43,89,255,.14), transparent 62%),
      radial-gradient(520px 360px at 55% 110%, rgba(113,191,68,.10), transparent 60%);
  }
  .rep-geo:after {
    content: ""; position: absolute; inset: 0;
    background-image: radial-gradient(rgba(16,24,40,.08) 1px, transparent 1px);
    background-size: 18px 18px;
    mask-image: radial-gradient(closest-side, rgba(0,0,0,.85), transparent 78%);
    opacity: .35;
  }

  .rep-card { position: relative; z-index: 1; background: var(--card); border: 1px solid var(--line); border-radius: 22px; overflow: hidden; box-shadow: 0 26px 70px rgba(16,24,40,.10); }

  .rep-hero {
    padding: 18px 18px 14px;
    border-bottom: 1px solid rgba(233,237,245,.85);
    background:
      radial-gradient(900px 520px at 15% 0%, rgba(241,114,82,.08), transparent 60%),
      radial-gradient(900px 520px at 92% 10%, rgba(43,89,255,.08), transparent 62%),
      linear-gradient(180deg, #ffffff, #fbfcff);
    display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; flex-wrap: wrap;
  }

  .rep-title { margin: 0; font-weight: 1000; letter-spacing: -.4px; color: #111827; font-size: clamp(18px, 2.2vw, 22px); line-height: 1.1; }
  .rep-sub { margin: 6px 0 0; color: var(--muted); font-size: 13px; line-height: 1.7; }

  .rep-badges { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 10px; }
  .rep-badge {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 8px 10px; border-radius: 999px;
    border: 1px solid rgba(233,237,245,.95);
    background: #fff; box-shadow: 0 10px 24px rgba(16,24,40,.06);
    font-weight: 900; font-size: 12px; color: #111827;
  }
  .rep-badge i { opacity: .9; }

  .rep-actions { display: flex; align-items: center; justify-content: flex-end; gap: 10px; flex-wrap: wrap; }
  .rep-btn { border-radius: 14px !important; font-weight: 900 !important; box-shadow: 0 10px 22px rgba(16,24,40,.06); }
  .rep-btn-primary { border: 0 !important; color: #fff !important; background: linear-gradient(135deg, var(--b-orange), #ff8a6f) !important; box-shadow: 0 18px 45px rgba(241,114,82,.22) !important; }
  .rep-btn-primary:hover { filter: brightness(.98); transform: translateY(-1px); }

  .rep-body { padding: 18px 22px 24px; }
  @media(min-width:992px){ .rep-body{ padding: 18px 26px 28px; } }

  .rep-meta { display: grid; grid-template-columns: 1fr; gap: 12px; margin: 6px 0 12px; }
  @media(min-width:992px){ .rep-meta{ grid-template-columns: 1fr 1fr; gap: 14px; } }

  .rep-box { border: 1px solid var(--line); border-radius: 18px; padding: 14px 14px; background: #fff; box-shadow: 0 16px 42px rgba(16,24,40,.06); }
  .rep-k { display: flex; align-items: center; justify-content: space-between; gap: 10px; color: #111827; font-weight: 1000; letter-spacing: -.2px; font-size: 13px; margin-bottom: 10px; }
  .rep-k .pill { font-size: 12px; font-weight: 900; color: #111827; border: 1px solid rgba(233,237,245,.95); background: linear-gradient(180deg,#fff,#fbfcff); padding: 7px 10px; border-radius: 999px; }

  .rep-s { color: #111827; font-size: 13px; line-height: 1.75; }
  .rep-s strong { font-weight: 950; }

  .rep-section { margin-top: 14px; }
  .rep-h { display:flex; align-items:center; justify-content:space-between; gap:10px; margin:0 0 8px; }
  .rep-h b { font-weight: 1000; letter-spacing: -.2px; color:#111827; font-size:14px; }
  .rep-h .chip { display:inline-flex; align-items:center; gap:8px; padding:7px 10px; border-radius:999px; border:1px solid rgba(233,237,245,.95); background:#fff; font-size:12px; font-weight:900; color:var(--muted); }

  .rep-textarea {
    width:100%; border:1px solid rgba(233,237,245,.95); border-radius:16px; padding:12px 12px;
    font-size:14px; line-height:1.75; resize:none; overflow:hidden; background:#fff; color:#111;
    text-align: justify; text-justify: inter-word; min-height:92px;
    box-shadow: 0 14px 40px rgba(16,24,40,.06);
    transition: border-color .15s ease, box-shadow .15s ease;
  }
  .rep-textarea:focus { outline:none; border-color: rgba(43,89,255,.35); box-shadow:0 18px 46px rgba(43,89,255,.10); }
  .rep-printtext { display:none; white-space:pre-wrap; word-break:break-word; }

  .rep-lead {
    margin:12px 0 12px; padding:12px 14px; border-radius:18px;
    border:1px solid rgba(233,237,245,.95);
    background:
      radial-gradient(520px 240px at 10% 10%, rgba(241,114,82,.08), transparent 60%),
      radial-gradient(520px 240px at 92% 10%, rgba(43,89,255,.08), transparent 60%),
      linear-gradient(180deg,#ffffff,#fbfcff);
    color:#111827; font-size:13.5px; line-height:1.75;
    box-shadow:0 16px 44px rgba(16,24,40,.06);
  }

  .rep-table {
    width:100%; border:1px solid rgba(233,237,245,.95);
    border-radius:18px; overflow:hidden; border-collapse:separate; border-spacing:0;
    background:#fff; box-shadow:0 16px 46px rgba(16,24,40,.06);
  }
  .rep-table thead th {
    background: linear-gradient(180deg,#f7f9ff,#ffffff);
    font-weight:1000; font-size:13px; padding:11px 12px;
    border-bottom:1px solid rgba(233,237,245,.95); text-align:left; color:#111827;
  }
  .rep-table tbody td { padding:11px 12px; border-bottom:1px solid rgba(233,237,245,.95); font-size:13.5px; vertical-align:top; color:#111827; }
  .rep-table tbody tr:last-child td { border-bottom:0; }

  .rep-dom { display:flex; align-items:center; gap:10px; font-weight:950; }
  .rep-dom .dot { width:10px; height:10px; border-radius:999px; background:var(--b-blue); box-shadow:0 0 0 6px rgba(43,89,255,.10); }
  .rep-dom.oppo .dot { background:var(--b-orange); box-shadow:0 0 0 6px rgba(241,114,82,.10); }
  .rep-dom.cog  .dot { background:var(--b-blue);   box-shadow:0 0 0 6px rgba(43,89,255,.10); }
  .rep-dom.hyp  .dot { background:var(--b-green);  box-shadow:0 0 0 6px rgba(113,191,68,.10); }
  .rep-dom.adhd .dot { background:#111827; box-shadow:0 0 0 6px rgba(17,24,39,.10); }

  .rep-savestate { font-size:12px; color:var(--muted); margin-top:8px; }

  .rep-sign { margin-top:18px; }
  .rep-digispace { height:44px; }
  .rep-signlabel { font-size:13px; color:#111; margin:0 0 6px; font-weight:950; }
  .rep-signline { width:320px; height:1px; background:#111; margin:6px 0 8px; }
  .rep-signname { font-weight:1000; color:#111; margin:0; }
  .rep-signtitle { margin:0; color:var(--muted); font-size:13px; }

  /* Email modal helpers */
  .em-pill {
    display:inline-flex; align-items:center; gap:8px;
    padding:7px 10px; border-radius:999px;
    border:1px solid rgba(233,237,245,.95);
    background:linear-gradient(180deg,#fff,#fbfcff);
    font-size:12px; font-weight:900; color:#111827;
  }
  .em-linkbox{
    border:1px dashed rgba(233,237,245,.95);
    border-radius:14px; padding:10px 12px; background:#fff;
    font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace;
    font-size:12px; word-break:break-all;
  }

  /* =========================================================
     PRINT FIX (your margin rules + fixed header/footer)
  ========================================================= */
  @page { size: A4; margin: 0; }

  @media print {
    nav, header, footer, .navbar, .no-print, .rep-actions, .btn, .rep-shell {
      display: none !important;
    }

    html, body {
      margin: 0 !important;
      padding: 0 !important;
      background: #fff !important;
      -webkit-print-color-adjust: exact;
      print-color-adjust: exact;
      font-family: "Times New Roman", Times, serif !important;
      font-size: 11pt !important;
      line-height: 1.55 !important;
      color: #111 !important;
    }

    .print-only { display: block !important; }

    .p-page {
      page-break-after: always;
      padding: 40mm 14mm 15.24mm 14mm !important;
      -webkit-box-decoration-break: clone;
      box-decoration-break: clone;
    }
    .p-page:last-child { page-break-after: auto; }

    .p-header {
      position: fixed; top: 0; left: 0; right: 0;
      height: 28mm;
      padding: 10mm 14mm 0 14mm;
      box-sizing: border-box;
      display: flex;
      align-items: flex-start;
      z-index: 9999;
    }
    .p-header img { height: 16mm; width: auto; display: block; }

    .p-footer {
      position: fixed; left: 0; right: 0; bottom: 0;
      padding: 0 14mm 3mm 14mm;
      box-sizing: border-box;
      font-size: 9pt;
      line-height: 1.15;
      text-align: center;
      z-index: 9999;
    }
    .p-footer .rule { border-top: 2px solid #71BF44; margin: 0 0 2mm 0; }
  }
</style>

<div class="rep-shell">
  <div class="rep-geo"></div>

  <div class="rep-card">

    <div class="rep-hero no-print">
      <div class="min-w-0">
        <h2 class="rep-title">ADHD Assessment Report</h2>
        <p class="rep-sub">
          Auto-saves while typing • Print-ready layout preserved • Assessment ID: <b>#<?= (int)$aid ?></b>
        </p>

        <div class="rep-badges">
          <span class="rep-badge"><i class="bi bi-person-badge"></i> <?= e($patientName ?: 'Patient') ?></span>
          <span class="rep-badge"><i class="bi bi-calendar2-check"></i> <?= e($assessDateDisplay) ?></span>
          <span class="rep-badge"><i class="bi bi-gender-ambiguous"></i> <?= e($genderDisplay ?: '-') ?></span>
          <span class="rep-badge"><i class="bi bi-tags"></i> <?= e($category ?: '-') ?></span>
          <span class="rep-badge"><i class="bi bi-person-plus"></i> <?= e($referred_by ?: 'Referred By: -') ?></span>
        </div>
      </div>

      <div class="rep-actions">
        <button class="btn rep-btn rep-btn-primary" type="button" id="emailBtn">
          <i class="bi bi-envelope-paper"></i> Email
        </button>

        <a class="btn btn-outline-secondary rep-btn"
           href="<?= e(BASE_URL) ?>/consultant/report.php?id=<?= (int)$aid ?>&download=word">
          <i class="bi bi-file-earmark-word"></i> DOCX
        </a>

        <a class="btn btn-outline-secondary rep-btn"
           href="<?= e(BASE_URL) ?>/consultant/history.php?id=<?= (int)$aid ?>">
          <i class="bi bi-clock-history"></i> History
        </a>

        <a class="btn btn-outline-secondary rep-btn"
           href="<?= e(BASE_URL) ?>/consultant/omr.php?id=<?= (int)$aid ?>">
          <i class="bi bi-ui-checks-grid"></i> OMR
        </a>

        <a class="btn btn-outline-secondary rep-btn"
           href="<?= e(BASE_URL) ?>/consultant/result.php?id=<?= (int)$aid ?>">
          <i class="bi bi-bar-chart-line"></i> Result
        </a>
      </div>
    </div>

    <div class="rep-body">

      <div class="rep-meta">
        <div class="rep-box">
          <div class="rep-k">
            <span>Patient Details</span>
            <span class="pill">Confidential</span>
          </div>
          <div class="rep-s">
            <strong>Name:</strong> <?= e($patientName) ?><br>
            <strong>Date of Assessment:</strong> <?= e($assessDateDisplay) ?><br>
            <strong>Date of Birth:</strong> <?= e($dobDisplay) ?><br>
            <strong>Chronological Age:</strong> <?= (int)$ageYears ?> years, <?= (int)$ageMonths ?> months, <?= (int)$ageDays ?> days<br>
            <strong>Gender:</strong> <?= e($genderDisplay) ?><br>
            <strong>Education:</strong> <?= e($education ?: '-') ?><br>
            <strong>Contact No:</strong> <?= e($contactNo ?: '-') ?><br>
            <strong>Referred By:</strong> <?= e($referred_by ?: '-') ?><br>
          </div>
        </div>

        <div class="rep-box">
          <div class="rep-k">
            <span>Assessment Info</span>
            <span class="pill">BPCL DSRS</span>
          </div>
          <div class="rep-s">
            <strong>ADHD</strong><br><br>
            <strong>Assessed By:</strong> <?= e($assessedBy) ?><br>
            <strong>Designation:</strong> Clinical Psychologist<br>
            <strong>Organization:</strong> Bangladesh Psychiatric Care Ltd.
          </div>

          <div class="rep-section" style="margin-top:10px;">
            <div class="rep-h" style="margin:0 0 6px;">
              <b style="font-size:13px;">Referred By</b>
              <span class="chip"><i class="bi bi-pencil-square"></i> Optional</span>
            </div>

            <textarea id="refText" class="rep-textarea" rows="2" placeholder="Write referred by..."><?= e($referred_by) ?></textarea>
            <div class="rep-printtext" id="refPrint"><?= e($referred_by) ?></div>
          </div>
        </div>
      </div>

      <div class="rep-section">
        <div class="rep-h">
          <b>History</b>
          <span class="chip"><i class="bi bi-lightning-charge"></i> Auto-save</span>
        </div>
        <textarea id="hisText" class="rep-textarea" rows="3" placeholder="Write patient history..."><?= e($history_text) ?></textarea>
        <div class="rep-printtext" id="hisPrint"><?= e($history_text) ?></div>
      </div>

      <div class="rep-lead">
        Conners’ Parent Rating Scale was applied to assess whether the patient has ADHD. His score is given below:
      </div>

      <div class="table-responsive">
        <table class="rep-table">
          <thead>
            <tr>
              <th style="width:22%;">Domain</th>
              <th style="width:10%;">R. Total</th>
              <th style="width:10%;">T-Score</th>
              <th style="width:13%;">Percentile</th>
              <th>Guideline</th>
            </tr>
          </thead>
          <tbody>
            <?php if ($scores): ?>
              <?php foreach ($scores as $s):
                $sub = (string)($s['subscale'] ?? '');
                $cls = 'cog';
                if ($sub === 'OPPOSITIONAL')  $cls = 'oppo';
                if ($sub === 'HYPERACTIVITY') $cls = 'hyp';
                if ($sub === 'ADHD_INDEX')    $cls = 'adhd';
              ?>
              <tr>
                <td>
                  <span class="rep-dom <?= e($cls) ?>">
                    <span class="dot"></span>
                    <?= e($labels[$sub] ?? $sub) ?>
                  </span>
                </td>
                <td><?= (int)($s['raw_total'] ?? 0) ?></td>
                <td><?= (int)($s['t_score'] ?? 0) ?></td>
                <td><?= e((string)($s['percentile_label'] ?? '')) ?></td>
                <td><?= e((string)($s['guideline'] ?? '')) ?></td>
              </tr>
              <?php endforeach; ?>
            <?php else: ?>
              <tr>
                <td colspan="5" style="padding:10px;">
                  No scores found. Please complete OMR and calculate result.
                </td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <div class="rep-section">
        <div class="rep-h">
          <b>Impression / Diagnosis</b>
          <span class="chip"><i class="bi bi-clipboard2-pulse"></i> Clinical notes</span>
        </div>
        <textarea id="impText" class="rep-textarea" rows="3" placeholder="Write Impression/Diagnosis..."><?= e($impression_text) ?></textarea>
        <div class="rep-printtext" id="impPrint"><?= e($impression_text) ?></div>
      </div>

      <div class="rep-section">
        <div class="rep-h">
          <b>Recommendations</b>
          <span class="chip"><i class="bi bi-check2-circle"></i> Plan</span>
        </div>
        <textarea id="recText" class="rep-textarea" rows="3" placeholder="Write Recommendations..."><?= e($recommendations_text) ?></textarea>
        <div class="rep-printtext" id="recPrint"><?= e($recommendations_text) ?></div>
      </div>

      <div class="rep-savestate no-print" id="saveState">Auto-save: ready</div>

      <div class="rep-sign">
        <b><div class="rep-signlabel">Assessed By</div></b>
        <div class="rep-digispace"></div>
        <div class="rep-signline"></div>
        <p class="rep-signname mb-0"><?= e($assessedBy) ?></p>
        <p class="rep-signtitle mb-0">Clinical Psychologist</p>
        <p class="rep-signtitle mb-0">Bangladesh Psychiatric Care Ltd.</p>
      </div>

    </div>
  </div>
</div>

<!-- ✅ Email Modal (Premium UI) -->
<div class="modal fade" id="emailModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content em-card">
      <div class="modal-header em-head">
        <div class="d-flex align-items-start gap-3">
          <div class="em-icon">
            <i class="bi bi-envelope-paper-heart"></i>
          </div>
          <div class="min-w-0">
            <h5 class="modal-title mb-1 em-title">Send Report via Email</h5>
            <div class="em-sub">
              This will send <b><?= e($reportTitle) ?></b> as a <b>DOCX attachment</b> from the system Gmail.
            </div>
            <div class="d-flex flex-wrap gap-2 mt-2">
              <span class="em-pill"><i class="bi bi-paperclip"></i> Attachment: DOCX</span>
              <span class="em-pill"><i class="bi bi-shield-check"></i> Secure Server Send</span>
              <span class="em-pill"><i class="bi bi-person-badge"></i> <?= e($patientName ?: 'Patient') ?> • #<?= (int)$aid ?></span>
            </div>
          </div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <div class="modal-body">
        <div class="alert alert-info em-note">
          ✅ No download link will be shown. The report will be attached automatically.
        </div>

        <div class="row g-3">
          <div class="col-lg-7">
            <label class="form-label fw-bold">Recipient (Gmail / any email)</label>
            <div class="input-group">
              <span class="input-group-text"><i class="bi bi-at"></i></span>
              <input type="email" class="form-control" id="emailTo" placeholder="example@gmail.com">
            </div>
            <div class="form-text">Tip: add patient guardian email here.</div>
          </div>

          <div class="col-lg-5">
            <label class="form-label fw-bold">Subject</label>
            <input type="text" class="form-control" id="emailSubject"
                   value="<?= e($reportTitle) ?> - <?= e($patientName ?: 'Patient') ?> (#<?= (int)$aid ?>)">
          </div>

          <div class="col-12">
            <label class="form-label fw-bold">Message</label>
            <textarea class="form-control em-body" id="emailBody" rows="7"><?=
e("Assalamu Alaikum,\n\nPlease find the attached {$reportTitle} (DOCX).\n\nPatient: " . ($patientName ?: 'Patient') . "\nAssessment ID: #{$aid}\nDate: {$assessDateDisplay}\n\nRegards,\n" . ($assessedBy ?: 'Consultant') . "\nBangladesh Psychiatric Care Ltd.")
?></textarea>
            <div class="form-text">
              Report name is dynamic: <b><?= e($reportTitle) ?></b> (depends on chosen category e.g., ADHD(Child)).
            </div>
          </div>
        </div>

        <div class="d-flex flex-wrap gap-2 align-items-center mt-3">
          <button class="btn btn-primary em-send" type="button" id="sendEmailBtn">
            <i class="bi bi-send"></i> Send Email
          </button>
          <button class="btn btn-outline-secondary em-cancel" type="button" data-bs-dismiss="modal">
            <i class="bi bi-x-circle"></i> Cancel
          </button>

          <div class="ms-auto small text-muted" id="emailSendState">Ready to send</div>
        </div>
      </div>
    </div>
  </div>
</div>

<style>
  .em-card{ border-radius:18px; overflow:hidden; border:1px solid rgba(233,237,245,.95); box-shadow:0 28px 90px rgba(16,24,40,.18); }
  .em-head{
    background:
      radial-gradient(900px 520px at 10% 10%, rgba(241,114,82,.15), transparent 60%),
      radial-gradient(900px 520px at 92% 0%, rgba(43,89,255,.14), transparent 62%),
      linear-gradient(180deg,#fff,#fbfcff);
    border-bottom:1px solid rgba(233,237,245,.95);
    padding:16px 16px;
  }
  .em-icon{
    width:46px; height:46px; border-radius:14px;
    display:grid; place-items:center;
    background:linear-gradient(135deg, rgba(241,114,82,.18), rgba(43,89,255,.18));
    border:1px solid rgba(233,237,245,.95);
    font-size:22px;
  }
  .em-title{ font-weight:1000; letter-spacing:-.2px; }
  .em-sub{ color:#5b6473; font-size:13px; line-height:1.6; }
  .em-pill{
    display:inline-flex; align-items:center; gap:8px;
    padding:7px 10px; border-radius:999px;
    border:1px solid rgba(233,237,245,.95);
    background:linear-gradient(180deg,#fff,#fbfcff);
    font-size:12px; font-weight:900; color:#111827;
  }
  .em-note{ border-radius:14px; margin-bottom:12px; }
  .em-body{ border-radius:14px; line-height:1.65; }
  .em-send{ border-radius:14px; font-weight:950; padding:10px 14px; }
  .em-cancel{ border-radius:14px; font-weight:900; padding:10px 14px; }
</style>

<script>
(function () {
  const his = document.getElementById('hisText');
  const imp = document.getElementById('impText');
  const rec = document.getElementById('recText');
  const ref = document.getElementById('refText');

  const hisPrint = document.getElementById('hisPrint');
  const impPrint = document.getElementById('impPrint');
  const recPrint = document.getElementById('recPrint');
  const refPrint = document.getElementById('refPrint');

  const saveState = document.getElementById('saveState');
  const CSRF = <?= json_encode(csrf_token()) ?>;

  function autoGrow(el) {
    if (!el) return;
    el.style.height = "auto";
    el.style.height = (el.scrollHeight + 2) + "px";
  }

  function syncPrint() {
    if (his && hisPrint) hisPrint.textContent = his.value || "";
    if (imp && impPrint) impPrint.textContent = imp.value || "";
    if (rec && recPrint) recPrint.textContent = rec.value || "";
    if (ref && refPrint) refPrint.textContent = ref.value || "";
  }

  let timer = null;
  let saving = false;
  let lastPayload = null;

  async function autosave() {
    if (saving) return;

    const payload = {
      history: his ? his.value : '',
      impression: imp ? imp.value : '',
      recommendations: rec ? rec.value : '',
      referred_by: ref ? ref.value : ''
    };

    const payloadStr = JSON.stringify(payload);
    if (payloadStr === lastPayload) return;
    lastPayload = payloadStr;

    saving = true;
    if (saveState) saveState.textContent = "Auto-save: saving…";

    try {
      const fd = new FormData();
      fd.append('csrf', CSRF);
      fd.append('history', payload.history);
      fd.append('impression', payload.impression);
      fd.append('recommendations', payload.recommendations);
      fd.append('referred_by', payload.referred_by);

      const res = await fetch(window.location.href, {
        method: 'POST',
        body: fd,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      });

      const j = await res.json();
      if (j && j.ok) {
        if (saveState) saveState.textContent = "Auto-save: saved (" + (j.saved_at || "just now") + ")";
      } else {
        if (saveState) saveState.textContent = "Auto-save: failed";
      }
    } catch (e) {
      if (saveState) saveState.textContent = "Auto-save: failed";
    } finally {
      saving = false;
    }
  }

  function scheduleSave() {
    clearTimeout(timer);
    timer = setTimeout(autosave, 650);
  }

  function bind(el) {
    if (!el) return;
    autoGrow(el);
    el.addEventListener('input', function () {
      autoGrow(el);
      syncPrint();
      scheduleSave();
    });
    el.addEventListener('blur', function () {
      scheduleSave();
    });
  }

  bind(his); bind(imp); bind(rec); bind(ref);
  syncPrint();
  autoGrow(his); autoGrow(imp); autoGrow(rec); autoGrow(ref);

  window.addEventListener('beforeprint', function () { syncPrint(); });

  /* =========================
     ✅ EMAIL BUTTON LOGIC (new flow)
     Click Email -> Open modal
     Click Send -> Server generates DOCX + sends attachment
  ========================= */
  const emailBtn = document.getElementById('emailBtn');
  const emailModalEl = document.getElementById('emailModal');
  const sendEmailBtn = document.getElementById('sendEmailBtn');
  const emailSendState = document.getElementById('emailSendState');

  const emailTo = document.getElementById('emailTo');
  const emailSubject = document.getElementById('emailSubject');
  const emailBody = document.getElementById('emailBody');

  function setState(text, ok=null){
    if (!emailSendState) return;
    emailSendState.textContent = text;
    emailSendState.style.color = (ok === true) ? '#1f7a3f' : (ok === false) ? '#b42318' : '#5b6473';
    emailSendState.style.fontWeight = (ok === null) ? '600' : '900';
  }

  async function sendEmailNow(){
    const to = (emailTo.value || '').trim();
    if (!to) { setState('Please enter recipient email', false); emailTo.focus(); return; }

    sendEmailBtn.disabled = true;
    sendEmailBtn.innerHTML = '<i class="bi bi-arrow-repeat"></i> Sending...';
    setState('Sending email…', null);

    try {
      const fd = new FormData();
      fd.append('csrf', CSRF);

      // include latest report texts (so emailed doc is updated)
      fd.append('history', his ? his.value : '');
      fd.append('impression', imp ? imp.value : '');
      fd.append('recommendations', rec ? rec.value : '');
      fd.append('referred_by', ref ? ref.value : '');

      // email fields
      fd.append('email_to', to);
      fd.append('email_subject', (emailSubject.value || '').trim());
      fd.append('email_body', (emailBody.value || '').trim());

      fd.append('action', 'send_email');

      const res = await fetch(window.location.href, {
        method: 'POST',
        body: fd,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      });

      const j = await res.json();
      if (j && j.ok && j.sent) {
        setState('Sent successfully (' + (j.sent_at || 'just now') + ')', true);
        sendEmailBtn.innerHTML = '<i class="bi bi-check2-circle"></i> Sent';

        setTimeout(() => {
          // close modal
          if (emailModalEl && window.bootstrap) {
            bootstrap.Modal.getOrCreateInstance(emailModalEl).hide();
          }
          sendEmailBtn.innerHTML = '<i class="bi bi-send"></i> Send Email';
          sendEmailBtn.disabled = false;
          setState('Ready to send', null);
        }, 900);

        return;
      }

      setState((j && j.error) ? j.error : 'Failed to send email', false);
      sendEmailBtn.innerHTML = '<i class="bi bi-exclamation-triangle"></i> Failed';
    } catch (e) {
      setState('Network/server error while sending', false);
      sendEmailBtn.innerHTML = '<i class="bi bi-exclamation-triangle"></i> Failed';
    } finally {
      setTimeout(() => {
        sendEmailBtn.innerHTML = '<i class="bi bi-send"></i> Send Email';
        sendEmailBtn.disabled = false;
      }, 900);
    }
  }

  if (emailBtn && emailModalEl && window.bootstrap) {
    emailBtn.addEventListener('click', function () {
      setState('Ready to send', null);
      const m = bootstrap.Modal.getOrCreateInstance(emailModalEl);
      m.show();
      setTimeout(()=> emailTo && emailTo.focus(), 250);
    });
  }

  if (sendEmailBtn) sendEmailBtn.addEventListener('click', sendEmailNow);

  async function prepareDocxForEmail() {
    genDocBtn.disabled = true;
    genDocBtn.innerHTML = '<i class="bi bi-arrow-repeat"></i> Generating...';

    try {
      const fd = new FormData();
      fd.append('csrf', CSRF);
      fd.append('history', his ? his.value : '');
      fd.append('impression', imp ? imp.value : '');
      fd.append('recommendations', rec ? rec.value : '');
      fd.append('referred_by', ref ? ref.value : '');
      fd.append('action', 'prep_email');

      const res = await fetch(window.location.href, {
        method: 'POST',
        body: fd,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      });

      const j = await res.json();
      if (j && j.ok && j.download_url) {
        setReady(j.download_url);
        genDocBtn.innerHTML = '<i class="bi bi-check2-circle"></i> Generated';
      } else {
        genDocBtn.innerHTML = '<i class="bi bi-exclamation-triangle"></i> Failed';
      }
    } catch (e) {
      genDocBtn.innerHTML = '<i class="bi bi-exclamation-triangle"></i> Failed';
    } finally {
      genDocBtn.disabled = false;
      setTimeout(() => {
        genDocBtn.innerHTML = '<i class="bi bi-magic"></i> Generate DOCX';
      }, 1200);
    }
  }

  if (emailBtn && emailModalEl && window.bootstrap) {
    emailBtn.addEventListener('click', function () {
      const m = bootstrap.Modal.getOrCreateInstance(emailModalEl);
      m.show();
    });
  } else if (emailBtn && emailModalEl) {
    emailBtn.addEventListener('click', function () {
      emailModalEl.classList.add('show');
      emailModalEl.style.display = 'block';
    });
  }

  if (genDocBtn) genDocBtn.addEventListener('click', prepareDocxForEmail);

  if (copyLinkBtn) copyLinkBtn.addEventListener('click', async function () {
    const link = (docLinkBox && docLinkBox.textContent) ? docLinkBox.textContent : '';
    if (!link) return;
    try {
      await navigator.clipboard.writeText(link);
      copyLinkBtn.innerHTML = '<i class="bi bi-check2"></i> Copied';
      setTimeout(() => copyLinkBtn.innerHTML = '<i class="bi bi-clipboard"></i> Copy Download Link', 900);
    } catch (e) {
      const ta = document.createElement('textarea');
      ta.value = link;
      document.body.appendChild(ta);
      ta.select();
      document.execCommand('copy');
      document.body.removeChild(ta);
      copyLinkBtn.innerHTML = '<i class="bi bi-check2"></i> Copied';
      setTimeout(() => copyLinkBtn.innerHTML = '<i class="bi bi-clipboard"></i> Copy Download Link', 900);
    }
  });

})();
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>