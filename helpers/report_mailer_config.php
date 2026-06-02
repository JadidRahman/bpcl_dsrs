<?php
// helpers/report_mailer_config.php

define('REPORT_SMTP_HOST', 'smtp.gmail.com');
define('REPORT_SMTP_PORT', 587);
define('REPORT_SMTP_SECURE', 'tls'); // STARTTLS

define('REPORT_SMTP_USER', 'reportbpcl@gmail.com');

// 16-char app password (NO spaces). Prefer env var on server.
define('REPORT_SMTP_PASS', getenv('REPORT_SMTP_PASS') ?: 'druaaakizhyjnbzd');

define('REPORT_MAIL_FROM', 'reportbpcl@gmail.com');
define('REPORT_MAIL_FROM_NAME', 'Report-Bangladesh Psychiatric Care Ltd.');