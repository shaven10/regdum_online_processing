document.addEventListener('DOMContentLoaded', function () {
    initNotificationToasts();

    const toggle = document.getElementById('sidebarToggle');
    const sidebar = document.getElementById('sidebar');

    if (toggle && sidebar) {
        const overlay = document.getElementById('sidebarOverlay');

        function setSidebarOpen(open) {
            sidebar.classList.toggle('open', open);
            if (overlay) {
                overlay.classList.toggle('visible', open);
                overlay.setAttribute('aria-hidden', open ? 'false' : 'true');
            }
            document.body.style.overflow = open && window.innerWidth <= 768 ? 'hidden' : '';
        }

        toggle.addEventListener('click', function () {
            setSidebarOpen(!sidebar.classList.contains('open'));
        });

        if (overlay) {
            overlay.addEventListener('click', function () {
                setSidebarOpen(false);
            });
        }

        document.addEventListener('click', function (e) {
            if (window.innerWidth <= 768 && sidebar.classList.contains('open') &&
                !sidebar.contains(e.target) && !toggle.contains(e.target)) {
                setSidebarOpen(false);
            }
        });

        window.addEventListener('resize', function () {
            if (window.innerWidth > 768) {
                setSidebarOpen(false);
            }
        });
    }

    document.querySelectorAll('.alert').forEach(function (alert) {
        setTimeout(function () {
            alert.style.transition = 'opacity .5s';
            alert.style.opacity = '0';
            setTimeout(function () { alert.remove(); }, 500);
        }, 5000);
    });

    const chatMessages = document.getElementById('chatMessages');
    if (chatMessages) {
        chatMessages.scrollTop = chatMessages.scrollHeight;
    }

    document.querySelectorAll('.sidebar-nav-group-toggle').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const group = btn.closest('.sidebar-nav-group');
            if (!group) return;
            const isOpen = group.classList.toggle('open');
            btn.classList.toggle('active', isOpen);
            btn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        });
    });

    initPaymentReviewModal();
    initReleaseScheduleForm();
    initStatusModal();
    initAdminFormModal();
});

function initStatusModal() {
    const modal = document.getElementById('statusModal');
    if (!modal) return;

    function closeModal() {
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
    }

    function setHidden(el, hidden) {
        if (!el) return;
        el.hidden = hidden;
    }

    function openStatusModal(data) {
        if (!data) return;

        const tone = data.tone || data.type || 'info';
        const icon = modal.querySelector('[data-status-icon]');
        const iconWrap = modal.querySelector('[data-status-icon-wrap]');
        const accent = modal.querySelector('[data-status-accent]');
        const title = modal.querySelector('[data-status-title]');
        const message = modal.querySelector('[data-status-message]');
        const details = modal.querySelector('[data-status-details]');
        const context = modal.querySelector('[data-status-context]');
        const nextBlock = modal.querySelector('[data-status-next]');
        const nextText = modal.querySelector('[data-status-next-text]');
        const time = modal.querySelector('[data-status-time]');
        const action = modal.querySelector('[data-status-action]');
        const eyebrow = modal.querySelector('[data-status-eyebrow]');

        modal.className = 'status-modal is-open tone-' + tone;
        modal.setAttribute('aria-hidden', 'false');

        if (icon) icon.className = 'fas ' + (data.icon || 'fa-info-circle');
        if (iconWrap) iconWrap.className = 'status-modal-icon-wrap tone-' + tone;
        if (accent) accent.className = 'status-modal-accent tone-' + tone;
        if (eyebrow) eyebrow.textContent = tone.charAt(0).toUpperCase() + tone.slice(1) + ' Update';
        if (title) title.textContent = data.title || 'Status Update';
        if (message) message.textContent = data.message || '';
        if (time) time.textContent = data.timestamp || 'Just now';

        if (details) {
            details.innerHTML = '';
            const detailItems = Array.isArray(data.details) ? data.details : [];
            if (detailItems.length) {
                detailItems.forEach(function (item) {
                    const li = document.createElement('li');
                    li.innerHTML = '<i class="fas fa-check"></i><span>' + escapeHtml(String(item)) + '</span>';
                    details.appendChild(li);
                });
                setHidden(details, false);
            } else {
                setHidden(details, true);
            }
        }

        if (context) {
            context.innerHTML = '';
            const contextItems = data.context && typeof data.context === 'object' ? data.context : {};
            const keys = Object.keys(contextItems);
            if (keys.length) {
                keys.forEach(function (key) {
                    const dt = document.createElement('dt');
                    dt.textContent = key;
                    const dd = document.createElement('dd');
                    dd.textContent = contextItems[key];
                    context.appendChild(dt);
                    context.appendChild(dd);
                });
                setHidden(context, false);
            } else {
                setHidden(context, true);
            }
        }

        if (nextBlock && nextText) {
            if (data.next_step) {
                nextText.textContent = data.next_step;
                setHidden(nextBlock, false);
            } else {
                setHidden(nextBlock, true);
            }
        }

        if (action) {
            if (data.action_url) {
                action.href = data.action_url;
                action.textContent = data.action_label || 'Continue';
                action.removeAttribute('hidden');
                action.style.display = '';
                action.style.pointerEvents = 'auto';
            } else {
                action.setAttribute('hidden', '');
                action.style.display = 'none';
                action.href = '#';
            }
        }

        document.body.style.overflow = 'hidden';
    }

    modal.querySelectorAll('[data-close-status-modal]').forEach(function (el) {
        el.addEventListener('click', closeModal);
    });

    const actionBtn = modal.querySelector('[data-status-action]');
    if (actionBtn) {
        actionBtn.addEventListener('click', function (e) {
            const url = actionBtn.getAttribute('href') || '';
            if (!url || url === '#' || url.endsWith('#')) {
                e.preventDefault();
                return;
            }
            closeModal();
        });
    }

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && modal.classList.contains('is-open')) {
            closeModal();
        }
    });

    document.querySelectorAll('[data-open-status-modal]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            try {
                openStatusModal(JSON.parse(btn.getAttribute('data-open-status-modal') || '{}'));
            } catch (err) {
                openStatusModal({
                    type: btn.dataset.statusType || 'info',
                    title: btn.dataset.statusTitle || 'Status Update',
                    message: btn.dataset.statusMessage || '',
                });
            }
        });
    });

    if (window.__APP_FLASH__) {
        openStatusModal(window.__APP_FLASH__);
        window.__APP_FLASH__ = null;
    }

    window.openStatusModal = openStatusModal;
}

function escapeHtml(value) {
    return value
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function initReleaseScheduleForm() {
    function bindReset(dateInput, timeInput, button) {
        if (!dateInput || !button) return;
        button.addEventListener('click', function () {
            const suggestedDate = dateInput.dataset.suggestedDate;
            const suggestedTime = dateInput.dataset.suggestedTime;
            if (suggestedDate) {
                dateInput.value = suggestedDate;
            }
            if (timeInput && suggestedTime) {
                timeInput.value = suggestedTime;
            }
        });
    }

    bindReset(
        document.getElementById('release_date'),
        document.getElementById('release_time'),
        document.getElementById('resetReleaseSchedule')
    );

    bindReset(
        document.getElementById('edit_release_date'),
        document.getElementById('edit_release_time'),
        document.getElementById('resetReleaseScheduleEdit')
    );
}

function initPaymentReviewModal() {
    const modal = document.getElementById('paymentReviewModal');
    if (!modal) return;

    const reviewStep = modal.querySelector('[data-step="review"]');
    const rejectStep = modal.querySelector('[data-step="reject"]');
    const rejectForm = document.getElementById('paymentRejectForm');
    const rejectNotes = document.getElementById('paymentRejectNotes');
    const charCount = modal.querySelector('[data-char-count]');
    const receiptPreview = modal.querySelector('[data-receipt-preview]');
    const receiptOpen = modal.querySelector('.payment-receipt-open');
    let activePaymentId = null;

    function setStep(step) {
        const showReview = step === 'review';
        reviewStep.hidden = !showReview;
        rejectStep.hidden = showReview;
    }

    function setField(name, value) {
        modal.querySelectorAll('[data-field="' + name + '"]').forEach(function (el) {
            el.textContent = value || '—';
        });
    }

    function setPaymentId(id) {
        activePaymentId = id;
        modal.querySelectorAll('[data-payment-id-input]').forEach(function (input) {
            input.value = id || '';
        });
    }

    function renderReceipt(url, isImage) {
        receiptPreview.innerHTML = '';
        if (!url) {
            receiptPreview.innerHTML = '<div class="payment-receipt-empty"><i class="fas fa-file-image"></i><p>No receipt uploaded</p></div>';
            receiptOpen.hidden = true;
            return;
        }

        receiptOpen.href = url;
        receiptOpen.hidden = false;

        if (isImage) {
            const img = document.createElement('img');
            img.src = url;
            img.alt = 'Payment receipt';
            receiptPreview.appendChild(img);
            return;
        }

        const link = document.createElement('a');
        link.href = url;
        link.target = '_blank';
        link.className = 'payment-receipt-file-link';
        link.innerHTML = '<i class="fas fa-file-pdf"></i> View receipt file';
        receiptPreview.appendChild(link);
    }

    function openModal(button) {
        const data = button.dataset;

        setField('request-number', data.requestNumber);
        setField('student-name', data.studentName);
        setField('student-id', data.studentId || '');
        setField('method', data.method);
        setField('amount', data.amount);
        setField('reference', data.reference);
        setField('submitted', data.submitted);

        const referenceLabel = modal.querySelector('[data-reference-label]');
        if (referenceLabel) {
            referenceLabel.textContent = data.isOnsite === '1' ? 'Payment Code' : 'Reference';
        }
        setField('reject-request-number', data.requestNumber);
        setField('reject-student-name', data.studentName);
        setField('reject-amount', data.amount);

        renderReceipt(data.receiptUrl || '', data.receiptIsImage === '1');

        if (rejectForm) {
            rejectForm.reset();
            modal.querySelectorAll('.reject-reason-chip').forEach(function (chip) {
                chip.classList.remove('is-selected');
            });
            if (charCount) charCount.textContent = '0';
        }

        const orInput = document.getElementById('paymentOrNumber');
        const dateInput = document.getElementById('paymentDatePaid');
        if (orInput) orInput.value = '';
        if (dateInput) dateInput.value = '';

        setPaymentId(data.paymentId);

        setStep('review');
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';
    }

    function closeModal() {
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
        setPaymentId(null);
        setStep('review');
    }

    document.querySelectorAll('.payment-review-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            openModal(btn);
        });
    });

    modal.querySelectorAll('[data-close-payment-modal]').forEach(function (el) {
        el.addEventListener('click', closeModal);
    });

    modal.querySelector('[data-goto-reject-step]')?.addEventListener('click', function () {
        setStep('reject');
        rejectNotes?.focus();
    });

    modal.querySelector('[data-goto-review-step]')?.addEventListener('click', function () {
        setStep('review');
    });

    modal.querySelectorAll('.reject-reason-chip').forEach(function (chip) {
        chip.addEventListener('click', function () {
            if (!rejectNotes) return;
            const reason = chip.dataset.reason || '';
            const current = rejectNotes.value.trim();
            rejectNotes.value = current ? current + '\n' + reason : reason;
            chip.classList.add('is-selected');
            rejectNotes.dispatchEvent(new Event('input'));
            rejectNotes.focus();
        });
    });

    rejectNotes?.addEventListener('input', function () {
        if (charCount) charCount.textContent = String(rejectNotes.value.length);
    });

    rejectForm?.addEventListener('submit', function (e) {
        if (!rejectNotes.value.trim()) {
            e.preventDefault();
            rejectNotes.focus();
            return;
        }
        if (!confirm('Send this rejection feedback to the student?')) {
            e.preventDefault();
        }
    });

    modal.querySelector('#paymentVerifyForm')?.addEventListener('submit', function (e) {
        const orInput = document.getElementById('paymentOrNumber');
        const dateInput = document.getElementById('paymentDatePaid');
        const orNumber = orInput?.value.trim() || '';
        const paymentDate = dateInput?.value.trim() || '';

        if (!orNumber || !paymentDate) {
            e.preventDefault();
            if (!orNumber) {
                orInput?.focus();
            } else {
                dateInput?.focus();
            }
            return;
        }

        if (!confirm('Verify this payment with OR ' + orNumber + ' dated ' + paymentDate + '?')) {
            e.preventDefault();
        }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && modal.classList.contains('is-open')) {
            closeModal();
        }
    });

    if (window.__ONSITE_PAYMENT_LOOKUP__) {
        const lookupId = String(window.__ONSITE_PAYMENT_LOOKUP__);
        const btn = document.querySelector('.payment-review-btn[data-payment-id="' + lookupId + '"]');
        if (btn) {
            openModal(btn);
            const row = document.getElementById('payment-' + lookupId);
            row?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    }
}

function initAdminFormModal() {
    const modal = document.getElementById('adminFormModal');
    if (!modal) return;

    const form = modal.querySelector('[data-admin-form]');
    if (!form) return;

    const titleEl = modal.querySelector('[data-admin-form-title]');
    const submitLabel = form.querySelector('[data-admin-form-submit-label]');
    const submitIcon = form.querySelector('[data-admin-form-submit] i');
    const actionInput = form.querySelector('[name="action"]');
    const idField = form.dataset.idField || '';
    const idInput = idField ? form.querySelector('[name="' + idField + '"]') : null;
    const createTitle = form.dataset.createTitle || 'Create';
    const updateTitle = form.dataset.updateTitle || 'Update';
    const createAction = form.dataset.createAction || 'create';
    const updateAction = form.dataset.updateAction || 'update';
    const createSubmitLabel = form.dataset.createSubmitLabel || 'Save';
    const updateSubmitLabel = form.dataset.updateSubmitLabel || 'Update';
    const createSubmitIcon = form.dataset.createSubmitIcon || 'fa-plus';
    const updateSubmitIcon = form.dataset.updateSubmitIcon || 'fa-save';
    const formType = form.dataset.adminFormType || '';

    function closeModal() {
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
        document.body.style.overflow = '';
    }

    function populateForm(record) {
        form.querySelectorAll('input, select, textarea').forEach(function (field) {
            if (!field.name || field.name === '_csrf') return;

            const baseName = field.name.replace(/\[\]$/, '');

            if (field.type === 'checkbox') {
                if (field.name.endsWith('[]')) {
                    const values = record && Array.isArray(record[baseName]) ? record[baseName] : [];
                    field.checked = values.includes(field.value);
                } else if (record) {
                    field.checked = record[baseName] === true || record[baseName] === 1 || record[baseName] === '1';
                } else {
                    field.checked = field.hasAttribute('data-default-checked');
                }
                return;
            }

            if (field.type === 'radio') {
                field.checked = record ? String(field.value) === String(record[baseName] ?? '') : field.defaultChecked;
                return;
            }

            if (idInput && field === idInput) {
                return;
            }

            if (record && Object.prototype.hasOwnProperty.call(record, baseName)) {
                field.value = record[baseName] ?? '';
            } else if (!record) {
                if (field.tagName === 'SELECT') {
                    const defaultOption = field.querySelector('[selected]');
                    field.value = defaultOption ? defaultOption.value : (field.options[0] ? field.options[0].value : '');
                } else if (field.type !== 'file') {
                    field.value = field.defaultValue || '';
                }
            }
        });

        if (idInput) {
            idInput.value = record ? (record[idField] || record.id || '') : '';
        }

        const passwordInput = form.querySelector('[name="password"]');
        if (passwordInput) {
            passwordInput.value = '';
            passwordInput.required = !record;
        }

        const selfNotice = form.querySelector('[data-admin-form-self-notice]');
        const activeCheckbox = form.querySelector('[name="is_active"][type="checkbox"]');
        const activeHidden = form.querySelector('input[type="hidden"][name="is_active"]');
        if (activeCheckbox) {
            const isSelf = !!(record && record.is_self);
            activeCheckbox.disabled = isSelf;
            if (selfNotice) selfNotice.hidden = !isSelf;
            if (activeHidden) activeHidden.disabled = !isSelf;
        }

        form.dispatchEvent(new CustomEvent('adminformpopulated', { detail: { mode: record ? 'update' : 'create', record: record || null } }));
    }

    function openModal(mode, record) {
        const isUpdate = mode === 'update' && record;
        if (titleEl) titleEl.textContent = isUpdate ? updateTitle : createTitle;
        if (submitLabel) submitLabel.textContent = isUpdate ? updateSubmitLabel : createSubmitLabel;
        if (submitIcon) submitIcon.className = 'fas ' + (isUpdate ? updateSubmitIcon : createSubmitIcon);
        if (actionInput) actionInput.value = isUpdate ? updateAction : createAction;

        if (formType === 'requirements' && record) {
            populateForm({
                req_codes: record.req_codes || record.codes || [],
            });
            if (idInput) idInput.value = record.document_type_id || record.id || '';
            if (titleEl && record.name) {
                titleEl.textContent = 'Configure: ' + record.name;
            }
            if (submitLabel) submitLabel.textContent = 'Save Requirements';
        } else {
            populateForm(isUpdate ? record : null);
        }

        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
        document.body.style.overflow = 'hidden';

        const firstField = form.querySelector('input:not([type="hidden"]):not([type="checkbox"]), select, textarea');
        if (firstField) firstField.focus();
    }

    modal.querySelectorAll('[data-close-admin-form]').forEach(function (el) {
        el.addEventListener('click', closeModal);
    });

    document.querySelectorAll('[data-open-admin-form="create"]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            openModal('create', null);
        });
    });

    document.querySelectorAll('[data-admin-form-edit]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            try {
                openModal('update', JSON.parse(btn.getAttribute('data-admin-form-edit') || '{}'));
            } catch (err) {
                console.error(err);
            }
        });
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && modal.classList.contains('is-open')) {
            closeModal();
        }
    });

    if (window.__ADMIN_FORM_EDIT__) {
        openModal('update', window.__ADMIN_FORM_EDIT__);
    }
}

function initNotificationToasts() {
    const host = document.getElementById('notificationToastHost');
    if (!host) return;

    const pollUrl = host.dataset.pollUrl || '';
    const markUrl = host.dataset.markUrl || pollUrl;
    const notificationsUrl = host.dataset.notificationsUrl || '';
    const csrfToken = host.dataset.csrf || '';
    const suppressInitial = host.dataset.suppressInitial === '1';
    const storageKey = 'regdum_seen_notification_ids';
    let seen = {};
    let lastId = 0;

    try {
        seen = JSON.parse(sessionStorage.getItem(storageKey) || '{}') || {};
    } catch (err) {
        seen = {};
    }

    function persistSeen() {
        const ids = Object.keys(seen);
        if (ids.length > 100) {
            ids.sort(function (a, b) { return Number(a) - Number(b); });
            ids.slice(0, ids.length - 80).forEach(function (id) { delete seen[id]; });
        }
        sessionStorage.setItem(storageKey, JSON.stringify(seen));
    }

    function rememberId(id) {
        const key = String(id || '');
        if (!key) return;
        seen[key] = 1;
        lastId = Math.max(lastId, Number(key) || 0);
        persistSeen();
    }

    function updateBadge(count) {
        if (count === null || typeof count === 'undefined') return;
        document.querySelectorAll('[data-notif-count]').forEach(function (badge) {
            const value = Number(count) || 0;
            badge.textContent = String(value);
            badge.hidden = value <= 0;
        });
    }

    function iconForType(type) {
        if (type === 'success') return 'fa-check-circle';
        if (type === 'error') return 'fa-times-circle';
        if (type === 'warning') return 'fa-exclamation-triangle';
        return 'fa-bell';
    }

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function markRead(id) {
        if (!markUrl || !csrfToken || !id) return;
        fetch(markUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                action: 'mark_read',
                id: Number(id),
                csrf_token: csrfToken,
            }),
        }).then(function (response) {
            return response.ok ? response.json() : null;
        }).then(function (payload) {
            if (payload && payload.ok) {
                updateBadge(payload.count || 0);
            }
        }).catch(function () {
            // ignore mark-read failures
        });
    }

    function showToast(item, options) {
        options = options || {};
        const id = String(item.id || '');
        if (!id || seen[id]) return;

        rememberId(id);

        const toast = document.createElement('div');
        toast.className = 'notification-toast notification-toast-' + (item.type || 'info');
        toast.setAttribute('role', 'status');

        const viewHref = item.link
            ? (notificationsUrl
                ? (notificationsUrl + '?read=' + encodeURIComponent(id) + '&goto=' + encodeURIComponent(item.link))
                : item.link)
            : notificationsUrl;

        const linkHtml = viewHref
            ? '<a class="notification-toast-link" href="' + escapeHtml(viewHref) + '">View</a>'
            : '';

        toast.innerHTML =
            '<button type="button" class="notification-toast-close" aria-label="Close">&times;</button>' +
            '<div class="notification-toast-icon"><i class="fas ' + iconForType(item.type) + '"></i></div>' +
            '<div class="notification-toast-body">' +
                '<strong>' + escapeHtml(item.title || 'Notification') + '</strong>' +
                '<p>' + escapeHtml(item.message || '') + '</p>' +
                '<div class="notification-toast-actions">' +
                    linkHtml +
                    '<button type="button" class="notification-toast-dismiss">Dismiss</button>' +
                '</div>' +
            '</div>';

        host.appendChild(toast);
        requestAnimationFrame(function () {
            toast.classList.add('is-visible');
        });

        const dismiss = function (shouldMarkRead) {
            toast.classList.remove('is-visible');
            setTimeout(function () { toast.remove(); }, 220);
            if (shouldMarkRead) {
                markRead(id);
            }
        };

        const dismissBtn = toast.querySelector('.notification-toast-dismiss');
        const closeBtn = toast.querySelector('.notification-toast-close');
        if (dismissBtn) {
            dismissBtn.addEventListener('click', function () { dismiss(true); });
        }
        if (closeBtn) {
            closeBtn.addEventListener('click', function () { dismiss(true); });
        }

        const viewLink = toast.querySelector('.notification-toast-link');
        if (viewLink) {
            viewLink.addEventListener('click', function () {
                rememberId(id);
            });
        }

        setTimeout(function () { dismiss(false); }, options.duration || 9000);
    }

    function readBootstrap() {
        const node = document.getElementById('notificationToastBootstrap');
        if (!node) return [];
        try {
            const parsed = JSON.parse(node.textContent || '[]');
            return Array.isArray(parsed) ? parsed : [];
        } catch (err) {
            return [];
        }
    }

    const initial = readBootstrap();
    initial.forEach(function (item) {
        lastId = Math.max(lastId, Number(item.id) || 0);
    });

    if (!suppressInitial) {
        initial.slice().reverse().forEach(function (item) {
            showToast(item);
        });
    } else {
        initial.forEach(function (item) {
            rememberId(item.id);
        });
    }

    if (!pollUrl) return;

    Object.keys(seen).forEach(function (id) {
        lastId = Math.max(lastId, Number(id) || 0);
    });

    async function poll() {
        try {
            const url = pollUrl
                + (pollUrl.indexOf('?') >= 0 ? '&' : '?')
                + 'after_id=' + encodeURIComponent(String(lastId))
                + '&limit=5';
            const response = await fetch(url, {
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' },
            });
            if (!response.ok) return;
            const payload = await response.json();
            if (!payload || !payload.ok) return;
            updateBadge(payload.count || 0);
            (payload.items || []).slice().reverse().forEach(function (item) {
                lastId = Math.max(lastId, Number(item.id) || 0);
                showToast(item);
            });
        } catch (err) {
            // ignore transient poll errors
        }
    }

    setTimeout(poll, 1500);
    setInterval(poll, 12000);
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) poll();
    });
}
