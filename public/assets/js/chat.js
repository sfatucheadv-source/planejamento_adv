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
      if (msg) { msg.hidden = false; }
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
