<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/external-api.php';
requireRole('admin');

ensureExternalApiSchema();
ensureAcademicProgramsSchema();
ensureCampusesSchema();

$format = strtolower(trim((string) ($_GET['format'] ?? 'md')));
$dateStamp = date('Y-m-d');

if ($format === 'pdf') {
    $filename = 'active-students-api-documentation-' . $dateStamp . '.pdf';
    $content = buildExternalApiDocumentationPdf();

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($content));
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    echo $content;
    exit;
}

$filename = 'active-students-api-documentation-' . $dateStamp . '.md';
$content = buildExternalApiDocumentationMarkdown();

header('Content-Type: text/markdown; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($content));
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

echo $content;
