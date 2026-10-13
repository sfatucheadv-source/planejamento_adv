/* Área do cliente: documento para assinar. Antes da assinatura, a página não
   imprime, não salva e não copia (o .docx e a impressão ficam no escritório).
   Assinatura em três passos: (1) nome, CPF e rubrica desenhada; (2) o selo
   aparece sobre a linha de assinatura e o cliente o arrasta até onde quiser,
   em qualquer página; (3) código enviado ao e-mail, aceite e, se o cliente
   permitir, a localização do aparelho. Data, hora e código do selo são
   preenchidos pelo servidor ao concluir. Retificação: texto livre. */
(function () {
  'use strict';
  var raiz = document.querySelector('[data-assin]');
  if (!raiz) return;
  var base = raiz.getAttribute('data-base');
  var dados = JSON.parse((document.getElementById('assin-dados') || {}).textContent || '{}');
  var S = dados.selo || {};

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

  /* ---------- janelas e barras ---------- */
  function janela(nome) { return raiz.querySelector('[data-janela="' + nome + '"]'); }
  function barra(nome) {
    Array.prototype.forEach.call(raiz.querySelectorAll('[data-barra]'), function (b) { b.hidden = b.getAttribute('data-barra') !== nome; });
  }
  function abrir(nome) {
    var j = janela(nome);
    j.hidden = false;
    var f = j.querySelector('input:not([type=hidden]):not([type=checkbox]), textarea');
    if (f && nome !== 'confirmar') f.focus();
    if (nome === 'assinar') ajustarPad();
  }
  raiz.addEventListener('click', function (e) {
    var a = e.target.closest('[data-abrir]');
    if (a) { abrir(a.getAttribute('data-abrir')); return; }
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

  /* ---------- rubrica: traços guardados como vetores (e uma imagem como prova) ---------- */
  var pad = raiz.querySelector('[data-pad]');
  var ctx = pad.getContext('2d');
  var tracos = [], atual = null, cssW = 0, cssH = 0;
  function ajustarPad() {
    var r = pad.getBoundingClientRect();
    if (!r.width) return;
    var escala = window.devicePixelRatio || 1;
    var w = Math.round(r.width * escala), h = Math.round(r.height * escala);
    if (pad.width !== w || pad.height !== h) {
      pad.width = w; pad.height = h; tracos = [];
    }
    cssW = Math.round(r.width); cssH = Math.round(r.height);
    ctx.setTransform(escala, 0, 0, escala, 0, 0);
    ctx.lineWidth = 2.4; ctx.lineCap = 'round'; ctx.lineJoin = 'round'; ctx.strokeStyle = '#1800AD';
  }
  function ponto(e) {
    var r = pad.getBoundingClientRect();
    return [Math.round((e.clientX - r.left) * 10) / 10, Math.round((e.clientY - r.top) * 10) / 10];
  }
  pad.addEventListener('pointerdown', function (e) {
    atual = [ponto(e)]; tracos.push(atual); pad.setPointerCapture(e.pointerId); e.preventDefault();
  });
  pad.addEventListener('pointermove', function (e) {
    if (!atual) return;
    var p = ponto(e), u = atual[atual.length - 1];
    if (Math.abs(p[0] - u[0]) + Math.abs(p[1] - u[1]) < 1.5) return;
    ctx.beginPath(); ctx.moveTo(u[0], u[1]); ctx.lineTo(p[0], p[1]); ctx.stroke();
    atual.push(p); e.preventDefault();
  });
  ['pointerup', 'pointercancel'].forEach(function (ev) { pad.addEventListener(ev, function () { atual = null; }); });
  raiz.querySelector('[data-limpar]').addEventListener('click', function () { ctx.clearRect(0, 0, pad.width, pad.height); tracos = []; });
  function tracosValidos() {
    var t = tracos.filter(function (s) { return s.length >= 2; });
    if (!t.length) return null;
    var xs = [], ys = [];
    t.forEach(function (s) { s.forEach(function (p) { xs.push(p[0]); ys.push(p[1]); }); });
    if (Math.max(Math.max.apply(null, xs) - Math.min.apply(null, xs), Math.max.apply(null, ys) - Math.min.apply(null, ys)) < 8) return null;
    return { w: cssW, h: cssH, s: t };
  }

  /* Mesma conta do servidor (sig_strokes_fit): encaixa os traços na área da rubrica do selo. */
  function encaixar(tr, box) {
    var all = [].concat.apply([], tr.s);
    var xs = all.map(function (p) { return p[0]; }), ys = all.map(function (p) { return p[1]; });
    var mnx = Math.min.apply(null, xs), mny = Math.min.apply(null, ys);
    var bw = Math.max(Math.max.apply(null, xs) - mnx, 1), bh = Math.max(Math.max.apply(null, ys) - mny, 1);
    var sc = Math.min((box.w - 2) / bw, (box.h - 2) / bh);
    var ox = box.x + (box.w - bw * sc) / 2 - mnx * sc, oy = box.y + (box.h - bh * sc) / 2 - mny * sc;
    return tr.s.map(function (s) { return s.map(function (p) { return [ox + p[0] * sc, oy + p[1] * sc]; }); });
  }

  /* ---------- passo 1: dados e rubrica ---------- */
  var fAss = raiz.querySelector('[data-form="assinar"]');
  var erroAss = fAss.querySelector('[data-erro]');
  fAss.addEventListener('submit', function (e) {
    e.preventDefault();
    erroAss.classList.remove('-ok');
    if (!fAss.nome.value.trim() || !fAss.cpf.value.trim()) { erroAss.textContent = 'Preencha o nome completo e o CPF.'; return; }
    if (!tracosValidos()) { erroAss.textContent = 'Faça a sua rubrica no quadro.'; return; }
    var btn = fAss.querySelector('[type=submit]');
    btn.disabled = true; erroAss.textContent = 'Conferindo…';
    var fd = new FormData(); fd.append('_csrf', fAss._csrf.value); fd.append('nome', fAss.nome.value); fd.append('cpf', fAss.cpf.value);
    enviar('/dados', fd).then(function (j) {
      btn.disabled = false;
      if (!j.ok) { erroAss.textContent = j.erro || 'Confira os dados.'; return; }
      erroAss.textContent = '';
      janela('assinar').hidden = true;
      posicionar();
    });
  });

  /* ---------- passo 2: selo para arrastar ---------- */
  var selo = null, pos = { pagina: S.pagina, x: S.x, y: S.y };
  function folha(n) { return document.querySelector('.folha[data-pagina="' + n + '"]'); }
  function aplicar() {
    var f = folha(pos.pagina);
    if (selo.parentNode !== f) f.appendChild(selo);
    selo.style.left = (pos.x / S.folhaW * 100) + '%';
    selo.style.top = (pos.y / S.folhaH * 100) + '%';
    selo.style.width = (S.w / S.folhaW * 100) + '%';
    selo.style.height = (S.h / S.folhaH * 100) + '%';
    var onde = raiz.querySelector('[data-selo-onde]');
    if (onde) onde.textContent = 'Página ' + pos.pagina + ' de ' + dados.paginas + '.';
  }
  function limitar() {
    pos.x = Math.max(S.minX, Math.min(S.folhaW - S.w - 2, pos.x));
    pos.y = Math.max(2, Math.min(S.folhaH - S.h - 2, pos.y));
  }
  function posicionar() {
    if (!selo) {
      selo = document.createElement('div');
      selo.className = 'selo-movel';
      selo.tabIndex = 0;
      selo.setAttribute('role', 'button');
      selo.setAttribute('aria-label', 'Selo da assinatura. Arraste, ou use as setas e Page Up / Page Down para mover.');
      selo.appendChild(document.getElementById('selo-modelo').content.cloneNode(true));
      ligarArraste();
    }
    var g = selo.querySelector('[data-rubrica]');
    g.innerHTML = '';
    encaixar(tracosValidos(), S.tinta).forEach(function (s) {
      var pl = document.createElementNS('http://www.w3.org/2000/svg', 'polyline');
      pl.setAttribute('points', s.map(function (p) { return p[0].toFixed(3) + ',' + p[1].toFixed(3); }).join(' '));
      pl.setAttribute('fill', 'none'); pl.setAttribute('stroke', '#1800AD'); pl.setAttribute('stroke-width', '0.45');
      pl.setAttribute('stroke-linecap', 'round'); pl.setAttribute('stroke-linejoin', 'round');
      g.appendChild(pl);
    });
    aplicar();
    barra('selo');
    selo.scrollIntoView({ behavior: 'smooth', block: 'center' });
    setTimeout(function () { selo.focus({ preventScroll: true }); }, 400);
  }

  function ligarArraste() {
    var pega = null, rolar = 0, ultimo = null;
    function mm(f, cx, cy) {
      var r = f.getBoundingClientRect();
      return [(cx - r.left) / r.width * S.folhaW, (cy - r.top) / r.height * S.folhaH];
    }
    function folhaSob(cx, cy) {
      var els = document.elementsFromPoint ? document.elementsFromPoint(cx, cy) : [];
      for (var i = 0; i < els.length; i++) if (els[i].classList && els[i].classList.contains('folha')) return els[i];
      return null;
    }
    function mover(cx, cy) {
      var f = folhaSob(cx, cy);
      if (!f) return;
      var p = mm(f, cx, cy);
      pos.pagina = +f.getAttribute('data-pagina');
      pos.x = p[0] - pega[0]; pos.y = p[1] - pega[1];
      limitar(); aplicar();
    }
    function loop() {
      if (!pega) return;
      if (rolar) { window.scrollBy(0, rolar); if (ultimo) mover(ultimo[0], ultimo[1]); }
      requestAnimationFrame(loop);
    }
    // O selo muda de folha durante o arraste (sai e volta ao documento), o que
    // desfaz a captura do ponteiro: o arraste é acompanhado na janela inteira.
    function seguir(e) {
      if (!pega || e.pointerId !== pega[2]) return;
      ultimo = [e.clientX, e.clientY];
      rolar = e.clientY < 70 ? -10 : (e.clientY > window.innerHeight - 140 ? 10 : 0);
      mover(e.clientX, e.clientY);
      e.preventDefault();
    }
    function soltar(e) {
      if (!pega || e.pointerId !== pega[2]) return;
      pega = null; rolar = 0; ultimo = null;
      selo.classList.remove('-arrastando');
      window.removeEventListener('pointermove', seguir);
      window.removeEventListener('pointerup', soltar);
      window.removeEventListener('pointercancel', soltar);
    }
    selo.addEventListener('pointerdown', function (e) {
      if (pega) return;
      var p = mm(selo.parentNode, e.clientX, e.clientY);
      pega = [p[0] - pos.x, p[1] - pos.y, e.pointerId];
      selo.classList.add('-arrastando');
      window.addEventListener('pointermove', seguir, { passive: false });
      window.addEventListener('pointerup', soltar);
      window.addEventListener('pointercancel', soltar);
      e.preventDefault();
      requestAnimationFrame(loop);
    });
    selo.addEventListener('keydown', function (e) {
      var d = e.shiftKey ? 5 : 1, ok = true;
      if (e.key === 'ArrowLeft') pos.x -= d;
      else if (e.key === 'ArrowRight') pos.x += d;
      else if (e.key === 'ArrowUp') pos.y -= d;
      else if (e.key === 'ArrowDown') pos.y += d;
      else if (e.key === 'PageDown' && pos.pagina < dados.paginas) pos.pagina++;
      else if (e.key === 'PageUp' && pos.pagina > 1) pos.pagina--;
      else ok = false;
      if (!ok) return;
      e.preventDefault(); limitar(); aplicar();
      if (e.key.indexOf('Page') === 0) selo.scrollIntoView({ block: 'center' });
    });
  }
  raiz.querySelector('[data-voltar]').addEventListener('click', function () {
    if (selo && selo.parentNode) selo.parentNode.removeChild(selo);
    barra('inicio');
    abrir('assinar');
  });
  raiz.querySelector('[data-confirmar-selo]').addEventListener('click', function () {
    raiz.querySelector('[data-selo-resumo]').textContent = 'O selo vai na página ' + pos.pagina + ' de ' + dados.paginas
      + ', onde você o deixou. A data, a hora e o código de verificação entram no selo e na margem de todas as páginas quando você concluir.';
    abrir('confirmar');
  });

  /* ---------- passo 3: código, aceite e assinatura ---------- */
  var fConf = raiz.querySelector('[data-form="confirmar"]');
  var erroConf = fConf.querySelector('[data-erro]');
  raiz.querySelector('[data-ajustar]').addEventListener('click', function () { janela('confirmar').hidden = true; selo.focus(); });
  fConf.querySelector('[data-codigo]').addEventListener('click', function () {
    var b = this;
    b.disabled = true; erroConf.textContent = 'Enviando o código…'; erroConf.classList.remove('-ok');
    var fd = new FormData(); fd.append('_csrf', fConf._csrf.value);
    enviar('/codigo', fd).then(function (j) {
      erroConf.textContent = j.ok ? j.msg : j.erro;
      erroConf.classList.toggle('-ok', !!j.ok);
      b.textContent = 'Reenviar código';
      setTimeout(function () { b.disabled = false; }, j.ok ? 30000 : 0);
      if (j.ok) fConf.codigo.focus();
    });
  });

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

  fConf.addEventListener('submit', function (e) {
    e.preventDefault();
    erroConf.classList.remove('-ok');
    if (fConf.codigo.value.replace(/\D/g, '').length !== 6) { erroConf.textContent = 'Digite o código de 6 números enviado ao seu e-mail.'; return; }
    if (!fConf.aceite.checked) { erroConf.textContent = 'Marque que leu e concorda com o documento.'; return; }
    var btn = fConf.querySelector('[type=submit]');
    btn.disabled = true; erroConf.textContent = 'Registrando a assinatura e gerando o PDF…';
    localizacao().then(function (g) {
      var fd = new FormData();
      fd.append('_csrf', fConf._csrf.value);
      fd.append('nome', fAss.nome.value); fd.append('cpf', fAss.cpf.value);
      fd.append('codigo', fConf.codigo.value); fd.append('aceite', '1');
      fd.append('tracos', JSON.stringify(tracosValidos()));
      fd.append('rubrica', pad.toDataURL('image/png'));
      var r = folha(pos.pagina).getBoundingClientRect();
      fd.append('selo', JSON.stringify({ pagina: pos.pagina, x: Math.round(pos.x * 100) / 100, y: Math.round(pos.y * 100) / 100, w: S.w, h: S.h,
        largura_folha_px: Math.round(r.width), zoom: window.visualViewport ? window.visualViewport.scale : 1, dpr: window.devicePixelRatio || 1,
        orientacao: screen.orientation ? screen.orientation.angle : (window.orientation || 0), tela: window.innerWidth + 'x' + window.innerHeight }));
      Object.keys(g).forEach(function (k) { fd.append(k, g[k]); });
      try { fd.append('fuso', Intl.DateTimeFormat().resolvedOptions().timeZone || ''); } catch (er) {}
      fd.append('tela', screen.width + 'x' + screen.height + ' @' + (window.devicePixelRatio || 1));
      fd.append('plataforma', navigator.platform || '');
      return enviar('/assinar', fd);
    }).then(function (j) {
      if (j.ok) { window.location.href = j.ir; return; }
      erroConf.textContent = j.erro || 'Não foi possível assinar agora.';
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
      raiz.querySelector('[data-barra="inicio"] p').innerHTML = '<strong>Pedido de retificação enviado.</strong> Sidney vai revisar o documento e avisar você pela conversa.';
      aviso(j.msg);
    });
  });
})();
