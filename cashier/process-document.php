<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole('cashier');

$user = currentUser();
$listUrl = APP_URL . '/cashier/documents.php';
$processUrl = APP_URL . '/cashier/process-document.php';
$activeNav = 'documents';
$processorLabel = 'Cashier';

require_once __DIR__ . '/../includes/process-assigned-document.php';
