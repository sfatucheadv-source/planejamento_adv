/* Painel da equipe: (1) quadro para desenhar a rubrica em Minha conta (traços
   guardados como vetores e reaproveitados nos selos); (2) selo do integrante
   para arrastar até o lugar da assinatura, em qualquer página, antes de aprovar. */
(function () {
  'use strict';

  /* ---------- rubrica em Minha conta ---------- */
  var pad = document.querySelector('[data-rubrica-pad]');
  if (pad) {
    var ctx = pad.getContext('2d'), tracos = [], atual = null, w = 0, h = 0;
    var ajustar = function () {
      var r = pad.getBoundingClientRect(), esc = window.devicePixelRatio || 1;
      if (!r.width) return;
      if (pad.width !== Math.round(r.width * esc)) { pad.width = Math.round(r.width * esc); pad.height = Math.round(r.height * esc); tracos = []; }
      w = Math.round(r.width); h = Math.round(r.height);
      ctx.setTransform(esc, 0, 0, esc, 0, 0); ctx.lineWidth = 2.4; ctx.lineCap = 'round'; ctx.lineJoin = 'round'; ctx.strokeStyle = '#1800AD';
    };
    ajustar(); window.addEventListener('resize', ajustar);
    var pt = function (e) { var r = pad.getBoundingClientRect(); return [Math.round((e.clientX - r.left) * 10) / 10, Math.round((e.clientY - r.top) * 10) / 10]; };
    pad.addEventListener('pointerdown', function (e) { atual = [pt(e)]; tracos.push(atual); pad.setPointerCapture(e.pointerId); e.preventDefault(); });
    pad.addEventListener('pointermove', function (e) {
      if (!atual) return;
      var p = pt(e), u = atual[atual.length - 1];
      if (Math.abs(p[0] - u[0]) + Math.abs(p[1] - u[1]) < 1.5) return;
      ctx.beginPath(); ctx.moveTo(u[0], u[1]); ctx.lineTo(p[0], p[1]); ctx.stroke(); atual.push(p); e.preventDefault();
    });
    ['pointerup', 'pointercancel'].forEach(function (ev) { pad.addEventListener(ev, function () { atual = null; }); });
    document.querySelector('[data-rubrica-limpar]').addEventListener('click', function () { ctx.clearRect(0, 0, pad.width, pad.height); tracos = []; });
    document.querySelector('[data-rubrica-form]').addEventListener('submit', function (e) {
      var t = tracos.filter(function (s) { return s.length >= 2; });
      if (!t.length) { e.preventDefault(); alert('Desenhe a rubrica no quadro.'); return; }
      document.querySelector('[data-rubrica-tracos]').value = JSON.stringify({ w: w, h: h, s: t });
    });
  }

  /* ---------- selo do integrante ---------- */
  var dadosEl = document.getElementById('selo-dados');
  if (!dadosEl) return;
  var dados = JSON.parse(dadosEl.textContent), S = dados.selo;
  var pos = { pagina: S.pagina, x: S.x, y: S.y };
  var selo = document.createElement('div');
  selo.className = 'selo-movel';
  selo.tabIndex = 0;
  selo.setAttribute('role', 'button');
  selo.setAttribute('aria-label', 'Seu selo de assinatura. Arraste, ou use as setas e Page Up / Page Down.');
  selo.appendChild(document.getElementById('selo-modelo').content.cloneNode(true));
  function folha(n) { return document.querySelector('.folha[data-pagina="' + n + '"]'); }
  function aplicar() {
    var f = folha(pos.pagina);
    if (selo.parentNode !== f) f.appendChild(selo);
    selo.style.left = (pos.x / S.folhaW * 100) + '%';
    selo.style.top = (pos.y / S.folhaH * 100) + '%';
    selo.style.width = (S.w / S.folhaW * 100) + '%';
    selo.style.height = (S.h / S.folhaH * 100) + '%';
    var onde = document.querySelector('[data-selo-onde]');
    if (onde) onde.textContent = 'Página ' + pos.pagina + ' de ' + dados.paginas + '.';
  }
  function limitar() {
    pos.x = Math.max(S.minX, Math.min(S.folhaW - S.w - 2, pos.x));
    pos.y = Math.max(2, Math.min(S.folhaH - S.h - 2, pos.y));
  }
  aplicar();
  setTimeout(function () { selo.scrollIntoView({ block: 'center' }); }, 200);

  var pega = null, rolar = 0, ultimo = null;
  function mm(f, cx, cy) { var r = f.getBoundingClientRect(); return [(cx - r.left) / r.width * S.folhaW, (cy - r.top) / r.height * S.folhaH]; }
  function folhaSob(cx, cy) {
    var els = document.elementsFromPoint ? document.elementsFromPoint(cx, cy) : [];
    for (var i = 0; i < els.length; i++) if (els[i].classList && els[i].classList.contains('folha')) return els[i];
    return null;
  }
  function mover(cx, cy) {
    var f = folhaSob(cx, cy);
    if (!f) return;
    var p = mm(f, cx, cy);
    pos.pagina = +f.getAttribute('data-pagina'); pos.x = p[0] - pega[0]; pos.y = p[1] - pega[1];
    limitar(); aplicar();
  }
  function loop() { if (!pega) return; if (rolar) { window.scrollBy(0, rolar); if (ultimo) mover(ultimo[0], ultimo[1]); } requestAnimationFrame(loop); }
  // o selo muda de folha durante o arraste: o acompanhamento é feito na janela
  function seguir(e) {
    if (!pega || e.pointerId !== pega[2]) return;
    ultimo = [e.clientX, e.clientY];
    rolar = e.clientY < 70 ? -10 : (e.clientY > window.innerHeight - 170 ? 10 : 0);
    mover(e.clientX, e.clientY); e.preventDefault();
  }
  function soltar(e) {
    if (!pega || e.pointerId !== pega[2]) return;
    pega = null; rolar = 0; ultimo = null; selo.classList.remove('-arrastando');
    window.removeEventListener('pointermove', seguir); window.removeEventListener('pointerup', soltar); window.removeEventListener('pointercancel', soltar);
  }
  selo.addEventListener('pointerdown', function (e) {
    if (pega) return;
    var p = mm(selo.parentNode, e.clientX, e.clientY);
    pega = [p[0] - pos.x, p[1] - pos.y, e.pointerId];
    selo.classList.add('-arrastando');
    window.addEventListener('pointermove', seguir, { passive: false }); window.addEventListener('pointerup', soltar); window.addEventListener('pointercancel', soltar);
    e.preventDefault(); requestAnimationFrame(loop);
  });
  selo.addEventListener('keydown', function (e) {
    var d = e.shiftKey ? 5 : 1, ok = true;
    if (e.key === 'ArrowLeft') pos.x -= d; else if (e.key === 'ArrowRight') pos.x += d;
    else if (e.key === 'ArrowUp') pos.y -= d; else if (e.key === 'ArrowDown') pos.y += d;
    else if (e.key === 'PageDown' && pos.pagina < dados.paginas) pos.pagina++;
    else if (e.key === 'PageUp' && pos.pagina > 1) pos.pagina--;
    else ok = false;
    if (!ok) return;
    e.preventDefault(); limitar(); aplicar();
    if (e.key.indexOf('Page') === 0) selo.scrollIntoView({ block: 'center' });
  });
  // cada formulário de assinatura leva a posição do selo
  Array.prototype.forEach.call(document.querySelectorAll('[data-form-selo]'), function (f) {
    f.addEventListener('submit', function () {
      var r = folha(pos.pagina).getBoundingClientRect();
      f.querySelector('[data-selo-campo]').value = JSON.stringify({ pagina: pos.pagina, x: Math.round(pos.x * 100) / 100, y: Math.round(pos.y * 100) / 100,
        largura_folha_px: Math.round(r.width), zoom: window.visualViewport ? window.visualViewport.scale : 1, dpr: window.devicePixelRatio || 1,
        orientacao: screen.orientation ? screen.orientation.angle : 0, tela: window.innerWidth + 'x' + window.innerHeight });
    });
  });
})();
