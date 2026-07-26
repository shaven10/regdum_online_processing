<?php
require_once __DIR__ . '/ui.php';
$appFlash = getFlash();
renderAppStatusModal($appFlash);
?>
<?php if ($user ?? null): ?>
        </main>
    </div>
</div>
<?php endif; ?>
<script src="<?= APP_URL ?>/assets/js/app.js"></script>
</body>
</html>
