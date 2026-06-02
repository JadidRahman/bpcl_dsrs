<?php
// config/report_mail.php
// SMTP settings only for sending assessment reports (DOCX attachment)


define('REPORT_SMTP_HOST', 'smtp.gmail.com');
define('REPORT_SMTP_PORT', 587);
define('REPORT_SMTP_USER', 'your_email@example.com');

// Put your NEW app password here (after regenerating)
define('REPORT_SMTP_PASS', 'change_this_random_key');

// Sender info
define('REPORT_MAIL_FROM', 'your_email@example.com');
define('REPORT_MAIL_FROM_NAME', 'Report- Bangladesh Psychiatric Care Ltd.');
