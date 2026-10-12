/* Conversa do atendimento inicial: rolagem, consulta periódica (sem WebSocket),
   indicação de salvamento e prévia de fotos. Funciona sem JavaScript também. */
(function () {
  'use strict';

  document.querySelectorAll('[data-chat-log]').forEach(function (log) {
    log.scrollTop = log.scrollHeight;
    var url = log.getAttribute('data-poll');
    if (!url || !window.fetch) { return; }
    var busy = false;
    var tick = function () {
      if (busy || document.hidden) { return; }
      busy = true;
      var last = log.getAttribute('data-last') || '0';
      fetch(url + (url.indexOf('?') < 0 ? '?' : '&') + 'depois=' + encodeURIComponent(last), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
        .then(function (r) { return r.ok ? r.json() : null; })
        .then(function (d) {
          if (d && d.badges && window.convBadges) { window.convBadges(d.badges); }
          if (!d || !d.html || String(d.last) === last) { return; }
          // Evita duplicar mensagens já exibidas (retentativa ou duas abas).
          var tmp = document.createElement('div');
          tmp.innerHTML = d.html;
          Array.prototype.forEach.call(tmp.querySelectorAll('[data-id]'), function (m) {
            if (!log.querySelector('[data-id="' + m.getAttribute('data-id') + '"]')) { log.appendChild(m); }
          });
          log.setAttribute('data-last', String(d.last));
          log.scrollTop = log.scrollHeight;
        })
        .catch(function () {})
        .then(function () { busy = false; });
    };
    setInterval(tick, 8000);
    document.addEventListener('visibilitychange', function () { if (!document.hidden) { tick(); } });
  });

  document.querySelectorAll('form[data-save]').forEach(function (form) {
    form.addEventListener('submit', function () {
      var box = form.closest('.cv-q') || form;
      var msg = box.querySelector('[data-saving]');
      var file = form.querySelector('input[type=file]');
      var reading = form.getAttribute('data-reading');
      if (!msg && reading) {
        msg = document.createElement('p');
        msg.className = 'cv-saving';
        msg.setAttribute('aria-live', 'polite');
        form.appendChild(msg);
      }
      if (msg) {
        if (reading && file && file.files && file.files.length) { msg.textContent = reading; }
        msg.hidden = false;
      }
      // Desabilita depois do envio para não perder o botão clicado nem duplicar o envio.
      setTimeout(function () {
        form.querySelectorAll('button').forEach(function (b) { b.disabled = true; });
      }, 0);
    });
  });

  document.querySelectorAll('input[type=file][data-preview]').forEach(function (input) {
    var box = input.closest('form').querySelector('[data-preview-box]');
    if (!box) { return; }
    input.addEventListener('change', function () {
      box.innerHTML = '';
      Array.prototype.forEach.call(input.files || [], function (f) {
        if (/^image\//.test(f.type) && window.URL) {
          var img = document.createElement('img');
          img.alt = f.name;
          img.src = URL.createObjectURL(f);
          box.appendChild(img);
        } else {
          var s = document.createElement('span');
          s.textContent = f.name;
          box.appendChild(s);
        }
        if (f.size > 15 * 1024 * 1024) {
          var w = document.createElement('span');
          w.textContent = f.name + ' passa de 15 MB. Envie uma versão menor ou mande pelo WhatsApp.';
          box.appendChild(w);
        }
      });
    });
  });

  document.querySelectorAll('[data-toggle-show]').forEach(function (radio) {
    var form = radio.closest('form');
    var sync = function () {
      form.querySelectorAll('[data-show]').forEach(function (el) {
        var on = form.querySelector('[data-toggle-show="' + el.getAttribute('data-show') + '"]');
        el.hidden = !(on && on.checked);
      });
    };
    radio.addEventListener('change', sync);
    sync();
  });
})();

/* Chat de atendimento com a IA: envio sem recarregar a página, indicação de
   "digitando", respostas sugeridas, anexos e áudio gravado no navegador (WAV
   16 kHz mono, aceito por todos os modelos). Sem JavaScript, o formulário
   continua funcionando com envio normal. */
(function () {
  'use strict';
  var form = document.querySelector('form[data-chat-form]');
  if (!form || !window.fetch || !window.FormData) { return; }
  var log = document.querySelector('[data-chat-log]');
  var bar = document.querySelector('[data-agent-bar]');
  var input = form.querySelector('[data-chat-input]');
  var fileInput = form.querySelector('input[type=file]');
  var preview = form.querySelector('[data-preview-box]');
  var docChoice = form.querySelector('[data-doc-choice]');
  var keyField = form.querySelector('[data-key]');
  var sendBtn = form.querySelector('.cv-send');
  var busy = false;

  var esc = function (s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; };
  var scroll = function () { if (log) { log.scrollTop = log.scrollHeight; } };
  var newKey = function () { keyField.value = Math.random().toString(16).slice(2) + Date.now().toString(16); };
  var syncDocChoice = function () { if (docChoice) { docChoice.hidden = !(fileInput && fileInput.files && fileInput.files.length); } };
  syncDocChoice();
  if (fileInput) { fileInput.addEventListener('change', syncDocChoice); }

  function bubble(author, html, extra) {
    var el = document.createElement('div');
    el.className = 'cv-msg cv-' + author + (extra ? ' ' + extra : '');
    el.innerHTML = '<div class="cv-bubble">' + html + '</div>';
    log.appendChild(el);
    scroll();
    return el;
  }

  function grow() {
    input.style.height = 'auto';
    input.style.height = Math.min(input.scrollHeight, 160) + 'px';
  }
  input.addEventListener('input', grow);
  input.addEventListener('keydown', function (ev) {
    if (ev.key === 'Enter' && !ev.shiftKey && !ev.isComposing && window.matchMedia('(pointer: fine)').matches) {
      ev.preventDefault();
      if (form.requestSubmit) { form.requestSubmit(); } else { form.dispatchEvent(new Event('submit', { cancelable: true })); }
    }
  });

  function send(fd, label, waiting) {
    busy = true;
    if (sendBtn) { sendBtn.disabled = true; }
    fd.set('ajax', '1');
    fd.set('depois', log.getAttribute('data-last') || '0');
    var mine = bubble('client', '<div class="cv-text">' + esc(label) + '</div>', 'is-sending');
    var typing = bubble('bot', '<span class="cv-who">Assistente virtual</span><div class="cv-typing" aria-label="' + esc(waiting) + '"><i></i><i></i><i></i><small>' + esc(waiting) + '</small></div>', 'is-typing');
    var restore = fd.get('message') || '';
    var done = function () {
      busy = false;
      if (sendBtn) { sendBtn.disabled = false; }
      mine.remove();
      typing.remove();
    };
    fetch(form.action, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }, function () { return { ok: false, j: {} }; }); })
      .then(function (res) {
        done();
        var j = res.j || {};
        if (!res.ok || !j.ok) {
          bubble('bot', '<div class="cv-text">' + esc(j.error || 'Não consegui enviar agora. Confira a conexão e tente de novo.') + '</div>', 'is-error');
          if (restore && !input.value) { input.value = restore; grow(); }
          return;
        }
        if (j.reload) { window.location.reload(); return; }
        var tmp = document.createElement('div');
        tmp.innerHTML = j.html || '';
        Array.prototype.forEach.call(tmp.querySelectorAll('[data-id]'), function (m) {
          if (!log.querySelector('[data-id="' + m.getAttribute('data-id') + '"]')) { log.appendChild(m); }
        });
        log.setAttribute('data-last', String(j.last));
        if (bar && j.bar !== null && j.bar !== undefined) { bar.innerHTML = j.bar; }
        scroll();
      })
      .catch(function () {
        done();
        bubble('bot', '<div class="cv-text">Sem conexão no momento. Sua mensagem não foi enviada; tente de novo.</div>', 'is-error');
        if (restore && !input.value) { input.value = restore; grow(); }
      });
  }

  form.addEventListener('submit', function (ev) {
    ev.preventDefault();
    if (busy) { return; }
    var fd = new FormData(form);
    var sub = ev.submitter;
    if (sub && sub.name) { fd.set(sub.name, sub.value); }
    var text = String(fd.get('message') || '').trim();
    var files = fileInput && fileInput.files ? Array.prototype.slice.call(fileInput.files) : [];
    if (!text && !files.length) { input.focus(); return; }
    var label = text;
    if (files.length) { label = (text ? text + '\n' : '') + '📎 ' + files.map(function (f) { return f.name; }).join(', '); }
    send(fd, label, files.length ? 'Recebendo e lendo o arquivo…' : 'Digitando…');
    if (!sub || sub.name !== 'message' || sub === sendBtn) { input.value = ''; grow(); }
    if (fileInput) { fileInput.value = ''; }
    if (preview) { preview.innerHTML = ''; }
    syncDocChoice();
    newKey();
  });

  /* ---------- Áudio ---------- */
  var recBtn = form.querySelector('[data-rec]');
  var recBar = form.querySelector('[data-rec-bar]');
  var recTime = form.querySelector('[data-rec-time]');
  var Ctx = window.AudioContext || window.webkitAudioContext;
  if (!recBtn || !Ctx || !navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) { return; }
  recBtn.hidden = false;
  var rec = null;
  var MAX = 180;

  function fmt(s) { return Math.floor(s / 60) + ':' + ('0' + Math.floor(s % 60)).slice(-2); }

  function stop(sendIt) {
    if (!rec) { return; }
    var r = rec;
    rec = null;
    clearInterval(r.timer);
    r.stream.getTracks().forEach(function (t) { t.stop(); });
    try { r.src.disconnect(); r.proc.disconnect(); } catch (e) { /* já desconectado */ }
    r.ctx.close();
    recBar.hidden = true;
    recBtn.hidden = false;
    var secs = (Date.now() - r.t0) / 1000;
    if (!sendIt || secs < 1 || busy) { return; }
    var fd = new FormData(form);
    fd.delete('arquivo[]');
    fd.set('message', '');
    fd.append('audio', wav(r.chunks, r.ctx.sampleRate, 16000), 'audio.wav');
    send(fd, '🎙️ Áudio (' + fmt(secs) + ')', 'Ouvindo o áudio…');
    newKey();
  }

  recBtn.addEventListener('click', function () {
    if (rec || busy) { return; }
    var ctx = new Ctx();
    navigator.mediaDevices.getUserMedia({ audio: { channelCount: 1, echoCancellation: true, noiseSuppression: true } }).then(function (stream) {
      if (ctx.state === 'suspended') { ctx.resume(); }
      var src = ctx.createMediaStreamSource(stream);
      var proc = ctx.createScriptProcessor(4096, 1, 1);
      var chunks = [];
      proc.onaudioprocess = function (e) { chunks.push(new Float32Array(e.inputBuffer.getChannelData(0))); };
      src.connect(proc);
      proc.connect(ctx.destination);
      rec = { ctx: ctx, stream: stream, src: src, proc: proc, chunks: chunks, t0: Date.now() };
      recBar.hidden = false;
      recBtn.hidden = true;
      recTime.textContent = '0:00';
      rec.timer = setInterval(function () {
        var s = (Date.now() - rec.t0) / 1000;
        recTime.textContent = fmt(s);
        if (s >= MAX) { stop(true); }
      }, 250);
    }).catch(function () {
      ctx.close();
      bubble('bot', '<div class="cv-text">Não consegui usar o microfone. Libere a permissão no navegador ou, se preferir, escreva ou anexe um arquivo de áudio.</div>', 'is-error');
    });
  });
  form.querySelector('[data-rec-stop]').addEventListener('click', function () { stop(true); });
  form.querySelector('[data-rec-cancel]').addEventListener('click', function () { stop(false); });

  /** Junta os trechos, reduz para 16 kHz e grava WAV PCM 16 bits mono. */
  function wav(chunks, rate, target) {
    var len = chunks.reduce(function (n, c) { return n + c.length; }, 0);
    var all = new Float32Array(len);
    var off = 0;
    chunks.forEach(function (c) { all.set(c, off); off += c.length; });
    var ratio = rate / target;
    var outLen = Math.floor(len / ratio);
    var pcm = new Int16Array(outLen);
    for (var i = 0; i < outLen; i++) {
      var a = Math.floor(i * ratio), b = Math.min(len, Math.floor((i + 1) * ratio)), sum = 0;
      for (var k = a; k < b; k++) { sum += all[k]; }
      var v = Math.max(-1, Math.min(1, b > a ? sum / (b - a) : 0));
      pcm[i] = v < 0 ? v * 0x8000 : v * 0x7FFF;
    }
    var buf = new ArrayBuffer(44 + pcm.length * 2);
    var dv = new DataView(buf);
    var str = function (o, s) { for (var i = 0; i < s.length; i++) { dv.setUint8(o + i, s.charCodeAt(i)); } };
    str(0, 'RIFF'); dv.setUint32(4, 36 + pcm.length * 2, true); str(8, 'WAVE'); str(12, 'fmt ');
    dv.setUint32(16, 16, true); dv.setUint16(20, 1, true); dv.setUint16(22, 1, true);
    dv.setUint32(24, target, true); dv.setUint32(28, target * 2, true); dv.setUint16(32, 2, true); dv.setUint16(34, 16, true);
    str(36, 'data'); dv.setUint32(40, pcm.length * 2, true);
    for (var j = 0; j < pcm.length; j++) { dv.setInt16(44 + j * 2, pcm[j], true); }
    return new Blob([dv], { type: 'audio/wav' });
  }
})();

/* Lista de atendimentos: marcação de novidades atualizada sem recarregar,
   contador no título da aba e avisos que podem ser fechados. */
(function () {
  'use strict';
  var app = document.querySelector('[data-app]');
  var list = document.querySelector('[data-conv-list]');
  document.querySelectorAll('[data-flash-close]').forEach(function (b) {
    b.addEventListener('click', function () { b.parentNode.remove(); });
  });
  var flashes = document.querySelector('[data-flashes]');
  if (flashes && !flashes.querySelector('.flash-error')) { setTimeout(function () { flashes.remove(); }, 6000); }
  if (!app || !list) { return; }
  var base = app.getAttribute('data-title') || document.title;
  window.convBadges = function (badges) {
    var total = 0;
    Object.keys(badges).forEach(function (id) {
      var n = parseInt(badges[id], 10) || 0;
      var item = list.querySelector('[data-conv="' + id + '"]');
      if (!item) { return; }
      if (item.classList.contains('is-on')) { n = 0; }
      total += n;
      var b = item.querySelector('[data-badge]');
      item.classList.toggle('has-new', n > 0);
      if (b) { b.textContent = String(n); b.hidden = n < 1; }
    });
    document.title = (total ? '(' + total + ') ' : '') + base;
  };
  // Sem conversa aberta (lista no celular), a lista consulta sozinha.
  if (document.querySelector('[data-chat-log]') || !window.fetch) { return; }
  var url = list.getAttribute('data-poll');
  var tick = function () {
    if (document.hidden) { return; }
    fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) { if (d && d.badges) { window.convBadges(d.badges); } })
      .catch(function () {});
  };
  setInterval(tick, 15000);
  document.addEventListener('visibilitychange', function () { if (!document.hidden) { tick(); } });
})();
