/* Área do cliente: documento para assinar. Antes da assinatura, a página não
   imprime, não salva e não copia (o .docx e a impressão ficam no escritório).
   Assinatura: nome, CPF, rubrica desenhada, código enviado ao e-mail, aceite e,
   se o cliente permitir, a localização do aparelho. Retificação: texto livre. */
(function () {
  'use strict';
  var raiz = document.querySelector('[data-assin]');
  if (!raiz) return;
  var base = raiz.getAttribute('data-base');

  /* ---------- sem imprimir, salvar ou copiar antes de assinar ---------- */
  document.addEventListener('keydown', function (e) {
    var k = (e.key || '').toLowerCase();
    if ((e.ctrlKey || e.metaKey) && (k === 'p' || k === 's' || k === 'c' || k === 'x' || k === 'a')) {
      if (e.target.closest && e.target.closest('input, textarea')) return;
      e.preventDefault();
      if (k === 'p' || k === 's') aviso('A impressão e o PDF ficam disponíveis depois da assinatura.');
    }
  });
  ['contextmenu', 'copy', 'cut', 'dragstart'].forEach(function (ev) {
    document.addEventListener(ev, function (e) {
      if (e.target.closest && e.target.closest('input, textarea')) return;
      e.preventDefault();
    });
  });

  var avisoEl = null, avisoT = null;
  function aviso(t) {
    if (!avisoEl) { avisoEl = document.createElement('div'); avisoEl.className = 'assin-aviso'; document.body.appendChild(avisoEl); }
    avisoEl.textContent = t;
    avisoEl.classList.add('is-on');
    clearTimeout(avisoT);
    avisoT = setTimeout(function () { avisoEl.classList.remove('is-on'); }, 3500);
  }

  /* ---------- janelas ---------- */
  function janela(nome) { return raiz.querySelector('[data-janela="' + nome + '"]'); }
  raiz.addEventListener('click', function (e) {
    var a = e.target.closest('[data-abrir]');
    if (a) {
      var j = janela(a.getAttribute('data-abrir'));
      j.hidden = false;
      var f = j.querySelector('input:not([type=hidden]), textarea');
      if (f) f.focus();
      if (a.getAttribute('data-abrir') === 'assinar') ajustarPad();
      return;
    }
    if (e.target.closest('[data-fechar]') || e.target.classList.contains('assin-modal')) {
      var m = e.target.closest('.assin-modal');
      if (m) m.hidden = true;
    }
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') Array.prototype.forEach.call(raiz.querySelectorAll('.assin-modal'), function (m) { m.hidden = true; });
  });

  function enviar(caminho, fd) {
    return fetch(base + caminho, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.json(); })
      .catch(function () { return { ok: false, erro: 'Sem conexão. Tente de novo.' }; });
  }

  /* ---------- rubrica ---------- */
  var pad = raiz.querySelector('[data-pad]');
  var ctx = pad.getContext('2d');
  var tinta = false, desenhando = false, ultimo = null;
  function ajustarPad() {
    var r = pad.getBoundingClientRect();
    if (!r.width) return;
    var escala = window.devicePixelRatio || 1;
    var w = Math.round(r.width * escala), h = Math.round(r.height * escala);
    if (pad.width !== w || pad.height !== h) {
      pad.width = w; pad.height = h; tinta = false;
    }
    ctx.lineWidth = 2.2 * escala; ctx.lineCap = 'round'; ctx.lineJoin = 'round'; ctx.strokeStyle = '#1800AD';
  }
  function ponto(e) {
    var r = pad.getBoundingClientRect(), escala = pad.width / r.width;
    return { x: (e.clientX - r.left) * escala, y: (e.clientY - r.top) * escala };
  }
  pad.addEventListener('pointerdown', function (e) { desenhando = true; ultimo = ponto(e); pad.setPointerCapture(e.pointerId); e.preventDefault(); });
  pad.addEventListener('pointermove', function (e) {
    if (!desenhando) return;
    var p = ponto(e);
    ctx.beginPath(); ctx.moveTo(ultimo.x, ultimo.y); ctx.lineTo(p.x, p.y); ctx.stroke();
    ultimo = p; tinta = true; e.preventDefault();
  });
  ['pointerup', 'pointercancel', 'pointerleave'].forEach(function (ev) { pad.addEventListener(ev, function () { desenhando = false; }); });
  raiz.querySelector('[data-limpar]').addEventListener('click', function () { ctx.clearRect(0, 0, pad.width, pad.height); tinta = false; });

  /* ---------- código por e-mail ---------- */
  var fAss = raiz.querySelector('[data-form="assinar"]');
  var erroAss = fAss.querySelector('[data-erro]');
  fAss.querySelector('[data-codigo]').addEventListener('click', function () {
    var b = this;
    b.disabled = true; erroAss.textContent = 'Enviando o código…'; erroAss.classList.remove('-ok');
    var fd = new FormData(); fd.append('_csrf', fAss.querySelector('[name=_csrf]').value);
    enviar('/codigo', fd).then(function (j) {
      erroAss.textContent = j.ok ? j.msg : j.erro;
      erroAss.classList.toggle('-ok', !!j.ok);
      b.textContent = 'Reenviar código';
      setTimeout(function () { b.disabled = false; }, j.ok ? 30000 : 0);
      if (j.ok) fAss.querySelector('[name=codigo]').focus();
    });
  });

  /* ---------- localização (opcional, pedida ao assinar) ---------- */
  function localizacao() {
    return new Promise(function (ok) {
      if (!navigator.geolocation) return ok({ geo_status: 'indisponivel' });
      var feito = false;
      var t = setTimeout(function () { if (!feito) { feito = true; ok({ geo_status: 'tempo' }); } }, 9000);
      navigator.geolocation.getCurrentPosition(function (p) {
        if (feito) return; feito = true; clearTimeout(t);
        ok({ geo_lat: p.coords.latitude, geo_lng: p.coords.longitude, geo_acc: p.coords.accuracy });
      }, function (er) {
        if (feito) return; feito = true; clearTimeout(t);
        ok({ geo_status: er && er.code === 1 ? 'negada' : 'indisponivel' });
      }, { enableHighAccuracy: true, timeout: 8000, maximumAge: 60000 });
    });
  }

  fAss.addEventListener('submit', function (e) {
    e.preventDefault();
    erroAss.classList.remove('-ok');
    if (!fAss.nome.value.trim() || !fAss.cpf.value.trim()) { erroAss.textContent = 'Preencha o nome completo e o CPF.'; return; }
    if (!tinta) { erroAss.textContent = 'Faça a sua rubrica no quadro.'; return; }
    if (fAss.codigo.value.replace(/\D/g, '').length !== 6) { erroAss.textContent = 'Digite o código de 6 números enviado ao seu e-mail.'; return; }
    if (!fAss.aceite.checked) { erroAss.textContent = 'Marque que leu e concorda com o documento.'; return; }
    var btn = fAss.querySelector('[type=submit]');
    btn.disabled = true; erroAss.textContent = 'Registrando a assinatura…';
    localizacao().then(function (g) {
      var fd = new FormData(fAss);
      fd.append('rubrica', pad.toDataURL('image/png'));
      Object.keys(g).forEach(function (k) { fd.append(k, g[k]); });
      try { fd.append('fuso', Intl.DateTimeFormat().resolvedOptions().timeZone || ''); } catch (er) {}
      fd.append('tela', screen.width + 'x' + screen.height + ' @' + (window.devicePixelRatio || 1));
      fd.append('plataforma', navigator.platform || '');
      return enviar('/assinar', fd);
    }).then(function (j) {
      if (j.ok) { window.location.href = j.ir; return; }
      erroAss.textContent = j.erro || 'Não foi possível assinar agora.';
      btn.disabled = false;
    });
  });

  /* ---------- retificação ---------- */
  var fRet = raiz.querySelector('[data-form="retificar"]');
  var erroRet = fRet.querySelector('[data-erro]');
  fRet.addEventListener('submit', function (e) {
    e.preventDefault();
    if (fRet.texto.value.trim().length < 10) { erroRet.textContent = 'Explique o que está errado ou o que precisa mudar.'; return; }
    var btn = fRet.querySelector('[type=submit]');
    btn.disabled = true; erroRet.textContent = 'Enviando…';
    enviar('/retificar', new FormData(fRet)).then(function (j) {
      btn.disabled = false;
      if (!j.ok) { erroRet.textContent = j.erro; return; }
      janela('retificar').hidden = true;
      fRet.texto.value = ''; erroRet.textContent = '';
      raiz.querySelector('.assin-barra p').innerHTML = '<strong>Pedido de retificação enviado.</strong> Sidney vai revisar o documento e avisar você pela conversa.';
      aviso(j.msg);
    });
  });
})();
