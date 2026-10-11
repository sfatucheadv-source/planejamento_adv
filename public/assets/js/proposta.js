/* Proposta: simulação e prévia das cláusulas refeitas a cada alteração.
   O texto da cláusula acompanha os valores até ser ajustado à mão. */
(function () {
  'use strict';
  var form = document.querySelector('[data-proposta]');
  if (!form || !window.fetch || !window.FormData) return;
  var url = form.getAttribute('data-previa');
  var sim = form.querySelector('[data-sim]');
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
