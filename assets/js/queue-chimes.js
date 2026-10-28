(function () {
    const patterns = {
        bell: [
            { at: 0, freq: 880, endFreq: 1320, duration: 0.45, type: 'sine', peak: 0.18 }
        ],
        ding: [
            { at: 0, freq: 988, duration: 0.16, type: 'sine', peak: 0.16 },
            { at: 0.18, freq: 1318, duration: 0.28, type: 'sine', peak: 0.16 }
        ],
        doorbell: [
            { at: 0, freq: 698, duration: 0.32, type: 'triangle', peak: 0.14 },
            { at: 0.34, freq: 523, duration: 0.42, type: 'triangle', peak: 0.14 }
        ],
        arpeggio: [
            { at: 0, freq: 523, duration: 0.2, type: 'sine', peak: 0.14 },
            { at: 0.14, freq: 659, duration: 0.2, type: 'sine', peak: 0.14 },
            { at: 0.28, freq: 784, duration: 0.32, type: 'sine', peak: 0.15 }
        ],
        soft: [
            { at: 0, freq: 494, endFreq: 740, duration: 0.7, type: 'sine', peak: 0.1 }
        ],
        bright: [
            { at: 0, freq: 1174, duration: 0.12, type: 'sine', peak: 0.14 },
            { at: 0.14, freq: 1568, duration: 0.22, type: 'sine', peak: 0.14 }
        ],
        alert: [
            { at: 0, freq: 880, duration: 0.12, type: 'square', peak: 0.07 },
            { at: 0.16, freq: 880, duration: 0.12, type: 'square', peak: 0.07 },
            { at: 0.32, freq: 880, duration: 0.18, type: 'square', peak: 0.07 }
        ],
        low: [
            { at: 0, freq: 262, endFreq: 392, duration: 0.55, type: 'triangle', peak: 0.2 }
        ],
        none: []
    };

    function tone(ctx, when, note, volume) {
        const duration = note.duration;
        const peak = (note.peak || 0.16) * volume;
        if (peak <= 0.0001) return;
        const osc = ctx.createOscillator();
        const gain = ctx.createGain();
        osc.type = note.type || 'sine';
        osc.frequency.setValueAtTime(note.freq, when);
        if (note.endFreq && note.endFreq !== note.freq) {
            osc.frequency.exponentialRampToValueAtTime(note.endFreq, when + duration * 0.55);
        }
        gain.gain.setValueAtTime(0.0001, when);
        gain.gain.exponentialRampToValueAtTime(peak, when + 0.02);
        gain.gain.exponentialRampToValueAtTime(0.0001, when + duration);
        osc.connect(gain);
        gain.connect(ctx.destination);
        osc.start(when);
        osc.stop(when + duration + 0.02);
    }

    window.QueueChimes = {
        play: function (ctx, id, volume) {
            if (!ctx) return 0;
            const level = typeof volume === 'number' && isFinite(volume)
                ? Math.max(0, Math.min(1, volume))
                : 1;
            if (level <= 0) return 0;
            const notes = patterns[id] || patterns.bell;
            if (!notes.length) return 0;
            const start = ctx.currentTime + 0.02;
            let end = start;
            notes.forEach(function (note) {
                const noteEnd = start + note.at + note.duration;
                if (noteEnd > end) end = noteEnd;
                tone(ctx, start + note.at, note, level);
            });
            return Math.max(0, end - ctx.currentTime);
        }
    };
})();
