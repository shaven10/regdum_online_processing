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

$pageTitle = 'Document Generation';
$activeNav = 'documents';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="card">
    <div class="card-header"><h2>Documents for Processing</h2></div>
    <div class="card-body">
        <?php if (empty($readyDocs)): ?>
            <div class="empty-state"><i class="fas fa-print"></i><p>No documents pending generation.</p></div>
        <?php else: ?>
            <table class="data-table">
                <thead><tr><th>Request #</th><th>Student</th><th>Document</th><th>Copies</th><th>Status</th><th>Verification Code</th><th>Action</th></tr></thead>
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
