<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole('registrar');

$user = currentUser();
$listUrl = APP_URL . '/registrar/documents.php';
$processUrl = APP_URL . '/registrar/process-document.php';
$activeNav = 'my-assignments';
$processorLabel = 'Registrar';

require_once __DIR__ . '/../includes/process-assigned-document.php';
