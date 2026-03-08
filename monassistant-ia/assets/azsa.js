(function () {
  function createEl(tag, attrs, html) {
    var el = document.createElement(tag);
    attrs = attrs || {};
    Object.keys(attrs).forEach(function (k) {
      if (k === 'class') el.className = attrs[k];
      else if (k === 'id') el.id = attrs[k];
      else el.setAttribute(k, attrs[k]);
    });
    if (typeof html === 'string') el.innerHTML = html;
    return el;
  }

  function init() {
    if (!window.AZSA_CONFIG) return;
    var cfg = window.AZSA_CONFIG;

    var root = createEl('div', { id: 'azsa-root' });
    var panel = createEl('section', { id: 'azsa-panel', 'aria-label': 'Assistant Chatbot Mon Assistant IA' });
    var toggle = createEl('button', { id: 'azsa-toggle', type: 'button', 'aria-label': 'Ouvrir l\'assistant' });
    var nudge = createEl('div', { id: 'azsa-nudge', role: 'status', 'aria-live': 'polite' },
      '<button type="button" aria-label="Fermer">×</button>Je peux vous aider pendant la visite de notre site et vous proposer un RDV téléphonique avec un de nos conseillers.'
    );

    if (cfg.logoUrl) {
      var img = createEl('img', { src: cfg.logoUrl, alt: 'Logo assistant' });
      toggle.appendChild(img);
    } else {
      toggle.textContent = 'AI';
    }

    var head = createEl('div', { class: 'azsa-head' },
      '<div><h4>' + (cfg.assistantName || 'Assistant') + '</h4><small>Assistant contextuel du site</small></div>'
    );
    var headActions = createEl('div', { class: 'azsa-head-actions' });
    var modeBtn = createEl('button', { id: 'azsa-mode', type: 'button' }, 'Mode: Écrit');
    var micBtn = createEl('button', { id: 'azsa-mic', type: 'button', class: 'azsa-hidden', 'aria-label': 'Activer micro' }, '🎙');
    var close = createEl('button', { id: 'azsa-close', type: 'button', 'aria-label': 'Fermer' }, '×');
    headActions.appendChild(modeBtn);
    headActions.appendChild(micBtn);
    headActions.appendChild(close);
    head.appendChild(headActions);

    var thread = createEl('div', { class: 'azsa-thread', id: 'azsa-thread' });
    var chips = createEl('div', { class: 'azsa-chips', id: 'azsa-chips' });
    var status = createEl('div', { class: 'azsa-status', id: 'azsa-status' }, 'Prêt');

    var form = createEl('form', { class: 'azsa-input', id: 'azsa-form' });
    var input = createEl('input', { id: 'azsa-input', type: 'text', placeholder: 'Posez votre question...' });
    var send = createEl('button', { type: 'submit' }, 'Envoyer');

    form.appendChild(input);
    form.appendChild(send);

    var bookingPane = createEl('aside', { class: 'azsa-booking', id: 'azsa-booking' });
    var bookingFrame = createEl('iframe', { id: 'azsa-booking-frame', title: 'Prise de RDV', loading: 'lazy', referrerpolicy: 'strict-origin-when-cross-origin' });
    bookingPane.appendChild(bookingFrame);
    var main = createEl('div', { class: 'azsa-main' });
    main.appendChild(head);
    main.appendChild(thread);
    main.appendChild(chips);
    main.appendChild(status);
    main.appendChild(form);
    var layout = createEl('div', { class: 'azsa-layout' });
    layout.appendChild(bookingPane);
    layout.appendChild(main);
    panel.appendChild(layout);

    root.appendChild(panel);
    root.appendChild(nudge);
    root.appendChild(toggle);
    document.body.appendChild(root);

    var opened = false;
    var busy = false;
    var voiceMode = false;
    var recognizer = null;
    var listening = false;
    var keepListening = false;
    var currentAudio = null;
    var selectedVoice = null;
    var nudgeTimer = null;
    var nudgeHideTimer = null;
    var nudgePausedUntil = 0;
    var bookingOpen = false;
    var bookingPollTimer = null;
    var bookingSessionId = '';
    var userQuestionCount = 0;
    var transcript = [];
    var lead = {
      askedAfterFirstReply: false,
      askedRdv: false,
      stage: 'none',
      wantsRecap: false,
      wantsRdv: false,
      rdvDetected: false,
      firstName: '',
      lastName: '',
      email: '',
      phone: '',
      intent: 'recap'
    };

    function setStatus(text, thinking) {
      status.textContent = text;
      status.classList.toggle('is-thinking', !!thinking);
    }

    function setMood() {}

    function push(role, text) {
      var row = createEl('div', { class: 'azsa-row ' + (role === 'user' ? 'azsa-row-user' : 'azsa-row-bot') });
      var bubble = createEl('div', { class: 'azsa-bubble' });
      bubble.textContent = text || '';
      row.appendChild(bubble);
      thread.appendChild(row);
      thread.scrollTop = thread.scrollHeight;
      transcript.push((role === 'user' ? 'Visiteur' : 'Assistant') + ': ' + (text || ''));
      if (transcript.length > 40) transcript = transcript.slice(transcript.length - 40);
    }

    function renderChips(items) {
      chips.innerHTML = '';
      (items || []).forEach(function (label) {
        var chip = createEl('button', { class: 'azsa-chip', type: 'button' });
        chip.textContent = label;
        chip.addEventListener('click', function () {
          input.value = label;
          form.dispatchEvent(new Event('submit', { cancelable: true }));
        });
        chips.appendChild(chip);
      });
    }

    function openPanel() {
      panel.classList.add('azsa-open');
      opened = true;
      setTimeout(function () { input.focus(); }, 40);
    }

    function closePanel() {
      panel.classList.remove('azsa-open');
      opened = false;
      closeBooking();
    }

    function getCalendlyUrl() {
      if ((cfg.calendarProvider || 'none') !== 'calendly') return '';
      return (cfg.calendlyUrl || '').trim();
    }

    function openBooking() {
      var url = getCalendlyUrl();
      if (!url) return false;
      bookingSessionId = 'azsa_' + Math.random().toString(36).slice(2, 8) + Date.now().toString(36);
      var sep = url.indexOf('?') === -1 ? '?' : '&';
      var trackedUrl = url + sep + 'utm_content=' + encodeURIComponent(bookingSessionId);
      bookingFrame.setAttribute('src', trackedUrl);
      panel.classList.add('azsa-with-booking');
      bookingOpen = true;
      startBookingPolling();
      return true;
    }

    function startBookingPolling() {
      stopBookingPolling();
      if (!cfg.calendlyPollUrl || !bookingSessionId) return;
      bookingPollTimer = setInterval(async function () {
        if (!bookingOpen || !bookingSessionId) return;
        try {
          var res = await fetch(cfg.calendlyPollUrl, {
            method: 'POST',
            headers: {
              'Content-Type': 'application/json',
              'X-WP-Nonce': cfg.nonce || ''
            },
            body: JSON.stringify({ session_id: bookingSessionId })
          });
          var data = await res.json();
          if (data && data.ok && data.found) {
            onCalendlyScheduled({ pre_resolved: data });
          }
        } catch (err) {}
      }, 8000);
    }

    function stopBookingPolling() {
      if (bookingPollTimer) {
        clearInterval(bookingPollTimer);
        bookingPollTimer = null;
      }
    }

    function closeBooking() {
      panel.classList.remove('azsa-with-booking');
      bookingOpen = false;
      stopBookingPolling();
    }

    function hideNudge() {
      nudge.classList.remove('azsa-show');
      if (nudgeHideTimer) clearTimeout(nudgeHideTimer);
      nudgeHideTimer = null;
    }

    function showNudge() {
      var now = Date.now();
      if (opened || busy || now < nudgePausedUntil) return;
      nudge.classList.add('azsa-show');
      if (nudgeHideTimer) clearTimeout(nudgeHideTimer);
      nudgeHideTimer = setTimeout(hideNudge, 6500);
    }

    function scheduleNudge() {
      if (nudgeTimer) clearInterval(nudgeTimer);
      nudgeTimer = setInterval(function () {
        showNudge();
      }, 45000);
      setTimeout(showNudge, 9000);
    }

    function setVoiceMode(on) {
      voiceMode = !!on;
      modeBtn.textContent = voiceMode ? 'Mode: Vocal' : 'Mode: Écrit';
      micBtn.classList.toggle('azsa-hidden', !voiceMode);
      if (!voiceMode) {
        keepListening = false;
        stopListening();
      }
      setMood('idle');
    }

    function stopCurrentAudio() {
      if (currentAudio) {
        try { currentAudio.pause(); } catch (e) {}
        currentAudio.src = '';
        currentAudio = null;
      }
      if (window.speechSynthesis) {
        try { window.speechSynthesis.cancel(); } catch (e) {}
      }
    }

    function pickFrenchMaleVoice() {
      var voices = window.speechSynthesis ? window.speechSynthesis.getVoices() : [];
      if (!voices || !voices.length) return null;
      var lang = (cfg.lang || 'fr').toLowerCase();
      var byLang = voices.filter(function (v) { return (v.lang || '').toLowerCase().indexOf(lang) === 0; });
      if (!byLang.length) return null;
      var male = byLang.find(function (v) {
        return /(male|homme|man|thomas|sebastien|paul|nicolas|alex|remy|rémy)/i.test(v.name || '');
      });
      return male || byLang[0];
    }

    function speakBrowser(text) {
      return new Promise(function (resolve) {
        if (!window.speechSynthesis) {
          resolve(false);
          return;
        }
        stopCurrentAudio();
        var u = new SpeechSynthesisUtterance(text);
        if (!selectedVoice) selectedVoice = pickFrenchMaleVoice();
        if (!selectedVoice) {
          resolve(false);
          return;
        }
        var map = { fr: 'fr-FR', en: 'en-US', es: 'es-ES', de: 'de-DE', it: 'it-IT', pt: 'pt-PT' };
        u.lang = map[(cfg.lang || 'fr')] || 'fr-FR';
        u.voice = selectedVoice;
        u.rate = 1.0;
        u.pitch = 0.92;
        u.volume = 1.0;
        setMood('speaking');
        u.onend = function () { setMood('idle'); resolve(true); };
        u.onerror = function () { setMood('error'); resolve(false); };
        window.speechSynthesis.speak(u);
      });
    }

    async function fetchTTS(text) {
      if (!cfg.ttsUrl) return { mode: 'browser_tts' };
      try {
        var res = await fetch(cfg.ttsUrl, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-WP-Nonce': cfg.nonce || ''
          },
          body: JSON.stringify({ text: text })
        });
        return await res.json();
      } catch (e) {
        return { mode: 'browser_tts' };
      }
    }

    function playBase64Audio(mime, b64) {
      return new Promise(function (resolve) {
        stopCurrentAudio();
        try {
          var audio = new Audio('data:' + (mime || 'audio/mpeg') + ';base64,' + b64);
          audio.preload = 'auto';
          audio.playsInline = true;
          currentAudio = audio;
          setMood('speaking');
          audio.onended = function () { setMood('idle'); resolve(true); };
          audio.onerror = function () { setMood('error'); resolve(false); };
          var p = audio.play();
          if (p && p.catch) p.catch(function () { resolve(false); });
        } catch (e) {
          resolve(false);
        }
      });
    }

    async function speak(text) {
      var tts = await fetchTTS(text);
      if (tts && tts.mode === 'ai_audio' && tts.audio_b64) {
        var okAudio = await playBase64Audio(tts.mime || 'audio/mpeg', tts.audio_b64);
        if (okAudio) return true;
      }
      return await speakBrowser(text);
    }

    function setupRecognition() {
      var SR = window.SpeechRecognition || window.webkitSpeechRecognition;
      if (!SR) return null;
      var rec = new SR();
      rec.lang = (cfg.lang || 'fr') === 'fr' ? 'fr-FR' : ((cfg.lang || 'en') + '-' + (cfg.lang || 'en').toUpperCase());
      rec.interimResults = false;
      rec.continuous = false;
      rec.maxAlternatives = 1;

      rec.onstart = function () {
        listening = true;
        micBtn.textContent = '⏹';
        setMood('listening');
        setStatus('Écoute en cours', true);
      };

      rec.onresult = function (event) {
        var heard = (((event || {}).results || [])[0] || [])[0];
        var text = heard && heard.transcript ? String(heard.transcript).trim() : '';
        if (!text) return;
        setMood('thinking');
        input.value = text;
        form.dispatchEvent(new Event('submit', { cancelable: true }));
      };

      rec.onerror = function () {
        listening = false;
        setMood('error');
        if (!keepListening) {
          micBtn.textContent = '🎙';
        }
      };

      rec.onend = function () {
        listening = false;
        if (keepListening && voiceMode && !busy) {
          setTimeout(startListening, 300);
        } else {
          micBtn.textContent = '🎙';
          setStatus('Prêt', false);
          setMood('idle');
        }
      };

      return rec;
    }

    function startListening() {
      if (!recognizer || listening || !voiceMode) return;
      try { recognizer.start(); } catch (e) {}
    }

    function stopListening() {
      if (!recognizer || !listening) return;
      try { recognizer.stop(); } catch (e) {}
    }

    toggle.addEventListener('click', function () {
      if (opened) closePanel();
      else openPanel();
      hideNudge();
      nudgePausedUntil = Date.now() + 30000;
    });
    close.addEventListener('click', closePanel);
    nudge.querySelector('button').addEventListener('click', function () {
      hideNudge();
      nudgePausedUntil = Date.now() + 90000;
    });
    nudge.addEventListener('click', function () {
      openPanel();
      hideNudge();
      nudgePausedUntil = Date.now() + 90000;
    });
    window.addEventListener('message', function (e) {
      if (!e || typeof e.data === 'undefined' || e.data === null) return;
      var data = e.data;
      if (typeof data === 'string') {
        try { data = JSON.parse(data); } catch (err) { return; }
      }
      if (!data || typeof data !== 'object') return;
      var evt = data.event || data.event_name || ((data.data && data.data.event) ? data.data.event : '');
      evt = (evt || '').toString().toLowerCase();
      if (evt === 'calendly.event_scheduled' || evt.indexOf('event_scheduled') !== -1 || evt.indexOf('invitee.created') !== -1) {
        var payload = data.payload || (data.data && data.data.payload) || {};
        onCalendlyScheduled(payload);
      }
    });

    modeBtn.addEventListener('click', function () {
      setVoiceMode(!voiceMode);
      if (voiceMode) {
        setStatus('Mode vocal actif', false);
        keepListening = false;
      } else {
        setStatus('Mode écrit actif', false);
      }
    });

    micBtn.addEventListener('click', function () {
      if (!voiceMode || !recognizer) return;
      if (keepListening) {
        keepListening = false;
        micBtn.textContent = '🎙';
        stopListening();
        setStatus('Micro arrêté', false);
        setMood('idle');
        return;
      }
      keepListening = true;
      stopCurrentAudio();
      setMood('listening');
      startListening();
    });

    async function ask(message) {
      var res = await fetch(cfg.restUrl, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': cfg.nonce || ''
        },
        body: JSON.stringify({ message: message })
      });
      var data = await res.json();
      return data || {};
    }

    async function saveLead() {
      if (!cfg.leadUrl) return { ok: false };
      try {
        var res = await fetch(cfg.leadUrl, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-WP-Nonce': cfg.nonce || ''
          },
          body: JSON.stringify({
            first_name: lead.firstName,
            last_name: lead.lastName,
            email: lead.email,
            phone: lead.phone,
            intent: lead.intent,
            wants_rdv: !!lead.wantsRdv,
            page_url: window.location.href,
            transcript: transcript.join('\n')
          })
        });
        return await res.json();
      } catch (e) {
        return { ok: false };
      }
    }

    async function resolveCalendly(payload) {
      if (!cfg.calendlyResolveUrl) return { ok: false };
      var inviteeUri = '';
      var eventUri = '';
      if (payload) {
        if (payload.invitee && payload.invitee.uri) inviteeUri = payload.invitee.uri;
        if (payload.event && payload.event.uri) eventUri = payload.event.uri;
        if (!inviteeUri && payload.invitee_uri) inviteeUri = payload.invitee_uri;
        if (!eventUri && payload.event_uri) eventUri = payload.event_uri;
      }
      try {
        var res = await fetch(cfg.calendlyResolveUrl, {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-WP-Nonce': cfg.nonce || ''
          },
          body: JSON.stringify({
            invitee_uri: inviteeUri,
            event_uri: eventUri
          })
        });
        return await res.json();
      } catch (e) {
        return { ok: false };
      }
    }

    function isEmail(v) {
      return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test((v || '').trim());
    }

    function looksLikePhone(v) {
      var txt = (v || '').toString().trim();
      var digits = txt.replace(/\D/g, '');
      return digits.length >= 8;
    }

    function looksLikeYes(v) {
      return /(^|\s)(oui|ok|d accord|volontiers|avec plaisir|oui rdv|rdv telephonique)(\s|$)/i.test(v || '');
    }

    function looksLikeNo(v) {
      return /(^|\s)(non|pas maintenant|plus tard|no|non merci)(\s|$)/i.test(v || '');
    }

    function parseFullName(v) {
      var txt = (v || '').trim().replace(/\s+/g, ' ');
      if (!txt) return null;
      var parts = txt.split(' ');
      if (parts.length < 2) return null;
      return { firstName: parts[0], lastName: parts.slice(1).join(' ') };
    }

    function formatDateShort(v) {
      if (!v) return '';
      var d = new Date(v);
      if (isNaN(d.getTime())) return String(v);
      var dd = String(d.getDate()).padStart(2, '0');
      var mm = String(d.getMonth() + 1).padStart(2, '0');
      var yy = String(d.getFullYear()).slice(-2);
      return dd + '/' + mm + '/' + yy;
    }

    function isCommercialContext(text) {
      var t = (text || '').toLowerCase();
      var keys = ['prix', 'tarif', 'offre', 'devis', 'abonnement', 'accompagnement', 'plan', 'achat', 'rdv', 'telephone', 'appel'];
      for (var i = 0; i < keys.length; i += 1) {
        if (t.indexOf(keys[i]) !== -1) return true;
      }
      return false;
    }

    async function handleLeadFlow(rawText) {
      var text = (rawText || '').trim();
      var normalized = text.toLowerCase();

      // Fallback: after RDV detection, accept phone even if stage drifted.
      if (lead.intent === 'rdv' && lead.rdvDetected && !lead.phone && looksLikePhone(text) && (lead.stage === 'ask_phone_after_booking' || lead.stage === 'done' || lead.stage === 'none')) {
        lead.phone = text;
        lead.stage = 'saving';
        setStatus('Finalisation de votre demande...', true);
        var savedRdvPhone = await saveLead();
        if (savedRdvPhone && savedRdvPhone.ok) {
          push('bot', "Parfait, votre téléphone est enregistré et votre fiche lead est finalisée.");
        } else {
          push('bot', "Je n'ai pas pu enregistrer votre téléphone pour le moment. Vous pouvez réessayer.");
        }
        lead.stage = 'done';
        renderChips([]);
        setStatus('Prêt', false);
        return true;
      }
      if (/^fermer calendrier$/i.test(text)) {
        closeBooking();
        push('bot', "Calendrier fermé. On continue dans le chat.");
        return true;
      }
      if (/^rdv téléphonique$/i.test(text) || /^rdv telephonique$/i.test(text)) {
        lead.stage = 'ask_rdv';
        normalized = 'oui rdv';
      }

      if (lead.stage === 'ask_rdv') {
        if (looksLikeYes(normalized)) {
          lead.wantsRdv = true;
          lead.intent = 'rdv';
          if (openBooking()) {
            openPanel();
            push('bot', "Parfait. Le calendrier s'affiche à gauche. Je détecterai automatiquement votre réservation.");
            renderChips(['Fermer calendrier']);
            lead.stage = 'await_booking';
            return true;
          }
          if (lead.firstName && lead.lastName && lead.email && lead.phone) {
            lead.stage = 'saving';
            setStatus('Organisation du RDV...', true);
            var savedRdv = await saveLead();
            if (savedRdv && savedRdv.ok) {
              push('bot', "Parfait, votre demande de RDV téléphonique est enregistrée. Nous vous recontactons rapidement.");
            } else {
              push('bot', "Je n'ai pas pu enregistrer le RDV pour l'instant. Vous pouvez réessayer dans un instant.");
            }
            lead.stage = 'done';
            setStatus('Prêt', false);
            return true;
          }
          lead.stage = 'ask_name';
          push('bot', "Avec plaisir. Pour organiser l'appel, pouvez-vous me donner votre prénom et nom ?");
          renderChips([]);
          return true;
        }
        if (looksLikeNo(normalized)) {
          lead.stage = 'done';
          closeBooking();
          push('bot', "Très bien, on continue ici. Je reste disponible pour vos questions.");
          return true;
        }
        push('bot', "Souhaitez-vous être rappelé pour un RDV téléphonique ? Répondez Oui ou Non.");
        return true;
      }

      if (lead.stage === 'ask_optin') {
        if (looksLikeYes(normalized)) {
          lead.wantsRecap = true;
          lead.intent = 'recap';
          lead.stage = 'ask_name';
          push('bot', "Super. Pour personnaliser le récapitulatif, puis-je avoir votre prénom et nom ?");
          renderChips([]);
          return true;
        }
        if (looksLikeNo(normalized)) {
          lead.stage = 'done';
          push('bot', "Très bien. On continue sans fiche contact. Posez votre prochaine question quand vous voulez.");
          return true;
        }
        push('bot', "Souhaitez-vous recevoir un récapitulatif par e-mail ? Répondez par Oui ou Non.");
        return true;
      }

      if (lead.stage === 'await_booking') {
        push('bot', "Je surveille votre réservation Calendly automatiquement.");
        return true;
      }

      if (lead.stage === 'ask_phone_after_booking') {
        if (/^passer$/i.test(text)) {
          lead.phone = '';
        } else {
          lead.phone = text;
        }
        lead.stage = 'saving';
        setStatus('Finalisation de votre demande...', true);
        var savedBooked = await saveLead();
        if (savedBooked && savedBooked.ok) {
          push('bot', "Parfait, votre demande est enregistrée. Notre équipe vous recontactera si nécessaire.");
        } else {
          push('bot', "Je n'ai pas pu finaliser l'enregistrement pour le moment. Vous pouvez continuer la conversation.");
        }
        lead.stage = 'done';
        closeBooking();
        renderChips([]);
        setStatus('Prêt', false);
        return true;
      }

      if (lead.stage === 'ask_name') {
        if (/^j ai reserve$/i.test(normalized) || /^j'ai reserve$/i.test(normalized) || /^jai reserve$/i.test(normalized)) {
          push('bot', "Parfait. Pour confirmer le RDV, pouvez-vous me donner votre prénom et nom ?");
          return true;
        }
        var parsed = parseFullName(text);
        if (!parsed) {
          push('bot', "Merci. Pouvez-vous indiquer prénom et nom (exemple: Jean Dupont) ?");
          return true;
        }
        lead.firstName = parsed.firstName;
        lead.lastName = parsed.lastName;
        lead.stage = 'ask_email';
        push('bot', "Merci " + lead.firstName + ". Quelle est votre adresse e-mail ?");
        return true;
      }

      if (lead.stage === 'ask_email') {
        if (!isEmail(text)) {
          push('bot', "Je n'ai pas reconnu un e-mail valide. Pouvez-vous réessayer ?");
          return true;
        }
        lead.email = text;
        lead.stage = 'ask_phone';
        push('bot', "Merci. Quel est votre numéro de téléphone ? (ou tapez \"Passer\")");
        return true;
      }

      if (lead.stage === 'ask_phone') {
        if (/^passer$/i.test(text)) {
          lead.phone = '';
        } else {
          lead.phone = text;
        }
        lead.stage = 'saving';
        setStatus('Création de votre fiche...', true);
        var saved = await saveLead();
        if (saved && saved.ok) {
          if (lead.wantsRdv) {
            push('bot', "C'est enregistré. Votre demande de RDV est bien prise en compte.");
          } else {
            push('bot', "C'est enregistré. Votre fiche est créée et le récapitulatif a été envoyé par e-mail.");
          }
        } else {
          push('bot', "Je n'ai pas pu finaliser l'envoi du récapitulatif pour le moment, mais vous pouvez continuer la conversation.");
        }
        lead.stage = 'done';
        closeBooking();
        renderChips([]);
        setStatus('Prêt', false);
        return true;
      }
      return false;
    }

    async function onCalendlyScheduled(payload) {
      if (lead.stage !== 'await_booking' && lead.stage !== 'ask_rdv') return;
      setStatus('Récupération du RDV...', true);
      lead.wantsRdv = true;
      lead.intent = 'rdv';
      lead.rdvDetected = true;

      var info = (payload && payload.pre_resolved) ? payload.pre_resolved : await resolveCalendly(payload || {});
      closeBooking();

      if (info && info.ok) {
        if (info.first_name) lead.firstName = info.first_name;
        if (info.last_name) lead.lastName = info.last_name;
        if (info.email) lead.email = info.email;
        if (info.phone) lead.phone = info.phone;
        var rdvText = "Parfait, votre RDV est confirmé";
        if (info.event_start) rdvText += " le " + formatDateShort(info.event_start);
        rdvText += ".";
        push('bot', rdvText);
        if (lead.firstName || lead.lastName || lead.email) {
          push('bot', "Infos récupérées: " + [lead.firstName, lead.lastName, lead.email].filter(Boolean).join(' | '));
        }
      } else {
        push('bot', "RDV détecté. Je n'ai pas pu récupérer automatiquement nom, prénom et email depuis Calendly.");
      }

      if (lead.phone) {
        lead.stage = 'saving';
        var savedDirect = await saveLead();
        if (savedDirect && savedDirect.ok) {
          push('bot', "Votre fiche lead est enregistrée. Merci.");
        } else {
          push('bot', "Je n'ai pas pu enregistrer la fiche pour le moment.");
        }
        lead.stage = 'done';
        renderChips([]);
      } else {
        lead.stage = 'ask_phone_after_booking';
        push('bot', "Pouvez-vous ajouter votre téléphone pour finaliser votre fiche ? (ou tapez \"Passer\")");
        renderChips(['Passer']);
      }
      setStatus('Prêt', false);
    }

    form.addEventListener('submit', async function (e) {
      e.preventDefault();
      if (busy) return;
      var text = (input.value || '').trim();
      if (!text) return;

      busy = true;
      input.value = '';
      push('user', text);
      setStatus('Je réfléchis...', true);
      setMood('thinking');
      if (voiceMode) stopListening();

      try {
        var leadHandled = await handleLeadFlow(text);
        if (leadHandled) {
          setStatus('Prêt', false);
          return;
        }
        userQuestionCount += 1;

        var data = await ask(text);
        var reply = data.reply || 'Je n\'ai pas pu répondre pour le moment.';
        push('bot', reply);

        if (Array.isArray(data.suggestions) && data.suggestions.length) {
          var suggestions = data.suggestions.slice(0, 3).map(function (s) {
            return (s || '').toString().trim();
          }).filter(Boolean);
          renderChips(suggestions);
        } else if (Array.isArray(data.sources) && data.sources.length) {
          var suggestions = data.sources.slice(0, 3).map(function (s) {
            return s && s.title ? ('Voir: ' + s.title) : '';
          }).filter(Boolean);
          renderChips(suggestions);
        }

        if (voiceMode) {
          await speak(reply);
        }

        if (!lead.askedAfterFirstReply) {
          lead.askedAfterFirstReply = true;
          lead.stage = 'ask_optin';
          push('bot', "Si vous le souhaitez, je peux vous envoyer un récapitulatif personnalisé. J'aurai simplement besoin de vos coordonnées.");
          renderChips(['Oui', 'Non merci']);
        }
        if (!lead.askedRdv && userQuestionCount >= 3 && isCommercialContext(text + ' ' + reply)) {
          lead.askedRdv = true;
          lead.stage = 'ask_rdv';
          push('bot', "Souhaitez-vous un RDV téléphonique avec notre équipe pour aller plus loin ?");
          renderChips(['RDV téléphonique', 'Non merci']);
        }

        setMood('success');
        setTimeout(function () { if (!busy && !listening) setMood('idle'); }, 650);
        setStatus('Réponse prête', false);
      } catch (err) {
        push('bot', 'Désolé, je rencontre un problème technique temporaire.');
        setStatus('Erreur temporaire', false);
        setMood('error');
      } finally {
        busy = false;
        input.focus();
        if (voiceMode && keepListening) {
          setTimeout(startListening, 280);
        } else if (!listening) {
          setMood('idle');
        }
      }
    });

    recognizer = setupRecognition();
    if (window.speechSynthesis) {
      selectedVoice = pickFrenchMaleVoice();
      window.speechSynthesis.onvoiceschanged = function () {
        selectedVoice = pickFrenchMaleVoice();
      };
    }

    var welcome = cfg.welcome || 'Bonjour, je suis votre assistant.';
    push('bot', welcome);
    renderChips([
      'Quels services propose ce site ?',
      'RDV téléphonique',
      'Comment vous contacter ?',
      'Pouvez-vous résumer la proposition de valeur ?'
    ]);

    if (!cfg.hasIndex) {
      setStatus('Index en cours de génération', true);
    }
    scheduleNudge();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
