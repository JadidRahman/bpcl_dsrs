<?php
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers/report_mailer.php';

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
use Mpdf\Mpdf;
use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;

$me = require_role('CONSULTANT', 'ADMIN');
$pdo = db();

$aid = (int) ($_GET['id'] ?? 0);
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
    'OPPOSITIONAL' => 'Oppositional',
    'COGNITION' => 'Cognition',
    'HYPERACTIVITY' => 'Hyperactivity',
    'ADHD_INDEX' => 'ADHD Index',
];

/* =========================
   Helpers: parse dates safely
   Accepts: Y-m-d, d.m.Y, d-m-Y
========================= */
function parse_date_any($s): ?DateTime
{
    $s = trim((string) $s);
    if ($s === '' || $s === '0000-00-00')
        return null;

    $fmts = ['Y-m-d', 'd.m.Y', 'd-m-Y'];
    foreach ($fmts as $f) {
        $dt = DateTime::createFromFormat($f, $s);
        if ($dt && $dt->format($f) === $s)
            return $dt;
    }

    $ts = strtotime($s);
    if ($ts !== false) {
        $dt = new DateTime();
        $dt->setTimestamp($ts);
        return $dt;
    }
    return null;
}
function fmt_dmY(?DateTime $dt): string
{
    return $dt ? $dt->format('d.m.Y') : '-';
}

/* =========================
   Text fields (NEW DB columns)
   + Backward compatibility with old notes JSON
========================= */
$hasHistoryCol = array_key_exists('history_text', $ass);
$hasImpressionCol = array_key_exists('impression_text', $ass);
$hasRecommendationsCol = array_key_exists('recommendations_text', $ass);
$hasReferredByCol = array_key_exists('referred_by', $ass);

$referred_by = trim((string) ($hasReferredByCol ? ($ass['referred_by'] ?? '') : ''));

$history_text = trim((string) ($hasHistoryCol ? ($ass['history_text'] ?? '') : ''));
$impression_text = trim((string) ($hasImpressionCol ? ($ass['impression_text'] ?? '') : ''));
$recommendations_text = trim((string) ($hasRecommendationsCol ? ($ass['recommendations_text'] ?? '') : ''));

if ($history_text === '' && $impression_text === '' && $recommendations_text === '') {
    $rawNotes = (string) ($ass['notes'] ?? '');
    if ($rawNotes !== '') {
        $decoded = json_decode($rawNotes, true);
        if (is_array($decoded)) {
            $history_text = (string) ($decoded['history'] ?? $history_text);
            $impression_text = (string) ($decoded['impression'] ?? $impression_text);
            $recommendations_text = (string) ($decoded['recommendations'] ?? $recommendations_text);
            $referred_by = (string) ($decoded['referred_by'] ?? $referred_by);
        } else {
            $impression_text = $impression_text ?: $rawNotes;
        }
    }
}

/* =========================
   Display helpers (pattern)
   (Moved ABOVE email handler to fix $patientName undefined)
========================= */
$patientName = (string) ($ass['patient_name'] ?? '');
$gender = (string) ($ass['gender'] ?? '');
$category = (string) ($ass['category'] ?? '');
$assessedBy = (string) ($me['name'] ?? '');

$education = (string) ($ass['education'] ?? '');
$contactNo = (string) ($ass['contact_no'] ?? '');

$genderDisplay = ($gender === 'M') ? 'Male' : (($gender === 'F') ? 'Female' : ($gender ?: '-'));

$assessDt = parse_date_any($ass['assessment_date'] ?? '');
if (!$assessDt)
    $assessDt = parse_date_any(substr((string) ($ass['created_at'] ?? ''), 0, 10));
if (!$assessDt)
    $assessDt = new DateTime();
$assessDateDisplay = fmt_dmY($assessDt);

$ageYears = (int) ($ass['age_years'] ?? 0);
$ageMonths = (int) ($ass['age_months'] ?? 0);
$ageDays = (int) ($ass['age_days'] ?? 0);
if ($ageYears <= 0)
    $ageYears = (int) ($ass['age'] ?? 0);
if ($ageYears < 0)
    $ageYears = 0;
if ($ageMonths < 0)
    $ageMonths = 0;
if ($ageDays < 0)
    $ageDays = 0;

$dobDt = parse_date_any($ass['dob'] ?? '');
if (!$dobDt && ($ageYears || $ageMonths || $ageDays)) {
    $dobDt = clone $assessDt;
    if ($ageYears)
        $dobDt->modify("-{$ageYears} years");
    if ($ageMonths)
        $dobDt->modify("-{$ageMonths} months");
    if ($ageDays)
        $dobDt->modify("-{$ageDays} days");
}
$dobDisplay = fmt_dmY($dobDt);
/* =========================
   PRINT (PDF) letterhead assets  ✅ MOVE HERE
========================= */
$printFooterLine1 = "Address: Shimanto Shambhar Shopping Complex, 6th Floor, Dhanmondi, Road-2, Dhaka-1205, Bangladesh";
$printFooterLine2 = "Phone No: 09604604604, +8801872863002,E-mail: bdpsycare@gmail.com, Web: www.bdpsychiatriccare.com, fb.com/bdpsychiatric.care";

$appendixGuidelines = [
    ['70+', '98+', 'Markedly Atypical (Indicate Significant Problem)'],
    ['66-70', '95-98', 'Moderately Atypical (Indicate Significant Problem)'],
    ['61-65', '86-94', 'Mildly Atypical (Possible Significant Problem)'],
    ['56-60', '74-85', 'Slightly Atypical (Borderline; Should Raise Concern)'],
    ['45-55', '27-73', 'Average (Typical Score; Should not Raise concern)'],
    ['40-44', '16-26', 'Slightly Atypical (Low Score, not a concern)'],
    ['35-39', '6-15', 'Mildly Atypical (Low Score, Not concern)'],
    ['30-34', '2-5', 'Moderate Atypical (Low Score, Not concern)'],
    ['<30', '<2', 'Markedly Atypical (Low Score, are good, not concern)'],
];
/* =========================
   AUTOSAVE (AJAX)
   - Keeps your existing JS working
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') !== 'send_email')) {
    csrf_check();

    // Only respond JSON for AJAX
    if (strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) !== 'xmlhttprequest') {
        http_response_code(400);
        exit('Bad Request');
    }

    $history = (string) ($_POST['history'] ?? '');
    $impression = (string) ($_POST['impression'] ?? '');
    $recommendations = (string) ($_POST['recommendations'] ?? '');
    $ref_by = (string) ($_POST['referred_by'] ?? '');

    // Try updating real columns if they exist; otherwise fall back to notes JSON
    $set = [];
    $params = [];

    if ($hasHistoryCol) {
        $set[] = "history_text=?";
        $params[] = $history;
    }
    if ($hasImpressionCol) {
        $set[] = "impression_text=?";
        $params[] = $impression;
    }
    if ($hasRecommendationsCol) {
        $set[] = "recommendations_text=?";
        $params[] = $recommendations;
    }
    if ($hasReferredByCol) {
        $set[] = "referred_by=?";
        $params[] = $ref_by;
    }

    try {
        if (!empty($set)) {
            $sql = "UPDATE assessments SET " . implode(", ", $set) . " WHERE id=? LIMIT 1";
            $params[] = $aid;
            $u = $pdo->prepare($sql);
            $u->execute($params);
        } else {
            // notes JSON fallback
            $existingNotes = (string) ($ass['notes'] ?? '');
            $arr = [];
            if ($existingNotes !== '') {
                $tmp = json_decode($existingNotes, true);
                if (is_array($tmp))
                    $arr = $tmp;
            }
            $arr['history'] = $history;
            $arr['impression'] = $impression;
            $arr['recommendations'] = $recommendations;
            $arr['referred_by'] = $ref_by;

            $json = json_encode($arr, JSON_UNESCAPED_UNICODE);
            $u = $pdo->prepare("UPDATE assessments SET notes=? WHERE id=? LIMIT 1");
            $u->execute([$json, $aid]);
        }

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'saved_at' => date('H:i:s')]);
        exit;
    } catch (Throwable $e) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Save failed']);
        exit;
    }
}

/* =========================
   SEND EMAIL (AJAX)
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (($_POST['action'] ?? '') === 'send_email')) {
    csrf_check();

    header('Content-Type: application/json; charset=utf-8');

    try {
        $to = trim((string) ($_POST['to'] ?? ''));
        $subject = trim((string) ($_POST['subject'] ?? ''));
        $bodyText = (string) ($_POST['bodyText'] ?? '');

        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['ok' => false, 'error' => 'Invalid recipient email.']);
            exit;
        }

        $REPORT_TITLE = 'ADHD Assessment Report';
        if ($subject === '') {
            $subject = $REPORT_TITLE . ' — ' . ($patientName ?: 'Patient') . " (Assessment #{$aid})";
        }

        if (trim($bodyText) === '') {
            $bodyText =
                "Greetings from BPCL,\n\n" .
                "Please find attached the ADHD Assessment Report.\n\n If you have any questions, please feel free to contact us.\n\n" .
                "Regards,\n" .
                "Bangladesh Psychiatric Care Ltd.\n" .
                "Phone: 09604604604";
        }

        if (!function_exists('bpcl_generate_report_pdf_to_path')) {
            echo json_encode(['ok' => false, 'error' => 'PDF generator not available.']);
            exit;
        }

        // ✅ Build a real temp file path
        $safeName = preg_replace('/[^a-zA-Z0-9_\-]+/', '_', ($patientName ?: 'Patient'));
        $attachmentName = "ADHD_Assessment_Report_{$safeName}_{$aid}.pdf";

        $tmpDir = sys_get_temp_dir();
        $tmpPath = rtrim($tmpDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . uniqid('bpcl_report_', true) . '.pdf';

        // ✅ Generate PDF to temp
        bpcl_generate_report_pdf_to_path($tmpPath);

        // ✅ Send
        $send = send_report_email_with_attachment([
            'to' => $to,
            'subject' => $subject,
            'bodyText' => $bodyText,

            'patientName' => $patientName ?: '—',
            'assessmentDate' => $assessDateDisplay ?: '—',
            'assessmentName' => 'ADHD Assessment Report',
            'assessedBy' => $assessedBy ?: '—',

            'attachmentNote' => 'If the preview looks incomplete in Gmail, please download and open the attachment.',
            'attachmentPath' => $tmpPath,
            'attachmentName' => $attachmentName,
        ]);

        @unlink($tmpPath);

        if (!empty($send['ok'])) {
            echo json_encode(['ok' => true]);
        } else {
            echo json_encode([
                'ok' => false,
                'error' => (string) ($send['error'] ?? $send['message'] ?? $send['debug'] ?? 'Email send failed.')
            ]);
        }
        exit;

    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => 'Mailer crash: ' . $e->getMessage()]);
        exit;
    }
}
/* =========================
   PDF BUILDER (mPDF)
   - Perfect header/footer zones (no overlap)
   - Works on Hostinger shared hosting (no exec)
   - Used by email (and you can later add ?download=pdf if you want)
========================= */
function bpcl_generate_report_pdf_to_path(string $outputPath): void
{
    // keep these - helpful for HTML parsing
    @ini_set('pcre.backtrack_limit', '5000000');
    @ini_set('pcre.recursion_limit', '200000');

    // (optional) try to raise memory if allowed by host/xampp
    @ini_set('memory_limit', '1024M');

    $aid = (int) ($GLOBALS['aid'] ?? 0);

    $patientName = (string) ($GLOBALS['patientName'] ?? '');
    $assessDateDisplay = (string) ($GLOBALS['assessDateDisplay'] ?? '-');
    $dobDisplay = (string) ($GLOBALS['dobDisplay'] ?? '-');
    $ageYears = (int) ($GLOBALS['ageYears'] ?? 0);
    $ageMonths = (int) ($GLOBALS['ageMonths'] ?? 0);
    $ageDays = (int) ($GLOBALS['ageDays'] ?? 0);
    $genderDisplay = (string) ($GLOBALS['genderDisplay'] ?? '-');
    $education = (string) ($GLOBALS['education'] ?? '');
    $contactNo = (string) ($GLOBALS['contactNo'] ?? '');
    $referred_by = (string) ($GLOBALS['referred_by'] ?? '');
    $assessedBy = (string) ($GLOBALS['assessedBy'] ?? '');

    $scores = (array) ($GLOBALS['scores'] ?? []);
    $labels = (array) ($GLOBALS['labels'] ?? []);

    $history_text = (string) ($GLOBALS['history_text'] ?? '');
    $impression_text = (string) ($GLOBALS['impression_text'] ?? '');
    $recommendations_text = (string) ($GLOBALS['recommendations_text'] ?? '');

    // ✅ IMPORTANT: if for any reason globals are empty, fall back safely
    $printFooterLine1 = (string) ($GLOBALS['printFooterLine1'] ?? '');
    $printFooterLine2 = (string) ($GLOBALS['printFooterLine2'] ?? '');

    if ($printFooterLine1 === '') {
        $printFooterLine1 = "Address: Shimanto Shambhar Shopping Complex, 6th Floor, Dhanmondi, Road-2, Dhaka-1205, Bangladesh";
    }
    if ($printFooterLine2 === '') {
        $printFooterLine2 = "Phone No: 09604604604, +8801872863002,E-mail: bdpsycare@gmail.com, Web: www.bdpsychiatriccare.com, fb.com/bdpsychiatric.care";
    }
    $appendixGuidelines = (array) ($GLOBALS['appendixGuidelines'] ?? []);

    if (empty($appendixGuidelines)) {
        // fallback (prevents blank appendix)
        $appendixGuidelines = [
            ['70+', '98+', 'Markedly Atypical (Indicate Significant Problem)'],
            ['66-70', '95-98', 'Moderately Atypical (Indicate Significant Problem)'],
            ['61-65', '86-94', 'Mildly Atypical (Possible Significant Problem)'],
            ['56-60', '74-85', 'Slightly Atypical (Borderline; Should Raise Concern)'],
            ['45-55', '27-73', 'Average (Typical Score; Should not Raise concern)'],
        ];
    }

    // ✅ Logo: resize PNG -> JPG to stop memory explosion in mPDF
    $logoFsPath = dirname(__DIR__) . '/assets/img/BPCL Logo.png';
    $logoUri = '';

    $makeFileUri = function (string $fsPath): string {
        $p = str_replace('\\', '/', realpath($fsPath));
        return $p ? ('file:///' . ltrim($p, '/')) : '';
    };

    $getResizedLogoJpg = function (string $srcPng): string {
        if (!is_file($srcPng) || !is_readable($srcPng))
            return '';

        $cache = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'bpcl_logo_mpdf_cache.jpg';
        $srcM = @filemtime($srcPng);
        $dstM = @filemtime($cache);

        // reuse cached resized logo
        if (is_file($cache) && $dstM !== false && $srcM !== false && $dstM >= $srcM) {
            return $cache;
        }

        if (!function_exists('imagecreatefrompng')) {
            // no GD available, use original
            return $srcPng;
        }

        $im = @imagecreatefrompng($srcPng);
        if (!$im)
            return $srcPng;

        $w = imagesx($im);
        $h = imagesy($im);

        // target width ~ 520px (sharp but light)
        $targetW = 520;
        $ratio = ($w > 0) ? ($targetW / $w) : 1;
        $targetH = (int) max(1, round($h * $ratio));

        $dst = imagecreatetruecolor($targetW, $targetH);
        $white = imagecolorallocate($dst, 255, 255, 255);
        imagefilledrectangle($dst, 0, 0, $targetW, $targetH, $white);

        imagecopyresampled($dst, $im, 0, 0, 0, 0, $targetW, $targetH, $w, $h);

        // write jpg
        @imagejpeg($dst, $cache, 85);

        imagedestroy($im);
        imagedestroy($dst);

        return is_file($cache) ? $cache : $srcPng;
    };

    $logoToUse = $getResizedLogoJpg($logoFsPath);
    if ($logoToUse)
        $logoUri = $makeFileUri($logoToUse);

    // mPDF init
    $defaultConfig = (new \Mpdf\Config\ConfigVariables())->getDefaults();
    $fontDirs = $defaultConfig['fontDir'];

    $defaultFontConfig = (new \Mpdf\Config\FontVariables())->getDefaults();
    $fontData = $defaultFontConfig['fontdata'];

    $mpdf = new \Mpdf\Mpdf([
        'mode' => 'utf-8',
        'format' => 'A4',

        'margin_left' => 14,
        'margin_right' => 14,
        'margin_top' => 40,

        // ✅ KEEP your rule (0.6")
        'margin_bottom' => 15.24,

        // ✅ IMPORTANT FIX:
        // footer needs more “reserved area” otherwise text gets clipped (Gmail preview clips first)
        'margin_footer' => 16,   // was 6
        'margin_header' => 8,

        'dpi' => 96,
        'img_dpi' => 96,

        'fontDir' => $fontDirs,
        'fontdata' => $fontData,
        'default_font' => 'dejavuserif',
        'tempDir' => sys_get_temp_dir(),
    ]);
    $mpdf->setAutoBottomMargin = 'stretch';
    $mpdf->SetTitle('ADHD Assessment Report');
    $mpdf->SetAuthor('Bangladesh Psychiatric Care Ltd.');
    $mpdf->SetDisplayMode('fullpage');

    // Header / Footer (tight + safe for Gmail preview)
    $headerHtml = '
    <div style="width:100%; padding-top:1mm;">
      <div style="display:flex; align-items:flex-start;">
        <div style="width:70mm;">' .
        ($logoUri ? '<img src="' . $logoUri . '" style="height:60m; width:auto; display:block;">' : '') .
        '</div>
        <div style="flex:1;"></div>
      </div>
    </div>';

    $footerHtml = '
<div style="width:100%; font-size:7.5pt; line-height:1.05; text-align:center; color:#111;">
  <div style="border-top:2px solid #71BF44; margin:0 0 1mm 0;"></div>
  <div style="margin:0; padding:0;">' . htmlspecialchars($printFooterLine1, ENT_QUOTES, "UTF-8") . '</div>
  <div style="margin:0; padding:0;">' . htmlspecialchars($printFooterLine2, ENT_QUOTES, "UTF-8") . '</div>
</div>';

    $mpdf->SetHTMLHeader($headerHtml);
    $mpdf->SetHTMLFooter($footerHtml);

    // Helpers
    $esc = function ($v) {
        return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    };
    $nl = function ($v) use ($esc): string {
        $t = trim((string) $v);
        if ($t === '')
            $t = '-';
        $t = str_replace(["\r\n", "\r"], "\n", $t);
        $parts = explode("\n", $t);
        $out = '';
        foreach ($parts as $i => $line) {
            $line = trim(preg_replace('/\s+/', ' ', $line));
            $out .= $esc($line);
            if ($i !== count($parts) - 1)
                $out .= "<br>";
        }
        return $out;
    };

    // CSS
    $css = '
    body{ font-family:"Times New Roman", serif; font-size:11pt; line-height:1.55; color:#111; }
    .center{ text-align:center; }
    .h1{ font-size:12pt; font-weight:700; text-decoration:underline; margin:0 0 4mm 0; }
    .lead{ margin:0 0 4mm 0; }
    .meta{ margin:0 0 4mm 0; line-height:1.25; }
    .sec{ font-weight:700; margin:4mm 0 2mm 0; }
    .just{ text-align:justify; text-justify:inter-word; margin:0 0 3mm 0; }
    table{ width:100%; border-collapse:collapse; table-layout:fixed; font-size:11pt; }
    th,td{ border:1px solid #111; padding:6px 8px; vertical-align:top; word-wrap:break-word; }
    th{ font-weight:700; background:#f2f2f2; }
    .sigline{ width:70mm; border-bottom:1px solid #111; height:0; margin:12mm 0 3mm 0; }
    ';
    $mpdf->WriteHTML($css, \Mpdf\HTMLParserMode::HEADER_CSS);
    $bodyH = $mpdf->h - $mpdf->tMargin - $mpdf->bMargin; // printable body height in mm
    /* ================= COVER PAGE ================= */
    $mpdf->WriteHTML('
  <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse; width:100%;">
    <tr>
      <td style="height:' . number_format($bodyH, 2, '.', '') . 'mm; vertical-align:middle; text-align:center;">
        
        <div style="display:inline-block; padding:10mm 18mm;">
          <div style="font-size:18pt; font-weight:700; margin:0;">ADHD Assessment Report</div>
          <div style="font-size:14pt; margin:2mm 0 0 0;">Conners\' Parent Rating Scale</div>
        </div>

      </td>
    </tr>
  </table>
', \Mpdf\HTMLParserMode::HTML_BODY);

    $mpdf->AddPage();

    /* ================= MAIN REPORT ================= */
    $mpdf->WriteHTML('<div class="h1 center">ADHD Assessment Report</div>', \Mpdf\HTMLParserMode::HTML_BODY);

    $mpdf->WriteHTML('
      <div class="meta">
        Name: ' . $esc($patientName) . '<br>
        Date of Assessment: ' . $esc($assessDateDisplay) . '<br>
        Date of Birth: ' . $esc($dobDisplay) . '<br>
        Chronological Age: ' . (int) $ageYears . ' years, ' . (int) $ageMonths . ' months, ' . (int) $ageDays . ' days<br>
        Gender: ' . $esc($genderDisplay) . '<br>
        Education: ' . $esc($education ?: '-') . '<br>
        Contact No: ' . $esc($contactNo ?: '-') . '<br>
        Referred By: ' . $esc($referred_by ?: '-') . '
      </div>
      <div class="lead">
        Conners’ Parent Rating Scale was applied to assess whether the patient has ADHD. His score is given below:
      </div>
    ', \Mpdf\HTMLParserMode::HTML_BODY);

    $mpdf->WriteHTML('
      <table>
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
    ', \Mpdf\HTMLParserMode::HTML_BODY);

    if (!empty($scores)) {
        foreach ($scores as $s) {
            $sub = (string) ($s['subscale'] ?? '');
            $domain = $labels[$sub] ?? $sub;

            $mpdf->WriteHTML('
              <tr>
                <td>' . $esc($domain) . '</td>
                <td>' . (int) ($s['raw_total'] ?? 0) . '</td>
                <td>' . (int) ($s['t_score'] ?? 0) . '</td>
                <td>' . $esc($s['percentile_label'] ?? '') . '</td>
                <td>' . $esc($s['guideline'] ?? '') . '</td>
              </tr>
            ', \Mpdf\HTMLParserMode::HTML_BODY);
        }
    } else {
        $mpdf->WriteHTML('<tr><td colspan="5">No scores found. Please complete OMR and calculate result.</td></tr>', \Mpdf\HTMLParserMode::HTML_BODY);
    }

    $mpdf->WriteHTML('</tbody></table>', \Mpdf\HTMLParserMode::HTML_BODY);

    $mpdf->WriteHTML('<div class="sec">History</div><div class="just">' . $nl($history_text) . '</div>', \Mpdf\HTMLParserMode::HTML_BODY);
    $mpdf->WriteHTML('<div class="sec">Impression / Diagnosis</div><div class="just">' . $nl($impression_text) . '</div>', \Mpdf\HTMLParserMode::HTML_BODY);
    $mpdf->WriteHTML('<div class="sec">Recommendations</div><div class="just">' . $nl($recommendations_text) . '</div>', \Mpdf\HTMLParserMode::HTML_BODY);

    $mpdf->WriteHTML('
      <div class="sec" style="margin-top:6mm;">Assessed By</div>
      <div class="sigline"></div>
      <div>' . $esc($assessedBy) . '</div>
      <div>Clinical Psychologist</div>
      <div>Bangladesh Psychiatric Care Ltd.</div>
    ', \Mpdf\HTMLParserMode::HTML_BODY);

    $mpdf->AddPage();

    /* ================= APPENDIX ================= */
    $mpdf->WriteHTML('
      <div class="center" style="font-size:18pt; font-weight:700; margin-top:20mm; margin-bottom:8mm;">Appendix</div>
      <div class="center" style="font-size:12pt; font-weight:700; text-decoration:underline; margin:0 0 5mm 0;">
        Conners’ Parent Rating Scale:
      </div>
      <div class="center" style="font-size:11pt; font-weight:700; margin:0 0 5mm 0;">
        Interpretative Guidelines for T-Score and Percentiles
      </div>
    ', \Mpdf\HTMLParserMode::HTML_BODY);

    $mpdf->WriteHTML('
      <table>
        <thead>
          <tr>
            <th style="width:18%;">T-Score</th>
            <th style="width:18%;">Percentile</th>
            <th>Guideline</th>
          </tr>
        </thead>
        <tbody>
    ', \Mpdf\HTMLParserMode::HTML_BODY);

    foreach ($appendixGuidelines as $r) {
        $mpdf->WriteHTML('
          <tr>
            <td>' . $esc($r[0] ?? '') . '</td>
            <td>' . $esc($r[1] ?? '') . '</td>
            <td>' . $esc($r[2] ?? '') . '</td>
          </tr>
        ', \Mpdf\HTMLParserMode::HTML_BODY);
    }

    $mpdf->WriteHTML('</tbody></table>', \Mpdf\HTMLParserMode::HTML_BODY);

    $mpdf->Output($outputPath, \Mpdf\Output\Destination::FILE);
}


/* =========================
   DOCX BUILDER (Reusable)
   - used by:
     1) ?download=word
     2) AJAX email send (temp file)
========================= */
function bpcl_generate_report_docx_to_path(string $outputPath): void
{

    $aid = (int) ($GLOBALS['aid'] ?? 0);
    $patientName = (string) ($GLOBALS['patientName'] ?? '');
    $assessDateDisplay = (string) ($GLOBALS['assessDateDisplay'] ?? '-');
    $dobDisplay = (string) ($GLOBALS['dobDisplay'] ?? '-');
    $ageYears = (int) ($GLOBALS['ageYears'] ?? 0);
    $ageMonths = (int) ($GLOBALS['ageMonths'] ?? 0);
    $ageDays = (int) ($GLOBALS['ageDays'] ?? 0);
    $genderDisplay = (string) ($GLOBALS['genderDisplay'] ?? '-');
    $education = (string) ($GLOBALS['education'] ?? '');
    $contactNo = (string) ($GLOBALS['contactNo'] ?? '');
    $referred_by = (string) ($GLOBALS['referred_by'] ?? '');
    $assessedBy = (string) ($GLOBALS['assessedBy'] ?? '');

    $scores = (array) ($GLOBALS['scores'] ?? []);
    $labels = (array) ($GLOBALS['labels'] ?? []);

    $history_text = (string) ($GLOBALS['history_text'] ?? '');
    $impression_text = (string) ($GLOBALS['impression_text'] ?? '');
    $recommendations_text = (string) ($GLOBALS['recommendations_text'] ?? '');

    $printFooterLine1 = (string) ($GLOBALS['printFooterLine1'] ?? '');
    $printFooterLine2 = (string) ($GLOBALS['printFooterLine2'] ?? '');

    $appendixGuidelines = (array) ($GLOBALS['appendixGuidelines'] ?? []);

    $W = function ($v) {
        return (string) $v;
    };

    $addJustifiedBlock = function ($section, $text, $spaceAfter = 240) {
        $text = (string) $text;
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = trim($text);
        if ($text === '')
            $text = '-';

        $p = [
            'alignment' => \PhpOffice\PhpWord\SimpleType\Jc::BOTH,
            'spaceBefore' => 0,
            'spaceAfter' => $spaceAfter,
            'lineHeight' => 1.15,
        ];

        $run = $section->addTextRun($p);
        $lines = explode("\n", $text);
        $last = count($lines) - 1;

        foreach ($lines as $i => $line) {
            $line = str_replace("\t", " ", $line);
            $line = preg_replace('/[ ]{2,}/', ' ', $line);
            $line = preg_replace('/([.!?])([A-Za-z])/', '$1 $2', $line);

            $run->addText(rtrim($line));
            if ($i !== $last)
                $run->addTextBreak(1);
        }
    };

    $addRecommendationsAsParagraphs = function ($section, $text) {
        $text = (string) $text;
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = trim($text);

        if ($text === '') {
            $section->addText('-', [], ['spaceBefore' => 0, 'spaceAfter' => 240]);
            return;
        }

        $lines = array_values(array_filter(array_map('trim', explode("\n", $text)), fn($v) => $v !== ''));

        $p = [
            'spaceBefore' => 0,
            'spaceAfter' => 0,
            'lineHeight' => 1.15,
            'alignment' => \PhpOffice\PhpWord\SimpleType\Jc::LEFT,
        ];

        foreach ($lines as $line) {
            $line = str_replace("\t", " ", $line);
            $line = preg_replace('/[ ]{2,}/', ' ', $line);
            $line = preg_replace('/([.!?])([A-Za-z])/', '$1 $2', $line);
            $section->addText($line, [], $p);
        }

        $section->addText('', [], ['spaceBefore' => 0, 'spaceAfter' => 240]);
    };

    $logoFsPath = dirname(__DIR__) . '/assets/img/BPCL Logo.png';
    $footerLine1 = $printFooterLine1;
    $footerLine2 = $printFooterLine2;

    $phpWord = new \PhpOffice\PhpWord\PhpWord();
    $phpWord->setDefaultFontName('Times New Roman');
    $phpWord->setDefaultFontSize(11);

    $C = '\PhpOffice\PhpWord\Shared\Converter';
    \PhpOffice\PhpWord\Settings::setOutputEscapingEnabled(true);

    $section = $phpWord->addSection([
        'marginTop' => $C::inchToTwip(1.4),
        'marginBottom' => $C::inchToTwip(0.6),
        'marginLeft' => $C::inchToTwip(0.55),
        'marginRight' => $C::inchToTwip(0.55),
        'headerHeight' => $C::inchToTwip(0.35),
        'footerHeight' => $C::inchToTwip(0.25),
    ]);

    $header = $section->addHeader();
    $hdrTbl = $header->addTable([
        'borderSize' => 0,
        'borderColor' => 'FFFFFF',
        'cellMarginTop' => 0,
        'cellMarginBottom' => 0,
        'cellMarginLeft' => 0,
        'cellMarginRight' => 0,
    ]);

    $hdrTbl->addRow(\PhpOffice\PhpWord\Shared\Converter::inchToTwip(0.75));

    $noCellBorder = [
        'valign' => 'center',
        'borderTopSize' => 0,
        'borderLeftSize' => 0,
        'borderRightSize' => 0,
        'borderBottomSize' => 0,
        'borderTopColor' => 'FFFFFF',
        'borderLeftColor' => 'FFFFFF',
        'borderRightColor' => 'FFFFFF',
        'borderBottomColor' => 'FFFFFF',
    ];

    $cellLogo = $hdrTbl->addCell(\PhpOffice\PhpWord\Shared\Converter::inchToTwip(2.0), $noCellBorder);
    $hdrTbl->addCell(\PhpOffice\PhpWord\Shared\Converter::inchToTwip(6.1), $noCellBorder);

    if (is_file($logoFsPath) && is_readable($logoFsPath)) {
        $cellLogo->addImage($logoFsPath, [
            'width' => \PhpOffice\PhpWord\Shared\Converter::inchToPixel(1.35),
            'alignment' => \PhpOffice\PhpWord\SimpleType\Jc::LEFT,
        ]);
    }

    $footer = $section->addFooter();
    $jcCenter = \PhpOffice\PhpWord\SimpleType\Jc::CENTER;

    $footer->addText(' ', [], [
        'alignment' => $jcCenter,
        'spaceBefore' => 0,
        'spaceAfter' => 120,
        'borderBottomSize' => 12,
        'borderBottomColor' => '71BF44',
    ]);

    $footP = ['alignment' => $jcCenter, 'spaceBefore' => 0, 'spaceAfter' => 0];
    $footer->addText($footerLine1, ['size' => 9], $footP);
    $footer->addText($footerLine2, ['size' => 9], $footP);

    // Cover page
    $section->addText(
        'ADHD Assessment Report',
        ['name' => 'Times New Roman', 'size' => 18, 'bold' => true],
        [
            'alignment' => $jcCenter,
            'spaceBefore' => $C::inchToTwip(3.2),
            'spaceAfter' => 120,
        ]
    );

    $section->addText(
        "Conners' Parent Rating Scale",
        ['name' => 'Times New Roman', 'size' => 14, 'bold' => false],
        [
            'alignment' => $jcCenter,
            'spaceBefore' => 0,
            'spaceAfter' => 0,
        ]
    );

    $section->addPageBreak();

    // Main report page
    $section->addText('ADHD Assessment Report', [
        'bold' => true,
        'underline' => 'single',
    ], [
        'alignment' => $jcCenter,
        'spaceAfter' => 240,
    ]);

    $meta = $section->addTextRun([
        'spaceBefore' => 0,
        'spaceAfter' => 0,
        'lineHeight' => 1.0,
    ]);

    $meta->addText("Name: " . $W($patientName));
    $meta->addTextBreak(1);
    $meta->addText("Date of Assessment: " . $W($assessDateDisplay));
    $meta->addTextBreak(1);
    $meta->addText("Date of Birth: " . $W($dobDisplay));
    $meta->addTextBreak(1);
    $meta->addText("Chronological Age: " . (int) $ageYears . " years, " . (int) $ageMonths . " months, " . (int) $ageDays . " days");
    $meta->addTextBreak(1);
    $meta->addText("Gender: " . $W($genderDisplay));
    $meta->addTextBreak(1);
    $meta->addText("Education: " . $W($education ?: '-'));
    $meta->addTextBreak(1);
    $meta->addText("Contact No: " . $W($contactNo ?: '-'));
    $meta->addTextBreak(1);
    $meta->addText("Referred By: " . $W($referred_by ?: '-'));

    $section->addTextBreak(1);

    $section->addText(
        "Conners’ Parent Rating Scale was applied to assess whether the patient has ADHD. His score is given below:",
        [],
        ['spaceAfter' => 240]
    );

    $table = $section->addTable([
        'borderSize' => 6,
        'borderColor' => '111111',
        'cellMarginTop' => 80,
        'cellMarginBottom' => 80,
        'cellMarginLeft' => 120,
        'cellMarginRight' => 120,
    ]);

    $table->addRow();
    $hdrCellStyle = ['bgColor' => 'F2F2F2'];
    $table->addCell(2200, $hdrCellStyle)->addText('Domain', ['bold' => true]);
    $table->addCell(900, $hdrCellStyle)->addText('R. Total', ['bold' => true]);
    $table->addCell(900, $hdrCellStyle)->addText('T-Score', ['bold' => true]);
    $table->addCell(1200, $hdrCellStyle)->addText('Percentile', ['bold' => true]);
    $table->addCell(5200, $hdrCellStyle)->addText('Guideline', ['bold' => true]);

    if (!empty($scores)) {
        foreach ($scores as $s) {
            $sub = (string) ($s['subscale'] ?? '');
            $domain = $labels[$sub] ?? $sub;

            $table->addRow();
            $table->addCell(2200)->addText($domain);
            $table->addCell(900)->addText((string) (int) ($s['raw_total'] ?? 0));
            $table->addCell(900)->addText((string) (int) ($s['t_score'] ?? 0));
            $table->addCell(1200)->addText((string) ($s['percentile_label'] ?? ''));
            $table->addCell(5200)->addText((string) ($s['guideline'] ?? ''));
        }
    } else {
        $table->addRow();
        $table->addCell(10200)->addText('No scores found. Please complete OMR and calculate result.');
    }

    $section->addTextBreak(1);

    $section->addText('History', ['bold' => true], ['spaceAfter' => 120]);
    $addJustifiedBlock($section, $history_text, 240);

    $section->addText('Impression / Diagnosis', ['bold' => true], ['spaceAfter' => 120]);
    $addJustifiedBlock($section, $impression_text, 240);

    $section->addText('Recommendations', ['bold' => true], ['spaceAfter' => 120]);
    $addRecommendationsAsParagraphs($section, $recommendations_text);

    $section->addTextBreak(1);

    $section->addText('Assessed By', ['bold' => true], ['spaceAfter' => 120]);
    $section->addText('', [], ['spaceAfter' => 80]);

    $section->addText(' ', [], [
        'spaceBefore' => 0,
        'spaceAfter' => 160,
        'borderBottomSize' => 12,
        'borderBottomColor' => '111111',
        'alignment' => \PhpOffice\PhpWord\SimpleType\Jc::LEFT,
        'indentation' => [
            'left' => 0,
            'right' => \PhpOffice\PhpWord\Shared\Converter::inchToTwip(4.0),
        ],
    ]);

    $assP = ['spaceBefore' => 0, 'spaceAfter' => 0, 'lineHeight' => 1.15];
    $section->addText($assessedBy, [], $assP);
    $section->addText('Clinical Psychologist', [], $assP);
    $section->addText('Bangladesh Psychiatric Care Ltd.', [], $assP);

    // Appendix
    $section->addPageBreak();

    $section->addText(
        'Appendix',
        ['name' => 'Times New Roman', 'size' => 18, 'bold' => true],
        [
            'alignment' => $jcCenter,
            'spaceBefore' => $C::inchToTwip(1.2),
            'spaceAfter' => 240,
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
        'borderSize' => 6,
        'borderColor' => '111111',
        'cellMarginTop' => 80,
        'cellMarginBottom' => 80,
        'cellMarginLeft' => 120,
        'cellMarginRight' => 120,
    ]);

    $gTbl->addRow();
    $hdrCellStyle = ['bgColor' => 'F2F2F2'];
    $gTbl->addCell(1700, $hdrCellStyle)->addText('T-Score', ['bold' => true]);
    $gTbl->addCell(1700, $hdrCellStyle)->addText('Percentile', ['bold' => true]);
    $gTbl->addCell(6800, $hdrCellStyle)->addText('Guideline', ['bold' => true]);

    foreach ($appendixGuidelines as $r) {
        $gTbl->addRow();
        $gTbl->addCell(1700)->addText($r[0]);
        $gTbl->addCell(1700)->addText($r[1]);
        $gTbl->addCell(6800)->addText($r[2]);
    }

    $writer = \PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'Word2007');
    $writer->save($outputPath);
}

/* =========================
   DOWNLOAD AS WORD (.docx)
========================= */
if (($_GET['download'] ?? '') === 'word') {
    $safeName = preg_replace('/[^a-zA-Z0-9_\-]+/', '_', ($patientName ?: 'Patient'));
    $fname = "ADHD_Assessment_Report_{$safeName}_{$aid}.docx";

    $tmpDir = sys_get_temp_dir();
    $tmpPath = rtrim($tmpDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . uniqid('bpcl_report_', true) . '.docx';

    bpcl_generate_report_docx_to_path($tmpPath);

    header("Content-Description: File Transfer");
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header('Content-Transfer-Encoding: binary');
    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
    header('Expires: 0');

    readfile($tmpPath);
    @unlink($tmpPath);
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
        /* ✅ 0.6" */
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

    .rep-shell {
        max-width: 1120px;
        margin: 0 auto;
        position: relative;
    }

    .rep-geo {
        position: absolute;
        inset: -120px -40px auto -40px;
        height: 420px;
        pointer-events: none;
        z-index: 0;
        opacity: .95;
    }

    .rep-geo:before {
        content: "";
        position: absolute;
        inset: 0;
        background:
            radial-gradient(640px 360px at 10% 20%, rgba(241, 114, 82, .14), transparent 62%),
            radial-gradient(620px 360px at 92% 22%, rgba(43, 89, 255, .14), transparent 62%),
            radial-gradient(520px 360px at 55% 110%, rgba(113, 191, 68, .10), transparent 60%);
    }

    .rep-geo:after {
        content: "";
        position: absolute;
        inset: 0;
        background-image: radial-gradient(rgba(16, 24, 40, .08) 1px, transparent 1px);
        background-size: 18px 18px;
        mask-image: radial-gradient(closest-side, rgba(0, 0, 0, .85), transparent 78%);
        opacity: .35;
    }

    .rep-card {
        position: relative;
        z-index: 1;
        background: var(--card);
        border: 1px solid var(--line);
        border-radius: 22px;
        overflow: hidden;
        box-shadow: 0 26px 70px rgba(16, 24, 40, .10);
    }

    .rep-hero {
        padding: 18px 18px 14px;
        border-bottom: 1px solid rgba(233, 237, 245, .85);
        background:
            radial-gradient(900px 520px at 15% 0%, rgba(241, 114, 82, .08), transparent 60%),
            radial-gradient(900px 520px at 92% 10%, rgba(43, 89, 255, .08), transparent 62%),
            linear-gradient(180deg, #ffffff, #fbfcff);
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 12px;
        flex-wrap: wrap;
    }

    .rep-title {
        margin: 0;
        font-weight: 1000;
        letter-spacing: -.4px;
        color: #111827;
        font-size: clamp(18px, 2.2vw, 22px);
        line-height: 1.1;
    }

    .rep-sub {
        margin: 6px 0 0;
        color: var(--muted);
        font-size: 13px;
        line-height: 1.7;
    }

    .rep-badges {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin-top: 10px;
    }

    .rep-badge {
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

    .rep-badge i {
        opacity: .9;
    }

    .rep-actions {
        display: flex;
        align-items: center;
        justify-content: flex-end;
        gap: 10px;
        flex-wrap: wrap;
    }

    .rep-btn {
        border-radius: 14px !important;
        font-weight: 900 !important;
        box-shadow: 0 10px 22px rgba(16, 24, 40, .06);
    }

    .rep-btn-primary {
        border: 0 !important;
        color: #fff !important;
        background: linear-gradient(135deg, var(--b-orange), #ff8a6f) !important;
        box-shadow: 0 18px 45px rgba(241, 114, 82, .22) !important;
    }

    .rep-btn-primary:hover {
        filter: brightness(.98);
        transform: translateY(-1px);
    }

    .rep-body {
        padding: 18px 22px 24px;
    }

    @media(min-width:992px) {
        .rep-body {
            padding: 18px 26px 28px;
        }
    }

    .rep-meta {
        display: grid;
        grid-template-columns: 1fr;
        gap: 12px;
        margin: 6px 0 12px;
    }

    @media(min-width:992px) {
        .rep-meta {
            grid-template-columns: 1fr 1fr;
            gap: 14px;
        }
    }

    .rep-box {
        border: 1px solid var(--line);
        border-radius: 18px;
        padding: 14px 14px;
        background: #fff;
        box-shadow: 0 16px 42px rgba(16, 24, 40, .06);
    }

    .rep-k {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        color: #111827;
        font-weight: 1000;
        letter-spacing: -.2px;
        font-size: 13px;
        margin-bottom: 10px;
    }

    .rep-k .pill {
        font-size: 12px;
        font-weight: 900;
        color: #111827;
        border: 1px solid rgba(233, 237, 245, .95);
        background: linear-gradient(180deg, #fff, #fbfcff);
        padding: 7px 10px;
        border-radius: 999px;
    }

    .rep-s {
        color: #111827;
        font-size: 13px;
        line-height: 1.75;
    }

    .rep-s strong {
        font-weight: 950;
    }

    .rep-section {
        margin-top: 14px;
    }

    .rep-h {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        margin: 0 0 8px;
    }

    .rep-h b {
        font-weight: 1000;
        letter-spacing: -.2px;
        color: #111827;
        font-size: 14px;
    }

    .rep-h .chip {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 7px 10px;
        border-radius: 999px;
        border: 1px solid rgba(233, 237, 245, .95);
        background: #fff;
        font-size: 12px;
        font-weight: 900;
        color: var(--muted);
    }

    .rep-textarea {
        width: 100%;
        border: 1px solid rgba(233, 237, 245, .95);
        border-radius: 16px;
        padding: 12px 12px;
        font-size: 14px;
        line-height: 1.75;
        resize: none;
        overflow: hidden;
        background: #fff;
        color: #111;
        text-align: justify;
        text-justify: inter-word;
        min-height: 92px;
        box-shadow: 0 14px 40px rgba(16, 24, 40, .06);
        transition: border-color .15s ease, box-shadow .15s ease;
    }

    .rep-textarea:focus {
        outline: none;
        border-color: rgba(43, 89, 255, .35);
        box-shadow: 0 18px 46px rgba(43, 89, 255, .10);
    }

    .rep-printtext {
        display: none;
        white-space: pre-wrap;
        word-break: break-word;
    }

    .rep-lead {
        margin: 12px 0 12px;
        padding: 12px 14px;
        border-radius: 18px;
        border: 1px solid rgba(233, 237, 245, .95);
        background:
            radial-gradient(520px 240px at 10% 10%, rgba(241, 114, 82, .08), transparent 60%),
            radial-gradient(520px 240px at 92% 10%, rgba(43, 89, 255, .08), transparent 60%),
            linear-gradient(180deg, #ffffff, #fbfcff);
        color: #111827;
        font-size: 13.5px;
        line-height: 1.75;
        box-shadow: 0 16px 44px rgba(16, 24, 40, .06);
    }

    .rep-table {
        width: 100%;
        border: 1px solid rgba(233, 237, 245, .95);
        border-radius: 18px;
        overflow: hidden;
        border-collapse: separate;
        border-spacing: 0;
        background: #fff;
        box-shadow: 0 16px 46px rgba(16, 24, 40, .06);
    }

    .rep-table thead th {
        background: linear-gradient(180deg, #f7f9ff, #ffffff);
        font-weight: 1000;
        font-size: 13px;
        padding: 11px 12px;
        border-bottom: 1px solid rgba(233, 237, 245, .95);
        text-align: left;
        color: #111827;
    }

    .rep-table tbody td {
        padding: 11px 12px;
        border-bottom: 1px solid rgba(233, 237, 245, .95);
        font-size: 13.5px;
        vertical-align: top;
        color: #111827;
    }

    .rep-table tbody tr:last-child td {
        border-bottom: 0;
    }

    .rep-dom {
        display: flex;
        align-items: center;
        gap: 10px;
        font-weight: 950;
    }

    .rep-dom .dot {
        width: 10px;
        height: 10px;
        border-radius: 999px;
        background: var(--b-blue);
        box-shadow: 0 0 0 6px rgba(43, 89, 255, .10);
    }

    .rep-dom.oppo .dot {
        background: var(--b-orange);
        box-shadow: 0 0 0 6px rgba(241, 114, 82, .10);
    }

    .rep-dom.cog .dot {
        background: var(--b-blue);
        box-shadow: 0 0 0 6px rgba(43, 89, 255, .10);
    }

    .rep-dom.hyp .dot {
        background: var(--b-green);
        box-shadow: 0 0 0 6px rgba(113, 191, 68, .10);
    }

    .rep-dom.adhd .dot {
        background: #111827;
        box-shadow: 0 0 0 6px rgba(17, 24, 39, .10);
    }

    .rep-savestate {
        font-size: 12px;
        color: var(--muted);
        margin-top: 8px;
    }

    .rep-sign {
        margin-top: 18px;
    }

    .rep-digispace {
        height: 44px;
    }

    .rep-signlabel {
        font-size: 13px;
        color: #111;
        margin: 0 0 6px;
        font-weight: 950;
    }

    .rep-signline {
        width: 320px;
        height: 1px;
        background: #111;
        margin: 6px 0 8px;
    }

    .rep-signname {
        font-weight: 1000;
        color: #111;
        margin: 0;
    }

    .rep-signtitle {
        margin: 0;
        color: var(--muted);
        font-size: 13px;
    }

    /* ========== PRINT-ONLY TEMPLATE ========== */
    .print-only {
        display: none;
    }

    .p-page {
        page-break-after: always;
    }

    .p-page:last-child {
        page-break-after: auto;
    }

    .p-title-18 {
        font-size: 18pt;
        font-weight: 700;
        text-align: center;
        margin: 0 0 6mm 0;
    }

    .p-sub-14 {
        font-size: 14pt;
        text-align: center;
        margin: 0;
    }

    .p-h1 {
        text-align: center;
        font-weight: 700;
        text-decoration: underline;
        margin: 0 0 4mm 0;
        font-size: 12pt;
    }

    .p-meta {
        margin: 0 0 4mm 0;
        line-height: 1.25;
        font-size: 11pt;
    }

    .p-lead {
        margin: 0 0 4mm 0;
        font-size: 11pt;
    }

    .p-sec-title {
        font-weight: 700;
        margin: 4mm 0 2mm 0;
        font-size: 11pt;
    }

    .p-justify {
        text-align: justify;
        text-justify: inter-word;
        margin: 0 0 3mm 0;
        white-space: pre-wrap;
    }

    .p-table {
        width: 100%;
        border-collapse: collapse;
        table-layout: fixed;
        margin: 0 0 4mm 0;
        font-size: 11pt;
    }

    .p-table th,
    .p-table td {
        border: 1px solid #111;
        padding: 6px 8px;
        vertical-align: top;
        word-wrap: break-word;
        white-space: normal;
    }

    .p-table th {
        font-weight: 700;
    }

    .p-assessed {
        margin-top: 6mm;
    }

    .p-line {
        width: 70mm;
        border-bottom: 1px solid #111;
        height: 0;
        margin: 12mm 0 3mm 0;
    }

    /* =========================================================
   PRINT FIX: header/footer must NOT overlap content
   Strategy:
   1) @page margin:0 so fixed header/footer anchor to paper edge
   2) Use padding on each printable "page" container as real margins
   3) Clone padding on every fragmented printed page
========================================================= */

    @page {
        size: A4;
        margin: 0;
        /* critical for reliable fixed header/footer positioning */
    }

    @media print {

        /* keep your existing "hide web UI" rules */
        nav,
        header,
        footer,
        .navbar,
        .no-print,
        .rep-actions,
        .btn,
        .rep-shell {
            display: none !important;
        }

        .p-cover-stage {
            /* available vertical space = full page - top padding - bottom padding */
            min-height: calc(297mm - 40mm - 15.24mm);
            display: flex;
            align-items: center;
            /* vertical center */
            justify-content: center;
            /* horizontal center */
            text-align: center;
            flex-direction: column;
        }

        /* Optional: ensure nothing pushes it down */
        .p-cover-stage>* {
            margin: 0;
        }

        .p-cover-title {
            font-size: 18pt;
            font-weight: 700;
        }

        .p-cover-sub {
            font-size: 14pt;
            margin-top: 8mm;
        }

        html,
        body {
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

        /* show the print-only document */
        .print-only {
            display: block !important;
        }

        .p-page {
            page-break-after: always;
            padding: 40mm 14mm 15.24mm 14mm !important;
            -webkit-box-decoration-break: clone;
            box-decoration-break: clone;
        }

        .p-page:last-child {
            page-break-after: auto;
        }

        .p-header {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            height: 28mm;
            padding: 10mm 14mm 0 14mm;
            box-sizing: border-box;
            display: flex;
            align-items: flex-start;
            z-index: 9999;
        }

        .p-header img {
            height: 16mm;
            width: auto;
            display: block;
        }

        .p-footer {
            position: fixed;
            left: 0;
            right: 0;
            bottom: 0;
            padding: 0 14mm 3mm 14mm;
            box-sizing: border-box;
            font-size: 9pt;
            line-height: 1.15;
            text-align: center;
            z-index: 9999;
        }

        .p-footer .rule {
            border-top: 2px solid #71BF44;
            margin: 0 0 2mm 0;
        }
    }
</style>

<div class="rep-shell">
    <div class="rep-geo"></div>

    <div class="rep-card">

        <div class="rep-hero no-print">
            <div class="min-w-0">
                <h2 class="rep-title">ADHD Assessment Report</h2>
                <p class="rep-sub">
                    Auto-saves while typing • Print-ready layout preserved • Assessment ID: <b>#<?= (int) $aid ?></b>
                </p>

                <div class="rep-badges">
                    <span class="rep-badge"><i class="bi bi-person-badge"></i>
                        <?= e($patientName ?: 'Patient') ?></span>
                    <span class="rep-badge"><i class="bi bi-calendar2-check"></i> <?= e($assessDateDisplay) ?></span>
                    <span class="rep-badge"><i class="bi bi-gender-ambiguous"></i>
                        <?= e($genderDisplay ?: '-') ?></span>
                    <span class="rep-badge"><i class="bi bi-tags"></i> <?= e($category ?: '-') ?></span>
                    <span class="rep-badge"><i class="bi bi-person-plus"></i>
                        <?= e($referred_by ?: 'Referred By: -') ?></span>
                </div>
            </div>

            <div class="rep-actions">
                <!-- <button class="btn rep-btn rep-btn-primary" type="button" onclick="window.print()">
                    <i class="bi bi-printer"></i> Print
                </button> -->
                <button type="button" class="btn btn-outline-secondary rep-btn" id="emailBtn">
                    <i class="bi bi-envelope-fill"></i> Email
                </button>
                <a class="btn btn-outline-secondary rep-btn"
                    href="<?= e(BASE_URL) ?>/consultant/report.php?id=<?= (int) $aid ?>&download=word">
                    <i class="bi bi-file-earmark-word"></i> DOCX
                </a>
                <a class="btn btn-outline-secondary rep-btn"
                    href="<?= e(BASE_URL) ?>/consultant/history.php?id=<?= (int) $aid ?>">
                    <i class="bi bi-clock-history"></i> History
                </a>
                <a class="btn btn-outline-secondary rep-btn"
                    href="<?= e(BASE_URL) ?>/consultant/omr.php?id=<?= (int) $aid ?>">
                    <i class="bi bi-ui-checks-grid"></i> OMR
                </a>
                <a class="btn btn-outline-secondary rep-btn"
                    href="<?= e(BASE_URL) ?>/consultant/result.php?id=<?= (int) $aid ?>">
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
                        <strong>Chronological Age:</strong> <?= (int) $ageYears ?> years, <?= (int) $ageMonths ?>
                        months,
                        <?= (int) $ageDays ?> days<br>
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

                        <textarea id="refText" class="rep-textarea" rows="2"
                            placeholder="Write referred by..."><?= e($referred_by) ?></textarea>
                        <div class="rep-printtext" id="refPrint"><?= e($referred_by) ?></div>
                    </div>
                </div>
            </div>

            <div class="rep-section">
                <div class="rep-h">
                    <b>History</b>
                    <span class="chip"><i class="bi bi-lightning-charge"></i> Auto-save</span>
                </div>
                <textarea id="hisText" class="rep-textarea" rows="3"
                    placeholder="Write patient history..."><?= e($history_text) ?></textarea>
                <div class="rep-printtext" id="hisPrint"><?= e($history_text) ?></div>
            </div>

            <div class="rep-lead">
                Conners’ Parent Rating Scale was applied to assess whether the patient has ADHD. His score is given
                below:
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
                                $sub = (string) ($s['subscale'] ?? '');
                                $cls = 'cog';
                                if ($sub === 'OPPOSITIONAL')
                                    $cls = 'oppo';
                                if ($sub === 'HYPERACTIVITY')
                                    $cls = 'hyp';
                                if ($sub === 'ADHD_INDEX')
                                    $cls = 'adhd';
                                ?>
                                <tr>
                                    <td>
                                        <span class="rep-dom <?= e($cls) ?>">
                                            <span class="dot"></span>
                                            <?= e($labels[$sub] ?? $sub) ?>
                                        </span>
                                    </td>
                                    <td><?= (int) ($s['raw_total'] ?? 0) ?></td>
                                    <td><?= (int) $s['t_score'] ?></td>
                                    <td><?= e($s['percentile_label']) ?></td>
                                    <td><?= e($s['guideline']) ?></td>
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
                <textarea id="impText" class="rep-textarea" rows="3"
                    placeholder="Write Impression/Diagnosis..."><?= e($impression_text) ?></textarea>
                <div class="rep-printtext" id="impPrint"><?= e($impression_text) ?></div>
            </div>

            <div class="rep-section">
                <div class="rep-h">
                    <b>Recommendations</b>
                    <span class="chip"><i class="bi bi-check2-circle"></i> Plan</span>
                </div>
                <textarea id="recText" class="rep-textarea" rows="3"
                    placeholder="Write Recommendations..."><?= e($recommendations_text) ?></textarea>
                <div class="rep-printtext" id="recPrint"><?= e($recommendations_text) ?></div>
            </div>

            <div class="rep-savestate no-print" id="saveState">Auto-save: ready</div>

            <div class="rep-sign">
                <b>
                    <div class="rep-signlabel">Assessed By</div>
                </b>
                <div class="rep-digispace"></div>
                <div class="rep-signline"></div>
                <p class="rep-signname mb-0"><?= e($assessedBy) ?></p>
                <p class="rep-signtitle mb-0">Clinical Psychologist</p>
                <p class="rep-signtitle mb-0">Bangladesh Psychiatric Care Ltd.</p>
            </div>

        </div>
    </div>
</div>
<!-- Email Report Modal -->
<div class="modal fade" id="emailModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content" style="border-radius:18px; overflow:hidden;">
            <div class="modal-header" style="background:linear-gradient(135deg, var(--b-orange), #ff8a6f); color:#fff;">
                <h5 class="modal-title" style="font-weight:1000;">
                    <i class="bi bi-envelope-paper"></i> Email Assessment Report
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"
                    aria-label="Close"></button>
            </div>

            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-bold">To (Email)</label>
                        <input type="email" class="form-control" id="mailTo" placeholder="recipient@example.com">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold">Subject</label>
                        <input type="text" class="form-control" id="mailSubject">
                    </div>

                    <div class="col-12">
                        <label class="form-label fw-bold">Email Body (Editable)</label>
                        <textarea class="form-control" id="mailBody" rows="8" style="white-space:pre-wrap;"></textarea>
                        <div class="small text-muted mt-2">
                            Attachment: <b id="mailAttachmentName"></b>
                        </div>
                    </div>
                </div>

                <div class="alert alert-danger mt-3 d-none" id="mailErr"></div>
                <div class="alert alert-success mt-3 d-none" id="mailOk">Email sent successfully.</div>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary rep-btn" data-bs-dismiss="modal">
                    Close
                </button>
                <button type="button" class="btn rep-btn rep-btn-primary" id="sendMailBtn">
                    <span class="spinner-border spinner-border-sm me-2 d-none" id="mailSpin"></span>
                    <i class="bi bi-send-fill"></i> Send Email
                </button>
            </div>
        </div>
    </div>
</div>

<script>
    /* =========================================================
       BPCL DSRS Report Page Scripts
       - Auto-grow + autosave (AJAX + CSRF)
       - Email modal: open + prefill + send (Bootstrap 5)
       ✅ FIXED: CSRF token now read from <meta name="csrf-token">
       ✅ FIXED: Safe JSON parsing when server returns non-JSON (403/html)
    ========================================================= */
    (function () {
        "use strict";

        const $ = (id) => document.getElementById(id);

        // ✅ CSRF: read from header.php meta tag
        const CSRF_TOKEN =
            document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || "";

        // ---------- AUTOGROW ----------
        function autoGrow(el) {
            if (!el) return;
            el.style.height = "auto";
            el.style.height = (el.scrollHeight + 2) + "px";
        }

        // ---------- PRINT SYNC ----------
        const his = $("hisText");
        const imp = $("impText");
        const rec = $("recText");
        const ref = $("refText");

        const hisPrint = $("hisPrint");
        const impPrint = $("impPrint");
        const recPrint = $("recPrint");
        const refPrint = $("refPrint");

        const saveState = $("saveState");

        function syncPrint() {
            if (his && hisPrint) hisPrint.textContent = his.value || "";
            if (imp && impPrint) impPrint.textContent = imp.value || "";
            if (rec && recPrint) recPrint.textContent = rec.value || "";
            if (ref && refPrint) refPrint.textContent = ref.value || "";
        }

        // ---------- AUTOSAVE ----------
        let timer = null;
        let saving = false;
        let lastPayload = null;

        async function autosave() {
            if (saving) return;

            const payload = {
                history: his ? his.value : "",
                impression: imp ? imp.value : "",
                recommendations: rec ? rec.value : "",
                referred_by: ref ? ref.value : "",
            };

            const payloadStr = JSON.stringify(payload);
            if (payloadStr === lastPayload) return;
            lastPayload = payloadStr;

            saving = true;
            if (saveState) saveState.textContent = "Auto-save: saving…";

            try {
                const fd = new FormData();
                // ✅ send correct CSRF
                fd.append("csrf", CSRF_TOKEN);
                fd.append("history", payload.history);
                fd.append("impression", payload.impression);
                fd.append("recommendations", payload.recommendations);
                fd.append("referred_by", payload.referred_by);

                const res = await fetch(window.location.href, {
                    method: "POST",
                    body: fd,
                    headers: { "X-Requested-With": "XMLHttpRequest" },
                });

                // ✅ safe JSON parse even on 403/html
                let j = null;
                try { j = await res.json(); } catch (e) { }

                if (!res.ok) {
                    if (saveState) saveState.textContent = "Auto-save: failed (HTTP " + res.status + ")";
                    return;
                }

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
            el.addEventListener("input", function () {
                autoGrow(el);
                syncPrint();
                scheduleSave();
            });
            el.addEventListener("blur", scheduleSave);
        }

        bind(his); bind(imp); bind(rec); bind(ref);
        syncPrint();
        autoGrow(his); autoGrow(imp); autoGrow(rec); autoGrow(ref);
        window.addEventListener("beforeprint", syncPrint);

        // ---------- EMAIL MODAL ----------
        window.addEventListener("load", function () {
            const emailBtn = $("emailBtn");
            const emailModalEl = $("emailModal");

            const mailTo = $("mailTo");
            const mailSubject = $("mailSubject");
            const mailBody = $("mailBody");
            const mailAttachmentName = $("mailAttachmentName");
            const sendMailBtn = $("sendMailBtn");
            const mailSpin = $("mailSpin");
            const mailErr = $("mailErr");
            const mailOk = $("mailOk");

            const REPORT_TITLE = "ADHD Assessment Report";
            const PATIENT_NAME = <?= json_encode($patientName ?: 'Patient') ?>;
            const ASSESS_DATE = <?= json_encode($assessDateDisplay ?: '-') ?>;
            const REPORT_ID = <?= json_encode((string) $aid) ?>;

            const attachmentName = `ADHD_Assessment_Report_${(PATIENT_NAME || 'Patient').replace(/[^a-zA-Z0-9_-]+/g, '_')}_${REPORT_ID}.pdf`;

            function hideAlerts() {
                if (mailErr) mailErr.classList.add("d-none");
                if (mailOk) mailOk.classList.add("d-none");
            }

            function showMailErr(msg) {
                if (!mailErr) return;
                mailErr.textContent = msg || "Email send failed.";
                mailErr.classList.remove("d-none");
                if (mailOk) mailOk.classList.add("d-none");
            }

            function showMailOk() {
                if (mailOk) mailOk.classList.remove("d-none");
                if (mailErr) mailErr.classList.add("d-none");
            }

            function prefillModalFields() {
                hideAlerts();

                if (mailSubject) {
                    mailSubject.value = `${REPORT_TITLE} — ${PATIENT_NAME} (Assessment #${REPORT_ID})`;
                }
                if (mailAttachmentName) {
                    mailAttachmentName.textContent = attachmentName;
                }
                if (mailBody) {
                    const body =
                        `Greetings from BPCL,

Please find attached the ADHD Assessment Report.If you have any questions, please feel free to contact us.

Regards,
Bangladesh Psychiatric Care Ltd.
Phone: 09604604604`;
                    if (!mailBody.value.trim()) mailBody.value = body;
                }
            }

            if (emailModalEl) {
                emailModalEl.addEventListener("show.bs.modal", prefillModalFields);
            }

            if (emailBtn) {
                emailBtn.addEventListener("click", function () {
                    if (!window.bootstrap || !bootstrap.Modal) {
                        showMailErr("Bootstrap JS not loaded. Include bootstrap.bundle.min.js.");
                        return;
                    }
                    if (!emailModalEl) return;

                    prefillModalFields();
                    bootstrap.Modal.getOrCreateInstance(emailModalEl).show();
                });
            }

            async function sendEmailNow() {
                if (!mailTo || !mailSubject || !mailBody) return;

                const to = (mailTo.value || "").trim();
                const subject = (mailSubject.value || "").trim();
                const bodyText = (mailBody.value || "");

                if (!to) { showMailErr("Please enter recipient email."); return; }

                if (sendMailBtn) sendMailBtn.disabled = true;
                if (mailSpin) mailSpin.classList.remove("d-none");
                hideAlerts();

                try {
                    const fd = new FormData();
                    // ✅ send correct CSRF
                    fd.append("csrf", CSRF_TOKEN);
                    fd.append("action", "send_email");
                    fd.append("to", to);
                    fd.append("subject", subject || REPORT_TITLE);
                    fd.append("bodyText", bodyText);

                    const res = await fetch(window.location.href, {
                        method: "POST",
                        body: fd,
                        headers: { "X-Requested-With": "XMLHttpRequest" },
                    });

                    // ✅ safe JSON parse even on 403/html
                    let j = null;
                    try { j = await res.json(); } catch (e) { }

                    if (!res.ok) {
                        showMailErr("Request failed (HTTP " + res.status + "). Most likely CSRF/session. Refresh and try again.");
                        return;
                    }

                    if (j && j.ok) {
                        showMailOk();

                        if (window.bootstrap && emailModalEl) {
                            const inst = bootstrap.Modal.getInstance(emailModalEl);
                            if (inst) inst.hide();
                        }

                        setTimeout(() => alert("✅ Email sent successfully."), 150);
                    } else {
                        showMailErr((j && j.error) ? j.error : "Email send failed.");
                    }
                } catch (e) {
                    showMailErr("Email send failed. Check server/SMTP or Console error.");
                } finally {
                    if (sendMailBtn) sendMailBtn.disabled = false;
                    if (mailSpin) mailSpin.classList.add("d-none");
                }
            }

            if (sendMailBtn) {
                sendMailBtn.addEventListener("click", sendEmailNow);
            }
        });
    })();
</script>

<?php include __DIR__ . '/../partials/footer.php'; ?>