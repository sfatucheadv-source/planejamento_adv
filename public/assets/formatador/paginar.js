/* ============================================================================
   FATUCHE — Motor de paginação A4
   Distribui o fluxo de #fonte em folhas físicas de 210×297 mm, aplicando
   controle de viúvas/órfãs, "manter com o próximo", notas de rodapé por
   página e quebra de parágrafos por linha (Range + caret).
   Sem dependências externas.
   ========================================================================= */
(function () {
  "use strict";

  var CFG = window.PECA_CFG || {};
  var MIN_LINHAS = 2;          // linhas mínimas em cada metade de um parágrafo partido
  var TOL = 0.6;               // tolerância em px para o teste de transbordo

  /* ---------------------------------------------------------------- utils */

  function el(tag, cls, html) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (html != null) n.innerHTML = html;
    return n;
  }

  function caretFromPoint(x, y) {
    if (document.caretRangeFromPoint) {
      var r = document.caretRangeFromPoint(x, y);
      return r ? { node: r.startContainer, offset: r.startOffset } : null;
    }
    if (document.caretPositionFromPoint) {
      var p = document.caretPositionFromPoint(x, y);
      return p ? { node: p.offsetNode, offset: p.offset } : null;
    }
    return null;
  }

  function linhasDe(node) {
    var r = document.createRange();
    r.selectNodeContents(node);
    var rects = Array.prototype.slice.call(r.getClientRects())
      .filter(function (q) { return q.height > 0.5 && q.width > 0.5; });
    var linhas = [], atual = null;
    rects.forEach(function (q) {
      if (atual && Math.abs(q.top - atual.top) < 2) {
        atual.left = Math.min(atual.left, q.left);
        atual.right = Math.max(atual.right, q.right);
        atual.bottom = Math.max(atual.bottom, q.bottom);
      } else {
        atual = { top: q.top, bottom: q.bottom, left: q.left, right: q.right };
        linhas.push(atual);
      }
    });
    return linhas;
  }

  /* ------------------------------------------------------------- estrutura */

  var papel, fonte, notasFonte, folhas = [], contadorNota = 0;

  function criarFolha() {
    var f = el("div", "folha");

    var cab = el("div", "folha__cabecalho");
    cab.innerHTML = window.PECA_SVG_CABECALHO || "";
    f.appendChild(cab);

    var rod = el("div", "folha__rodape");
    rod.innerHTML = window.PECA_SVG_RODAPE || "";
    f.appendChild(rod);

    var folio = el("div", "folha__folio");
    f.appendChild(folio);

    if (CFG.marcaDagua) {
      var marca = el("div", "folha__marca", CFG.marcaDagua);
      f.appendChild(marca);
    }

    var corpo = el("div", "folha__corpo");
    corpo.style.display = "flex";
    corpo.style.flexDirection = "column";

    var fluxo = el("div", "folha__fluxo corpo");
    fluxo.style.flex = "0 0 auto";
    corpo.appendChild(fluxo);

    var notas = el("div", "folha__notas");
    notas.style.marginTop = "auto";
    notas.style.display = "none";
    corpo.appendChild(notas);

    f.appendChild(corpo);
    papel.appendChild(f);

    var obj = { raiz: f, corpo: corpo, fluxo: fluxo, notas: notas, folio: folio };
    folhas.push(obj);
    return obj;
  }

  function transborda(folha) {
    return folha.corpo.scrollHeight > folha.corpo.clientHeight + TOL;
  }

  function limiteY(folha) {
    var rc = folha.corpo.getBoundingClientRect();
    var alturaNotas = folha.notas.style.display === "none" ? 0 : folha.notas.offsetHeight;
    return rc.top + folha.corpo.clientHeight - alturaNotas;
  }

  /* ------------------------------------------------------------ notas ---- */

  function notasDe(node) {
    return Array.prototype.slice.call(node.querySelectorAll ? node.querySelectorAll(".nota-ref") : []);
  }

  function anexarNotas(folha, refs) {
    refs.forEach(function (ref) {
      var id = ref.getAttribute("data-nota");
      if (!id || ref.hasAttribute("data-colocada")) return;
      var def = notasFonte ? notasFonte.querySelector('[data-nota="' + id + '"]') : null;
      if (!def) return;
      contadorNota += 1;
      ref.textContent = String(contadorNota);
      ref.setAttribute("data-colocada", "1");
      var n = el("div", "nota");
      n.appendChild(el("span", "nota__num", String(contadorNota)));
      n.insertAdjacentHTML("beforeend", " " + def.innerHTML);
      folha.notas.appendChild(n);
      folha.notas.style.display = "";
    });
  }

  function removerNotasDe(folha, refs) {
    refs.forEach(function (ref) {
      if (!ref.hasAttribute("data-colocada")) return;
      ref.removeAttribute("data-colocada");
      var n = folha.notas.lastElementChild;
      if (n) folha.notas.removeChild(n);
      contadorNota -= 1;
    });
    if (!folha.notas.children.length) folha.notas.style.display = "none";
  }

  /* --------------------------------------------------------- divisão ----- */

  var DIVISIVEIS = { P: 1, BLOCKQUOTE: 1, DIV: 1, SECTION: 1 };
  var POR_FILHOS = { OL: 1, UL: 1, TABLE: 1 };

  function podeDividir(node) {
    if (node.getAttribute && node.getAttribute("data-keep") === "together") return false;
    if (/^H[1-6]$/.test(node.tagName)) return false;
    if (node.classList && (node.classList.contains("assinatura") ||
        node.classList.contains("titulo-peca") ||
        node.classList.contains("enderecamento") ||
        node.classList.contains("quadro"))) return false;
    return !!(DIVISIVEIS[node.tagName] || POR_FILHOS[node.tagName]);
  }

  function dividirPorFilhos(node, limite) {
    var ehTabela = node.tagName === "TABLE";
    var recipiente = ehTabela ? (node.tBodies[0] || node) : node;
    var filhos = Array.prototype.slice.call(recipiente.children);
    var minimo = 1;
    var corte = -1;
    // o último filho precisa deixar espaço para o padding/borda de fechamento
    // do próprio container (ex.: .julgado, .citacao) — sem isso, um bloco cujo
    // conteúdo cabe mas cujo padding inferior extrapola o limite é tratado como
    // "nada cabe" e o bloco inteiro pula de página, deixando um vão enorme.
    var folgaFinal = 0;
    if (!ehTabela) {
      var cs = node.currentStyle || window.getComputedStyle(node);
      folgaFinal = (parseFloat(cs.paddingBottom) || 0) + (parseFloat(cs.borderBottomWidth) || 0) +
                   (parseFloat(cs.marginBottom) || 0);
    }
    var limiteEfetivo = limite - folgaFinal;
    for (var i = 0; i < filhos.length; i++) {
      if (filhos[i].getBoundingClientRect().bottom > limiteEfetivo) { corte = i; break; }
    }
    if (corte < minimo) return null;
    if (filhos.length - corte < minimo) return null;

    // a linha de fonte do julgado (fonte + url + nota de grifo) nunca desce
    // sozinha: leva consigo as últimas linhas da ementa (25/09/2026)
    if (!ehTabela && filhos[corte].classList && filhos[corte].classList.contains("julgado__fecho") &&
        corte === filhos.length - 1) {
      var altFecho = filhos[corte].getBoundingClientRect().height;
      return dividirTexto(node, limiteEfetivo - altFecho);
    }

    // não deixa rótulo/cabeça de bloco (ou linha raquítica) sozinho no pé da página
    var substancia = 0;
    for (var k = 0; k < corte; k++) {
      // rótulo do julgado/síntese e título de quadro não contam como conteúdo:
      // sem isto, um rótulo longo ("Superior Tribunal de Justiça · Agravo…")
      // passava por substância e ficava sozinho no pé da página (25/09/2026)
      var fk = filhos[k];
      if (fk.classList && (fk.classList.contains("julgado__rotulo") ||
          fk.classList.contains("sintese-julgado__rotulo") ||
          fk.classList.contains("quadro__titulo"))) continue;
      if ((fk.textContent || "").trim().length > 45) substancia++;
    }
    if (!substancia) return null;

    var cont = node.cloneNode(false);
    cont.classList.add("-cont");

    if (ehTabela) {
      var cap = node.querySelector("caption");
      if (cap) {
        var cap2 = cap.cloneNode(true);
        cap2.textContent = cap.textContent + " (continuação)";
        cont.appendChild(cap2);
      }
      var thead = node.querySelector("thead");
      if (thead) cont.appendChild(thead.cloneNode(true));
      var corpo2 = recipiente.cloneNode(false);
      for (var t = corte; t < filhos.length; t++) corpo2.appendChild(filhos[t]);
      cont.appendChild(corpo2);
      return cont;
    }

    if (node.tagName === "OL") {
      var inicio = parseInt(node.getAttribute("start") || "1", 10);
      cont.setAttribute("start", String(inicio + corte));
      if (node.classList.contains("pedidos")) {
        cont.style.counterReset = "pedido " + (inicio - 1 + corte);
      }
    }
    for (var j = corte; j < filhos.length; j++) cont.appendChild(filhos[j]);
    return cont;
  }

  function ehEspaco(ch) { return ch === " " || ch === " " || ch === "\t" || ch === "\n"; }

  function recuarParaPalavra(pos) {
    if (!pos || pos.node.nodeType !== 3) return pos;
    var t = pos.node.textContent, o = pos.offset;
    if (o <= 0 || o >= t.length) return pos;
    if (ehEspaco(t[o]) || ehEspaco(t[o - 1])) return pos;
    var k = o;
    while (k > 0 && !ehEspaco(t[k - 1])) k--;
    return k > 0 ? { node: pos.node, offset: k } : pos;
  }

  function nosDeTexto(root) {
    var out = [], w = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, null);
    var n;
    while ((n = w.nextNode())) { if (n.nodeValue.length) out.push(n); }
    return out;
  }

  function posGlobal(nos, g) {
    for (var i = 0; i < nos.length; i++) {
      var L = nos[i].nodeValue.length;
      if (g <= L) return { node: nos[i], offset: g };
      g -= L;
    }
    var ult = nos[nos.length - 1];
    return { node: ult, offset: ult.nodeValue.length };
  }

  function dividirTexto(node, limite) {
    // o fundo do texto precisa deixar lugar para o padding/borda inferior do
    // próprio bloco (ex.: .julgado): sem isso a 1.ª metade transbordava, o corte
    // era desfeito e o julgado inteiro pulava de folha, abrindo um vão (25/09/2026)
    var csT = window.getComputedStyle(node);
    limite -= (parseFloat(csT.paddingBottom) || 0) + (parseFloat(csT.borderBottomWidth) || 0);
    // a margem inferior do parágrafo e o entrelinhamento também ocupam a folha:
    // sem descontá-los, a 1.ª metade "transbordava" por poucos pixels, o corte
    // era desfeito e o parágrafo inteiro pulava de folha, abrindo um vão (13/10/2026)
    limite -= parseFloat(csT.marginBottom) || 0;
    var linhas = linhasDe(node);
    if (linhas.length < MIN_LINHAS * 2) return null;
    var alturaLinha = parseFloat(csT.lineHeight);
    var alturaGlifo = linhas[0].bottom - linhas[0].top;
    if (alturaLinha > alturaGlifo) limite -= (alturaLinha - alturaGlifo) / 2;
    // viúva: pelo menos MIN_LINHAS linhas descem para a folha seguinte
    limite = Math.min(limite, linhas[linhas.length - MIN_LINHAS - 1].bottom);

    var nos = nosDeTexto(node);
    // julgado: o corte cai sempre dentro da ementa, nunca na linha de fonte, e
    // pelo menos MIN_LINHAS linhas da ementa descem junto com a fonte (25/09/2026)
    var fecho = node.querySelector(":scope > .julgado__fecho");
    if (fecho) {
      nos = nos.filter(function (t) { return !fecho.contains(t); });
      var ementas = node.querySelectorAll(":scope > p");
      var ultP = ementas.length ? ementas[ementas.length - 1] : null;
      if (ultP) {
        var lp = linhasDe(ultP);
        if (lp.length > MIN_LINHAS) limite = Math.min(limite, lp[lp.length - MIN_LINHAS - 1].bottom);
        else limite = Math.min(limite, ultP.getBoundingClientRect().top - 1);
      }
    }
    if (!nos.length) return null;
    var total = 0;
    nos.forEach(function (t) { total += t.nodeValue.length; });
    if (total < 60) return null;

    var r = document.createRange();
    function fundoAte(g) {
      var p = posGlobal(nos, g);
      try {
        r.setStart(nos[0], 0);
        r.setEnd(p.node, p.offset);
      } catch (e) { return Infinity; }
      var rects = r.getClientRects();
      if (!rects.length) return -Infinity;
      var b = -Infinity;
      for (var i = 0; i < rects.length; i++) if (rects[i].height > 0.5) b = Math.max(b, rects[i].bottom);
      return b;
    }

    // busca binária: maior g cujo fundo ainda cabe
    var lo = 1, hi = total, best = -1;
    while (lo <= hi) {
      var mid = (lo + hi) >> 1;
      if (fundoAte(mid) <= limite + TOL) { best = mid; lo = mid + 1; } else { hi = mid - 1; }
    }
    if (best < 30) return null;

    var pos = posGlobal(nos, best);
    pos = recuarParaPalavra(pos);
    if (pos.offset <= 0 && nos[0] === pos.node) return null;

    var intacto = node.innerHTML;
    var rr = document.createRange();
    try {
      rr.setStart(pos.node, pos.offset);
      rr.setEnd(node, node.childNodes.length);
    } catch (e) { return null; }
    if (rr.collapsed) return null;

    var frag = rr.extractContents();
    var cont = node.cloneNode(false);
    cont.classList.add("-cont");
    cont.appendChild(frag);

    if (!cont.textContent.trim() || !node.textContent.trim()) {
      node.innerHTML = intacto;
      return null;
    }
    // viúvas e órfãs
    if (linhasDe(node).length < MIN_LINHAS) {
      node.innerHTML = intacto;
      return null;
    }
    return cont;
  }

  function dividir(node, limite) {
    if (POR_FILHOS[node.tagName]) return dividirPorFilhos(node, limite);
    if (node.children.length && !node.querySelector(":scope > *:not(p):not(cite):not(span):not(em):not(strong):not(br):not(div)")) {
      // container de parágrafos (blockquote, .julgado, .citacao) — div entra
      // por causa do wrapper .julgado__fecho (fonte+url+grifo), que precisa
      // ser tratado como um filho atômico e não texto solto
      var porFilhos = dividirPorFilhos(node, limite);
      if (porFilhos) return porFilhos;
    }
    return dividirTexto(node, limite);
  }

  /* ------------------------------------------------------------ fluxo ---- */

  function colocar(folha, node) {
    folha.fluxo.appendChild(node);
    anexarNotas(folha, notasDe(node));

    if (!transborda(folha)) return folha;

    // 1ª tentativa: dividir o bloco entre esta folha e a próxima. Se a 1.ª
    // metade ainda transborda (arredondamentos, margens), corta uma linha
    // antes e tenta de novo, em vez de mandar o bloco inteiro para a próxima.
    var lim = limiteY(folha);
    var intacto = node.innerHTML;
    var passo = parseFloat(window.getComputedStyle(node).lineHeight) || 20;
    for (var tentativa = 0; tentativa < 4 && podeDividir(node); tentativa++) {
      var cont = dividir(node, lim - tentativa * passo);
      if (!cont) break;
      removerNotasDe(folha, notasDe(cont));
      if (!transborda(folha)) {
        return colocar(criarFolha(), cont);
      }
      // não coube partido assim: desfaz e tenta cortando mais acima
      removerNotasDe(folha, notasDe(node));
      node.innerHTML = intacto;
      anexarNotas(folha, notasDe(node));
    }

    // 2ª tentativa: mover o bloco inteiro, arrastando o que deve segui-lo
    removerNotasDe(folha, notasDe(node));
    folha.fluxo.removeChild(node);

    var arrasta = [];
    while (folha.fluxo.lastElementChild && deveArrastar(folha.fluxo.lastElementChild)) {
      var ult = folha.fluxo.lastElementChild;
      removerNotasDe(folha, notasDe(ult));
      folha.fluxo.removeChild(ult);
      arrasta.unshift(ult);
    }

    // fecho e assinatura nunca abrem a folha sozinhos: se o que ficou por
    // último é uma lista (pedidos), o último item desce junto (25/09/2026)
    if (!arrasta.length && node.querySelector && node.querySelector(".assinatura, .fecho")) {
      var ultimo = folha.fluxo.lastElementChild;
      if (ultimo && ultimo.tagName === "OL" && ultimo.children.length > 1) {
        var ini = parseInt(ultimo.getAttribute("start") || "1", 10);
        var nItens = ultimo.children.length;
        var novo = ultimo.cloneNode(false);
        novo.classList.add("-cont");
        novo.setAttribute("start", String(ini + nItens - 1));
        if (ultimo.classList.contains("pedidos")) novo.style.counterReset = "pedido " + (ini - 1 + nItens - 1);
        var item = ultimo.lastElementChild;
        removerNotasDe(folha, notasDe(item));
        novo.appendChild(item);
        arrasta.push(novo);
      }
    }

    if (!folha.fluxo.children.length) {
      // bloco maior que uma folha inteira e indivisível: aceita o transbordo
      arrasta.forEach(function (a) { folha.fluxo.appendChild(a); anexarNotas(folha, notasDe(a)); });
      folha.fluxo.appendChild(node);
      anexarNotas(folha, notasDe(node));
      return folha;
    }

    var f2 = criarFolha();
    arrasta.forEach(function (a) { f2 = colocar(f2, a); });
    return colocar(f2, node);
  }

  function deveArrastar(node) {
    if (!node) return false;
    if (/^H[1-6]$/.test(node.tagName)) return true;
    if (node.classList && (node.classList.contains("secao") ||
        node.classList.contains("subsecao") ||
        node.classList.contains("julgado__rotulo") ||
        node.classList.contains("titulo-peca"))) return true;
    return node.getAttribute && node.getAttribute("data-keep") === "next";
  }

  /* ------------------------------------------------------------ folios --- */

  function numerarFolhas() {
    var total = folhas.length;
    folhas.forEach(function (f, i) {
      if (CFG.folio === false) { f.folio.style.display = "none"; return; }
      f.folio.textContent = (i + 1) + " / " + total;
    });
  }

  /* --------------------------------------------------------------- run --- */

  function paginar() {
    papel = document.getElementById("papel");
    fonte = document.getElementById("fonte");
    if (!papel || !fonte) return;
    notasFonte = fonte.querySelector(".notas");

    papel.innerHTML = "";
    folhas = [];
    contadorNota = 0;

    if (!fonte.hasAttribute("data-hifen-ajustado")) {
      ajustarHifenizacao();
      fonte.setAttribute("data-hifen-ajustado", "1");
    }

    var corpoFonte = fonte.querySelector(".corpo") || fonte;
    var blocos = Array.prototype.slice.call(corpoFonte.children);
    var folha = criarFolha();

    blocos.forEach(function (b) {
      var node = b.cloneNode(true);
      if (node.classList && node.classList.contains("notas")) return;

      if (node.getAttribute && node.getAttribute("data-quebra") === "antes" && folha.fluxo.children.length) {
        folha = criarFolha();
      }
      folha = colocar(folha, node);
    });

    numerarFolhas();
    document.body.setAttribute("data-paginas", folhas.length);
    document.title = document.title || "Peça";
  }

  window.PECA = {
    paginar: paginar,
    folhas: function () { return folhas.length; }
  };

  function algumTransbordo() {
    for (var i = 0; i < folhas.length; i++) if (transborda(folhas[i])) return true;
    return false;
  }

  function carregarFontes() {
    if (!document.fonts || !document.fonts.load) return Promise.resolve();
    var pedidos = [
      '400 16px Lora', '700 16px Lora', 'italic 400 16px Lora',
      '400 14px Poppins', '500 14px Poppins', '600 14px Poppins', '700 14px Poppins'
    ].map(function (f) { return document.fonts.load(f, 'Aáç0'); });
    return Promise.all(pedidos).then(function () { return document.fonts.ready; });
  }

  function suportaHifenizacaoNativa() {
    var d = document.createElement("div");
    d.lang = "pt-BR";
    d.style.cssText = "position:absolute;left:-9999px;top:0;width:70px;font:16px Georgia,serif;" +
                      "line-height:20px;hyphens:auto;-webkit-hyphens:auto";
    d.textContent = "responsabilidade constitucional";
    document.body.appendChild(d);
    var linhas = Math.round(d.offsetHeight / 20);
    document.body.removeChild(d);
    return linhas >= 4;
  }

  function ajustarHifenizacao() {
    if (CFG.hifenizacao === "condicional") return;      // força os hifens embutidos
    if (!suportaHifenizacaoNativa()) return;
    document.documentElement.classList.add("hifen-nativa");
    // remove os hifens condicionais: a camada de texto do PDF sai limpa
    var w = document.createTreeWalker(fonte, NodeFilter.SHOW_TEXT, null), n;
    while ((n = w.nextNode())) {
      if (n.nodeValue.indexOf("\u00AD") >= 0) n.nodeValue = n.nodeValue.replace(/\u00AD/g, "");
    }
  }

  // Sinaliza que a paginação assentou: sem isso, quem confere o arquivo por fora
  // (verificar.js, geração de PDF, captura de tela) precisa chutar um tempo de
  // espera — e num documento longo o chute erra, lendo um estado intermediário
  // com uma página a mais.
  function marcarPronto() {
    window.PECA.pronto = true;
    document.documentElement.setAttribute("data-paginado", String(folhas.length));
    document.dispatchEvent(new CustomEvent("peca:paginada", {
      detail: { folhas: folhas.length }
    }));
  }

  function iniciar() {
    window.PECA.pronto = false;
    document.documentElement.removeAttribute("data-paginado");
    carregarFontes().then(function () {
      paginar();
      // rede de segurança: se as métricas mudaram depois da 1ª passada, refaz
      var tentativas = 0;
      (function conferir() {
        if (tentativas++ >= 2) { marcarPronto(); return; }
        requestAnimationFrame(function () {
          if (algumTransbordo()) { paginar(); conferir(); }
          else marcarPronto();
        });
      })();
    });
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", iniciar);
  } else {
    iniciar();
  }

  var t;
  window.addEventListener("resize", function () {
    clearTimeout(t);
    t = setTimeout(function () { paginar(); marcarPronto(); }, 250);
  });
})();
