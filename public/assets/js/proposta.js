/* Proposta: abas, simulação e prévia das cláusulas refeitas a cada alteração,
   e editor do texto das cláusulas (aba Contrato). O texto acompanha os valores
   até ser ajustado à mão. O editor grava a marcação do documento no campo
   texto_*: {|b i u m f=poppins t=14|}trecho{|/|}, "@destaque " e "- item". */
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
  var each = function (list, fn) { Array.prototype.forEach.call(list, fn); };

  /* ---------- Abas ---------- */
  var nav = document.querySelector('[data-abas]');
  var ordem = ['produto', 'simulacao', 'contrato'];
  var campoAba = form.querySelector('[data-aba-campo]');
  var prox = form.querySelector('[data-aba-prox]');
  var atual = 'produto';
  function abrir(nome, foco) {
    if (ordem.indexOf(nome) < 0) nome = 'produto';
    atual = nome;
    each(document.querySelectorAll('[data-aba], [data-aba-extra]'), function (p) {
      p.classList.toggle('is-on', (p.getAttribute('data-aba') || p.getAttribute('data-aba-extra')) === nome);
    });
    each(nav.querySelectorAll('[data-aba-btn]'), function (b) {
      var on = b.getAttribute('data-aba-btn') === nome;
      b.setAttribute('aria-selected', on ? 'true' : 'false');
      b.tabIndex = on ? 0 : -1;
      if (on && foco) b.focus();
    });
    campoAba.value = nome;
    var i = ordem.indexOf(nome);
    if (prox) {
      prox.hidden = i === ordem.length - 1;
      var nx = nav.querySelector('[data-aba-btn="' + ordem[i + 1] + '"]');
      if (nx) prox.textContent = 'Próximo: ' + nx.textContent.replace(/^\d+/, '').trim() + ' ›';
    }
    try { sessionStorage.setItem('proposta-aba', nome); } catch (e) {}
  }
  function abaDoAlvo(id) {
    var el = id && document.getElementById(id);
    if (!el) return null;
    var p = el.closest('[data-aba], [data-aba-extra]');
    return p ? (p.getAttribute('data-aba') || p.getAttribute('data-aba-extra')) : null;
  }
  if (nav) {
    nav.hidden = false;
    document.body.classList.add('has-abas');
    var inicial = abaDoAlvo(location.hash.slice(1));
    if (!inicial) { try { inicial = sessionStorage.getItem('proposta-aba'); } catch (e) {} }
    abrir(inicial || 'produto');
    var alvo = location.hash && document.getElementById(location.hash.slice(1));
    if (alvo && ordem.indexOf(location.hash.slice(1)) < 0) setTimeout(function () { alvo.scrollIntoView({ block: 'start' }); }, 0);
    nav.addEventListener('click', function (ev) {
      var b = ev.target.closest('[data-aba-btn]');
      if (b) { abrir(b.getAttribute('data-aba-btn')); history.replaceState(null, '', '#' + atual); }
    });
    nav.addEventListener('keydown', function (ev) {
      var i = ordem.indexOf(atual);
      if (ev.key === 'ArrowRight' || ev.key === 'ArrowLeft') {
        ev.preventDefault();
        abrir(ordem[(i + (ev.key === 'ArrowRight' ? 1 : ordem.length - 1)) % ordem.length], true);
      }
    });
    if (prox) prox.addEventListener('click', function () {
      abrir(ordem[Math.min(ordem.indexOf(atual) + 1, ordem.length - 1)]);
      history.replaceState(null, '', '#' + atual);
      nav.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });
    // Campo obrigatório em outra aba: mostra a aba antes do aviso do navegador.
    form.addEventListener('invalid', function (ev) {
      var p = ev.target.closest('[data-aba]');
      if (p && !p.classList.contains('is-on')) abrir(p.getAttribute('data-aba'));
    }, true);
  }

  /* ---------- Editor das cláusulas ---------- */
  var FONTES = ['lora', 'poppins', 'arial', 'times'];
  var TAMANHOS = [9, 10, 11, 12, 13, 14, 16, 18, 20, 24];
  var barra = form.querySelector('[data-ed-barra]');
  var edAtivo = null;
  var faixa = null; // última seleção dentro de um editor

  function estilo(el, st) {
    var n = { b: st.b, i: st.i, u: st.u, m: st.m, f: st.f, t: st.t };
    var tag = el.nodeName;
    if (tag === 'B' || tag === 'STRONG') n.b = true;
    if (tag === 'I' || tag === 'EM') n.i = true;
    if (tag === 'U') n.u = true;
    if (tag === 'MARK' && el.classList.contains('marca')) n.m = true;
    var cs = el.style;
    if (cs) {
      if (cs.fontWeight === 'bold' || parseInt(cs.fontWeight, 10) >= 600) n.b = true;
      if (cs.fontWeight === 'normal') n.b = false;
      if (cs.fontStyle === 'italic') n.i = true;
      if ((cs.textDecorationLine || cs.textDecoration || '').indexOf('underline') >= 0) n.u = true;
    }
    if (el.hasAttribute('data-m')) n.m = el.getAttribute('data-m') === '1';
    if (el.hasAttribute('data-f')) { var f = el.getAttribute('data-f'); n.f = FONTES.indexOf(f) > 0 ? f : ''; }
    if (el.hasAttribute('data-t')) { var t = parseInt(el.getAttribute('data-t'), 10); n.t = TAMANHOS.indexOf(t) >= 0 && t !== 12 ? t : 0; }
    return n;
  }
  function chave(st) {
    var a = [];
    if (st.b) a.push('b');
    if (st.i) a.push('i');
    if (st.u) a.push('u');
    if (st.m) a.push('m');
    if (st.f) a.push('f=' + st.f);
    if (st.t) a.push('t=' + st.t);
    return a.join(' ');
  }
  function trechos(nodes, st, out) {
    each(nodes, function (c) {
      if (c.nodeType === 3) {
        var t = c.nodeValue.replace(/ /g, ' ').replace(/[\r\n]+/g, ' ');
        if (t) out.push({ t: t, k: chave(st) });
      } else if (c.nodeName === 'BR') {
        out.push({ t: '\n', k: '' });
      } else if (c.nodeType === 1 && /^(UL|OL)$/.test(c.nodeName)) {
        each(c.querySelectorAll('li'), function (li) { out.push({ t: '\n- ', k: '' }); trechos(li.childNodes, st, out); });
      } else if (c.nodeType === 1) {
        trechos(c.childNodes, c.matches('mark.pendente') ? st : estilo(c, st), out);
      }
    });
    return out;
  }
  function marcacao(runs) {
    var m = [];
    runs.forEach(function (r) {
      var last = m[m.length - 1];
      if (last && last.k === r.k) last.t += r.t; else m.push({ t: r.t, k: r.k });
    });
    return m.map(function (r) { return r.k && r.t.trim() ? '{|' + r.k + '|}' + r.t + '{|/|}' : r.t; }).join('')
      .replace(/[ \t]+\n/g, '\n').replace(/^\n+|\n+$/g, '');
  }
  function serializar(ed) {
    var blocos = [];
    var solto = [];
    function fecha() {
      var t = marcacao(solto);
      if (t.trim()) blocos.push(t);
      solto = [];
    }
    each(ed.childNodes, function (c) {
      if (c.nodeType === 1 && /^(UL|OL)$/.test(c.nodeName)) {
        fecha();
        var itens = [];
        each(c.children, function (li) {
          var t = marcacao(trechos(li.childNodes, {}, [])).replace(/\n+/g, ' ').trim();
          if (t) itens.push('- ' + t);
        });
        if (itens.length) blocos.push(itens.join('\n'));
      } else if (c.nodeType === 1 && /^(P|DIV|H[1-6]|BLOCKQUOTE|LI)$/.test(c.nodeName)) {
        fecha();
        var t = marcacao(trechos(c.childNodes, {}, []));
        if (t.trim()) blocos.push((c.getAttribute('data-bloco') === 'destaque' ? '@destaque ' : '') + t);
      } else {
        trechos([c], {}, solto);
      }
    });
    fecha();
    return blocos.join('\n\n');
  }

  function noEditor(node) {
    var el = node && (node.nodeType === 1 ? node : node.parentNode);
    return el && el.closest ? el.closest('[data-ed]') : null;
  }
  function selecaoNoEditor() {
    var s = window.getSelection();
    if (!s || !s.rangeCount) return null;
    var ed = noEditor(s.anchorNode);
    return ed && ed === noEditor(s.focusNode) ? ed : null;
  }
  function restaura() {
    if (!edAtivo) return false;
    edAtivo.focus();
    if (faixa) {
      var s = window.getSelection();
      s.removeAllRanges();
      s.addRange(faixa);
    }
    return true;
  }
  function valorAtual(attr) {
    var s = window.getSelection();
    var el = s && s.anchorNode;
    el = el && (el.nodeType === 1 ? el : el.parentNode);
    while (el && !el.hasAttribute('data-ed')) {
      if (el.hasAttribute('data-' + attr)) return el.getAttribute('data-' + attr);
      if (attr === 'm' && el.nodeName === 'MARK' && el.classList.contains('marca')) return '1';
      el = el.parentNode;
    }
    return '';
  }
  // Envolve a seleção num marcador (fontName) e troca pelo span com o atributo.
  function aplicar(attrs) {
    if (!restaura()) return;
    document.execCommand('styleWithCSS', false, false);
    document.execCommand('fontName', false, 'x-ed-marcador');
    var novos = [];
    each(edAtivo.querySelectorAll('font[face="x-ed-marcador"]'), function (f) {
      var sp = document.createElement('span');
      Object.keys(attrs).forEach(function (a) {
        sp.setAttribute('data-' + a, attrs[a]);
        each(f.querySelectorAll('[data-' + a + ']'), function (d) { d.removeAttribute('data-' + a); });
        if (a === 'm') each(f.querySelectorAll('mark.marca'), function (mk) { while (mk.firstChild) mk.parentNode.insertBefore(mk.firstChild, mk); mk.remove(); });
      });
      each(f.querySelectorAll('[style]'), function (d) { d.style.fontFamily = ''; d.style.fontSize = ''; });
      while (f.firstChild) sp.appendChild(f.firstChild);
      f.parentNode.replaceChild(sp, f);
      novos.push(sp);
    });
    // Mantém o trecho selecionado para o próximo comando (fonte depois do tamanho etc.).
    if (novos.length) {
      var r = document.createRange();
      r.setStartBefore(novos[0]);
      r.setEndAfter(novos[novos.length - 1]);
      var s = window.getSelection();
      s.removeAllRanges();
      s.addRange(r);
      faixa = r.cloneRange();
    }
  }
  function blocoDaSelecao() {
    var s = window.getSelection();
    var el = s && s.anchorNode;
    while (el && el.parentNode !== edAtivo) el = el.parentNode;
    return el;
  }
  function comando(cmd, valor) {
    if (!restaura()) return;
    if (cmd === 'bold' || cmd === 'italic' || cmd === 'underline') {
      document.execCommand('styleWithCSS', false, false);
      document.execCommand(cmd, false, null);
    } else if (cmd === 'marca') {
      aplicar({ m: valorAtual('m') === '1' ? '0' : '1' });
    } else if (cmd === 'fonte') {
      aplicar({ f: valor });
    } else if (cmd === 'tamanho') {
      aplicar({ t: valor });
    } else if (cmd === 'lista') {
      document.execCommand('insertUnorderedList', false, null);
    } else if (cmd === 'destaque') {
      var b = blocoDaSelecao();
      if (!b || b.nodeType !== 1 || /^(UL|OL)$/.test(b.nodeName)) {
        document.execCommand('formatBlock', false, 'p');
        b = blocoDaSelecao();
      }
      if (b && b.nodeType === 1 && !/^(UL|OL)$/.test(b.nodeName)) {
        if (b.getAttribute('data-bloco') === 'destaque') b.removeAttribute('data-bloco'); else b.setAttribute('data-bloco', 'destaque');
      }
    } else if (cmd === 'limpar') {
      document.execCommand('removeFormat', false, null);
      aplicar({ f: '', t: '', m: '0' });
    }
    alterado(edAtivo);
    estadoBarra();
  }
  function estadoBarra() {
    if (!barra) return;
    var ed = selecaoNoEditor();
    barra.classList.toggle('is-ativa', !!(ed || edAtivo));
    if (!ed) return;
    ['bold', 'italic', 'underline'].forEach(function (c) {
      var b = barra.querySelector('[data-cmd="' + c + '"]');
      var on = false;
      try { on = document.queryCommandState(c); } catch (e) {}
      b.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
    barra.querySelector('[data-cmd="marca"]').setAttribute('aria-pressed', valorAtual('m') === '1' ? 'true' : 'false');
    var f = valorAtual('f');
    barra.querySelector('[data-cmd="fonte"]').value = f === 'lora' ? '' : f;
    var t = valorAtual('t');
    barra.querySelector('[data-cmd="tamanho"]').value = t === '12' ? '' : t;
    var bl = blocoDaSelecao();
    barra.querySelector('[data-cmd="destaque"]').setAttribute('aria-pressed', bl && bl.nodeType === 1 && bl.getAttribute('data-bloco') === 'destaque' ? 'true' : 'false');
    barra.querySelector('[data-cmd="lista"]').setAttribute('aria-pressed', bl && bl.nodeType === 1 && /^(UL|OL)$/.test(bl.nodeName) ? 'true' : 'false');
  }

  var boxes = Array.prototype.slice.call(form.querySelectorAll('[data-texto]')).map(function (box) {
    // ref: texto automático do momento do ajuste (avisa se os valores mudaram depois).
    return { box: box, ta: box.querySelector('textarea'), ed: box.querySelector('[data-ed]'), base: box.querySelector('[data-base]'),
      manual: box.querySelector('[data-manual]'), ref: box.querySelector('[data-ref]'), key: box.getAttribute('data-texto') };
  });
  var temEditor = !!(barra && document.execCommand && window.getSelection);
  if (temEditor) {
    try { document.execCommand('defaultParagraphSeparator', false, 'p'); } catch (e) {}
    barra.hidden = false;
    boxes.forEach(function (o) {
      if (!o.ed) return;
      each(o.ed.querySelectorAll('[style]'), function (el) { el.removeAttribute('style'); });
      o.ed.hidden = false;
      o.ta.closest('.ed-fonte').hidden = true;
      o.ta.value = serializar(o.ed);
      if (o.manual.value !== '1') o.base.value = o.ta.value;
    });
    barra.addEventListener('mousedown', function (ev) { if (ev.target.closest('button')) ev.preventDefault(); });
    barra.addEventListener('click', function (ev) {
      var b = ev.target.closest('button[data-cmd]');
      if (b) comando(b.getAttribute('data-cmd'));
    });
    barra.addEventListener('change', function (ev) {
      var sl = ev.target.closest('select[data-cmd]');
      if (sl) comando(sl.getAttribute('data-cmd'), sl.value);
    });
    document.addEventListener('selectionchange', function () {
      var ed = selecaoNoEditor();
      if (ed) {
        edAtivo = ed;
        faixa = window.getSelection().getRangeAt(0).cloneRange();
      }
      estadoBarra();
    });
    form.addEventListener('paste', function (ev) {
      if (!noEditor(ev.target)) return;
      ev.preventDefault();
      var t = (ev.clipboardData || window.clipboardData).getData('text/plain');
      document.execCommand('insertText', false, t);
    });
  }
  function caixaDe(el) { return boxes.filter(function (b) { return b.ed === el || b.ta === el; })[0]; }
  function alterado(el) {
    var o = caixaDe(el);
    if (!o) return;
    if (o.ed && el === o.ed) o.ta.value = serializar(o.ed);
    if (o.manual.value !== '1') { o.ref.value = o.ta.getAttribute('data-auto'); setManual(o, true); }
  }

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
            if (temEditor && o.ed && j.auto_html) {
              o.ed.innerHTML = j.auto_html[o.key] || '';
              each(o.ed.querySelectorAll('[style]'), function (el) { el.removeAttribute('style'); });
              o.ta.value = serializar(o.ed);
            } else {
              o.ta.value = auto;
            }
            o.base.value = o.ta.value;
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
    var ed = noEditor(ev.target);
    if (ed) { alterado(ed); return; }
    if (caixaDe(ev.target)) { alterado(ev.target); return; }
    if (ev.target.closest('[data-texto]')) return;
    later();
  });
  form.addEventListener('change', function (ev) {
    if (!ev.target.closest('[data-texto]') && !ev.target.closest('[data-ed-barra]')) later();
  });

  // Leva uma condição (do simulador ou da avaliação da IA) para os campos da proposta.
  function setVal(name, v) { var el = form.querySelector('[name="' + name + '"]'); if (el && v !== undefined && v !== null) el.value = v; }
  function aplicarCondicao(o) {
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
    refresh();
    if (nav) abrir('simulacao');
    var alvo = document.getElementById('pagamento');
    if (alvo && alvo.scrollIntoView) alvo.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }
  document.addEventListener('click', function (ev) {
    var u = ev.target.closest('[data-usar-tipo]');
    if (u) { aplicarCondicao({ tipo: u.getAttribute('data-usar-tipo'), parcelas: u.getAttribute('data-usar-parcelas'), forma: u.getAttribute('data-usar-forma') }); return; }
    var ia = ev.target.closest('[data-aplicar-ia]');
    if (ia) { aplicarCondicao({ valor: ia.getAttribute('data-valor'), tipo: ia.getAttribute('data-tipo'), parcelas: ia.getAttribute('data-parcelas'), entrada: ia.getAttribute('data-entrada'), forma: ia.getAttribute('data-forma') }); return; }
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
  form.addEventListener('submit', function (ev) {
    boxes.forEach(function (o) { if (temEditor && o.ed) o.ta.value = serializar(o.ed); });
    if (ev.submitter && ev.submitter.hasAttribute('data-avaliar')) {
      var msg = form.querySelector('[data-avaliando]');
      if (msg) msg.textContent = 'A IA está lendo o atendimento. Leva até um minuto.';
      setTimeout(function () { ev.submitter.disabled = true; }, 0);
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
