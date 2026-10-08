<?php

require_once __DIR__ . '/../includes/auth.php';
requireRole('registrar');

$editId = (int) ($_POST['request_id'] ?? $_GET['request_id'] ?? $_GET['id'] ?? 0);
if ($editId <= 0) {
    setFlash('error', 'Request not found.');
    redirect(APP_URL . '/registrar/compliance.php');
}

$_GET['request_id'] = $editId;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $_POST['request_id'] = $editId;
}

require __DIR__ . '/../student/new-request.php';
