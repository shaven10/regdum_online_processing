<?php
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/functions.php';

$db = getDB();
$faqs = $db->query('SELECT * FROM faqs WHERE is_active = 1 ORDER BY sort_order, category')->fetchAll();
$grouped = [];
foreach ($faqs as $faq) {
    $grouped[$faq['category']][] = $faq;
}

$pageTitle = 'FAQ';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FAQ - <?= e(APP_NAME) ?></title>
    <link rel="icon" type="image/png" href="<?= e(APP_LOGO) ?>">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</head>
<body>
<nav class="landing-nav">
    <div class="nav-brand">
        <a href="index.php">
            <img src="<?= e(APP_LOGO) ?>" alt="<?= e(APP_NAME) ?>" class="app-logo app-logo-nav">
            <span><?= e(APP_NAME) ?></span>
        </a>
    </div>
    <div class="nav-links">
        <a href="verify.php">Verify Document</a>
        <a href="auth/login.php">Sign In</a>
    </div>
</nav>

<div class="container page-container">
    <h1>Frequently Asked Questions</h1>

    <?php foreach ($grouped as $category => $items): ?>
        <div class="faq-section">
            <h2><?= e($category) ?></h2>
            <?php foreach ($items as $faq): ?>
                <details class="faq-item">
                    <summary><?= e($faq['question']) ?></summary>
                    <p><?= e($faq['answer']) ?></p>
                </details>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>
</div>
</body>
</html>
