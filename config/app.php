<?php
define('APP_NAME', 'Office of the Registrar');
define('APP_TAGLINE', 'J.H. Cerilles State College');
define('APP_SYSTEM_NAME', 'Online Document Request');
define('APP_URL', 'http://localhost/regdum_ol_docs_prcsng');
define('APP_ROOT', dirname(__DIR__));
define('APP_LOGO', APP_URL . '/assets/images/logo.png');
define('UPLOAD_PATH', APP_ROOT . '/uploads');
define('UPLOAD_URL', APP_URL . '/uploads');

define('SESSION_TIMEOUT', 1800); // 30 minutes
define('ITEMS_PER_PAGE', 15);

// Email settings (configure for production)
define('MAIL_FROM', 'noreply@regdum.edu.ph');
define('MAIL_FROM_NAME', APP_NAME);
define('SMTP_ENABLED', false);

// Payment settings
define('PAYMENT_GATEWAY', 'manual'); // manual, paymongo, gcash
define('CURRENCY', '₱');
define('DOCUMENT_STAMP_FEE', 30.00);

// Security
define('MFA_ENABLED', false);
define('PASSWORD_MIN_LENGTH', 8);

date_default_timezone_set('Asia/Manila');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once APP_ROOT . '/config/database.php';
