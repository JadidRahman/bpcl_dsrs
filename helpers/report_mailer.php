<?php
/**
 * helpers/report_mailer.php
 * Premium HTML email sender (PHPMailer) for BPCL DSRS reports.
 *
 * ✅ Premium email template
 * ✅ Report Summary portion (Patient Name, Date, Assessment, Assessed By)
 * ✅ Body is fixed premium pattern (as requested)
 * ✅ “Attachment Included” card inside body + OPTIONAL “Download Attachment” button
 * ✅ Right-aligned “Confidential Report Delivery”
 * ✅ Preserves newlines if you still use bodyText elsewhere, but default body is fixed here
 *
 * ✅ NEW: Auto convert DOCX -> PDF BEFORE attaching (no report.php change needed)
 *    - Tries LibreOffice (best fidelity) if available
 *    - Fallback: PhpWord + mPDF (composer require mpdf/mpdf)
 */

declare(strict_types=1);

function bpcl_mailer_find_autoload(): ?string
{
  $candidates = [
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/../../vendor/autoload.php',
    dirname(__DIR__) . '/vendor/autoload.php',
    dirname(__DIR__, 2) . '/vendor/autoload.php',
  ];
  foreach ($candidates as $p)
    if (is_file($p))
      return $p;
  return null;
}

$cfg = __DIR__ . '/../config/report_mail.php';
if (is_file($cfg))
  require_once $cfg;

$cfg2 = __DIR__ . '/../config/report_mailer_config.php';
if (is_file($cfg2))
  require_once $cfg2;

$autoload = bpcl_mailer_find_autoload();
if (!$autoload)
  throw new RuntimeException("Composer autoload not found. Run: composer require phpmailer/phpmailer");
require_once $autoload;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;

function bpcl_mail_e(string $s): string
{
  return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
function bpcl_mail_normalize_newlines(string $s): string
{
  return trim(str_replace(["\r\n", "\r"], "\n", (string) $s));
}

/**
 * Convert DOCX to PDF and return the PDF path.
 * 1) LibreOffice headless (best fidelity, closest to Word "Save As PDF")
 * 2) PhpWord + mPDF fallback (composer require mpdf/mpdf)
 *
 * Requires (for LO):
 *   - define('REPORT_SOFFICE_PATH', 'C:\Program Files\LibreOffice\program\soffice.exe'); (Windows) OR
 *   - `soffice` available in PATH (Linux)
 *
 * Optional:
 *   - define('REPORT_FORCE_PDF', true); (default true)
 */
function bpcl_convert_docx_to_pdf(string $docxPath): string
{
  if (!is_file($docxPath) || !is_readable($docxPath)) {
    throw new Exception("Attachment not found/readable.");
  }

  $tmpDir = sys_get_temp_dir();
  $base   = pathinfo($docxPath, PATHINFO_FILENAME);

  // Unique output file
  $pdfOut = rtrim($tmpDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $base . '_' . uniqid('bpclpdf_', true) . '.pdf';

  // -------------------- 1) LibreOffice headless --------------------
  $soffice = null;
  if (defined('REPORT_SOFFICE_PATH') && (string)REPORT_SOFFICE_PATH !== '') {
    $soffice = (string) REPORT_SOFFICE_PATH;
  } else {
    $soffice = 'soffice'; // try PATH
  }

  $exportDir = rtrim($tmpDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'bpcl_pdf_' . uniqid();
  @mkdir($exportDir, 0777, true);

  // only try LO if exec() is available
  $canExec = function_exists('exec') && !in_array('exec', array_map('trim', explode(',', (string)ini_get('disable_functions'))), true);

  if ($canExec && $soffice) {
    $sofficeEsc = escapeshellarg($soffice);
    $docxEsc    = escapeshellarg($docxPath);
    $outDirEsc  = escapeshellarg($exportDir);

    $cmd = "{$sofficeEsc} --headless --nologo --nofirststartwizard --convert-to pdf --outdir {$outDirEsc} {$docxEsc}";
    @exec($cmd . " 2>&1", $out, $code);

    // LibreOffice produces: {exportDir}/{base}.pdf
    $expectedPdf = $exportDir . DIRECTORY_SEPARATOR . $base . '.pdf';
    if ((int)$code === 0 && is_file($expectedPdf) && filesize($expectedPdf) > 1000) {
      @rename($expectedPdf, $pdfOut);
      // cleanup export dir
      @unlink($expectedPdf);
      @rmdir($exportDir);
      if (is_file($pdfOut) && filesize($pdfOut) > 1000) {
        return $pdfOut;
      }
    }
  }

  // cleanup export dir
  if (is_dir($exportDir)) {
    foreach (glob($exportDir . DIRECTORY_SEPARATOR . '*') as $f) {
      @unlink($f);
    }
    @rmdir($exportDir);
  }

  // -------------------- 2) PhpWord + mPDF fallback --------------------
  // Needs: composer require mpdf/mpdf
  if (class_exists('\PhpOffice\PhpWord\IOFactory') && class_exists('\PhpOffice\PhpWord\Settings')) {

    // mPDF must exist
    if (!class_exists('\Mpdf\Mpdf')) {
      throw new Exception("PDF conversion unavailable: install mPDF (composer require mpdf/mpdf) OR enable LibreOffice.");
    }

    // Configure PhpWord PDF renderer
    \PhpOffice\PhpWord\Settings::setPdfRendererName(\PhpOffice\PhpWord\Settings::PDF_RENDERER_MPDF);

    // IMPORTANT:
    // PhpWord expects the PATH to the renderer library.
    // For mPDF, pointing to vendor/mpdf/mpdf is standard.
    $mpdfPath = dirname(__DIR__) . '/../vendor/mpdf/mpdf';
    \PhpOffice\PhpWord\Settings::setPdfRendererPath($mpdfPath);

    // Load docx and save PDF
    $phpWord = \PhpOffice\PhpWord\IOFactory::load($docxPath, 'Word2007');
    $writer  = \PhpOffice\PhpWord\IOFactory::createWriter($phpWord, 'PDF');
    $writer->save($pdfOut);

    if (is_file($pdfOut) && filesize($pdfOut) > 1000) {
      return $pdfOut;
    }
  }

  throw new Exception("PDF conversion failed (LibreOffice not available, or PhpWord PDF renderer not configured).");
}

function bpcl_mail_text_to_html(string $text): string
{
  $text = bpcl_mail_normalize_newlines($text);
  if ($text === '')
    return '<p style="margin:0;">&nbsp;</p>';

  $lines = explode("\n", $text);
  $paras = [];
  $buf = [];

  foreach ($lines as $ln) {
    $t = trim($ln);
    if ($t === '') {
      if (!empty($buf)) {
        $paras[] = implode(' ', $buf);
        $buf = [];
      }
      continue;
    }
    $buf[] = $t;
  }
  if (!empty($buf))
    $paras[] = implode(' ', $buf);

  $out = [];
  foreach ($paras as $p) {
    $out[] =
      '<p style="margin:0 0 12px 0; line-height:1.75; color:#0b1220; font-size:14px;">' .
      bpcl_mail_e($p) .
      '</p>';
  }
  return implode("\n", $out);
}
function bpcl_mail_html_to_text(string $html): string
{
  $t = strip_tags($html);
  $t = html_entity_decode($t, ENT_QUOTES, 'UTF-8');
  $t = preg_replace("/[ \t]+/", " ", $t);
  $t = preg_replace("/\n{3,}/", "\n\n", $t);
  return trim($t);
}

function bpcl_mail_build_html(array $args): string
{
  $subject = (string) ($args['subject'] ?? 'Report Delivery');
  $subtitle = (string) ($args['subtitle'] ?? 'This message contains a confidential clinical report attachment.');

  // ✅ fixed body pattern as you requested (do not show patient/meta here)
  $fixedBody = "Greetings from BPCL,\n\n"
    . "Please find attached the ADHD Assessment Report.\n\n"
    . "If you have any questions, please feel free to contact us.\n\n"
    . "Regards,\n"
    . "Bangladesh Psychiatric Care Ltd.\n"
    . "Phone: 09604604604";

  $bodyText = (string) ($args['bodyText'] ?? '');
  $bodyText = trim($bodyText) !== '' ? $bodyText : $fixedBody;

  $ctaUrl = (string) ($args['ctaUrl'] ?? '');
  $ctaLabel = (string) ($args['ctaLabel'] ?? 'Open Portal');

  $attachmentName = (string) ($args['attachmentName'] ?? 'Report Attachment');
  $attachmentType = (string) ($args['attachmentType'] ?? 'FILE');
  $attachmentNote = (string) ($args['attachmentNote'] ?? 'For best viewing, download and open the attachment.');
  $downloadUrl = (string) ($args['downloadUrl'] ?? ''); // optional

  // ✅ Report Summary fields
  $patientName = trim((string) ($args['patientName'] ?? ''));
  $assessmentDate = trim((string) ($args['assessmentDate'] ?? ''));
  $assessmentName = trim((string) ($args['assessmentName'] ?? ''));
  $assessedBy = trim((string) ($args['assessedBy'] ?? ''));

  $patientName = $patientName !== '' ? $patientName : '—';
  $assessmentDate = $assessmentDate !== '' ? $assessmentDate : '—';
  $assessmentName = $assessmentName !== '' ? $assessmentName : '—';
  $assessedBy = $assessedBy !== '' ? $assessedBy : '—';

  // ✅ Remove trailing "(Assessment #123)" ONLY for the card headline
  $displaySubject = preg_replace('/\s*\(\s*Assessment\s*#\s*\d+\s*\)\s*$/i', '', $subject);

  $safeSubject = bpcl_mail_e($subject);         // real email subject (keep)
  $safeDisplaySubject = bpcl_mail_e($displaySubject);  // card title (clean)
  $safeSubtitle = bpcl_mail_e($subtitle);

  $brandOrange = '#f17252';
  $brandGreen = '#3c8f49';
  $ink = '#0b1220';
  $muted = '#5b6473';
  $soft = '#f6f8fb';
  $line = '#e9edf5';

  $bodyHtml = bpcl_mail_text_to_html($bodyText);

  $ctaHtml = '';
  if ($ctaUrl !== '') {
    $ctaHtml = '
          <tr>
            <td style="padding:0 20px 20px 20px;">
              <a href="' . bpcl_mail_e($ctaUrl) . '"
                 style="display:inline-block; text-decoration:none;
                        padding:12px 16px; border-radius:12px;
                        background:linear-gradient(135deg, ' . $brandOrange . ', #ff8a6f);
                        color:#fff; font-weight:800; font-size:14px;">
                ' . bpcl_mail_e($ctaLabel) . '
              </a>
            </td>
          </tr>';
  }

  // ✅ Download button inside attachment card (only if downloadUrl provided)
  $downloadBtn = '';
  if ($downloadUrl !== '') {
    $downloadBtn = '
          <div style="margin-top:10px;">
            <a href="' . bpcl_mail_e($downloadUrl) . '"
               style="display:inline-block; text-decoration:none;
                      padding:10px 14px; border-radius:12px;
                      background:#0b1220; color:#fff; font-weight:900; font-size:13px;">
              ⬇ Download Attachment
            </a>
            <div style="margin-top:8px; color:#94a3b8; font-size:11.5px; line-height:1.5;">
              If the button doesn’t work, copy this link:<br>
              <span style="word-break:break-all;">' . bpcl_mail_e($downloadUrl) . '</span>
            </div>
          </div>';
  }

  // ✅ Report Summary card
  $summaryCard = '
      <tr>
        <td style="padding:0 20px 14px 20px;">
          <div style="border:1px solid ' . $line . '; border-radius:14px; background:#fff; overflow:hidden;">
            <div style="padding:12px 14px; background:rgba(241,114,82,.10); border-bottom:1px solid ' . $line . ';">
              <div style="font-weight:900; color:' . $ink . '; font-size:13px; letter-spacing:.2px;">
                Report Summary
              </div>
            </div>

            <div style="padding:12px 14px;">
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">
                <tr>
                  <td style="padding:6px 0; color:#64748b; font-size:12px; width:36%;">Patient Name</td>
                  <td style="padding:6px 0; color:' . $ink . '; font-size:12.5px; font-weight:800;">' . bpcl_mail_e($patientName) . '</td>
                </tr>
                <tr>
                  <td style="padding:6px 0; color:#64748b; font-size:12px;">Date of Assessment</td>
                  <td style="padding:6px 0; color:' . $ink . '; font-size:12.5px; font-weight:800;">' . bpcl_mail_e($assessmentDate) . '</td>
                </tr>
                <tr>
                  <td style="padding:6px 0; color:#64748b; font-size:12px;">Assessment Name</td>
                  <td style="padding:6px 0; color:' . $ink . '; font-size:12.5px; font-weight:800;">' . bpcl_mail_e($assessmentName) . '</td>
                </tr>
                <tr>
                  <td style="padding:6px 0; color:#64748b; font-size:12px;">Assessed By</td>
                  <td style="padding:6px 0; color:' . $ink . '; font-size:12.5px; font-weight:800;">' . bpcl_mail_e($assessedBy) . '</td>
                </tr>
              </table>
            </div>
          </div>
        </td>
      </tr>';

  $attachCard = '
      <tr>
        <td style="padding:0 20px 18px 20px;">
          <div style="border:1px solid ' . $line . '; border-radius:14px; padding:12px 14px; background:#fff;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">
              <tr>
                <td style="vertical-align:top; width:28px;">
                  <div style="width:26px; height:26px; border-radius:10px;
                              background:rgba(43,89,255,.10);
                              display:inline-block; text-align:center; line-height:26px; font-size:14px;">
                    📎
                  </div>
                </td>
                <td style="vertical-align:top; padding-left:10px;">
                  <div style="font-weight:900; color:' . $ink . '; font-size:13px;">
                    Attachment Included
                  </div>
                  <div style="margin-top:3px; color:' . $muted . '; font-size:12.5px; line-height:1.5;">
                    <b>' . bpcl_mail_e($attachmentName) . '</b>
                    <span style="color:#94a3b8;">•</span>
                    <span style="font-weight:800;">' . bpcl_mail_e($attachmentType) . '</span>
                  </div>
                  <div style="margin-top:6px; color:#64748b; font-size:12px; line-height:1.6;">
                    ' . bpcl_mail_e($attachmentNote) . '
                  </div>
                  ' . $downloadBtn . '
                </td>
              </tr>
              <tr>
                <td colspan="2" style="height:4px; background:linear-gradient(90deg, ' . $brandOrange . ', ' . $brandGreen . ');"></td>
              </tr>
            </table>
          </div>
        </td>
      </tr>';

  $year = date('Y');

  return '<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="x-apple-disable-message-reformatting">
  <title>' . $safeSubject . '</title>
</head>
<body style="margin:0; padding:0; background:' . $soft . ';">
  <div style="display:none; max-height:0; overflow:hidden; opacity:0; color:transparent;">
    ' . $safeSubject . ' — ' . $safeSubtitle . '
  </div>

  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse; background:' . $soft . ';">
    <tr>
      <td align="center" style="padding:26px 12px;">
        <table role="presentation" width="680" cellpadding="0" cellspacing="0"
               style="border-collapse:collapse; width:100%; max-width:680px;
                      background:#fff; border:1px solid ' . $line . ';
                      border-radius:18px; overflow:hidden;">

          <tr>
            <td style="height:4px; background:linear-gradient(90deg, ' . $brandOrange . ', ' . $brandGreen . ');"></td>
          </tr>

          <tr>
            <td style="padding:18px 20px 12px 20px; background:' . $soft . ';">
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">
                <tr>
                  <td align="left" style="vertical-align:middle;">
                    <div style="display:inline-block; padding:6px 10px; border-radius:999px;
                                background:linear-gradient(135deg, ' . $brandOrange . ', #ff9b7f);
                                color:#fff; font-weight:800; letter-spacing:.4px; font-size:12px;">
                      Bangladesh Psychiatric Care Ltd.
                    </div>
                  </td>
                  <td align="right" style="vertical-align:middle;">
                    <div style="display:inline-block; float:right; text-align:right;
                                padding:6px 10px; border-radius:999px;
                                border:1px solid ' . $line . '; background:#fff;
                                color:' . $muted . '; font-weight:800; letter-spacing:.2px; font-size:12px;">
                      Confidential Report Delivery
                    </div>
                  </td>
                </tr>
              </table>

              <div style="margin-top:12px; font-size:20px; font-weight:800; letter-spacing:-.2px; color:' . $ink . ';">
                ' . $safeDisplaySubject . '
              </div>
              <div style="margin-top:6px; color:' . $muted . '; font-size:13px; line-height:1.6;">
                ' . $safeSubtitle . '
              </div>
            </td>
          </tr>

          <tr>
            <td style="padding:18px 20px 6px 20px;">
              ' . $bodyHtml . '
            </td>
          </tr>

          ' . $summaryCard . '
          ' . $attachCard . '
          ' . $ctaHtml . '

          <tr>
            <td style="padding:0 20px 18px 20px;">
              <div style="border-top:2px solid ' . $brandGreen . '; margin:0 0 10px 0;"></div>
              <div style="text-align:center; color:' . $muted . '; font-size:12px; line-height:1.6;">
                <b style="color:' . $ink . ';">Bangladesh Psychiatric Care Ltd.</b><br>
                Phone: 09604604604 • Web: bdpsychiatriccare.com<br>
                <span style="color:#94a3b8;">© ' . $year . ' BPCL. All rights reserved.</span>
              </div>
              <div style="margin-top:10px; text-align:center; color:#94a3b8; font-size:11px; line-height:1.5;">
                This email may contain confidential clinical information. If you received it by mistake, please reply and delete it.
              </div>
            </td>
          </tr>

        </table>
      </td>
    </tr>
  </table>
</body>
</html>';
}

function send_report_email_with_attachment(array $args): array
{
  $to = trim((string) ($args['to'] ?? ''));
  if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
    return ['ok' => false, 'error' => 'Invalid recipient email.'];
  }

  $subject = trim((string) ($args['subject'] ?? 'Report Delivery'));
  if ($subject === '')
    $subject = 'Report Delivery';

  $attachmentPath = (string) ($args['attachmentPath'] ?? '');
  $attachmentName = (string) ($args['attachmentName'] ?? '');

  if ($attachmentPath === '' || !is_file($attachmentPath)) {
    return ['ok' => false, 'error' => 'Attachment file not found on server.'];
  }
  if ($attachmentName === '')
    $attachmentName = basename($attachmentPath);

  $host = defined('REPORT_SMTP_HOST') ? (string) REPORT_SMTP_HOST : 'smtp.gmail.com';
  $port = defined('REPORT_SMTP_PORT') ? (int) REPORT_SMTP_PORT : 587;
  $user = defined('REPORT_SMTP_USER') ? (string) REPORT_SMTP_USER : '';
  $from = defined('REPORT_MAIL_FROM') ? (string) REPORT_MAIL_FROM : $user;
  $fromName = defined('REPORT_MAIL_FROM_NAME') ? (string) REPORT_MAIL_FROM_NAME : 'BPCL Report';

  $pass = '';
  if (defined('REPORT_SMTP_PASS'))
    $pass = (string) REPORT_SMTP_PASS;
  if ($pass === '')
    $pass = (string) getenv('REPORT_SMTP_PASS');

  $secure = '';
  if (defined('REPORT_SMTP_SECURE'))
    $secure = (string) REPORT_SMTP_SECURE;
  if ($secure === '')
    $secure = 'tls';

  if ($user === '' || $from === '' || $pass === '') {
    return ['ok' => false, 'error' => 'SMTP configuration missing (user/from/pass).'];
  }

  // --- force PDF conversion by default (no report.php changes needed) ---
  $forcePdf = defined('REPORT_FORCE_PDF') ? (bool) REPORT_FORCE_PDF : true;

  $tmpPdfToDelete = null;
  $finalAttachPath = $attachmentPath;
  $finalAttachName = $attachmentName;

  // Convert only if it's DOCX and forcePdf enabled
  if ($forcePdf) {
    $extOnPath = strtolower(pathinfo($attachmentPath, PATHINFO_EXTENSION));
    $extOnName = strtolower(pathinfo($attachmentName, PATHINFO_EXTENSION));
    $isDocx = ($extOnPath === 'docx') || ($extOnName === 'docx');

    if ($isDocx) {
      try {
        $pdfPath = bpcl_convert_docx_to_pdf($attachmentPath);
        $tmpPdfToDelete = $pdfPath;

        $finalAttachPath = $pdfPath;

        // rename attachment filename to .pdf (keep same base)
        $finalAttachName = preg_replace('/\.docx$/i', '.pdf', $attachmentName);
        if (stripos($finalAttachName, '.pdf') === false) {
          $finalAttachName .= '.pdf';
        }
      } catch (Throwable $e) {
        // DO NOT silently send DOCX (you requested PDF only)
        return ['ok' => false, 'error' => 'PDF conversion failed: ' . $e->getMessage()];
      }
    }
  }

  $attachmentExt = strtoupper(pathinfo($finalAttachName, PATHINFO_EXTENSION) ?: 'FILE');
  $subtitle = (string) ($args['subtitle'] ?? 'Please review the attached assessment report.');
  $ctaUrl = (string) ($args['ctaUrl'] ?? '');
  $ctaLabel = (string) ($args['ctaLabel'] ?? 'Open Portal');

  $attachmentNote = (string) ($args['attachmentNote'] ?? 'If the preview looks incomplete in Gmail, please download and open the attachment .');
  $downloadUrl = (string) ($args['downloadUrl'] ?? '');

  $html = bpcl_mail_build_html([
    'subject' => $subject,
    'subtitle' => $subtitle,

    // Keep optional override. If you don't pass bodyText, fixed pattern is used.
    'bodyText' => (string) ($args['bodyText'] ?? ''),

    // ✅ Report summary values
    'patientName' => (string) ($args['patientName'] ?? ''),
    'assessmentDate' => (string) ($args['assessmentDate'] ?? ''),
    'assessmentName' => (string) ($args['assessmentName'] ?? ''),
    'assessedBy' => (string) ($args['assessedBy'] ?? ''),

    'ctaUrl' => $ctaUrl,
    'ctaLabel' => $ctaLabel,

    // show PDF filename in the email card if converted
    'attachmentName' => $finalAttachName,
    'attachmentType' => $attachmentExt,
    'attachmentNote' => $attachmentNote,
    'downloadUrl' => $downloadUrl,
  ]);

  $alt = bpcl_mail_html_to_text($html);

  try {
    $mail = new PHPMailer(true);
    $mail->CharSet = 'UTF-8';

    $mail->isSMTP();
    $mail->Host = $host;
    $mail->Port = $port;
    $mail->SMTPAuth = true;
    $mail->Username = $user;
    $mail->Password = $pass;
    $mail->SMTPSecure = $secure;

    $mail->setFrom($from, $fromName);

    // allow override reply-to if you ever pass it
    $replyTo = trim((string) ($args['replyTo'] ?? ''));
    $replyToName = trim((string) ($args['replyToName'] ?? ''));
    if ($replyTo !== '' && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
      $mail->addReplyTo($replyTo, $replyToName !== '' ? $replyToName : $fromName);
    } else {
      $mail->addReplyTo($from, $fromName);
    }

    $mail->addAddress($to);

    $mail->isHTML(true);
    $mail->Subject = $subject;
    $mail->Body = $html;
    $mail->AltBody = $alt;

    // Attach PDF (or original if forcePdf disabled / not docx)
    $mail->addAttachment($finalAttachPath, $finalAttachName);

    $mail->addCustomHeader('X-Mailer', 'BPCL DSRS Mailer');
    $mail->addCustomHeader('X-Entity-Ref-ID', bin2hex(random_bytes(10)));

    $mail->send();
    return ['ok' => true];

  } catch (MailException $e) {
    return ['ok' => false, 'error' => $e->getMessage()];
  } catch (Throwable $e) {
    return ['ok' => false, 'error' => 'Mailer crash: ' . $e->getMessage()];
  } finally {
    // Cleanup temp PDF if we created one
    if ($tmpPdfToDelete && is_file($tmpPdfToDelete)) {
      @unlink($tmpPdfToDelete);
    }
  }
}