<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole('clearance_officer');

$user = currentUser();
$listUrl = APP_URL . '/clearance/documents.php';
$processUrl = APP_URL . '/clearance/process-document.php';
$activeNav = 'documents';
$processorLabel = 'Guidance Office';

require_once __DIR__ . '/../includes/process-assigned-document.php';
