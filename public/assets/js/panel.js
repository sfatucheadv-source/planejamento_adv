/* Painel da equipe — interações */
(function () {
  'use strict';

  var side = document.querySelector('[data-side]');
  var toggle = document.querySelector('[data-side-toggle]');
  if (side && toggle) {
    toggle.addEventListener('click', function () { side.classList.toggle('is-open'); });
  }

  // Confirmações em ações sensíveis
  document.addEventListener('click', function (ev) {
    var el = ev.target.closest('[data-confirm]');
    if (el && !window.confirm(el.getAttribute('data-confirm'))) ev.preventDefault();
  });

  // Link do WhatsApp acompanha o texto editado
  var msg = document.querySelector('[data-msg]');
  var wa = document.querySelector('[data-wa-link]');
  if (msg && wa) {
    var phone = wa.getAttribute('data-phone') || '';
    if (phone.length <= 11) phone = '55' + phone;
    var sync = function () { wa.href = 'https://wa.me/' + phone + '?text=' + encodeURIComponent(msg.value); };
    msg.addEventListener('input', sync);
    sync();
  }

  // Calculadora da auditoria (espelha app/lib/cases.php → audit_calc)
  var out = document.querySelector('[data-calc-out]');
  if (out) {
    var num = function (k) {
      var el = document.querySelector('[data-k="' + k + '"]');
      if (!el) return null;
      var s = (el.value || '').replace(/[^\d,.-]/g, '');
      if (!s) return null;
      if (s.indexOf(',') !== -1) s = s.replace(/\./g, '').replace(',', '.');
      var n = parseFloat(s);
      return isNaN(n) ? null : n;
    };
    var money = function (v) { return v === null || !isFinite(v) ? '—' : 'R$ ' + v.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
    var pct = function (v, d) { return v === null || !isFinite(v) ? '—' : v.toLocaleString('pt-BR', { minimumFractionDigits: d || 2, maximumFractionDigits: d || 2 }) + '%'; };
    var annual = function (m) { return (Math.pow(1 + m / 100, 12) - 1) * 100; };
    var pmt = function (p, m, n) { var i = m / 100; return i === 0 ? p / n : p * i / (1 - Math.pow(1 + i, -n)); };
    var balance = function (p, m, n, k) { var i = m / 100, a = pmt(p, m, n); if (i === 0) return Math.max(0, p - a * k); return Math.max(0, p * Math.pow(1 + i, k) - a * ((Math.pow(1 + i, k) - 1) / i)); };
    var implied = function (p, a, n) {
      if (!(p > 0 && a > 0 && n > 0) || a * n < p) return null;
      var i = 0.02;
      for (var k = 0; k < 100; k++) {
        var f = pmt(p, i * 100, n) - a;
        var d = (pmt(p, (i + 1e-7) * 100, n) - pmt(p, i * 100, n)) / 1e-7;
        if (Math.abs(d) < 1e-12) break;
        var nx = i - f / d;
        if (Math.abs(nx - i) < 1e-10) { i = nx; break; }
        i = Math.max(1e-9, nx);
      }
      return i * 100;
    };
    var set = function (k, v) { var el = out.querySelector('[data-o="' + k + '"]'); if (el) el.textContent = v; };
    var run = function () {
      var p = num('principal'), n = num('n'), a = num('pmt'), im = num('im'), b = num('bcb'), paid = num('paid'), down = num('down');
      var imp = (p && a && n) ? implied(p, a, n) : null;
      var rate = im !== null ? im : imp;
      set('annual', im !== null ? pct(annual(im)) : '—');
      set('implied', imp !== null ? pct(imp, 3) : '—');
      var pc = (p && im !== null && n) ? pmt(p, im, n) : null;
      set('pmtc', money(pc));
      set('repro', pc === null || !a ? '—' : (Math.abs(pc - a) <= Math.max(1, a * 0.005) ? 'Sim' : 'Não: conferir dados antes de propor alterações'));
      set('bcba', b !== null ? pct(annual(b)) : '—');
      set('ratio', rate !== null && b ? (rate / b).toLocaleString('pt-BR', { maximumFractionDigits: 2, minimumFractionDigits: 2 }) + '×' : '—');
      var pb = (p && b !== null && n) ? pmt(p, b, n) : null;
      set('pmtb', money(pb));
      var diff = (pb !== null && a) ? a - pb : null;
      set('diff', money(diff));
      set('diffpaid', diff !== null && paid !== null ? money(diff * paid) : '—');
      set('balc', p && rate !== null && n && paid !== null ? money(balance(p, rate, n, Math.min(paid, n))) : '—');
      set('balb', p && b !== null && n && paid !== null ? money(balance(p, b, n, Math.min(paid, n))) : '—');
      var c = 0; ['c1', 'c2', 'c3', 'c4', 'c5'].forEach(function (k) { c += num(k) || 0; });
      set('charges', c ? money(c) + (p ? ' (' + pct(c / p * 100, 1) + ' do principal)' : '') : '—');
      set('total', a && n ? money(a * n + (down || 0)) : '—');
    };
    document.querySelectorAll('[data-calc] input').forEach(function (el) { el.addEventListener('input', run); });
  }

  // Modelo de pergunta: ao escolher "oferta", ajusta o texto padrão
  var kind = document.querySelector('[data-preset-kind]');
  if (kind) {
    var form = kind.closest('form');
    var q = form.querySelector('[name=question]');
    var o = form.querySelector('[name=options]');
    var presets = {
      caminho: ['Qual caminho você prefere seguir?', o.value],
      oferta: ['O banco fez uma proposta de acordo. Você autoriza aceitar?', 'Autorizo aceitar a proposta\nNão aceito; tentem uma contraproposta'],
      acordo: ['Você confirma o texto final do acordo, o valor e o calendário de pagamentos?', 'Confirmo o acordo\nPreciso de ajustes'],
      acao: ['Você autoriza ajuizar a ação, ciente dos custos e riscos explicados?', 'Autorizo ajuizar a ação\nPrefiro não ajuizar agora'],
      recurso: ['Você quer recorrer da decisão, considerando custo, risco e utilidade explicados?', 'Quero recorrer\nNão quero recorrer'],
      encerramento: ['Você confirma que recebeu a prestação de contas e os comprovantes?', 'Confirmo\nTenho dúvidas'],
      outro: ['', '']
    };
    kind.addEventListener('change', function () {
      var p = presets[kind.value];
      if (p) { q.value = p[0]; o.value = p[1]; }
    });
  }
})();
