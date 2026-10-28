(function () {
    const root = document.querySelector('.queue-display-page') || document.querySelector('[data-queue-monitor]');
    if (!root) return;

    const statusUrl = root.getAttribute('data-queue-status-url');
    if (!statusUrl) return;

    const isMonitor = root.hasAttribute('data-queue-monitor');
    const windowsEl = root.querySelector('[data-queue-windows]');
    const waitingEl = root.querySelector('[data-queue-waiting]');
    const waitingCountEl = root.querySelector('[data-queue-waiting-count]');
    const dateEl = root.querySelector('[data-queue-date]');
    const timeEl = root.querySelector('[data-queue-time]');
    const adsEl = root.querySelector('[data-queue-ads]');
    let soundEnabled = true;
<<<<<<< HEAD
    let chimeId = 'bell';
    let chimeUrl = '';
    let chimeVolume = 1;
    let chimeAudio = null;
    let voicePref = { uri: '', name: '', lang: '' };
    let primed = false;
    let announced = {};
=======
    let speechVoiceUri = '';
>>>>>>> 0913fedd2764b6fd8ed8cf1afaa93b9b1c5da732
    let adsSignature = '';
    let adsIndex = 0;
    let adsTimer = null;
    let audioCtx = null;
    const tickerBoot = root.querySelector('[data-queue-ticker-text]');
    let tickerText = tickerBoot ? String(tickerBoot.textContent || '').replace(/\s+/g, ' ').trim() : '';

    function escapeHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

<<<<<<< HEAD
    function requestorLabel(name, properCase) {
        const trimmed = String(name || '').trim();
        if (trimmed === '') return 'Walk-in';
        if (!properCase) return trimmed;
        return trimmed.toLocaleLowerCase().replace(/(^|[\s'-])([^\s'-])/g, function (match, separator, letter) {
            return separator + letter.toLocaleUpperCase();
        });
    }

    function speakableCode(code) {
        const words = {
            '0': 'zero', '1': 'one', '2': 'two', '3': 'three', '4': 'four',
            '5': 'five', '6': 'six', '7': 'seven', '8': 'eight', '9': 'nine'
        };
        return String(code || '').split('').map(function (ch) {
            if (words[ch]) return words[ch];
            if (/[A-Za-z]/.test(ch)) return ch.toUpperCase();
            return '';
        }).filter(Boolean).join(' ');
    }

    function queueAnnouncements(calls) {
        calls.forEach(function (call, index) {
            announced[String(call.id)] = call.called_at;
            window.setTimeout(function () { announce(call); }, index * 7000);
        });
    }

    function preferredVoice() {
        if (!window.speechSynthesis) return null;
        const voices = window.speechSynthesis.getVoices();
        const uri = voicePref.uri || '';
        const name = voicePref.name || '';
        const lang = (voicePref.lang || '').toLowerCase();
        return (uri && voices.find(function (voice) { return voice.voiceURI === uri; }))
            || (name && voices.find(function (voice) { return voice.name === name; }))
            || (lang && voices.find(function (voice) { return String(voice.lang || '').toLowerCase() === lang; }))
            || voices.find(function (voice) { return /en-US/i.test(voice.lang); })
            || voices.find(function (voice) { return /^en/i.test(voice.lang); })
            || null;
    }

    function playChime(done) {
        if (!soundEnabled || chimeId === 'none') {
            done(0);
            return;
=======
    function announceCall(called) {
        if (!soundEnabled || !called) return;
        if (window.QueueSpeech && typeof window.QueueSpeech.announce === 'function') {
            window.QueueSpeech.announce(called, {
                enabled: true,
                voiceUri: speechVoiceUri
            });
>>>>>>> 0913fedd2764b6fd8ed8cf1afaa93b9b1c5da732
        }
        if (chimeUrl) {
            try {
                if (chimeAudio) {
                    chimeAudio.pause();
                    chimeAudio = null;
                }
                const audio = new Audio(chimeUrl);
                chimeAudio = audio;
                audio.volume = Math.max(0, Math.min(1, chimeVolume));
                let reported = false;
                const report = function (seconds) {
                    if (reported) return;
                    reported = true;
                    window.clearTimeout(fallback);
                    done(seconds);
                };
                const fallback = window.setTimeout(function () { report(2); }, 4000);
                audio.addEventListener('loadedmetadata', function () {
                    report(isFinite(audio.duration) ? audio.duration : 0);
                }, { once: true });
                audio.addEventListener('error', function () { report(0); }, { once: true });
                const played = audio.play();
                if (played && played.catch) {
                    played.catch(function () { report(0); });
                }
            } catch (err) {
                done(0);
            }
            return;
        }
        if (!window.QueueChimes) {
            done(0);
            return;
        }
        try {
            const Ctx = window.AudioContext || window.webkitAudioContext;
            if (!Ctx) {
                done(0);
                return;
            }
            if (!audioCtx) audioCtx = new Ctx();
            if (audioCtx.state === 'suspended') audioCtx.resume();
            done(window.QueueChimes.play(audioCtx, chimeId, chimeVolume));
        } catch (err) {
            done(0);
        }
    }

    function speak(text) {
        if (!soundEnabled || !window.speechSynthesis || !text) return;
        const utter = new SpeechSynthesisUtterance(text);
        utter.rate = 0.92;
        const voice = preferredVoice();
        if (voice) {
            utter.voice = voice;
            utter.lang = voice.lang || voicePref.lang || 'en-US';
        } else {
            utter.lang = voicePref.lang || 'en-US';
        }
        window.speechSynthesis.cancel();
        window.speechSynthesis.resume();
        window.speechSynthesis.speak(utter);
    }

    function announcementText(call) {
        const code = speakableCode(call.ticket_code);
        const name = String(call.requestor_name || '').trim();
        const windowName = String(call.window || '').trim() || 'the window';
        let text = 'Now serving number ' + code + '.';
        if (name) text += ' ' + name + '.';
        text += ' Please proceed to ' + windowName + '.';
        return text;
    }

    function announce(call) {
        if (!soundEnabled || !call) return;
        const text = announcementText(call);
        playChime(function (chimeSeconds) {
            window.setTimeout(function () { speak(text); }, Math.round(chimeSeconds * 1000) + 180);
        });
    }

    function maybeAnnounce(state) {
        const windows = Array.isArray(state.windows) ? state.windows : [];
        const fresh = [];
        windows.forEach(function (windowItem) {
            if (!windowItem.ticket_id || !windowItem.ticket_code) return;
            const calledAt = String(windowItem.called_at || '');
            const key = String(windowItem.ticket_id);
            if (!calledAt) return;
            if (!primed) {
                announced[key] = calledAt;
                return;
            }
            if (announced[key] !== calledAt) {
                fresh.push({
                    id: windowItem.ticket_id,
                    ticket_code: windowItem.ticket_code,
                    requestor_name: windowItem.requestor_name,
                    window: windowItem.name,
                    called_at: calledAt
                });
            }
        });
        primed = true;
        if (!fresh.length) return;
        fresh.sort(function (a, b) {
            return a.called_at < b.called_at ? -1 : (a.called_at > b.called_at ? 1 : 0);
        });
        if (!soundEnabled) {
            fresh.forEach(function (call) { announced[String(call.id)] = call.called_at; });
            return;
        }
        queueAnnouncements(fresh);
    }

    function render(state) {
        if (!state) return;
        soundEnabled = !!state.sound_enabled;
<<<<<<< HEAD
        chimeId = state.chime || 'bell';
        chimeUrl = state.chime_url || '';
        const volume = Number(state.chime_volume);
        chimeVolume = Number.isFinite(volume) ? Math.max(0, Math.min(100, volume)) / 100 : 1;
        voicePref = state.voice || voicePref;
=======
        speechVoiceUri = state.speech_voice ? String(state.speech_voice) : '';
>>>>>>> 0913fedd2764b6fd8ed8cf1afaa93b9b1c5da732
        if (dateEl) dateEl.textContent = state.date_label || '';
        if (timeEl) timeEl.textContent = state.time_label || '';

        if (windowsEl && Array.isArray(state.windows)) {
            windowsEl.innerHTML = state.windows.map(function (windowItem) {
                return isMonitor ? renderMonitorWindow(windowItem) : renderDisplayWindow(windowItem);
            }).join('');
        }

        if (waitingCountEl) waitingCountEl.textContent = String(state.waiting_count || 0);
        if (waitingEl) {
            const waiting = Array.isArray(state.waiting) ? state.waiting : [];
            if (waiting.length === 0) {
                waitingEl.innerHTML = '<li class="is-empty">' + (isMonitor ? 'No one is waiting.' : 'No one waiting') + '</li>';
            } else {
                waitingEl.innerHTML = waiting.map(function (ticket, index) {
                    if (isMonitor) {
                        return '<li><strong>' + escapeHtml(ticket.ticket_code) + '</strong> '
                            + escapeHtml(requestorLabel(ticket.requestor_name, false)) + '</li>';
                    }
                    return '<li' + (index === 0 ? ' class="is-next"' : '') + '>'
                        + '<span class="queue-display-waiting-number">' + escapeHtml(ticket.ticket_code) + '</span>'
                        + '<span class="queue-display-waiting-kicker">Client</span>'
                        + '<span class="queue-display-waiting-name">' + escapeHtml(requestorLabel(ticket.requestor_name, true)) + '</span>'
                        + '</li>';
                }).join('');
            }
        }

        const announcementLive = root.querySelector('[data-queue-announcement-live]');
        if (announcementLive) {
            const announcement = String(state.announcement || '').trim();
            announcementLive.textContent = announcement || 'No announcement';
        }
        if (!isMonitor) {
            renderAds(state.ads || {});
            renderTicker(state.announcement);
        }
        maybeAnnounce(state);
    }

<<<<<<< HEAD
    function renderTicker(text) {
        const ticker = root.querySelector('[data-queue-ticker]');
        if (!ticker) return;
        const next = String(text || '').replace(/\s+/g, ' ').trim();
        if (next === tickerText) return;
        tickerText = next;
        ticker.hidden = next === '';
        ticker.querySelectorAll('[data-queue-ticker-text]').forEach(function (span) {
            span.textContent = next;
        });
        const track = ticker.querySelector('[data-queue-ticker-track]');
        if (track) {
            const seconds = Math.min(80, Math.max(18, Math.round(Math.max(1, next.length) * 0.28)));
            track.style.setProperty('--queue-ticker-duration', seconds + 's');
=======
        const calledId = state.last_called && state.last_called.id ? Number(state.last_called.id) : null;
        if (calledId && lastCalledId !== null && calledId !== lastCalledId && state.last_called) {
            announceCall(state.last_called);
>>>>>>> 0913fedd2764b6fd8ed8cf1afaa93b9b1c5da732
        }
    }

    function renderDisplayWindow(windowItem) {
        const code = windowItem.ticket_code || '---';
        const idle = windowItem.ticket_code ? '' : ' is-idle';
        const staff = windowItem.staff || 'Window open';
        let html = '<article class="queue-display-window' + idle + '">'
            + '<p class="queue-display-window-name">' + escapeHtml(windowItem.name) + '</p>'
            + '<p class="queue-display-now-label">Now Serving</p>'
            + '<p class="queue-display-number">' + escapeHtml(code) + '</p>';
        if (windowItem.ticket_code) {
            html += '<p class="queue-display-field-label">Client</p>'
                + '<p class="queue-display-requestor">' + escapeHtml(requestorLabel(windowItem.requestor_name, true)) + '</p>';
        }
        html += '<p class="queue-display-field-label">Window teller</p>'
            + '<p class="queue-display-staff">' + escapeHtml(staff) + '</p></article>';
        return html;
    }

    function renderMonitorWindow(windowItem) {
        const code = windowItem.ticket_code || '---';
        const idle = windowItem.ticket_code ? '' : ' is-idle';
        const staff = windowItem.staff || 'Unassigned';
        const isAdmin = root.getAttribute('data-queue-admin') === '1';
        const windowUrl = root.getAttribute('data-queue-window-url') || '';
        let html = '<article class="queue-monitor-window' + idle + '">'
            + '<h3>' + escapeHtml(windowItem.name) + '</h3>'
            + '<p class="queue-monitor-now-label">Now serving</p>'
            + '<p class="queue-monitor-number">' + escapeHtml(code) + '</p>';
        if (windowItem.ticket_code) {
            html += '<p class="queue-monitor-requestor">' + escapeHtml(requestorLabel(windowItem.requestor_name)) + '</p>';
        }
        html += '<p class="text-muted">' + escapeHtml(staff) + '</p>';
        if (isAdmin && windowUrl) {
            html += '<a href="' + escapeHtml(windowUrl + '?window_id=' + windowItem.id) + '" class="btn btn-sm btn-outline">Open window</a>';
        }
        html += '</article>';
        return html;
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

<<<<<<< Updated upstream
    function applyAdPresentation(ads) {
        const layouts = ['side', 'side-left', 'top', 'bottom'];
        const splits = ['equal', 'ads', 'queue'];
        const fits = ['cover', 'contain', 'fill'];
        const layout = layouts.indexOf(ads.layout) >= 0 ? ads.layout : 'side';
        const split = splits.indexOf(ads.split) >= 0 ? ads.split : 'equal';
        const fit = fits.indexOf(ads.fit) >= 0 ? ads.fit : 'cover';
        layouts.forEach(function (name) { root.classList.remove('queue-ads-layout-' + name); });
        splits.forEach(function (name) { root.classList.remove('queue-ads-split-' + name); });
        fits.forEach(function (name) { root.classList.remove('queue-ads-fit-' + name); });
<<<<<<< HEAD
        root.classList.add('queue-ads-layout-' + layout, 'queue-ads-split-' + split, 'queue-ads-fit-' + fit);
=======
        root.classList.add('queue-ads-layout-' + layout, 'queue-ads-fit-' + fit);
=======
    function startAdsTimer(intervalSeconds, slideCount) {
        if (adsTimer) {
            clearInterval(adsTimer);
            adsTimer = null;
        }
        if (slideCount < 2) {
            return;
        }
        const interval = Math.max(3, Number(intervalSeconds) || 8);
        adsTimer = setInterval(function () {
            showAd(adsIndex + 1);
        }, interval * 1000);
    }

    function bootstrapAdsFromDom() {
        if (!adsEl || adsEl.hidden) {
            return;
        }
        const slides = adsEl.querySelectorAll('.queue-display-ad');
        if (!slides.length) {
            return;
        }
        const interval = Math.max(3, Number(adsEl.getAttribute('data-queue-ads-interval')) || 8);
        const urls = Array.from(slides).map(function (slide) {
            const img = slide.querySelector('img');
            return img ? (img.getAttribute('src') || img.src || '') : '';
        });
        adsSignature = urls.join('|') + '|' + interval + '|1';
        root.classList.add('has-queue-ads');
        showAd(adsIndex);
        startAdsTimer(interval, slides.length);
>>>>>>> Stashed changes
>>>>>>> 0913fedd2764b6fd8ed8cf1afaa93b9b1c5da732
    }

    function renderAds(ads) {
        if (!adsEl) return;
        applyAdPresentation(ads);
        const images = Array.isArray(ads.images) ? ads.images : [];
        const enabled = !!ads.enabled && images.length > 0;
        const interval = Math.max(3, Number(ads.interval_seconds) || 8);
        const signature = images.map(function (image) { return image.url || ''; }).join('|') + '|' + interval + '|' + (enabled ? '1' : '0');

        root.classList.toggle('has-queue-ads', enabled);
        adsEl.hidden = !enabled;
        adsEl.setAttribute('data-queue-ads-interval', String(interval));
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
            startAdsTimer(interval, images.length);
        } else if (!adsTimer && images.length > 1) {
            startAdsTimer(interval, images.length);
        }
    }

    function poll() {
        fetch(statusUrl, { cache: 'no-store' })
            .then(function (res) { return res.json(); })
            .then(render)
            .catch(function () { /* keep last frame */ });
    }

<<<<<<< HEAD
    root.addEventListener('pointerdown', function () {
        try {
            const Ctx = window.AudioContext || window.webkitAudioContext;
            if (Ctx) {
                if (!audioCtx) audioCtx = new Ctx();
                if (audioCtx.state === 'suspended') audioCtx.resume();
            }
            if (window.speechSynthesis) window.speechSynthesis.resume();
        } catch (err) {
            /* ignore audio errors */
        }
    });
    if (window.speechSynthesis) {
        window.speechSynthesis.getVoices();
    }

=======
    bootstrapAdsFromDom();
>>>>>>> 0913fedd2764b6fd8ed8cf1afaa93b9b1c5da732
    poll();
    setInterval(poll, 3000);
})();
