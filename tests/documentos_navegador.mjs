/* Paginação dos documentos no navegador — mesmas conferências do verificar.js
   do formatador: texto íntegro, nenhuma folha transbordando, nenhum título
   órfão no pé da página, timbrado em todas as folhas. Tira uma imagem de cada
   folha na pasta de saída.
   Uso: node documentos_navegador.mjs BASE '{"chave":"/caminho"}' PASTA      */
import { createRequire } from 'module';
const require = createRequire(import.meta.url);
const { chromium } = require('playwright');

const [base, urlsJson, out] = process.argv.slice(2);
const urls = JSON.parse(urlsJson);
const b = await chromium.launch();
const ctx = await b.newContext({ viewport: { width: 1000, height: 1400 } });
const p = await ctx.newPage();
await p.goto(base + '/painel/entrar');
await p.fill('input[name=email]', 'sidney@teste.local');
await p.fill('input[name=password]', 'senha-de-teste-1');
await Promise.all([p.waitForNavigation(), p.click('button[type=submit]')]);

const res = {};
for (const [k, u] of Object.entries(urls)) {
  const erros = [];
  const on = e => erros.push(e.message);
  p.on('pageerror', on);
  await p.goto(base + u);
  try {
    await p.waitForFunction(() => document.documentElement.hasAttribute('data-paginado'), null, { timeout: 30000 });
    await p.waitForTimeout(150);
  } catch (e) { erros.push('sem sinal de paginação'); }
  const r = await p.evaluate(() => {
    const norm = s => s.replace(/­/g, '').replace(/\s+/g, '').trim();
    const a = norm(document.querySelector('#fonte .corpo').textContent);
    const c = norm(Array.from(document.querySelectorAll('.folha__fluxo')).map(x => x.textContent).join(''));
    let i = 0; while (i < a.length && i < c.length && a[i] === c[i]) i++;
    return {
      folhas: document.querySelectorAll('.folha').length,
      identico: a === c,
      divergencia: a === c ? null : { origem: a.slice(Math.max(0, i - 40), i + 60), pagina: c.slice(Math.max(0, i - 40), i + 60) },
      transbordo: Array.from(document.querySelectorAll('.folha__corpo')).filter(x => x.scrollHeight > x.clientHeight + 1).length,
      orfaos: Array.from(document.querySelectorAll('.folha__fluxo')).filter(f => { const u = f.lastElementChild; return u && /^H[1-6]$/.test(u.tagName); }).length,
      timbrado: Array.from(document.querySelectorAll('.folha')).filter(f => f.querySelector('.folha__cabecalho svg') && f.querySelector('.folha__rodape svg')).length,
      fontes: [...document.fonts].filter(f => f.status === 'loaded').map(f => f.family).filter((v, i, s) => s.indexOf(v) === i),
    };
  });
  if (r.orfaos) erros.push(r.orfaos + ' título(s) órfão(s) no pé da página');
  if (!r.fontes.includes('Lora') || !r.fontes.includes('Poppins')) erros.push('fontes não carregadas: ' + r.fontes.join(','));
  const folhas = await p.$$('.folha');
  for (let i = 0; i < folhas.length; i++) {
    await folhas[i].screenshot({ path: `${out}/${k}-folha${i + 1}.png` });
  }
  await p.pdf({ path: `${out}/${k}-navegador.pdf`, preferCSSPageSize: true, printBackground: true });
  p.off('pageerror', on);
  res[k] = { ...r, erros };
}
await b.close();
console.log(JSON.stringify(res));
