(function (global) {
    'use strict';

    function getVoices() {
        if (!global.speechSynthesis) {
            return [];
        }
        return global.speechSynthesis.getVoices() || [];
    }

    function resolveVoice(voiceUri) {
        var voices = getVoices();
        if (!voices.length) {
            return null;
        }
        var uri = String(voiceUri || '').trim();
        if (uri !== '') {
            var exact = voices.find(function (v) { return v.voiceURI === uri; });
            if (exact) return exact;
            var byName = voices.find(function (v) { return v.name === uri; });
            if (byName) return byName;
        }
        return voices.find(function (v) { return (v.lang || '').toLowerCase().indexOf('en') === 0; })
            || voices[0]
            || null;
    }

    function buildAnnouncement(called) {
        if (!called) return '';
        var windowNum = Number(called.window_number);
        if (!windowNum || windowNum < 1) {
            var windowLabel = String(called.window || '');
            var match = windowLabel.match(/(\d+)/);
            windowNum = match ? Number(match[1]) : 0;
        }
        var atWindow = windowNum > 0 ? ' at window ' + windowNum : '';

        var name = String(called.requestor_name || '').trim();
        if (name !== '') {
            return 'Now serving ' + name + atWindow + '.';
        }

        var code = String(called.ticket_code || '').trim();
        if (code !== '') {
            return 'Now serving number ' + code + atWindow + '.';
        }

        return 'Now serving' + atWindow + '.';
    }

    function announce(called, options) {
        options = options || {};
        if (!options.enabled) return;
        if (!global.speechSynthesis || typeof global.SpeechSynthesisUtterance === 'undefined') {
            return;
        }

        var text = buildAnnouncement(called);
        if (!text) return;

        try {
            global.speechSynthesis.cancel();
            var utter = new global.SpeechSynthesisUtterance(text);
            utter.rate = 0.95;
            utter.pitch = 1;
            var voice = resolveVoice(options.voiceUri);
            if (voice) {
                utter.voice = voice;
                utter.lang = voice.lang || 'en-US';
            } else {
                utter.lang = 'en-US';
            }
            global.speechSynthesis.speak(utter);
        } catch (err) {
            /* ignore speech errors */
        }
    }

    function populateVoiceSelect(selectEl, selectedUri) {
        if (!selectEl) return;
        var selected = String(selectedUri || '').trim();
        var voices = getVoices().slice().sort(function (a, b) {
            var langA = (a.lang || '').toLowerCase();
            var langB = (b.lang || '').toLowerCase();
            if (langA !== langB) return langA.localeCompare(langB);
            return (a.name || '').localeCompare(b.name || '');
        });

        while (selectEl.options.length > 1) {
            selectEl.remove(1);
        }

        voices.forEach(function (voice) {
            var opt = document.createElement('option');
            opt.value = voice.voiceURI;
            var label = voice.name || voice.voiceURI;
            if (voice.lang) {
                label += ' (' + voice.lang + ')';
            }
            if (voice.default) {
                label += ' — default';
            }
            opt.textContent = label;
            if (selected !== '' && (voice.voiceURI === selected || voice.name === selected)) {
                opt.selected = true;
            }
            selectEl.appendChild(opt);
        });

        if (selected !== '' && selectEl.selectedIndex <= 0) {
            var fallback = document.createElement('option');
            fallback.value = selected;
            fallback.textContent = 'Saved voice (not on this device): ' + selected;
            fallback.selected = true;
            selectEl.appendChild(fallback);
        }
    }

    global.QueueSpeech = {
        getVoices: getVoices,
        resolveVoice: resolveVoice,
        buildAnnouncement: buildAnnouncement,
        announce: announce,
        populateVoiceSelect: populateVoiceSelect
    };
})(typeof window !== 'undefined' ? window : this);
