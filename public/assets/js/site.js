/* Sidney Fatuche Advocacia — interações do site público */
(function () {
  'use strict';
  document.documentElement.classList.add('js');

  /* Menu móvel e submenu */
  var toggle = document.querySelector('[data-menu-toggle]');
  var menu = document.getElementById('menu');
  if (toggle && menu) {
    toggle.addEventListener('click', function () {
      var open = menu.classList.toggle('is-open');
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  }
  document.querySelectorAll('[data-sub-toggle]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var group = btn.closest('.nav-group');
      var open = group.classList.toggle('is-open');
      btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  });

  /* Copiar link */
  document.querySelectorAll('[data-copy]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var text = btn.getAttribute('data-copy');
      var done = function () { var t = btn.textContent; btn.textContent = 'Link copiado'; setTimeout(function () { btn.textContent = t; }, 1800); };
      if (navigator.clipboard) { navigator.clipboard.writeText(text).then(done); } else { window.prompt('Copie o link:', text); }
    });
  });

  /* Filtro do blog */
  document.querySelectorAll('[data-filter]').forEach(function (input) {
    var items = document.querySelectorAll(input.getAttribute('data-filter'));
    var empty = document.querySelector('[data-filter-empty]');
    var norm = function (s) { return s.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, ''); };
    input.addEventListener('input', function () {
      var q = norm(input.value.trim());
      var shown = 0;
      items.forEach(function (el) {
        var ok = !q || norm(el.textContent).indexOf(q) !== -1;
        el.hidden = !ok;
        if (ok) shown++;
      });
      if (empty) empty.hidden = shown !== 0;
    });
  });

  /* Máscaras leves */
  document.querySelectorAll('[data-money]').forEach(function (el) {
    el.addEventListener('blur', function () {
      var v = el.value.replace(/[^\d,.]/g, '');
      if (!v) { el.value = ''; return; }
      if (v.indexOf(',') !== -1) v = v.replace(/\./g, '').replace(',', '.');
      var n = parseFloat(v);
      if (!isNaN(n)) el.value = 'R$ ' + n.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    });
  });
  document.querySelectorAll('[data-phone]').forEach(function (el) {
    el.addEventListener('input', function () {
      var d = el.value.replace(/\D/g, '').slice(0, 13);
      if (d.length > 11 && d.indexOf('55') === 0) d = d.slice(2);
      var out = d;
      if (d.length > 2) out = '(' + d.slice(0, 2) + ') ' + d.slice(2);
      if (d.length > 7) out = '(' + d.slice(0, 2) + ') ' + d.slice(2, d.length - 4) + '-' + d.slice(-4);
      el.value = out;
    });
  });

  /* Mostrar blocos condicionais: data-show-if="campo:valor1,valor2" */
  function syncReveals(scope) {
    scope.querySelectorAll('[data-show-if]').forEach(function (el) {
      var parts = el.getAttribute('data-show-if').split(':');
      var name = parts[0];
      var values = parts[1].split(',');
      var checked = scope.querySelector('input[name="' + name + '"]:checked');
      var show = !!checked && values.indexOf(checked.value) !== -1;
      el.classList.toggle('is-shown', show);
    });
  }

  /* Triagem em etapas */
  var form = document.querySelector('[data-triage]');
  if (!form) return;

  var steps = Array.prototype.slice.call(form.querySelectorAll('[data-step]'));
  var prev = form.querySelector('[data-prev]');
  var next = form.querySelector('[data-next]');
  var submit = form.querySelector('[data-submit]');
  var bar = form.querySelector('[data-progress-bar]');
  var count = form.querySelector('[data-step-count]');
  var sentences = JSON.parse(form.getAttribute('data-sentences') || '{}');
  var current = 0;

  function val(name) {
    var el = form.querySelector('[name="' + name + '"]:checked') || form.querySelector('[name="' + name + '"]:not([type=radio])');
    return el ? el.value : '';
  }

  function parseMoney(s) {
    s = (s || '').replace(/[^\d,.]/g, '');
    if (!s) return 0;
    if (s.indexOf(',') !== -1) s = s.replace(/\./g, '').replace(',', '.');
    return parseFloat(s) || 0;
  }

  function summary() {
    var prio = val('priority') || 'outro';
    var s = 'Sua prioridade é ' + (sentences[prio] || 'resolver sua situação');
    var budget = parseMoney(val('budget_installment'));
    if (budget > 0 && ['manter_veiculo', 'reduzir_parcela', 'quitar', 'apreensao'].indexOf(prio) !== -1) {
      s += ' com uma parcela de até R$ ' + budget.toLocaleString('pt-BR', { minimumFractionDigits: 2 }) + ' sem apertar suas despesas essenciais';
    } else if (prio === 'manter_veiculo') {
      s += ' e encontrar uma prestação que consiga pagar';
    }
    var notice = val('notice');
    var map = { apreendido: 'uma apreensão', justica: 'um documento da Justiça', cartorio: 'uma notificação de cartório' };
    if (map[notice]) s += '. Como há ' + map[notice] + ', o advogado vai olhar o seu caso primeiro';
    return s + '. Correto?';
  }

  function validStep(i) {
    var step = steps[i];
    var ok = true;
    step.querySelectorAll('[required]').forEach(function (el) {
      if (el.type === 'radio') {
        if (!step.querySelector('input[name="' + el.name + '"]:checked')) ok = false;
      } else if (el.type === 'checkbox') {
        if (!el.checked) ok = false;
      } else if (!el.value.trim()) {
        ok = false;
      }
    });
    if (ok && step.querySelector('[name=phone]')) {
      var d = val('phone').replace(/\D/g, '');
      if (d.length < 10) ok = false;
    }
    var msg = step.querySelector('.js-step-error');
    if (!ok) {
      if (!msg) {
        msg = document.createElement('p');
        msg.className = 'field-error js-step-error';
        msg.setAttribute('role', 'alert');
        step.appendChild(msg);
      }
      msg.textContent = step.querySelector('[name=consent]') ? 'Preencha nome, telefone com DDD e a autorização para continuar.' : 'Escolha uma opção para continuar. Se não souber, “não sei” também vale.';
    } else if (msg) {
      msg.remove();
    }
    return ok;
  }

  function show(i) {
    current = Math.max(0, Math.min(i, steps.length - 1));
    steps.forEach(function (s, k) { s.classList.toggle('is-current', k === current); });
    prev.style.visibility = current === 0 ? 'hidden' : 'visible';
    var last = current === steps.length - 1;
    next.hidden = last;
    submit.hidden = !last;
    if (bar) bar.style.width = ((current + 1) / steps.length * 100) + '%';
    if (count) count.textContent = 'Passo ' + (current + 1) + ' de ' + steps.length;
    if (steps[current].hasAttribute('data-summary-step')) {
      form.querySelector('[data-summary]').textContent = summary();
      form.querySelector('[data-summary-choice]').hidden = false;
    }
  }

  function focusStep() {
    var legend = steps[current].querySelector('legend');
    var top = form.getBoundingClientRect().top + window.scrollY - 90;
    if (window.scrollY > top) window.scrollTo({ top: top, behavior: 'smooth' });
    if (legend) { legend.setAttribute('tabindex', '-1'); legend.focus({ preventScroll: true }); }
  }

  next.addEventListener('click', function () {
    if (!validStep(current)) return;
    show(current + 1);
    focusStep();
  });
  prev.addEventListener('click', function () { show(current - 1); focusStep(); });

  form.addEventListener('change', function () { syncReveals(form); });
  form.addEventListener('submit', function (ev) {
    for (var i = 0; i < steps.length; i++) {
      if (!validStep(i)) { ev.preventDefault(); show(i); focusStep(); return; }
    }
    if (!form.querySelector('input[name=summary_ok]:checked')) {
      var yes = form.querySelector('input[name=summary_ok][value="1"]');
      if (yes) yes.checked = true;
    }
    submit.disabled = true;
    submit.textContent = 'Enviando…';
  });

  // Respostas de urgência avançam sozinhas para manter o ritmo da conversa.
  steps[0].querySelectorAll('input[name=notice]').forEach(function (r) {
    r.addEventListener('change', function () { syncReveals(form); });
  });

  // Com erros vindos do servidor, abre no primeiro passo com problema.
  var start = 0;
  if (form.getAttribute('data-has-errors') === '1') {
    for (var k = 0; k < steps.length; k++) {
      if (steps[k].querySelector('.field-error')) { start = k; break; }
    }
  } else if (form.querySelector('input[name=notice]:checked')) {
    start = 0;
  }
  syncReveals(form);
  show(start);
})();
