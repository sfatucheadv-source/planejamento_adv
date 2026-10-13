/* Conferência da cópia: o SHA-256 é calculado no navegador e só o hash vai ao
   servidor. O arquivo não é enviado nem guardado. */
(function () {
  'use strict';
  var f = document.querySelector('[data-conferir]');
  if (!f) return;
  var zona = f.querySelector('[data-zona]');
  var input = f.querySelector('[data-arquivo]');
  var nome = f.querySelector('[data-nome]');
  var res = f.querySelector('[data-resultado]');
  var hashEl = f.querySelector('[data-hash]');

  var MSG = {
    identica: ['-ok', 'Cópia idêntica à versão assinada registrada.'],
    diferente: ['-dif', 'Cópia diferente da versão assinada registrada.'],
    erro: ['-erro', 'Não foi possível realizar a conferência.']
  };
  function mostrar(r, extra) {
    var m = MSG[r] || MSG.erro;
    res.className = 'verif-resultado ' + m[0];
    res.innerHTML = '';
    var s = document.createElement('strong'); s.textContent = m[1]; res.appendChild(s);
    if (extra) res.appendChild(document.createTextNode(' ' + extra));
  }

  /* SHA-256 em JavaScript, para navegadores sem crypto.subtle (página sem HTTPS). */
  function sha256js(buf) {
    var K = [0x428a2f98,0x71374491,0xb5c0fbcf,0xe9b5dba5,0x3956c25b,0x59f111f1,0x923f82a4,0xab1c5ed5,0xd807aa98,0x12835b01,0x243185be,0x550c7dc3,0x72be5d74,0x80deb1fe,0x9bdc06a7,0xc19bf174,
      0xe49b69c1,0xefbe4786,0x0fc19dc6,0x240ca1cc,0x2de92c6f,0x4a7484aa,0x5cb0a9dc,0x76f988da,0x983e5152,0xa831c66d,0xb00327c8,0xbf597fc7,0xc6e00bf3,0xd5a79147,0x06ca6351,0x14292967,
      0x27b70a85,0x2e1b2138,0x4d2c6dfc,0x53380d13,0x650a7354,0x766a0abb,0x81c2c92e,0x92722c85,0xa2bfe8a1,0xa81a664b,0xc24b8b70,0xc76c51a3,0xd192e819,0xd6990624,0xf40e3585,0x106aa070,
      0x19a4c116,0x1e376c08,0x2748774c,0x34b0bcb5,0x391c0cb3,0x4ed8aa4a,0x5b9cca4f,0x682e6ff3,0x748f82ee,0x78a5636f,0x84c87814,0x8cc70208,0x90befffa,0xa4506ceb,0xbef9a3f7,0xc67178f2];
    var H = [0x6a09e667,0xbb67ae85,0x3c6ef372,0xa54ff53a,0x510e527f,0x9b05688c,0x1f83d9ab,0x5be0cd19];
    var b = new Uint8Array(buf), n = b.length, len = ((n + 9 + 63) >> 6) << 6;
    var m = new Uint8Array(len); m.set(b); m[n] = 0x80;
    var dv = new DataView(m.buffer);
    dv.setUint32(len - 8, Math.floor(n / 0x20000000)); dv.setUint32(len - 4, (n << 3) >>> 0);
    var w = new Uint32Array(64);
    function r(x, c) { return (x >>> c) | (x << (32 - c)); }
    for (var o = 0; o < len; o += 64) {
      for (var i = 0; i < 16; i++) w[i] = dv.getUint32(o + i * 4);
      for (i = 16; i < 64; i++) {
        var s0 = r(w[i - 15], 7) ^ r(w[i - 15], 18) ^ (w[i - 15] >>> 3), s1 = r(w[i - 2], 17) ^ r(w[i - 2], 19) ^ (w[i - 2] >>> 10);
        w[i] = (w[i - 16] + s0 + w[i - 7] + s1) >>> 0;
      }
      var a = H[0], bb = H[1], c = H[2], d = H[3], e = H[4], f2 = H[5], g = H[6], h = H[7];
      for (i = 0; i < 64; i++) {
        var t1 = (h + (r(e, 6) ^ r(e, 11) ^ r(e, 25)) + ((e & f2) ^ (~e & g)) + K[i] + w[i]) >>> 0;
        var t2 = ((r(a, 2) ^ r(a, 13) ^ r(a, 22)) + ((a & bb) ^ (a & c) ^ (bb & c))) >>> 0;
        h = g; g = f2; f2 = e; e = (d + t1) >>> 0; d = c; c = bb; bb = a; a = (t1 + t2) >>> 0;
      }
      H[0] = (H[0] + a) >>> 0; H[1] = (H[1] + bb) >>> 0; H[2] = (H[2] + c) >>> 0; H[3] = (H[3] + d) >>> 0;
      H[4] = (H[4] + e) >>> 0; H[5] = (H[5] + f2) >>> 0; H[6] = (H[6] + g) >>> 0; H[7] = (H[7] + h) >>> 0;
    }
    return H.map(function (x) { return ('00000000' + x.toString(16)).slice(-8); }).join('');
  }
  function hex(buf) {
    return Array.prototype.map.call(new Uint8Array(buf), function (x) { return ('0' + x.toString(16)).slice(-2); }).join('');
  }
  function sha256(buf) {
    if (window.crypto && crypto.subtle && crypto.subtle.digest) {
      return crypto.subtle.digest('SHA-256', buf).then(hex, function () { return sha256js(buf); });
    }
    return Promise.resolve(sha256js(buf));
  }

  function conferir(file) {
    if (!file) return;
    nome.textContent = file.name + ' · ' + file.size.toLocaleString('pt-BR') + ' bytes';
    res.className = 'verif-resultado'; res.textContent = 'Calculando o hash no seu aparelho…';
    hashEl.hidden = true;
    var leitor = new FileReader();
    leitor.onerror = function () { mostrar('erro', 'O arquivo não pôde ser lido.'); };
    leitor.onload = function () {
      sha256(leitor.result).then(function (h) {
        hashEl.hidden = false;
        hashEl.textContent = 'SHA-256 da sua cópia: ' + h;
        var fd = new FormData();
        fd.append('_csrf', f.querySelector('[name=_csrf]').value);
        fd.append('hash', h);
        return fetch(f.getAttribute('data-url'), { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
          .then(function (r) { return r.json(); })
          .then(function (j) {
            mostrar(j.resultado, j.resultado === 'diferente' ? 'Pode ser uma cópia reimpressa, digitalizada ou exportada de novo — compare o conteúdo com a via original acima.' : (j.msg || ''));
          });
      }).catch(function () { mostrar('erro', 'Tente de novo em instantes.'); });
    };
    leitor.readAsArrayBuffer(file);
  }

  input.addEventListener('change', function () { conferir(input.files[0]); });
  ['dragenter', 'dragover'].forEach(function (ev) {
    zona.addEventListener(ev, function (e) { e.preventDefault(); zona.classList.add('-sobre'); });
  });
  ['dragleave', 'drop'].forEach(function (ev) {
    zona.addEventListener(ev, function (e) { e.preventDefault(); zona.classList.remove('-sobre'); });
  });
  zona.addEventListener('drop', function (e) {
    if (e.dataTransfer && e.dataTransfer.files[0]) conferir(e.dataTransfer.files[0]);
  });
})();
