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
    const adsEl = root.querySelector('[data-queue-ads]');
    let lastCalledId = null;
    let soundEnabled = true;
    let adsSignature = '';
    let adsIndex = 0;
    let adsTimer = null;

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

        renderAds(state.ads || {});

        const calledId = state.last_called && state.last_called.id ? Number(state.last_called.id) : null;
        if (calledId && lastCalledId !== null && calledId !== lastCalledId) {
            playChime();
        }
        if (calledId) {
            lastCalledId = calledId;
        }
    }

    function showAd(index) {
        if (!adsEl) return;
        const slides = adsEl.querySelectorAll('.queue-display-ad');
        if (!slides.length) return;
        adsIndex = ((index % slides.length) + slides.length) % slides.length;
        slides.forEach(function (slide, slideIndex) {
            slide.classList.toggle('is-active', slideIndex === adsIndex);
        });
        const indexEl = adsEl.querySelector('[data-queue-ads-index]');
        if (indexEl) indexEl.textContent = String(adsIndex + 1);
    }

    function renderAds(ads) {
        if (!adsEl) return;
        const images = Array.isArray(ads.images) ? ads.images : [];
        const enabled = !!ads.enabled && images.length > 0;
        const interval = Math.max(3, Number(ads.interval_seconds) || 8);
        const signature = images.map(function (image) { return image.url || ''; }).join('|') + '|' + interval + '|' + (enabled ? '1' : '0');

        root.classList.toggle('has-queue-ads', enabled);
        adsEl.hidden = !enabled;
        if (!enabled) {
            if (adsTimer) {
                clearInterval(adsTimer);
                adsTimer = null;
            }
            adsSignature = signature;
            return;
        }

        if (signature !== adsSignature) {
            adsSignature = signature;
            adsIndex = 0;
            const count = adsEl.querySelector('[data-queue-ads-count]');
            adsEl.querySelectorAll('.queue-display-ad').forEach(function (slide) { slide.remove(); });
            images.forEach(function (image, index) {
                const slide = document.createElement('div');
                slide.className = 'queue-display-ad' + (index === 0 ? ' is-active' : '');
                const img = document.createElement('img');
                img.src = image.url || '';
                img.alt = image.name || 'Advertisement';
                slide.appendChild(img);
                adsEl.insertBefore(slide, count);
            });
            const totalEl = adsEl.querySelector('[data-queue-ads-total]');
            if (totalEl) totalEl.textContent = String(images.length);
            if (count) count.hidden = images.length < 2;
            showAd(0);
            if (adsTimer) {
                clearInterval(adsTimer);
                adsTimer = null;
            }
            if (images.length > 1) {
                adsTimer = setInterval(function () {
                    showAd(adsIndex + 1);
                }, interval * 1000);
            }
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
