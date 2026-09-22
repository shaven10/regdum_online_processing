<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/ui.php';
require_once __DIR__ . '/../includes/queue.php';
require_once __DIR__ . '/../includes/theme.php';

ensureQueueSchema();
$state = getQueueDisplayState();
$ads = $state['ads'] ?? ['enabled' => false, 'interval_seconds' => 8, 'images' => []];
$adsVisible = !empty($ads['enabled']) && ($ads['images'] ?? []) !== [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Now Serving - <?= e(APP_NAME) ?></title>
    <link rel="icon" type="image/png" href="<?= e(APP_LOGO) ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@500;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
    <?php renderThemeStyleTag(); ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
</head>
<body class="queue-display-page<?= $adsVisible ? ' has-queue-ads' : '' ?>" data-queue-status-url="<?= e(queueStatusUrl()) ?>">
    <header class="queue-display-top">
        <div class="queue-display-brand">
            <?= renderAppLogo('nav') ?>
            <div>
                <p class="queue-display-office"><?= e(APP_NAME) ?></p>
                <p class="queue-display-tagline"><?= e(APP_TAGLINE) ?> · Onsite Document Processing</p>
            </div>
        </div>
        <div class="queue-display-clock">
            <span data-queue-date><?= e($state['date_label']) ?></span>
            <strong data-queue-time><?= e($state['time_label']) ?></strong>
        </div>
    </header>

    <div class="queue-display-split">
        <div class="queue-display-board">
            <main class="queue-display-main">
                <section class="queue-display-windows" data-queue-windows>
                    <?php foreach ($state['windows'] as $window): ?>
                        <article class="queue-display-window<?= empty($window['ticket_code']) ? ' is-idle' : '' ?>">
                            <p class="queue-display-window-name"><?= e((string) $window['name']) ?></p>
                            <p class="queue-display-now-label">Now Serving</p>
                            <p class="queue-display-number"><?= e((string) ($window['ticket_code'] ?: '---')) ?></p>
                            <p class="queue-display-staff"><?= e((string) ($window['staff'] !== '' ? $window['staff'] : 'Window open')) ?></p>
                        </article>
                    <?php endforeach; ?>
                </section>

                <aside class="queue-display-waiting">
                    <h2>Waiting</h2>
                    <p class="queue-display-waiting-count"><span data-queue-waiting-count><?= (int) $state['waiting_count'] ?></span> in queue</p>
                    <ol data-queue-waiting>
                        <?php if ($state['waiting'] === []): ?>
                            <li class="is-empty">No one waiting</li>
                        <?php else: ?>
                            <?php foreach ($state['waiting'] as $ticket): ?>
                                <li><strong><?= e((string) $ticket['ticket_code']) ?></strong> <?= e((string) $ticket['service_label']) ?></li>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </ol>
                </aside>
            </main>
            <p class="queue-display-footer">Please wait until your number is called, then proceed to the window shown.</p>
        </div>

        <aside class="queue-display-ads" data-queue-ads <?= $adsVisible ? '' : 'hidden' ?> data-queue-ads-interval="<?= (int) ($ads['interval_seconds'] ?? 8) ?>">
            <?php foreach ($ads['images'] ?? [] as $index => $image): ?>
                <div class="queue-display-ad<?= $index === 0 ? ' is-active' : '' ?>">
                    <img src="<?= e((string) $image['url']) ?>" alt="<?= e((string) ($image['name'] ?? 'Advertisement')) ?>">
                </div>
            <?php endforeach; ?>
            <p class="queue-display-ads-count" data-queue-ads-count <?= count($ads['images'] ?? []) > 1 ? '' : 'hidden' ?>>
                <span data-queue-ads-index>1</span> / <span data-queue-ads-total><?= count($ads['images'] ?? []) ?></span>
            </p>
        </aside>
    </div>
    <script src="<?= APP_URL ?>/assets/js/queue-display.js"></script>
</body>
</html>
