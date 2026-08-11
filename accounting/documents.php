<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/accounting.php';
requireRole('accounting');

ensureAccountingModule();

$user = currentUser();
$pageTitle = 'SOA Assignments';
$activeNav = 'documents';
$officeLabel = 'Accounting — Statement of Account (SOA)';
$processBaseUrl = APP_URL . '/accounting/process-document.php';
$documentCodeFilter = ACCOUNTING_SOA_CODE;

require_once __DIR__ . '/../includes/assigned-documents-list.php';
