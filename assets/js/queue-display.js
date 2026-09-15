(function () {
    const root = document.querySelector('.queue-display-page');
    if (!root) return;

    const statusUrl = root.getAttribute('data-queue-status-url');
    if (!statusUrl) return;

    const windowsEl = root.querySelector('[data-queue-windows]');
    const waitingEl = root.querySelector('[data-queue-waiting]');
    const waitingCountEl = root.querySelector('[data-queue-waiting-count]');
    const dateEl = root.querySelector('[data-queue-date]');
    const timeEl = root.querySelector('[data-queue-time]');
    let lastCalledId = null;
    let soundEnabled = true;

    function escapeHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function playChime() {
        if (!soundEnabled) return;
        try {
            const ctx = new (window.AudioContext || window.webkitAudioContext)();
            const osc = ctx.createOscillator();
            const gain = ctx.createGain();
            osc.type = 'sine';
            osc.frequency.setValueAtTime(880, ctx.currentTime);
            osc.frequency.exponentialRampToValueAtTime(1320, ctx.currentTime + 0.18);
            gain.gain.setValueAtTime(0.0001, ctx.currentTime);
            gain.gain.exponentialRampToValueAtTime(0.18, ctx.currentTime + 0.03);
            gain.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + 0.45);
            osc.connect(gain);
            gain.connect(ctx.destination);
            osc.start();
            osc.stop(ctx.currentTime + 0.5);
        } catch (err) {
            /* ignore audio errors */
        }
    }

    function render(state) {
        if (!state) return;
        soundEnabled = !!state.sound_enabled;
        if (dateEl) dateEl.textContent = state.date_label || '';
        if (timeEl) timeEl.textContent = state.time_label || '';

        if (windowsEl && Array.isArray(state.windows)) {
            windowsEl.innerHTML = state.windows.map(function (windowItem) {
                const code = windowItem.ticket_code || '---';
                const idle = windowItem.ticket_code ? '' : ' is-idle';
                const staff = windowItem.staff || 'Window open';
                return '<article class="queue-display-window' + idle + '">'
                    + '<p class="queue-display-window-name">' + escapeHtml(windowItem.name) + '</p>'
                    + '<p class="queue-display-now-label">Now Serving</p>'
                    + '<p class="queue-display-number">' + escapeHtml(code) + '</p>'
                    + '<p class="queue-display-staff">' + escapeHtml(staff) + '</p>'
                    + '</article>';
            }).join('');
        }

        if (waitingCountEl) waitingCountEl.textContent = String(state.waiting_count || 0);
        if (waitingEl) {
            const waiting = Array.isArray(state.waiting) ? state.waiting : [];
            if (waiting.length === 0) {
                waitingEl.innerHTML = '<li class="is-empty">No one waiting</li>';
            } else {
                waitingEl.innerHTML = waiting.map(function (ticket) {
                    return '<li><strong>' + escapeHtml(ticket.ticket_code) + '</strong> '
                        + escapeHtml(ticket.service_label || '') + '</li>';
                }).join('');
            }
        }

        const calledId = state.last_called && state.last_called.id ? Number(state.last_called.id) : null;
        if (calledId && lastCalledId !== null && calledId !== lastCalledId) {
            playChime();
        }
        if (calledId) {
            lastCalledId = calledId;
        }
    }

    function poll() {
        fetch(statusUrl, { cache: 'no-store' })
            .then(function (res) { return res.json(); })
            .then(render)
            .catch(function () { /* keep last frame */ });
    }

    poll();
    setInterval(poll, 3000);
})();
