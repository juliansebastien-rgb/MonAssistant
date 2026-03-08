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

    panel.appendChild(head);
    panel.appendChild(thread);
    panel.appendChild(chips);
    panel.appendChild(status);
    panel.appendChild(form);

    root.appendChild(panel);
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
    var transcript = [];
    var lead = {
      askedAfterFirstReply: false,
      stage: 'none',
      wantsRecap: false,
      email: '',
      phone: ''
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
    });
    close.addEventListener('click', closePanel);

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
            email: lead.email,
            phone: lead.phone,
            page_url: window.location.href,
            transcript: transcript.join('\n')
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

    async function handleLeadFlow(rawText) {
      var text = (rawText || '').trim();
      var normalized = text.toLowerCase();
      if (lead.stage === 'ask_optin') {
        if (/(^|\\s)(oui|ok|d accord|volontiers|avec plaisir)(\\s|$)/i.test(normalized)) {
          lead.wantsRecap = true;
          lead.stage = 'ask_email';
          push('bot', "Parfait. Quelle est votre adresse e-mail pour recevoir le récapitulatif ?");
          renderChips([]);
          return true;
        }
        if (/(^|\\s)(non|pas maintenant|plus tard|no)(\\s|$)/i.test(normalized)) {
          lead.stage = 'done';
          push('bot', "Très bien. On continue sans fiche contact. Posez votre prochaine question quand vous voulez.");
          return true;
        }
        push('bot', "Souhaitez-vous recevoir un récapitulatif par e-mail ? Répondez par Oui ou Non.");
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
          push('bot', "C'est enregistré. Votre fiche est créée et le récapitulatif a été envoyé par e-mail.");
        } else {
          push('bot', "Je n'ai pas pu finaliser l'envoi du récapitulatif pour le moment, mais vous pouvez continuer la conversation.");
        }
        lead.stage = 'done';
        setStatus('Prêt', false);
        return true;
      }
      return false;
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
          push('bot', "Souhaitez-vous que je crée une fiche et vous envoie un récapitulatif par e-mail ? J'aurai juste besoin de votre e-mail et téléphone.");
          renderChips(['Oui', 'Non merci']);
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
      'Comment vous contacter ?',
      'Pouvez-vous résumer la proposition de valeur ?'
    ]);

    if (!cfg.hasIndex) {
      setStatus('Index en cours de génération', true);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
