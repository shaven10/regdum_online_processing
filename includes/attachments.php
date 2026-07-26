<?php

function formatFileSize(int $bytes): string {
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    if ($bytes < 1048576) {
        return round($bytes / 1024, 1) . ' KB';
    }
    return round($bytes / 1048576, 1) . ' MB';
}

function attachmentFileExt(string $filename): string {
    return strtolower(pathinfo($filename, PATHINFO_EXTENSION));
}

function attachmentIsImage(string $ext): bool {
    return in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true);
}

function attachmentIsPdf(string $ext): bool {
    return $ext === 'pdf';
}

function attachmentIcon(string $ext): string {
    return match (true) {
        attachmentIsImage($ext) => 'fa-file-image',
        attachmentIsPdf($ext)   => 'fa-file-pdf',
        in_array($ext, ['doc', 'docx'], true) => 'fa-file-word',
        default => 'fa-file',
    };
}

function attachmentUrl(?string $filePath): string {
    if (!$filePath) {
        return '';
    }
    return UPLOAD_URL . '/' . ltrim($filePath, '/');
}

function registrarInstructionCategory(): string {
    return 'registrar_instruction';
}

function isRegistrarInstructionCategory(?string $category): bool {
    return ($category ?? '') === registrarInstructionCategory();
}

function normalizeUploadedFiles(array $fileInput): array {
    if (!isset($fileInput['name'])) {
        return [];
    }

    if (!is_array($fileInput['name'])) {
        if (($fileInput['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return [];
        }
        return [$fileInput];
    }

    $files = [];
    foreach ($fileInput['name'] as $index => $name) {
        $error = $fileInput['error'][$index] ?? UPLOAD_ERR_NO_FILE;
        if ($error === UPLOAD_ERR_NO_FILE || $name === '') {
            continue;
        }
        $files[] = [
            'name' => $name,
            'type' => $fileInput['type'][$index] ?? '',
            'tmp_name' => $fileInput['tmp_name'][$index] ?? '',
            'error' => $error,
            'size' => $fileInput['size'][$index] ?? 0,
        ];
    }
    return $files;
}

function getRegistrarInstructionAttachments(int $requestId): array {
    require_once __DIR__ . '/student.php';
    ensureRepresentativeDocumentSchema();

    $db = getDB();
    $stmt = $db->prepare('SELECT * FROM request_documents
        WHERE request_id = ? AND document_category = ?
        ORDER BY uploaded_at ASC, id ASC');
    $stmt->execute([$requestId, registrarInstructionCategory()]);
    return $stmt->fetchAll();
}

function saveRegistrarInstructionAttachments(int $requestId, array $fileInput): int {
    require_once __DIR__ . '/student.php';
    ensureRepresentativeDocumentSchema();

    $files = normalizeUploadedFiles($fileInput);
    if ($files === []) {
        return 0;
    }

    $db = getDB();
    $insert = $db->prepare('INSERT INTO request_documents
        (request_id, document_category, file_name, original_name, file_type, file_size)
        VALUES (?, ?, ?, ?, ?, ?)');
    $saved = 0;

    foreach ($files as $file) {
        $storedPath = uploadFile($file, 'instructions');
        if (!$storedPath) {
            continue;
        }
        $insert->execute([
            $requestId,
            registrarInstructionCategory(),
            $storedPath,
            $file['name'],
            $file['type'] ?? null,
            $file['size'] ?? null,
        ]);
        $saved++;
    }

    return $saved;
}

function renderRegistrarNotesHtml(?array $summary, int $requestId): string {
    $remarks = trim((string) ($summary['remarks'] ?? ''));
    $attachmentsHtml = renderRegistrarInstructionAttachmentsHtml($requestId);
    if ($remarks === '' && $attachmentsHtml === '') {
        return '';
    }

    ob_start();
    ?>
    <div class="registrar-notes-panel">
        <div class="registrar-notes-header">
            <i class="fas fa-sticky-note"></i>
            <strong>Registrar Notes</strong>
        </div>
        <?php if ($remarks !== ''): ?>
            <div class="registrar-notes-body"><?= e($remarks) ?></div>
        <?php else: ?>
            <p class="text-muted registrar-notes-body">The registrar attached instruction files for this request.</p>
        <?php endif; ?>
        <?= $attachmentsHtml ?>
    </div>
    <?php
    return ob_get_clean();
}

function renderRegistrarInstructionAttachmentsHtml(int $requestId, bool $showEmpty = false): string {
    $attachments = getRegistrarInstructionAttachments($requestId);
    if ($attachments === [] && !$showEmpty) {
        return '';
    }

    ob_start();
    ?>
    <div class="instruction-attachments">
        <div class="instruction-attachments-title">
            <i class="fas fa-paperclip"></i> Instruction Attachments
        </div>
        <?php if ($attachments === []): ?>
            <p class="text-muted">No instruction files attached.</p>
        <?php else: ?>
            <ul class="instruction-attachment-list">
                <?php foreach ($attachments as $file): ?>
                    <?php
                    $url = attachmentUrl($file['file_name'] ?? '');
                    $name = $file['original_name'] ?? basename((string) ($file['file_name'] ?? ''));
                    $ext = attachmentFileExt($name);
                    ?>
                    <li>
                        <i class="fas <?= e(attachmentIcon($ext)) ?>"></i>
                        <a href="<?= e($url) ?>" target="_blank"><?= e($name) ?></a>
                        <?php if (!empty($file['file_size'])): ?>
                            <span class="text-muted"><?= e(formatFileSize((int) $file['file_size'])) ?></span>
                        <?php endif; ?>
                        <a href="<?= e($url) ?>" download class="btn btn-sm btn-outline">Download</a>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}

function getRequestAttachmentsGrouped(int $requestId): array {
    require_once __DIR__ . '/compliance.php';
    require_once __DIR__ . '/student.php';

    $db = getDB();
    $assigned = getAssignedRequirements($requestId);

    $stmt = $db->prepare('SELECT * FROM request_documents WHERE request_id = ? ORDER BY uploaded_at ASC');
    $stmt->execute([$requestId]);
    $documents = $stmt->fetchAll();

    $requirementDocs = [];
    $initialDocs = [];
    $representativeDocs = [];
    $instructionDocs = [];

    foreach ($documents as $doc) {
        if (isRepresentativeDocumentCategory($doc['document_category'] ?? null)) {
            $representativeDocs[] = $doc;
            continue;
        }

        if (isRegistrarInstructionCategory($doc['document_category'] ?? null)) {
            $instructionDocs[] = $doc;
            continue;
        }

        $matched = null;
        foreach ($assigned as $req) {
            if ((int) ($req['document_id'] ?? 0) === (int) $doc['id']) {
                $matched = $req;
                break;
            }
        }

        if ($matched) {
            $requirementDocs[] = array_merge($doc, [
                'requirement_name' => $matched['requirement_name'],
                'requirement_code' => $matched['requirement_code'] ?? '',
                'is_met' => (int) $matched['is_met'],
            ]);
        } else {
            $initialDocs[] = $doc;
        }
    }

    $paymentStmt = $db->prepare('SELECT id, amount, payment_method, reference_number, receipt_path, status, created_at
        FROM payments WHERE request_id = ? AND receipt_path IS NOT NULL AND receipt_path != ""
        ORDER BY created_at DESC');
    $paymentStmt->execute([$requestId]);
    $paymentReceipts = $paymentStmt->fetchAll();

    return [
        'initial' => $initialDocs,
        'requirements' => $requirementDocs,
        'representative' => $representativeDocs,
        'instructions' => $instructionDocs,
        'payments' => $paymentReceipts,
        'total' => count($initialDocs) + count($requirementDocs) + count($representativeDocs) + count($instructionDocs) + count($paymentReceipts),
    ];
}

function getRequestsWithAttachments(string $filter = '', string $search = ''): array {
    $db = getDB();
    $where = ["r.status NOT IN ('rejected')"];
    $params = [];

    if ($filter) {
        $where[] = 'r.status = ?';
        $params[] = $filter;
    }

    if ($search) {
        $where[] = '(r.request_number LIKE ? OR u.first_name LIKE ? OR u.last_name LIKE ? OR u.student_id LIKE ?)';
        array_push($params, "%$search%", "%$search%", "%$search%", "%$search%");
    }

    $sql = 'SELECT r.id, r.request_number, r.status, r.created_at,
                   u.first_name, u.last_name, u.student_id,
                   dt.name as document_name,
                   (SELECT COUNT(*) FROM request_documents rd WHERE rd.request_id = r.id) as document_count,
                   (SELECT COUNT(*) FROM payments p WHERE p.request_id = r.id AND p.receipt_path IS NOT NULL AND p.receipt_path != "") as receipt_count
            FROM requests r
            JOIN users u ON r.user_id = u.id
            JOIN document_types dt ON r.document_type_id = dt.id
            WHERE ' . implode(' AND ', $where) . '
            HAVING (document_count + receipt_count) > 0
            ORDER BY r.created_at DESC';

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function renderAttachmentPreview(array $file, string $label = ''): string {
    $path = $file['file_name'] ?? $file['receipt_path'] ?? '';
    $name = $file['original_name'] ?? basename($path);
    $url = attachmentUrl($path);
    if (!$url) {
        return '';
    }

    $ext = attachmentFileExt($name ?: $path);
    $icon = attachmentIcon($ext);
    $size = isset($file['file_size']) ? formatFileSize((int) $file['file_size']) : '';
    $uploaded = isset($file['uploaded_at']) ? formatDateTime($file['uploaded_at']) : (isset($file['created_at']) ? formatDateTime($file['created_at']) : '');

    ob_start();
    ?>
    <div class="attachment-card">
        <div class="attachment-preview">
            <?php if (attachmentIsImage($ext)): ?>
                <a href="<?= e($url) ?>" target="_blank" class="attachment-image-link">
                    <img src="<?= e($url) ?>" alt="<?= e($name) ?>" loading="lazy">
                </a>
            <?php elseif (attachmentIsPdf($ext)): ?>
                <iframe src="<?= e($url) ?>" title="<?= e($name) ?>" class="attachment-pdf-frame"></iframe>
            <?php else: ?>
                <div class="attachment-file-icon"><i class="fas <?= e($icon) ?>"></i></div>
            <?php endif; ?>
        </div>
        <div class="attachment-meta">
            <?php if ($label): ?><span class="attachment-label"><?= e($label) ?></span><?php endif; ?>
            <strong class="attachment-name" title="<?= e($name) ?>"><?= e($name) ?></strong>
            <div class="attachment-details">
                <?php if ($size): ?><span><?= e($size) ?></span><?php endif; ?>
                <?php if ($uploaded): ?><span><?= e($uploaded) ?></span><?php endif; ?>
            </div>
            <div class="attachment-actions">
                <a href="<?= e($url) ?>" target="_blank" class="btn btn-sm btn-outline"><i class="fas fa-eye"></i> View</a>
                <a href="<?= e($url) ?>" download class="btn btn-sm btn-primary"><i class="fas fa-download"></i> Download</a>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

function renderAttachmentSection(string $title, array $files, string $labelPrefix = ''): string {
    if (empty($files)) {
        return '';
    }

    $html = '<div class="attachment-section"><h3>' . e($title) . '</h3><div class="attachment-grid">';
    foreach ($files as $file) {
        $label = $labelPrefix;
        if (!empty($file['requirement_name'])) {
            $label = $file['requirement_name'];
        }
        $html .= renderAttachmentPreview($file, $label);
    }
    $html .= '</div></div>';
    return $html;
}
