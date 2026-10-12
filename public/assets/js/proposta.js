/* Proposta: simulação e prévia das cláusulas refeitas a cada alteração.
   O texto da cláusula acompanha os valores até ser ajustado à mão. */
(function () {
  'use strict';
  var form = document.querySelector('[data-proposta]');
  if (!form || !window.fetch || !window.FormData) return;
  var url = form.getAttribute('data-previa');
  var sim = form.querySelector('[data-sim]');
  var vendas = form.querySelector('[data-vendas]');
  var kpis = document.querySelector('[data-kpis]');
  var erros = form.querySelector('[data-erros]');
  var timer = null;
  var seq = 0;
  var norm = function (s) { return (s || '').replace(/\r\n?/g, '\n').replace(/[ \t]+\n/g, '\n').trim(); };

  var boxes = Array.prototype.slice.call(form.querySelectorAll('[data-texto]')).map(function (box) {
    var ta = box.querySelector('textarea');
    // ref: texto automático do momento do ajuste (avisa se os valores mudaram depois).
    return { box: box, ta: ta, base: box.querySelector('[data-base]'), manual: box.querySelector('[data-manual]'), ref: box.querySelector('[data-ref]'), key: box.getAttribute('data-texto') };
  });

  function setManual(o, on) {
    o.manual.value = on ? '1' : '0';
    o.box.classList.toggle('is-manual', on);
    if (!on) { o.box.classList.remove('is-velho'); o.ref.value = ''; }
  }

  function refresh() {
    var my = ++seq;
    fetch(url, { method: 'POST', body: new FormData(form), credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (j) {
        if (!j || my !== seq) return;
        sim.innerHTML = j.simulacao;
        if (vendas && j.vendas) vendas.innerHTML = j.vendas;
        if (kpis && j.kpis) kpis.innerHTML = j.kpis;
        if (erros) erros.textContent = j.erros && j.erros.length ? j.erros.join(' ') : '';
        boxes.forEach(function (o) {
          var auto = j.auto[o.key] || '';
          o.ta.setAttribute('data-auto', auto);
          if (o.manual.value !== '1') {
            o.ta.value = auto;
            o.base.value = auto;
          } else {
            o.box.classList.toggle('is-velho', norm(auto) !== norm(o.ref.value));
          }
          var c = j.clausulas && j.clausulas[o.key];
          if (c) {
            o.box.querySelector('[data-antes]').innerHTML = c.antes;
            o.box.querySelector('[data-depois]').innerHTML = c.depois;
          }
        });
      })
      .catch(function () {});
  }

  function later() { clearTimeout(timer); timer = setTimeout(refresh, 350); }

  form.addEventListener('input', function (ev) {
    var o = boxes.filter(function (b) { return b.ta === ev.target; })[0];
    if (o) {
      if (o.manual.value !== '1') { o.ref.value = o.ta.getAttribute('data-auto'); setManual(o, true); }
      return;
    }
    later();
  });
  form.addEventListener('change', function (ev) {
    if (!ev.target.closest('[data-texto]')) later();
  });
  // Leva uma condição (do simulador ou da avaliação da IA) para os campos da proposta.
  function setVal(name, v) { var el = form.querySelector('[name="' + name + '"]'); if (el && v !== undefined && v !== null) el.value = v; }
  function aplicar(o) {
    if (o.valor) setVal('fixos_valor', o.valor);
    var radio = form.querySelector('input[name="pagamento_tipo"][value="' + o.tipo + '"]');
    if (radio) radio.checked = true;
    if (o.tipo !== 'avista' && o.parcelas) setVal('parcelas', o.parcelas);
    if (o.entrada !== undefined) setVal('entrada', o.entrada);
    if (o.forma) { var cb = form.querySelector('input[name="formas[]"][value="' + o.forma + '"]'); if (cb) cb.checked = true; }
    if (o.tipo === 'proprio') {
      var pv = form.querySelector('[name="primeiro_vencimento"]');
      if (pv && !pv.value) { var dt = new Date(); dt.setDate(dt.getDate() + 30); pv.value = dt.toISOString().slice(0, 10); }
    }
    radio && radio.dispatchEvent(new Event('change', { bubbles: true }));
    refresh();
    var top = form.querySelector('.prop-campos');
    if (top && top.scrollIntoView) top.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }
  document.addEventListener('click', function (ev) {
    var u = ev.target.closest('[data-usar-tipo]');
    if (u) { aplicar({ tipo: u.getAttribute('data-usar-tipo'), parcelas: u.getAttribute('data-usar-parcelas'), forma: u.getAttribute('data-usar-forma') }); return; }
    var ia = ev.target.closest('[data-aplicar-ia]');
    if (ia) { aplicar({ valor: ia.getAttribute('data-valor'), tipo: ia.getAttribute('data-tipo'), parcelas: ia.getAttribute('data-parcelas'), entrada: ia.getAttribute('data-entrada'), forma: ia.getAttribute('data-forma') }); return; }
    var as = ev.target.closest('[data-asaas-sim]');
    if (as) {
      var res = form.querySelector('[data-asaas-res]');
      var fd = new FormData();
      var tok = form.querySelector('[name="_csrf"]');
      fd.append('_csrf', tok ? tok.value : '');
      var sv = form.querySelector('[name="sim_valor"]');
      fd.append('valor', (sv && sv.value) || (sv && sv.getAttribute('placeholder')) || '');
      var tipo = form.querySelector('input[name="pagamento_tipo"]:checked');
      fd.append('parcelas', tipo && tipo.value !== 'avista' ? (form.querySelector('[name="parcelas"]') || {}).value || 1 : 1);
      res.textContent = 'Consultando o Asaas…';
      as.disabled = true;
      fetch(as.getAttribute('data-asaas-sim'), { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
        .then(function (r) { return r.json(); })
        .then(function (j) { res.innerHTML = j.html || ''; })
        .catch(function () { res.textContent = 'Não foi possível consultar o Asaas agora.'; })
        .then(function () { as.disabled = false; });
    }
  });
  var guia = document.getElementById('guia');
  if (guia) guia.addEventListener('submit', function (ev) {
    if (ev.submitter && ev.submitter.hasAttribute('data-avaliar')) {
      var msg = guia.querySelector('[data-avaliando]');
      if (msg) msg.hidden = false;
      setTimeout(function () { guia.querySelectorAll('button').forEach(function (b) { b.disabled = true; }); }, 0);
    }
  });

  form.addEventListener('click', function (ev) {
    var btn = ev.target.closest('[data-restaurar]');
    if (!btn) return;
    var o = boxes.filter(function (b) { return b.box.contains(btn); })[0];
    setManual(o, false);
    o.ta.value = o.ta.getAttribute('data-auto');
    o.base.value = o.ta.value;
    refresh();
  });
})();
