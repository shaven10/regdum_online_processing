<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole('registrar');

$user = currentUser();
$pageTitle = 'My Assignments';
$activeNav = 'my-assignments';
$officeLabel = 'Registrar Document Assignments';
$processBaseUrl = APP_URL . '/registrar/process-document.php';

require_once __DIR__ . '/../includes/assigned-documents-list.php';
