/* Entrega ao motor de paginação (paginar.js) a configuração e o timbrado do
   documento, que vêm num bloco JSON da página: a política de segurança do site
   não executa scripts embutidos. Liga também o botão de imprimir. */
(function () {
  var dados = document.getElementById('documento-config');
  var cfg = dados ? JSON.parse(dados.textContent) : {};
  window.PECA_CFG = cfg.cfg || {};
  window.PECA_SVG_CABECALHO = cfg.cabecalho || '';
  window.PECA_SVG_RODAPE = cfg.rodape || '';
  document.addEventListener('click', function (e) {
    var b = e.target.closest ? e.target.closest('[data-imprimir]') : null;
    if (b) { e.preventDefault(); window.print(); }
  });
})();
