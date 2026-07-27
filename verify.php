<?php
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/ui.php';

$code = trim($_GET['code'] ?? '');
$ref = trim($_GET['ref'] ?? '');
$result = null;

if ($code || $ref) {
    $db = getDB();
    if ($code) {
        $normalized = normalizeVerificationCode($code);
        $stmt = $db->prepare('SELECT r.*, dt.name as document_name, u.first_name, u.last_name, u.student_id
            FROM requests r
            JOIN document_types dt ON r.document_type_id = dt.id
            JOIN users u ON r.user_id = u.id
            WHERE REPLACE(UPPER(r.verification_code), "-", "") = ?
               OR UPPER(r.verification_code) = ?
            LIMIT 1');
        $stmt->execute([$normalized, strtoupper($code)]);
    } else {
        $stmt = $db->prepare('SELECT r.*, dt.name as document_name, u.first_name, u.last_name, u.student_id FROM requests r JOIN document_types dt ON r.document_type_id = dt.id JOIN users u ON r.user_id = u.id WHERE r.request_number = ?');
        $stmt->execute([$ref]);
    }
    $result = $stmt->fetch();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verify Document - <?= e(APP_NAME) ?></title>
    <link rel="icon" type="image/png" href="<?= e(APP_LOGO) ?>">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
    <?php
    require_once __DIR__ . '/includes/theme.php';
    renderThemeStyleTag();
    ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</head>
<body class="landing-page">
<?php renderLandingNav('verify'); ?>

<div class="container page-container">
    <h1><i class="fas fa-shield-alt"></i> Document Verification</h1>
    <p>Verify the authenticity of issued credentials using the verification code or request number.</p>

    <form method="GET" class="verify-form">
        <div class="form-row">
            <div class="form-group">
                <label for="code">Verification Code</label>
                <input type="text" id="code" name="code" value="<?= e($code) ?>" placeholder="ABCD-2345" maxlength="20" autocomplete="off" style="text-transform:uppercase;letter-spacing:.08em">
            </div>
            <div class="form-group">
                <label for="ref">Or Request Number</label>
                <input type="text" id="ref" name="ref" value="<?= e($ref) ?>" placeholder="REQ-2026-XXXXXX">
            </div>
        </div>
        <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Verify</button>
    </form>

    <?php if ($code || $ref): ?>
        <?php if ($result && $result['status'] === 'completed'): ?>
            <div class="verify-result valid">
                <div class="verify-icon"><i class="fas fa-check-circle"></i></div>
                <h2>Document Verified</h2>
                <p>This is an authentic document issued by <?= e(APP_NAME) ?>.</p>
                <div class="detail-grid">
                    <div class="detail-item"><label>Request #</label><span><?= e($result['request_number']) ?></span></div>
                    <div class="detail-item"><label>Document</label><span><?= e($result['document_name']) ?></span></div>
                    <div class="detail-item"><label>Issued To</label><span><?= e($result['first_name'] . ' ' . $result['last_name']) ?></span></div>
                    <div class="detail-item"><label>Student ID</label><span><?= e($result['student_id']) ?></span></div>
                    <div class="detail-item"><label>Copies</label><span><?= $result['copies'] ?></span></div>
                    <?= renderRequestTermInfoHtml($result) ?>
                    <?= renderRequestSoaInfoHtml($result) ?>
                    <?= renderRequestAuthenticationItemsHtml($result) ?>
                    <div class="detail-item"><label>Completed</label><span><?= formatDateTime($result['completed_at']) ?></span></div>
                    <div class="detail-item"><label>Verification Code</label><span><code><?= e(formatVerificationCode($result['verification_code'])) ?></code></span></div>
                </div>
            </div>
        <?php elseif ($result): ?>
            <div class="verify-result pending">
                <div class="verify-icon"><i class="fas fa-clock"></i></div>
                <h2>Document Found — Not Yet Completed</h2>
                <p>Request <?= e($result['request_number']) ?> is currently: <?= ucwords(str_replace('_',' ',$result['status'])) ?></p>
            </div>
        <?php else: ?>
            <div class="verify-result invalid">
                <div class="verify-icon"><i class="fas fa-times-circle"></i></div>
                <h2>Document Not Found</h2>
                <p>No matching document was found. Please check the code and try again.</p>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
<?php renderPublicPageFooter(); ?>
</body>
</html>
