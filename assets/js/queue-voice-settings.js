(function () {
    const root = document.getElementById('queue-voice-settings');
    const select = document.getElementById('queue_voice_uri');
    const nameInput = document.getElementById('queue_voice_name');
    const langInput = document.getElementById('queue_voice_lang');
    const preview = document.getElementById('queue-voice-enable');
    const chimeSelect = document.getElementById('queue_chime');
    const chimePreview = document.getElementById('queue-chime-preview');
    const volumeInput = document.getElementById('queue_chime_volume');
    const volumeLabel = document.getElementById('queue-chime-volume-label');
    const volumeDown = document.getElementById('queue-chime-volume-down');
    const volumeUp = document.getElementById('queue-chime-volume-up');
    let chimeCtx = null;
    const soundToggle = document.getElementById('queue_sound_enabled');
    const note = document.getElementById('queue-voice-note');
    if (!root || !select || !nameInput || !langInput) return;

    const savedUri = root.getAttribute('data-voice-uri') || '';
    const savedName = root.getAttribute('data-voice-name') || '';
    const savedLang = root.getAttribute('data-voice-lang') || '';

    function syncHidden() {
        const option = select.options[select.selectedIndex];
        if (!option || option.value === '') {
            nameInput.value = '';
            langInput.value = '';
            return;
        }
        nameInput.value = option.getAttribute('data-name') || '';
        langInput.value = option.getAttribute('data-lang') || '';
    }

    function addOption(value, label, name, lang, selected) {
        const option = document.createElement('option');
        option.value = value;
        option.textContent = label;
        if (name) option.setAttribute('data-name', name);
        if (lang) option.setAttribute('data-lang', lang);
        if (selected) option.selected = true;
        select.appendChild(option);
    }

    function fillVoices() {
        if (!window.speechSynthesis) return;
        const voices = window.speechSynthesis.getVoices().slice().sort(function (a, b) {
            const aEnglish = /^en/i.test(a.lang) ? 0 : 1;
            const bEnglish = /^en/i.test(b.lang) ? 0 : 1;
            if (aEnglish !== bEnglish) return aEnglish - bEnglish;
            return a.name.localeCompare(b.name);
        });
        const current = select.value || savedUri;
        select.innerHTML = '';
        addOption('', 'Default English voice', '', '', current === '');

        let matched = current === '';
        voices.forEach(function (voice) {
            const selected = !matched && current !== '' && voice.voiceURI === current;
            if (selected) matched = true;
            addOption(voice.voiceURI, voice.name + ' (' + voice.lang + ')', voice.name, voice.lang, selected);
        });
        if (!matched && savedName !== '') {
            for (let index = 0; index < select.options.length; index++) {
                if (select.options[index].getAttribute('data-name') === savedName) {
                    select.selectedIndex = index;
                    matched = true;
                    break;
                }
            }
        }

        if (!matched && (savedUri || savedName)) {
            const label = (savedName || 'Saved voice') + (savedLang ? ' (' + savedLang + ')' : '');
            addOption(savedUri, label + ' — saved', savedName, savedLang, true);
        }

        if (note) {
            note.textContent = voices.length
                ? 'Voices listed here are installed on this computer. Save the settings so the queue monitor uses the same voice when it is available there.'
                : 'This browser has not listed any voices yet. Use the default English voice, or open this page again in a moment.';
        }
        syncHidden();
    }

    select.addEventListener('change', syncHidden);
    const form = root.closest('form');
    if (form) form.addEventListener('submit', syncHidden);

    function chimeLevel() {
        const value = volumeInput ? parseInt(volumeInput.value, 10) : 100;
        if (!isFinite(value)) return 1;
        return Math.max(0, Math.min(100, value)) / 100;
    }

    function showChimeVolume() {
        if (!volumeInput || !volumeLabel) return;
        const value = Math.round(chimeLevel() * 100);
        volumeInput.value = String(value);
        volumeLabel.textContent = value + '%';
        if (volumeDown) volumeDown.disabled = value <= 0;
        if (volumeUp) volumeUp.disabled = value >= 100;
    }

    const chimeFile = document.getElementById('chime_audio');
    if (chimeFile) {
        chimeFile.addEventListener('change', function () {
            const file = chimeFile.files && chimeFile.files[0];
            chimeFile.setCustomValidity('');
            if (!file) return;
            if (file.size > 2 * 1024 * 1024) {
                chimeFile.setCustomValidity('A chime file must be 2 MB or smaller.');
                chimeFile.reportValidity();
                return;
            }
            const previewAudio = document.createElement('audio');
            previewAudio.preload = 'metadata';
            const objectUrl = URL.createObjectURL(file);
            previewAudio.src = objectUrl;
            previewAudio.addEventListener('loadedmetadata', function () {
                URL.revokeObjectURL(objectUrl);
                if (previewAudio.duration > 15.05) {
                    chimeFile.setCustomValidity('A chime must be 15 seconds or shorter.');
                    chimeFile.reportValidity();
                }
            });
        });
    }

    function playSelectedChime() {
        if (!chimeSelect) return;
        const option = chimeSelect.options[chimeSelect.selectedIndex];
        const url = option ? option.getAttribute('data-url') : '';
        if (url) {
            const audio = new Audio(url);
            audio.volume = chimeLevel();
            audio.play();
            return;
        }
        if (!window.QueueChimes) return;
        const Ctx = window.AudioContext || window.webkitAudioContext;
        if (!Ctx) return;
        if (!chimeCtx) chimeCtx = new Ctx();
        if (chimeCtx.state === 'suspended') chimeCtx.resume();
        window.QueueChimes.play(chimeCtx, chimeSelect.value || 'bell', chimeLevel());
    }

    function changeChimeVolume(step) {
        if (!volumeInput) return;
        const next = Math.max(0, Math.min(100, Math.round(chimeLevel() * 100) + step));
        volumeInput.value = String(next);
        showChimeVolume();
        playSelectedChime();
    }

    showChimeVolume();
    if (volumeDown) volumeDown.addEventListener('click', function () { changeChimeVolume(-10); });
    if (volumeUp) volumeUp.addEventListener('click', function () { changeChimeVolume(10); });
    if (chimePreview) chimePreview.addEventListener('click', playSelectedChime);

    if (preview) {
        preview.addEventListener('click', function () {
            if (soundToggle) soundToggle.checked = true;
            syncHidden();
            if (!window.speechSynthesis) {
                if (note) note.textContent = 'This browser cannot play voice announcements.';
                return;
            }
            const voices = window.speechSynthesis.getVoices();
            const selected = voices.find(function (voice) { return voice.voiceURI === select.value; });
            const utter = new SpeechSynthesisUtterance('Now serving number zero zero one. Please proceed to Window 1.');
            utter.rate = 0.92;
            if (selected) {
                utter.voice = selected;
                utter.lang = selected.lang || 'en-US';
            } else {
                utter.lang = savedLang || 'en-US';
            }
            window.speechSynthesis.cancel();
            window.speechSynthesis.resume();
            window.speechSynthesis.speak(utter);
        });
    }

    if (!window.speechSynthesis) {
        if (note) note.textContent = 'This browser cannot play voice announcements. The queue monitor will use its default English voice.';
        return;
    }

    fillVoices();
    window.speechSynthesis.addEventListener('voiceschanged', fillVoices);
})();
