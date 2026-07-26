<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole('cashier');

$user = currentUser();
$pageTitle = 'Assigned Documents';
$activeNav = 'documents';
$officeLabel = 'Cashier Document Assignments';
$processBaseUrl = APP_URL . '/cashier/process-document.php';

require_once __DIR__ . '/../includes/assigned-documents-list.php';
