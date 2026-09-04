/**
 * musica.js — o que está tocando.
 * ---------------------------------------------------------------------
 * Capa, título, artista e uma barra que anda. O token do Spotify não passa
 * por aqui: o servidor é quem fala com eles, e esta página só recebe o nome
 * da música já pronto.
 *
 * A aparência sai das mesmas variáveis dos outros overlays, então fonte, cor,
 * contorno, sombra e brilho valem igual em tudo.
 */
(function (global) {
  'use strict';

  var R = global.Relogio;

  var MOLDE =
    '<div class="mu__caixa">' +
      '<img class="mu__capa" alt="">' +
      '<div class="mu__texto">' +
        '<div class="mu__nome"></div>' +
        '<div class="mu__artista"></div>' +
        '<div class="mu__barra"><i></i></div>' +
      '</div>' +
    '</div>';

  function mount(root, cfgInicial) {
    root.classList.add('mu', 'rl');
    root.innerHTML = MOLDE;

    var caixa   = root.querySelector('.mu__caixa');
    var capa    = root.querySelector('.mu__capa');
    var elNome  = root.querySelector('.mu__nome');
    var elArt   = root.querySelector('.mu__artista');
    var barra   = root.querySelector('.mu__barra');
    var dentro  = barra.firstElementChild;

    var cfg = R.sanitize(cfgInicial);
    var atual = null;         /* a música de agora */
    var marcado = 0;          /* quando o servidor mediu o progresso */
    var pulso = null, reserva = null;

    function aplica() {
      R.aplicaEstilo(root, cfg);
      var s = root.style;
      s.setProperty('--mu-capa', cfg.mtam + 'px');
      s.setProperty('--mu-gap', cfg.mgap + 'px');
      s.setProperty('--mu-larg', cfg.mlarg + 'px');
      s.setProperty('--mu-raio', cfg.mraio + 'px');
      s.setProperty('--mu-fundo', cfg.mfundo === 'none' ? 'transparent' : R.rgba(cfg.mfcor, cfg.mfopac));
      s.setProperty('--mu-fpad', cfg.mfpad + 'px');
      s.setProperty('--mu-artcor', R.rgba(cfg.martcor, cfg.martopac));
      s.setProperty('--mu-artsize', (cfg.martsize / 100) + '');
      s.setProperty('--mu-bcor', cfg.mbcor);
      s.setProperty('--mu-balt', cfg.mbalt + 'px');

      root.classList.toggle('mu--sem-capa', !cfg.mcapa);
      root.classList.toggle('mu--sem-barra', !cfg.mbarra);
      root.classList.toggle('mu--capa-direita', cfg.mlado === 'direita');
      root.classList.toggle('mu--rola', !!cfg.mrola);
    }

    /* A barra anda sozinha entre uma consulta e outra.
       O servidor diz "estava em 45s quando eu perguntei"; daí pra frente é
       conta local, senão a barra pularia de 15 em 15 segundos. */
    function pinta() {
      if (!atual) return;
      var em = atual.em + (atual.tocando ? (Date.now() - marcado) : 0);
      var pct = atual.dura > 0 ? Math.min(100, em * 100 / atual.dura) : 0;
      dentro.style.width = pct + '%';
    }

    function mostra(m) {
      var mudou = !atual || !m || atual.nome !== m.nome || atual.artista !== m.artista;
      atual = m;
      marcado = Date.now();

      if (!m) {
        /* Nada tocando: some. Um overlay preso na última música é pior do que
           overlay nenhum — ele mente. */
        root.classList.add('mu--vazio');
        return;
      }
      root.classList.remove('mu--vazio');

      if (mudou) {
        elNome.textContent = m.nome;
        elArt.textContent = m.artista;
        if (m.capa && capa.getAttribute('src') !== m.capa) capa.setAttribute('src', m.capa);
        /* Reinicia a animação de entrada só quando a MÚSICA muda, e não a
           cada consulta: senão ela repetiria de 15 em 15 segundos. */
        caixa.classList.remove('mu--entra');
        void caixa.offsetWidth;
        caixa.classList.add('mu--entra');
        /* O texto que não cabe rola; o que cabe fica quieto. */
        [elNome, elArt].forEach(function (el) {
          el.classList.toggle('mu--longo', cfg.mrola && el.scrollWidth > el.clientWidth + 2);
        });
      }
      pinta();
    }

    function update(novo) {
      cfg = R.sanitize(novo);
      aplica();
      if (atual) mostra(atual);
    }

    aplica();
    root.classList.add('mu--vazio');
    pulso = (function () {
      try {
        var f = 'onmessage=function(){setInterval(function(){postMessage(0)},250)}';
        var w = new Worker(URL.createObjectURL(new Blob([f], { type: 'text/javascript' })));
        w.onmessage = pinta;
        w.postMessage(0);
        return w;
      } catch (e) { return null; }
    })();
    reserva = setInterval(pinta, 500);

    return {
      update: update,
      config: function () { return cfg; },
      musica: mostra,
      exemplo: function () {
        mostra({
          nome: 'Tempo Perdido', artista: 'Legião Urbana', album: 'Dois',
          capa: '', dura: 292000, em: 74000, tocando: true,
        });
      },
      destroy: function () {
        clearInterval(reserva);
        if (pulso) pulso.terminate();
        root.innerHTML = '';
      },
    };
  }

  global.Musica = { mount: mount };
})(window);
