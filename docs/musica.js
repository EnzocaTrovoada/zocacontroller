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
      /* A capa de novo, borrada e ampliada, como fundo. É a cor da própria
         música pintando a caixa — muda a cada faixa sem ninguém escolher. */
      '<img class="mu__brilho" alt="" aria-hidden="true">' +
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
    var brilho  = root.querySelector('.mu__brilho');
    var elNome  = root.querySelector('.mu__nome');
    var elArt   = root.querySelector('.mu__artista');
    var barra   = root.querySelector('.mu__barra');
    var dentro  = barra.firstElementChild;

    var cfg = R.sanitize(cfgInicial);
    var atual = null;         /* a música de agora */
    var marcado = 0;          /* quando o servidor mediu o progresso */
    var pulso = null, reserva = null;

    /* O CICLO DA ANIMAÇÃO.

       Guardo QUANDO cada etapa deve acontecer, e não uso setTimeout: com a
       fonte escondida no OBS os temporizadores congelam, e ao voltar a
       música fecharia toda de uma vez. O relógio aqui é o pulso do Worker,
       que sobrevive. */
    var fase = 'fora';        /* fora | abrindo | parado | fechando */
    var trocaEm = 0;

    function ciclo() {
      if (cfg.manim === 'nenhum' || cfg.mquando === 'sempre') return;
      if (!trocaEm || Date.now() < trocaEm) return;

      if (fase === 'abrindo') {
        fase = 'parado';
        trocaEm = Date.now() + cfg.mtempo * 1000;
      } else if (fase === 'parado') {
        fase = 'fechando';
        root.classList.remove('mu--revelar', 'mu--surge');
        root.classList.add('mu--fechando');
        trocaEm = Date.now() + cfg.mfechar + 60;
      } else if (fase === 'fechando') {
        fase = 'fora';
        trocaEm = 0;
        root.classList.add('mu--vazio');
        root.classList.remove('mu--fechando');
      }
    }

    function abre() {
      if (cfg.manim === 'nenhum') { root.classList.remove('mu--vazio'); return; }
      root.classList.remove('mu--vazio', 'mu--fechando');
      /* Tira e devolve a classe pra animação recomeçar: sem isso, música nova
         durante a anterior não reanima nada. */
      root.classList.remove('mu--revelar', 'mu--surge');
      void root.offsetWidth;
      root.classList.add(cfg.manim === 'surge' ? 'mu--surge' : 'mu--revelar');
      fase = 'abrindo';
      trocaEm = Date.now() + (cfg.manim === 'surge' ? 440 : cfg.mabrir + 20);
    }

    function aplica() {
      R.aplicaEstilo(root, cfg);
      var s = root.style;
      s.setProperty('--mu-capa', cfg.mtam + 'px');
      s.setProperty('--mu-gap', cfg.mgap + 'px');
      s.setProperty('--mu-larg', cfg.mlarg + 'px');
      /* O lado do quadrado fechado: a capa mais o respiro dos dois lados.
         Sem capa, um quadrado do tamanho da altura da linha. */
      s.setProperty('--mu-quad', (cfg.mcapa ? cfg.mtam + cfg.mfpad * 2 : Math.round(cfg.size * 2.2)) + 'px');
      s.setProperty('--mu-raio', cfg.mraio + 'px');
      /* No modo capa a cor chapada sai de cena: quem pinta é a imagem. */
      s.setProperty('--mu-fundo', (cfg.mfundo === 'none' || cfg.mfundo === 'capa')
        ? 'transparent' : R.rgba(cfg.mfcor, cfg.mfopac));
      s.setProperty('--mu-borrar', cfg.mborrar + 'px');
      s.setProperty('--mu-escuro', (1 - cfg.mescuro / 100).toFixed(2));
      s.setProperty('--mu-abrir', cfg.mabrir + 'ms');
      s.setProperty('--mu-fechar', cfg.mfechar + 'ms');
      s.setProperty('--mu-fpad', cfg.mfpad + 'px');
      s.setProperty('--mu-artcor', R.rgba(cfg.martcor, cfg.martopac));
      s.setProperty('--mu-artsize', (cfg.martsize / 100) + '');
      s.setProperty('--mu-bcor', cfg.mbcor);
      s.setProperty('--mu-balt', cfg.mbalt + 'px');

      root.classList.toggle('mu--sem-capa', !cfg.mcapa);
      root.classList.toggle('mu--sem-barra', !cfg.mbarra);
      root.classList.toggle('mu--capa-direita', cfg.mlado === 'direita');
      root.classList.toggle('mu--rola', !!cfg.mrola);
      root.classList.toggle('mu--fundo-capa', cfg.mfundo === 'capa');
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
        root.classList.remove('mu--revelar', 'mu--surge', 'mu--fechando');
        fase = 'fora'; trocaEm = 0;
        return;
      }
      if (cfg.mquando === 'sempre' || cfg.manim === 'nenhum') root.classList.remove('mu--vazio');

      if (mudou) {
        elNome.textContent = m.nome;
        elArt.textContent = m.artista;
        if (m.capa && capa.getAttribute('src') !== m.capa) {
          capa.setAttribute('src', m.capa);
          brilho.setAttribute('src', m.capa);
        }
        /* Reinicia a animação de entrada só quando a MÚSICA muda, e não a
           cada consulta: senão ela repetiria de 15 em 15 segundos. */
        abre();
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
        w.onmessage = function () { pinta(); ciclo(); };
        w.postMessage(0);
        return w;
      } catch (e) { return null; }
    })();
    reserva = setInterval(function () { pinta(); ciclo(); }, 500);

    /* O !musica mostra e esconde a fonte no OBS. Quando ela volta a aparecer,
       a animação recomeça — é assim que o comando "chama" a música na tela. */
    global.addEventListener('obsSourceVisibleChanged', function (e) {
      if (e && e.detail && e.detail.visible === false) return;
      if (atual) abre();
    });

    return {
      update: update,
      config: function () { return cfg; },
      musica: mostra,
      mostrarDeNovo: function () { if (atual) abre(); },
      exemplo: function () {
        mostra({
          nome: 'Tempo Perdido', artista: 'Legião Urbana', album: 'Dois',
          /* Uma capa de mentira desenhada aqui mesmo: sem imagem nao da pra
             ver o fundo tirado dela, que e metade do visual.

             As cores vao CRUAS: quem escapa e o encodeURIComponent. Escrever
             %23 aqui e deixar ele codificar de novo vira %2523, e o SVG nao
             desenha nada. */
          capa: 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(
            '<svg xmlns="http://www.w3.org/2000/svg" width="300" height="300">'
            + '<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1">'
            + '<stop offset="0" stop-color="#1E6F8C"/>'
            + '<stop offset="0.55" stop-color="#2F9E6B"/>'
            + '<stop offset="1" stop-color="#C9D86B"/></linearGradient></defs>'
            + '<rect width="300" height="300" fill="url(#g)"/>'
            + '<circle cx="96" cy="104" r="56" fill="#FFFFFF" opacity="0.25"/>'
            + '<rect x="146" y="168" width="118" height="96" fill="#0E2630" opacity="0.5"/>'
            + '<rect x="34" y="214" width="86" height="18" fill="#FFFFFF" opacity="0.35"/></svg>'),
          dura: 292000, em: 74000, tocando: true,
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
