<?php
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/ui.php';

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
    <?php
    require_once __DIR__ . '/includes/theme.php';
    renderThemeStyleTag();
    ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</head>
<body class="landing-page">
    <?php renderLandingNav('home'); ?>

    <section class="landing-hero-carousel" id="landingHeroCarousel" tabindex="0" aria-roledescription="carousel" aria-label="Welcome highlights">
        <div class="landing-hero-carousel-viewport">
            <div class="landing-hero-carousel-track" id="landingHeroCarouselTrack">
                <article class="landing-hero-carousel-slide is-active" aria-hidden="false">
                    <div class="landing-hero-carousel-slide-inner tone-primary">
                        <div class="landing-hero-carousel-content">
                            <p class="landing-hero-carousel-eyebrow"><?= e(APP_TAGLINE) ?></p>
                            <h1>Online Credential Request System</h1>
                            <p>Request official documents, track your application, and receive credentials — all online.</p>
                            <div class="landing-hero-carousel-actions">
                                <a href="auth/register.php" class="btn btn-primary btn-lg">Get Started</a>
                                <a href="auth/login.php" class="btn btn-outline btn-lg btn-on-dark">Sign In</a>
                            </div>
                        </div>
                    </div>
                </article>
                <article class="landing-hero-carousel-slide" aria-hidden="true">
                    <div class="landing-hero-carousel-slide-inner tone-accent">
                        <div class="landing-hero-carousel-content">
                            <p class="landing-hero-carousel-eyebrow">Fast &amp; Convenient</p>
                            <h1>Request Documents Online</h1>
                            <p>Submit requests for TOR, diploma, certificates, and other registrar records from anywhere.</p>
                            <div class="landing-hero-carousel-actions">
                                <a href="auth/register.php" class="btn btn-primary btn-lg">Register Now</a>
                            </div>
                        </div>
                    </div>
                </article>
                <article class="landing-hero-carousel-slide" aria-hidden="true">
                    <div class="landing-hero-carousel-slide-inner tone-violet">
                        <div class="landing-hero-carousel-content">
                            <p class="landing-hero-carousel-eyebrow">Secure Payments</p>
                            <h1>Pay Online or On-Site</h1>
                            <p>Use bank transfer after review or pay at the cashier when you visit the office.</p>
                            <div class="landing-hero-carousel-actions">
                                <a href="auth/login.php" class="btn btn-primary btn-lg">Sign In to Pay</a>
                            </div>
                        </div>
                    </div>
                </article>
                <article class="landing-hero-carousel-slide" aria-hidden="true">
                    <div class="landing-hero-carousel-slide-inner tone-info">
                        <div class="landing-hero-carousel-content">
                            <p class="landing-hero-carousel-eyebrow">Authenticity Check</p>
                            <h1>Digital Verification</h1>
                            <p>Verify issued credentials instantly using the verification code or request number.</p>
                            <div class="landing-hero-carousel-actions">
                                <a href="verify.php" class="btn btn-primary btn-lg">Verify Document</a>
                            </div>
                        </div>
                    </div>
                </article>
            </div>
        </div>
        <button type="button" class="landing-hero-carousel-control landing-hero-carousel-prev" id="landingHeroCarouselPrev" aria-label="Previous slide">
            <i class="fas fa-chevron-left" aria-hidden="true"></i>
        </button>
        <button type="button" class="landing-hero-carousel-control landing-hero-carousel-next" id="landingHeroCarouselNext" aria-label="Next slide">
            <i class="fas fa-chevron-right" aria-hidden="true"></i>
        </button>
        <div class="landing-hero-carousel-dots" id="landingHeroCarouselDots" role="tablist" aria-label="Choose slide">
            <button type="button" class="is-active" role="tab" aria-selected="true" aria-label="Slide 1" data-slide="0"></button>
            <button type="button" role="tab" aria-selected="false" aria-label="Slide 2" data-slide="1"></button>
            <button type="button" role="tab" aria-selected="false" aria-label="Slide 3" data-slide="2"></button>
            <button type="button" role="tab" aria-selected="false" aria-label="Slide 4" data-slide="3"></button>
        </div>
    </section>

    <section class="landing-features">
        <div class="hero-features">
            <a href="auth/register.php" class="feature-card feature-card-link">
                <i class="fas fa-file-alt"></i>
                <h3>Request Documents</h3>
                <p>TOR, Diploma, Certificates, and more</p>
            </a>
            <div class="feature-card">
                <i class="fas fa-credit-card"></i>
                <h3>Online Payment</h3>
                <p>Pay via bank transfer or on-site at the cashier</p>
            </div>
            <a href="verify.php" class="feature-card feature-card-link">
                <i class="fas fa-qrcode"></i>
                <h3>Digital Verification</h3>
                <p>QR codes and digital signatures for authenticity</p>
            </a>
        </div>
    </section>

    <section class="documents-section">
        <h2>Available Documents</h2>
        <div class="doc-grid">
            <?php
            try {
                $docs = getPubliclyAvailableDocumentTypes();
                if ($docs === []): ?>
                    <p class="text-muted">No documents are currently available for request.</p>
                <?php else:
                    foreach ($docs as $doc) {
                        echo renderLandingDocumentCard($doc);
                    }
                endif;
            } catch (Exception $e) {
                echo '<p class="text-muted">Database not configured. Please import database/schema.sql</p>';
            }
            ?>
        </div>
    </section>

    <?php renderPublicPageFooter(); ?>
</body>
</html>
