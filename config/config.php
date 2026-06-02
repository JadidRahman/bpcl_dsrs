<?php
define('DB_HOST', 'localhost');
define('DB_NAME', 'bpcl_dsrs');
define('DB_USER', 'root');
define('DB_PASS', '');

// ---- App ----
define('APP_NAME', 'BPCL DSRS');
define('BASE_URL', 'http://localhost/bpcl_dsrs');

// ---- Email (verification) ----
define('APP_DEBUG', false);
define('MAIL_FROM', 'no-reply@bpcl.local');
define('MAIL_FROM_NAME', 'BPCL DSRS');

// Token expiry (minutes)
define('VERIFY_TOKEN_EXP_MIN', 60);

// Session settings
define('SESSION_NAME', 'bpcl_dsrs_sess');

// Security
define('CSRF_KEY', 'change-this-to-a-long-random-string');
define('APP_KEY', 'put-a-long-random-secret-here-change-it');
define('EMAIL_OTP_TTL_MIN', 10);