<?php

function appLogoUrl(): string {
    return defined('APP_LOGO') ? APP_LOGO : (APP_URL . '/assets/images/logo.png');
}

function renderAppLogo(string $variant = 'default', string $alt = ''): string {
    $altText = $alt !== '' ? $alt : (APP_NAME . ' — ' . APP_TAGLINE);
    $class = match ($variant) {
        'sidebar' => 'app-logo app-logo-sidebar',
        'auth' => 'app-logo app-logo-auth',
        'nav' => 'app-logo app-logo-nav',
        'claim' => 'app-logo app-logo-claim',
        default => 'app-logo',
    };

    return '<img src="' . e(appLogoUrl()) . '" alt="' . e($altText) . '" class="' . e($class) . '">';
}

function statusFlashMeta(string $type): array {
    return match ($type) {
        'success' => [
            'title' => 'Action Completed',
            'icon'  => 'fa-check-circle',
            'tone'  => 'success',
        ],
        'error' => [
            'title' => 'Something Went Wrong',
            'icon'  => 'fa-exclamation-circle',
            'tone'  => 'error',
        ],
        'warning' => [
            'title' => 'Attention Required',
            'icon'  => 'fa-exclamation-triangle',
            'tone'  => 'warning',
        ],
        'info' => [
            'title' => 'Status Update',
            'icon'  => 'fa-info-circle',
            'tone'  => 'info',
        ],
        default => [
            'title' => 'Notification',
            'icon'  => 'fa-bell',
            'tone'  => 'info',
        ],
    };
}

function setStatusFlash(string $type, string $message, array $options = []): void {
    $meta = statusFlashMeta($type);
    setFlash($type, $message, array_merge([
        'title' => $meta['title'],
    ], $options));
}

function renderAppStatusModal(?array $flash): void {
    $payload = normalizeFlashPayload($flash);
    ?>
    <div class="status-modal" id="statusModal" aria-hidden="true">
        <div class="status-modal-overlay" data-close-status-modal></div>
        <div class="status-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="statusModalTitle">
            <div class="status-modal-accent" data-status-accent></div>
            <button type="button" class="status-modal-close" data-close-status-modal aria-label="Close">
                <i class="fas fa-times"></i>
            </button>

            <div class="status-modal-icon-wrap" data-status-icon-wrap>
                <i class="fas fa-info-circle" data-status-icon></i>
            </div>

            <span class="status-modal-eyebrow" data-status-eyebrow>System Update</span>
            <h2 class="status-modal-title" id="statusModalTitle" data-status-title>Status Update</h2>
            <p class="status-modal-message" data-status-message></p>

            <ul class="status-modal-details" data-status-details hidden></ul>

            <dl class="status-modal-context" data-status-context hidden></dl>

            <div class="status-modal-next" data-status-next hidden>
                <i class="fas fa-arrow-right"></i>
                <div>
                    <strong>What happens next</strong>
                    <p data-status-next-text></p>
                </div>
            </div>

            <div class="status-modal-meta">
                <span><i class="fas fa-clock"></i> <span data-status-time><?= e(date('M d, Y h:i A')) ?></span></span>
            </div>

            <div class="status-modal-actions">
                <button type="button" class="btn btn-outline" data-close-status-modal>Close</button>
                <a href="#" class="btn btn-primary" data-status-action hidden style="display:none">Continue</a>
            </div>
        </div>
    </div>
    <?php if ($payload): ?>
        <script>window.__APP_FLASH__ = <?= json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;</script>
    <?php endif;
}

function normalizeFlashPayload(?array $flash): ?array {
    if (!$flash) {
        return null;
    }

    $meta = statusFlashMeta($flash['type'] ?? 'info');
    $details = $flash['details'] ?? [];
    if (is_string($details)) {
        $details = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $details))));
    }

    $context = $flash['context'] ?? [];
    if (!is_array($context)) {
        $context = [];
    }

    return [
        'type'         => $flash['type'] ?? 'info',
        'tone'         => $meta['tone'],
        'icon'         => $meta['icon'],
        'title'        => $flash['title'] ?? $meta['title'],
        'message'      => $flash['message'] ?? '',
        'details'      => $details,
        'context'      => $context,
        'next_step'    => $flash['next_step'] ?? '',
        'action_url'   => $flash['action_url'] ?? '',
        'action_label' => $flash['action_label'] ?? '',
        'timestamp'    => date('M d, Y h:i A'),
    ];
}

function buildRequestStatusModalData(array $request, int $requestId): array {
    require_once __DIR__ . '/compliance.php';

    $next = studentProgressNextAction($request['status'], $requestId, $request['delivery_method'] ?? null);
    $tone = match ($request['status']) {
        'completed' => 'success',
        'rejected' => 'error',
        'needs_revision' => 'warning',
        default => 'info',
    };

    return normalizeFlashPayload([
        'type' => $tone,
        'title' => studentProgressStatusLabel($request['status']),
        'message' => studentProgressDescription($request['status'], $requestId),
        'context' => [
            'Request' => $request['request_number'],
            'Document' => $request['document_name'],
            'Status' => ucwords(str_replace('_', ' ', $request['status'])),
            'Progress' => studentProgressPercent($request['status'], $requestId) . '% complete',
        ],
        'next_step' => $next['hint'] ?? '',
        'action_url' => $next['url'] ?? '',
        'action_label' => $next['label'] ?? '',
    ]) ?? [];
}

function renderRequestStatusButton(array $request, int $requestId): string {
    $payload = buildRequestStatusModalData($request, $requestId);
    $json = htmlspecialchars(json_encode($payload, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');

    return '<button type="button" class="btn btn-outline btn-sm status-detail-btn" data-open-status-modal="' . $json . '">'
        . '<i class="fas fa-circle-info"></i> View Status Details</button>';
}

function adminFormRecordAttr(array $record): string {
    return htmlspecialchars(json_encode($record, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
}

function adminSettingsActionMeta(string $action): array {
    return match ($action) {
        'edit'       => ['icon' => 'fa-edit', 'label' => 'Edit'],
        'activate'   => ['icon' => 'fa-toggle-on', 'label' => 'Activate'],
        'deactivate' => ['icon' => 'fa-toggle-off', 'label' => 'Deactivate'],
        'delete'     => ['icon' => 'fa-trash-alt', 'label' => 'Delete'],
        'configure'  => ['icon' => 'fa-sliders-h', 'label' => 'Configure'],
        default      => ['icon' => 'fa-circle', 'label' => ucfirst($action)],
    };
}

function adminSettingsIconBtnClass(string $variant = 'outline'): string {
    return 'btn btn-sm btn-' . $variant . ' btn-icon-action';
}

function adminSettingsIconBtnAttrs(string $action, string $variant = 'outline'): string {
    $meta = adminSettingsActionMeta($action);
    return 'class="' . e(adminSettingsIconBtnClass($variant)) . '" title="' . e($meta['label']) . '" aria-label="' . e($meta['label']) . '"';
}

function adminSettingsIconBtnContent(string $action): string {
    $meta = adminSettingsActionMeta($action);
    return '<i class="fas ' . e($meta['icon']) . '" aria-hidden="true"></i>';
}

function renderAdminFormModalOpen(string $eyebrow = 'Settings', string $title = '', string $modalId = 'adminFormModal', bool $wide = false): void {
    ?>
    <div class="admin-form-modal" id="<?= e($modalId) ?>" aria-hidden="true">
        <div class="admin-form-modal-overlay" data-close-admin-form></div>
        <div class="admin-form-modal-dialog<?= $wide ? ' wide' : '' ?>" role="dialog" aria-modal="true" aria-labelledby="adminFormModalTitle">
            <div class="admin-form-modal-header">
                <div>
                    <span class="admin-form-modal-eyebrow"><?= e($eyebrow) ?></span>
                    <h2 class="admin-form-modal-title" id="adminFormModalTitle" data-admin-form-title><?= e($title) ?></h2>
                </div>
                <button type="button" class="admin-form-modal-close" data-close-admin-form aria-label="Close">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="admin-form-modal-body">
    <?php
}

function renderAdminFormModalClose(): void {
    ?>
            </div>
        </div>
    </div>
    <?php
}

function renderAdminFormModalFooter(string $submitLabel = 'Save', string $submitIcon = 'fa-save'): void {
    ?>
    <div class="admin-form-modal-footer">
        <button type="button" class="btn btn-outline" data-close-admin-form>Cancel</button>
        <button type="submit" class="btn btn-primary" data-admin-form-submit>
            <i class="fas <?= e($submitIcon) ?>"></i> <span data-admin-form-submit-label><?= e($submitLabel) ?></span>
        </button>
    </div>
    <?php
}

function adminCredentialSettingsModules(): array {
    return [
        'documents' => [
            'label' => 'Document Types',
            'url'   => APP_URL . '/admin/document-types.php',
            'icon'  => 'fa-file-alt',
        ],
        'release-rules' => [
            'label' => 'Release Rules',
            'url'   => APP_URL . '/admin/document-release-rules.php',
            'icon'  => 'fa-user-check',
        ],
        'requirement-types' => [
            'label' => 'Requirement Types',
            'url'   => APP_URL . '/admin/requirement-types.php',
            'icon'  => 'fa-list-check',
        ],
        'requirements' => [
            'label' => 'Requirement Settings',
            'url'   => APP_URL . '/admin/requirement-settings.php',
            'icon'  => 'fa-sliders-h',
        ],
        'purpose-suggestions' => [
            'label' => 'Purpose & Suggestions',
            'url'   => APP_URL . '/admin/purpose-suggestions.php',
            'icon'  => 'fa-bullseye',
        ],
    ];
}

function renderAdminCredentialSettingsNav(string $activeKey): void {
    $modules = adminCredentialSettingsModules();
    ?>
    <nav class="admin-settings-module-nav" aria-label="Credential settings modules">
        <?php foreach ($modules as $key => $module): ?>
            <?php $isActive = $key === $activeKey; ?>
            <a href="<?= e($module['url']) ?>"
               class="btn btn-sm <?= $isActive ? 'btn-primary' : 'btn-outline' ?>"
               <?= $isActive ? 'aria-current="page"' : '' ?>>
                <i class="fas <?= e($module['icon']) ?>"></i> <?= e($module['label']) ?>
            </a>
        <?php endforeach; ?>
    </nav>
    <?php
}
