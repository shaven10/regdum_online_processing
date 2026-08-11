<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/clearance.php';
requireRole('clearance_officer');

$user = currentUser();
$pageTitle = 'Assigned Documents';
$activeNav = 'documents';
$officeLabel = 'Assigned Documents';
$processBaseUrl = APP_URL . '/clearance/process-document.php';

require_once __DIR__ . '/../includes/assigned-documents-list.php';
