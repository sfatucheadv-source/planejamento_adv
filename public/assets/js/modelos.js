/* Modelos de documentos: barra de diagramação e prévia no timbrado refeita a
   cada alteração (o texto em edição vai para a simulação; nada é salvo). */
(function () {
  'use strict';
  var root = document.querySelector('[data-mdl]');
  if (!root) return;
  var ta = root.querySelector('[data-mdl-body]');
  var sim = root.querySelector('[data-mdl-sim]');
  var simBody = root.querySelector('[data-mdl-sim-body]');
  var frame = root.querySelector('[data-mdl-frame]');
  var barra = root.querySelector('[data-mdl-barra]');
  var estado = root.querySelector('[data-mdl-estado]');
  var zoomIn = root.querySelector('[data-mdl-zoom]');
  var zoomBtns = root.querySelector('[data-mdl-zoom-btns]');
  var original = ta.value;
  var timer = null;
  var scrollY = 0;

  function atualizar() {
    simBody.value = ta.value;
    try { scrollY = frame.contentWindow.scrollY || 0; } catch (e) { scrollY = 0; }
    sim.submit();
  }
  function depois() { clearTimeout(timer); timer = setTimeout(atualizar, 500); }
  frame.addEventListener('load', function () {
    try { if (scrollY) frame.contentWindow.scrollTo(0, scrollY); } catch (e) {}
  });

  atualizar(); // a prévia já abre com o atendimento escolhido

  ta.addEventListener('input', function () {
    if (estado) estado.textContent = ta.value !== original ? 'Alterações não salvas: a prévia já mostra o texto novo.' : '';
    depois();
  });
  sim.addEventListener('input', depois);
  window.addEventListener('beforeunload', function (ev) {
    if (ta.value !== original && !ta.form.dataset.enviando) { ev.preventDefault(); ev.returnValue = ''; }
  });
  ta.form.addEventListener('submit', function () { ta.form.dataset.enviando = '1'; });

  var caso = root.querySelector('[data-mdl-caso] select');
  if (caso) caso.addEventListener('change', function () {
    if (ta.value !== original && !confirm('Trocar o atendimento recarrega a página e descarta o texto não salvo. Continuar?')) return;
    ta.form.dataset.enviando = '1';
    caso.form.submit();
  });

  if (zoomBtns) {
    zoomBtns.hidden = false;
    var label = zoomBtns.querySelector('[data-zoom-label]');
    zoomBtns.addEventListener('click', function (ev) {
      var b = ev.target.closest('[data-zoom]');
      if (!b) return;
      var z = Math.max(0.4, Math.min(1.2, Math.round((parseFloat(zoomIn.value) + parseFloat(b.getAttribute('data-zoom'))) * 10) / 10));
      zoomIn.value = String(z);
      label.textContent = Math.round(z * 100) + '%';
      atualizar();
    });
  }

  /* ---------- Barra de diagramação ---------- */
  function substituir(texto, selIni, selFim) {
    var a = ta.selectionStart, b = ta.selectionEnd;
    ta.focus();
    ta.setRangeText(texto, a, b, 'end');
    if (selIni !== undefined) ta.setSelectionRange(a + selIni, a + selFim);
    ta.dispatchEvent(new Event('input', { bubbles: true }));
  }
  // Bloco: entra em parágrafo próprio (linha em branco antes e depois).
  function bloco(texto) {
    var v = ta.value, a = ta.selectionStart;
    var antes = v.slice(0, a), depois = v.slice(ta.selectionEnd);
    var pre = antes === '' || /\n\n$/.test(antes) ? '' : (/\n$/.test(antes) ? '\n' : '\n\n');
    var pos = depois === '' || /^\n\n/.test(depois) ? '' : (/^\n/.test(depois) ? '\n' : '\n\n');
    substituir(pre + texto + pos, pre.length, pre.length + texto.length);
  }
  function envolver(antes, depois, exemplo) {
    var sel = ta.value.slice(ta.selectionStart, ta.selectionEnd) || exemplo || 'texto';
    substituir(antes + sel + depois, antes.length, antes.length + sel.length);
  }
  if (barra) {
    barra.hidden = false;
    barra.addEventListener('mousedown', function (ev) { if (ev.target.closest('button')) ev.preventDefault(); });
    barra.addEventListener('click', function (ev) {
      var b = ev.target.closest('[data-wrap]');
      if (!b) return;
      var p = b.getAttribute('data-wrap').split('|');
      envolver(p[0], p[1], b.getAttribute('data-exemplo'));
    });
    barra.addEventListener('change', function (ev) {
      var s = ev.target;
      var v = s.value;
      if (!v) return;
      if (s.hasAttribute('data-ins-bloco')) {
        bloco(v);
      } else if (s.hasAttribute('data-ins-estilo')) {
        envolver('{|' + s.getAttribute('data-ins-estilo') + '=' + v + '|}', '{|/|}');
      } else if (s.hasAttribute('data-ins-var')) {
        substituir('{{' + v + '}}');
      } else if (s.hasAttribute('data-ins-se')) {
        var sel = ta.value.slice(ta.selectionStart, ta.selectionEnd) || 'Trecho que só entra quando a condição vale.';
        bloco('@se ' + v + '\n' + sel + '\n@fimse');
      }
      s.value = '';
    });
  }
})();
