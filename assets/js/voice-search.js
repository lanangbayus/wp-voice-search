/**
 * WP Voice Search – Unified (Shortcode + Gutenberg)
 * Versi: 1.6.0 (Wave Fix + Parent Fallback + Tooltip)
 *
 * ✅ Perbaikan UTAMA:
 * - Wave muncul dengan dua skema:
 *   (1) Sibling: .[vs|wpvs]-mic.is-active ~ .vs-meter / .wpvs-wave
 *   (2) Fallback Parent: parent (.vs-field / .wpvs-input-wrap) diberi .is-listening
 * - Menghindari double-bind pada container & tombol mic
 * - Tooltip status ringan (optional)
 *
 * Catatan:
 * - CSS harus sudah mendukung selector wave untuk .vs-meter & .wpvs-wave
 * - Pastikan izin mic & konteks aman (HTTPS / http://localhost)
 */
(function () {
  const DEBUG = false; // set true untuk log debug
  const SR = window.SpeechRecognition || window.webkitSpeechRecognition;

  /** ⚙️ Config dari wp_localize_script (opsional) */
  const CFG = Object.assign({
    enabled: true,
    lang: 'id-ID',         // default bahasa
    autostart: false,      // auto klik mic pertama saat load
    autosubmit: true,      // submit form otomatis setelah dapat hasil
    btn: '🎤',             // fallback label tombol mic jika butuh dibuat
    messages: {
      unsupported: 'Browser tidak mendukung voice search.',
      listening: '🎙️ Mendengarkan...',
      stopped: 'Selesai.',
      error: 'Terjadi error, coba lagi.',
      disabled: 'Voice search dimatikan.'
    }
  }, window.WPVS || {});

  const log = (...a) => { if (DEBUG) console.log('[WPVS]', ...a); };
  const err = (...a) => { if (DEBUG) console.error('[WPVS]', ...a); };

  // Jika dimatikan di settings → hanya tampilkan pesan saat klik
  if (!CFG.enabled) {
    document.addEventListener('click', (ev) => {
      const micBtn = ev.target.closest('.wpvs-mic, .vs-mic');
      if (!micBtn) return;
      const form = micBtn.closest('.wpvs-search-form, .vs-wrap, .wp-block-search') || micBtn.closest('form');
      const status = form && form.querySelector('.wpvs-status, .vs-status');
      if (status) status.textContent = CFG.messages.disabled || 'Voice search disabled.';
    });
    log('Voice search disabled by setting');
    return;
  }

  // Jika browser tidak support → beri pesan & berhenti
  if (!SR) {
    const msg = (CFG.messages && CFG.messages.unsupported) || 'Browser tidak mendukung voice search.';
    document.addEventListener('DOMContentLoaded', () => {
      document.querySelectorAll('.wpvs-status, .vs-status').forEach(s => s.textContent = msg);
    });
    log('No SpeechRecognition support');
    return;
  }

  // 🔎 Cari & pasang di semua container relevan
  function initAll() {
    const containers = document.querySelectorAll('.vs-wrap, .wpvs-search-form, .wp-block-search');
    containers.forEach(attachVoiceSearch);
  }

  // 🧠 Deteksi icon custom mic dari CSS variable dan tambahkan class penanda
  function applyCustomMicIcon(scope = document) {
    scope.querySelectorAll('.wpvs-mic, .vs-mic').forEach(btn => {
      const iconURL = getComputedStyle(btn).getPropertyValue('--wpvs-mic-icon').trim();
      if (iconURL && iconURL !== 'none' && !btn.classList.contains('has-custom-icon')) {
        btn.classList.add('has-custom-icon');
        log('Custom mic icon detected', iconURL);
      }
    });
  }

  // 💬 Tooltip status ringan (opsional)
  function ensureTooltip(micBtn, initialText) {
    if (micBtn.__tipEl && micBtn.__tipEl.isConnected) {
      if (initialText) micBtn.__tipEl.textContent = initialText;
      return micBtn.__tipEl;
    }
    const tip = document.createElement('span');
    tip.className = 'wpvs-tip';
    tip.setAttribute('role', 'status');      // a11y non-intrusive
    tip.setAttribute('aria-live', 'polite'); // update teks halus
    tip.textContent = initialText || 'Klik untuk bicara';
    micBtn.after(tip);
    micBtn.__tipEl = tip;
    return tip;
  }

  // 🔧 Pasang voice search pada satu container
  function attachVoiceSearch(container) {
    if (container.__wpvsBound) return; // cegah double-bind per container

    // Cari input
    const input =
      container.querySelector('.vs-input') ||
      container.querySelector('.wpvs-field') ||
      container.querySelector('input[type="search"], input[name="s"]');
    if (!input) return;

    // Cari/buat mic
    let micBtn =
      container.querySelector('.vs-mic') ||
      container.querySelector('.wpvs-mic');

    // Parent field untuk fallback state wave
    const parentField =
      container.querySelector('.vs-field') ||
      container.querySelector('.wpvs-input-wrap') ||
      container.querySelector('.wp-block-search__inside-wrapper') ||
      container;

    if (!micBtn) {
      micBtn = document.createElement('button');
      micBtn.type = 'button';
      micBtn.className = 'wpvs-mic';
      micBtn.setAttribute('aria-pressed', 'false');
      micBtn.setAttribute('aria-label', 'Mulai voice search');
      micBtn.textContent = (CFG.btn || '🎤');
      parentField.appendChild(micBtn);
    }

    // Cari/buat meter (gunakan .vs-meter agar CSS lama tetap berlaku)
    let meter = parentField.querySelector('.vs-meter');
    if (!meter) {
      meter = document.createElement('div');
      meter.className = 'vs-meter';
      meter.setAttribute('aria-hidden', 'true');
      meter.innerHTML = '<i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i>';
      micBtn.after(meter);
    }
    const meterBars = meter.querySelectorAll('i');

    // STATUS EL opsional
    const statusEl =
      container.querySelector('.wpvs-status') ||
      container.querySelector('.vs-status');
    const setStatus = (t) => { if (statusEl) statusEl.textContent = t || ''; };

    // STATE per-container untuk Web Audio meter
    let stream, audioCtx, source, analyser, raf;

    // 🎤 Buat instance SpeechRecognition
    const rec = new SR();
    rec.lang = CFG.lang || navigator.language || 'id-ID';
    rec.continuous = false;
    rec.interimResults = false;

    // Helper: aktif/nonaktif mic + fallback parent state
    function setMicActive(on) {
      micBtn.classList.toggle('is-active', on);           // trigger wave (sibling)
      micBtn.classList.toggle('wpvs-listening', on);      // animasi mic
      micBtn.setAttribute('aria-pressed', String(on));
      if (parentField) parentField.classList.toggle('is-listening', on); // Fallback parent
      // Tooltip (opsional)
      if (micBtn.__tipEl) {
        micBtn.__tipEl.textContent = on
          ? (CFG.messages?.listening || '🎙️ Mendengarkan...')
          : 'Klik untuk bicara';
      }
    }

    // 🎚️ Nyalakan meter pakai Web Audio API (visual RMS → scale bar)
    async function startMeter() {
      if (audioCtx) return;
      // Minta izin mic untuk waveform (terpisah dari SR supaya bar hidup sejak awal)
      stream = await navigator.mediaDevices.getUserMedia({ audio: true });
      audioCtx = new (window.AudioContext || window.webkitAudioContext)();
      source = audioCtx.createMediaStreamSource(stream);
      analyser = audioCtx.createAnalyser();
      analyser.fftSize = 256;
      source.connect(analyser);
      const data = new Uint8Array(analyser.frequencyBinCount);

      const draw = () => {
        analyser.getByteTimeDomainData(data);
        let sum = 0;
        for (let i = 0; i < data.length; i++) {
          const v = (data[i] - 128) / 128;
          sum += v * v;
        }
        const rms = Math.sqrt(sum / data.length);
        const lvl = Math.min(1, rms * 3); // skala 0–1
        meterBars.forEach((el, idx) => {
          const jitter = 0.06 * Math.sin(performance.now() / 120 + idx);
          el.style.setProperty('--lvl', Math.max(.2, lvl + jitter).toFixed(2));
        });
        raf = requestAnimationFrame(draw);
      };
      draw();
    }

    function stopMeter() {
      if (raf) cancelAnimationFrame(raf); raf = null;
      try { stream && stream.getTracks().forEach(t => t.stop()); } catch {}
      try { audioCtx && audioCtx.close(); } catch {}
      stream = audioCtx = source = analyser = null;
      // Reset tinggi bar agar tidak “beku” tinggi
      meterBars.forEach(el => el.style.setProperty('--lvl', '.4'));
    }

    // Tooltip (opsional)
    ensureTooltip(micBtn, 'Klik untuk bicara');

    // 👆 Klik mic: toggle start/stop
    if (!micBtn.__wpvsBound) {
      micBtn.__wpvsBound = true;
      micBtn.addEventListener('click', async () => {
        if (micBtn.classList.contains('is-active')) {
          // Stop jika sedang aktif
          try { rec.stop(); } catch {}
          stopMeter();
          setMicActive(false);
          setStatus('');
          return;
        }
        // Start
        setMicActive(true);
        setStatus(CFG.messages.listening);
        try { await startMeter(); } catch (e) { log('Meter error', e); }
        try { rec.start(); } catch (e) { /* chromium double-start guard */ }
      });
    }

    // 🎤 Recognition events
    rec.addEventListener('result', (e) => {
      try {
        const transcript = e.results[0][0].transcript || '';
        input.value = transcript;

        // Penting: trigger event supaya tema/builder menangkap perubahan
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));

        setStatus(`✅ ${transcript}`);

        // Tooltip feedback cepat
        if (micBtn.__tipEl) {
          micBtn.__tipEl.textContent = 'Teks ditangkap ✓';
          setTimeout(() => {
            if (!micBtn.classList.contains('is-active') && micBtn.__tipEl) {
              micBtn.__tipEl.textContent = 'Klik untuk bicara';
            }
          }, 1200);
        }

        if (CFG.autosubmit) {
          setTimeout(() => {
            const form = input.closest('form');
            if (form && typeof form.submit === 'function') form.submit();
          }, 250);
        } else {
          setTimeout(() => {
            if (statusEl && statusEl.textContent.startsWith('✅')) statusEl.textContent = '';
          }, 3000);
        }
      } catch (e2) {
        err('Gagal memproses hasil', e2);
        setStatus(CFG.messages.error);
      }
    });

    rec.addEventListener('error', (e) => {
      err('Speech error', e?.error || e);
      setMicActive(false);
      stopMeter();
      setStatus(CFG.messages.error + (e?.error ? ` (${e.error})` : ''));
      if (micBtn.__tipEl) micBtn.__tipEl.textContent = 'Gagal, coba lagi';
    });

    rec.addEventListener('audiostart', () => { setMicActive(true); });
    rec.addEventListener('soundstart', () => { setMicActive(true); });
    rec.addEventListener('speechstart', () => { setMicActive(true); });

    rec.addEventListener('audioend', () => { /* biarkan end yang matikan */ });
    rec.addEventListener('speechend', () => { /* biarkan end yang matikan */ });

    rec.addEventListener('end', () => {
      setMicActive(false);
      stopMeter();
      const listeningText = CFG.messages.listening || 'Mendengarkan';
      if (statusEl && (statusEl.textContent || '').includes(listeningText)) {
        setStatus(CFG.messages.stopped);
        setTimeout(() => {
          if (statusEl && statusEl.textContent === CFG.messages.stopped) statusEl.textContent = '';
        }, 2000);
      }
      if (micBtn.__tipEl) micBtn.__tipEl.textContent = 'Klik untuk bicara';
    });

    // Deteksi ikon custom pada tombol ini (CSS var)
    const iconURL = getComputedStyle(micBtn).getPropertyValue('--wpvs-mic-icon').trim();
    if (iconURL && iconURL !== 'none') micBtn.classList.add('has-custom-icon');

    // Tandai container sudah di-bind
    container.__wpvsBound = true;
    log('Bound container', container);
  }

  // 👀 Observe node baru (mis. block dimuat dinamis oleh builder)
  const mo = new MutationObserver((muts) => {
    let shouldInit = false;
    for (const m of muts) {
      if (!(m.addedNodes && m.addedNodes.length)) continue;
      m.addedNodes.forEach(n => {
        if (!(n instanceof Element)) return;
        if (n.matches?.('.vs-wrap, .wpvs-search-form, .wp-block-search') ||
            n.querySelector?.('.vs-wrap, .wpvs-search-form, .wp-block-search')) {
          shouldInit = true;
        }
      });
    }
    if (shouldInit) {
      initAll();
      applyCustomMicIcon(document);
    }
  });

  // 🚀 Start
  document.addEventListener('DOMContentLoaded', () => {
    initAll();
    applyCustomMicIcon(document);

    // Autostart (opsional)
    if (CFG.autostart) {
      const firstMic = document.querySelector('.wpvs-mic, .vs-mic');
      if (firstMic) firstMic.click();
    }

    try { mo.observe(document.documentElement, { childList: true, subtree: true }); } catch {}
    log('✅ voice-search.js loaded');
  });
})();
