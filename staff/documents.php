<?php
require_once __DIR__ . '/../includes/auth.php';
requireRole('staff');

$db = getDB();

$readyDocs = $db->query("SELECT r.*, dt.name as document_name, u.first_name, u.last_name FROM requests r JOIN document_types dt ON r.document_type_id = dt.id JOIN users u ON r.user_id = u.id WHERE r.status IN ('processing','ready_for_pickup') ORDER BY r.created_at ASC")->fetchAll();
foreach ($readyDocs as &$readyDoc) {
    $simpleCode = ensureSimpleVerificationCode((int) $readyDoc['id']);
    if ($simpleCode) {
        $readyDoc['verification_code'] = $simpleCode;
    }
}
unset($readyDoc);
$sortColumns = [
    'request_number' => ['type' => 'string'],
    'name' => [
        'type' => 'string',
        'get' => static fn(array $r): string => trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? '')),
    ],
    'document_name' => ['type' => 'string'],
    'copies' => ['type' => 'number', 'default_dir' => 'desc'],
    'status' => ['type' => 'string'],
    'verification_code' => ['type' => 'string'],
    'created_at' => ['type' => 'date', 'default_dir' => 'asc'],
];
$sortState = resolveRecordsSort($sortColumns, 'created_at', 'asc');
$readyDocs = sortRecordList($readyDocs, $sortState);
$listFilters = recordsSortFilterParams($sortState);
$pagedReadyDocs = paginateRecordList($readyDocs, $listFilters, 'staffDocumentsFilterForm', 'document', 'documents');
$readyDocs = $pagedReadyDocs['items'];
$sortQuery = $listFilters;
if ($pagedReadyDocs['per_page'] !== ITEMS_PER_PAGE) {
    $sortQuery['per_page'] = $pagedReadyDocs['per_page'];
}

$pageTitle = 'Document Generation';
$activeNav = 'documents';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="card">
    <div class="card-header"><h2>Documents for Processing</h2></div>
    <div class="card-body">
        <form method="GET" id="staffDocumentsFilterForm"><?= recordsSortFormFields($sortState) ?></form>
        <?= $pagedReadyDocs['meta_html'] ?>
        <?php if (empty($readyDocs)): ?>
            <div class="empty-state"><i class="fas fa-print"></i><p>No documents pending generation.</p></div>
        <?php else: ?>
            <table class="data-table">
                <thead><tr>
                    <?= renderRecordsSortHeader('Request #', 'request_number', $sortState, $sortQuery) ?>
                    <?= renderRecordsSortHeader('Student', 'name', $sortState, $sortQuery) ?>
                    <?= renderRecordsSortHeader('Document', 'document_name', $sortState, $sortQuery) ?>
                    <?= renderRecordsSortHeader('Copies', 'copies', $sortState, $sortQuery) ?>
                    <?= renderRecordsSortHeader('Status', 'status', $sortState, $sortQuery) ?>
                    <?= renderRecordsSortHeader('Verification Code', 'verification_code', $sortState, $sortQuery) ?>
                    <th>Action</th>
                </tr></thead>
                <tbody>
                    <?php foreach ($readyDocs as $req): ?>
                    <tr>
                        <td><?= e($req['request_number']) ?></td>
                        <td><?= e($req['first_name'] . ' ' . $req['last_name']) ?></td>
                        <td><?= e($req['document_name']) ?></td>
                        <td><?= $req['copies'] ?></td>
                        <td><?= statusBadge($req['status']) ?></td>
                        <td><code><?= e(formatVerificationCode($req['verification_code'])) ?></code></td>
                        <td>
                            <a href="process-request.php?id=<?= $req['id'] ?>" class="btn btn-sm btn-primary">Process</a>
                            <a href="<?= APP_URL ?>/verify.php?code=<?= urlencode(formatVerificationCode($req['verification_code'])) ?>&ref=<?= urlencode($req['request_number']) ?>" target="_blank" class="btn btn-sm btn-outline">Verify QR</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?= $pagedReadyDocs['html'] ?>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <div class="card-header"><h2>Document Generation Info</h2></div>
    <div class="card-body">
        <p>Each generated document includes:</p>
        <ul>
            <li><strong>Verification Code</strong> — Unique code for authenticity verification</li>
            <li><strong>QR Code</strong> — Scannable link to the online verification page</li>
            <li><strong>Digital Signature</strong> — Official registrar digital signature (when configured)</li>
        </ul>
        <p class="text-muted">For PDF generation, integrate a library like TCPDF or DomPDF in production.</p>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
