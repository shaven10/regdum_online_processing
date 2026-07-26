<?php
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/auth.php';

if (isLoggedIn()) {
    redirect(dashboardUrl());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e(APP_NAME) ?></title>
    <link rel="icon" type="image/png" href="<?= e(APP_LOGO) ?>">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</head>
<body class="landing-page">
    <nav class="landing-nav">
        <div class="nav-brand">
            <img src="<?= e(APP_LOGO) ?>" alt="<?= e(APP_NAME) ?>" class="app-logo app-logo-nav">
            <span><?= e(APP_NAME) ?></span>
        </div>
        <div class="nav-links">
            <a href="faq.php">FAQ</a>
            <a href="verify.php">Verify Document</a>
            <a href="auth/login.php" class="btn btn-outline">Sign In</a>
            <a href="auth/register.php" class="btn btn-primary">Register</a>
        </div>
    </nav>

    <section class="hero">
        <div class="hero-content">
            <h1>Online Credential Request System</h1>
            <p>Request official documents, track your application, and receive credentials — all online.</p>
            <div class="hero-actions">
                <a href="auth/register.php" class="btn btn-primary btn-lg">Get Started</a>
                <a href="auth/login.php" class="btn btn-outline btn-lg">Sign In</a>
            </div>
        </div>
        <div class="hero-features">
            <div class="feature-card">
                <i class="fas fa-file-alt"></i>
                <h3>Request Documents</h3>
                <p>TOR, Diploma, Certificates, and more</p>
            </div>
            <div class="feature-card">
                <i class="fas fa-credit-card"></i>
                <h3>Online Payment</h3>
                <p>Pay via card, e-wallet, or bank transfer</p>
            </div>
            <div class="feature-card">
                <i class="fas fa-truck"></i>
                <h3>Track & Deliver</h3>
                <p>Real-time status updates and delivery options</p>
            </div>
            <div class="feature-card">
                <i class="fas fa-qrcode"></i>
                <h3>Digital Verification</h3>
                <p>QR codes and digital signatures for authenticity</p>
            </div>
        </div>
    </section>

    <section class="documents-section">
        <h2>Available Documents</h2>
        <div class="doc-grid">
            <?php
            try {
                $db = getDB();
                $docs = $db->query('SELECT * FROM document_types WHERE is_active = 1 ORDER BY name')->fetchAll();
                foreach ($docs as $doc): ?>
                    <div class="doc-item">
                        <h4><?= e($doc['name']) ?></h4>
                        <p><?= e($doc['description']) ?></p>
                        <span class="doc-fee"><?= formatDocumentTypeUnitFee($doc) ?></span>
                        <span class="doc-days"><?= (int)$doc['processing_days'] ?> days processing</span>
                    </div>
                <?php endforeach;
            } catch (Exception $e) {
                echo '<p class="text-muted">Database not configured. Please import database/schema.sql</p>';
            }
            ?>
        </div>
    </section>

    <footer class="landing-footer">
        <p>&copy; <?= date('Y') ?> <?= e(APP_NAME) ?>. All rights reserved.</p>
    </footer>
</body>
</html>
