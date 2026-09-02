<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole('staff');

$user = currentUser();
$pageTitle = 'Process Requests';
$activeNav = 'requests';
$officeLabel = 'My Document Assignments';
$processBaseUrl = APP_URL . '/staff/process-request.php';
$listPageUrl = APP_URL . '/staff/requests.php';

require_once __DIR__ . '/../includes/assigned-documents-list.php';
