<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/accounting.php';
requireRole('accounting');

ensureAccountingModule();

$user = currentUser();
$listUrl = APP_URL . '/accounting/documents.php';
$processUrl = APP_URL . '/accounting/process-document.php';
$activeNav = 'documents';
$processorLabel = 'Accounting';
$allowedDocumentCodes = [ACCOUNTING_SOA_CODE];

require_once __DIR__ . '/../includes/process-assigned-document.php';
