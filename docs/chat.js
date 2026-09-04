/**
 * chat.js — o chat na tela.
 * ---------------------------------------------------------------------
 * Lê o chat da Twitch direto, sem token e sem bot: entra como espectador
 * anônimo (justinfan) no IRC deles. Por isso não precisa de permissão
 * nenhuma, e por isso também não dá pra FALAR no chat por aqui — só ouvir.
 *
 * A aparência (fonte, cor, contorno, sombra, brilho, caixa de fundo) vem do
 * mesmo lugar que a dos outros overlays: as variáveis do clock.css. Assim
 * um ajuste de tipografia vale pra tudo, em vez de existirem dois sistemas
 * de cor que divergem com o tempo.
 */
(function (global) {
  'use strict';

  var IRC = 'wss://irc-ws.chat.twitch.tv:443';
  var R = global.Relogio;

  function esc(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  /* ------------------------------------------------------------------
     LER UMA LINHA DE IRC

     As etiquetas vêm escapadas pelo protocolo: \s é espaço, \: é ponto e
     vírgula. Sem desfazer isso, um nome com espaço chega quebrado.
     ------------------------------------------------------------------ */
  function analisar(linha) {
    var resto = linha, tags = {};

    if (resto[0] === '@') {
      var corte = resto.indexOf(' ');
      resto.slice(1, corte).split(';').forEach(function (par) {
        var i = par.indexOf('=');
        if (i === -1) return;
        tags[par.slice(0, i)] = par.slice(i + 1)
          .replace(/\\s/g, ' ').replace(/\\:/g, ';').replace(/\\\\/g, '\\');
      });
      resto = resto.slice(corte + 1);
    }

    var login = '';
    if (resto[0] === ':') {
      var c2 = resto.indexOf(' ');
      login = resto.slice(1, c2).split('!')[0];
      resto = resto.slice(c2 + 1);
    }
    if (resto.indexOf('PRIVMSG') !== 0) return null;

    var dois = resto.indexOf(' :');
    tags.login = login;
    return { tags: tags, texto: dois === -1 ? '' : resto.slice(dois + 2) };
  }

  /* ------------------------------------------------------------------
     EMOTES

     A Twitch manda as posições, não as imagens: "25:0-4,12-16" quer dizer
     que o emote 25 ocupa os caracteres 0 a 4 e 12 a 16. As posições contam
     PONTOS DE CÓDIGO, não bytes nem unidades de UTF-16 — então quebro o
     texto com Array.from, senão qualquer emoji antes de um emote desloca
     tudo e as imagens saem no lugar errado.
     ------------------------------------------------------------------ */
  function comEmotes(texto, tagEmotes, mostrar) {
    var letras = Array.from(texto);
    if (!mostrar || !tagEmotes) return esc(letras.join(''));

    var faixas = [];
    tagEmotes.split('/').forEach(function (bloco) {
      var p = bloco.split(':');
      if (p.length !== 2) return;
      p[1].split(',').forEach(function (faixa) {
        var q = faixa.split('-');
        faixas.push({ id: p[0], de: +q[0], ate: +q[1] });
      });
    });
    if (!faixas.length) return esc(letras.join(''));
    faixas.sort(function (a, b) { return a.de - b.de; });

    var saida = '', cursor = 0;
    faixas.forEach(function (f) {
      if (f.de < cursor) return;                 /* faixas sobrepostas: ignora */
      saida += esc(letras.slice(cursor, f.de).join(''));
      var nome = esc(letras.slice(f.de, f.ate + 1).join(''));
      saida += '<img class="ch__emote" alt="' + nome + '" title="' + nome + '" src="'
             + 'https://static-cdn.jtvnw.net/emoticons/v2/' + encodeURIComponent(f.id) + '/default/dark/2.0">';
      cursor = f.ate + 1;
    });
    saida += esc(letras.slice(cursor).join(''));
    return saida;
  }

  /* Um pulso que sobrevive ao OBS esconder a fonte.
     setInterval é freado pra 1x por minuto quando a fonte não é pintada, e aí
     mensagem velha ficaria pendurada na tela por um minuto depois da hora. */
  function criaPulso(ms, fn) {
    try {
      var fonte = 'onmessage=function(){setInterval(function(){postMessage(0)},' + ms + ')}';
      var w = new Worker(URL.createObjectURL(new Blob([fonte], { type: 'text/javascript' })));
      w.onmessage = fn;
      w.postMessage(0);
      return w;
    } catch (e) { return null; }
  }

  var MOLDE =
    '<div class="ch__palco">' +
      '<div class="ch__pista"></div>' +
    '</div>';

  function mount(root, cfgInicial) {
    root.classList.add('ch', 'rl');       /* 'rl' traz os tokens de aparência */
    root.innerHTML = MOLDE;

    var pista = root.querySelector('.ch__pista');
    var palco = root.querySelector('.ch__palco');
    var cfg = R.sanitize(cfgInicial);
    var mensagens = [];                   /* {el, nasceu} */
    var soquete = null, espera = 1000, canalLigado = '', morto = false;

    /* ---------------- aparência ---------------- */
    function aplica() {
      /* A tipografia, a cor, o contorno, a sombra, o brilho e a caixa de
         fundo saem daqui — o mesmo desenhista dos outros overlays. Sem esta
         chamada as variaveis do clock.css ficam no padrao de seguranca, e o
         chat aparece com 96px numa fonte que ninguem escolheu. */
      R.aplicaEstilo(root, cfg);

      var s = root.style;
      s.setProperty('--ch-larg', cfg.clarg + 'px');
      s.setProperty('--ch-gap', cfg.cgap + 'px');
      s.setProperty('--ch-nick', (cfg.cnicksize / 100) + '');
      s.setProperty('--ch-bolha', R.rgba ? R.rgba(cfg.cbcor, cfg.cbopac) : cfg.cbcor);
      s.setProperty('--ch-braio', cfg.cbraio + 'px');
      s.setProperty('--ch-bpad', cfg.cbpad + 'px');

      root.classList.toggle('ch--bolha', !!cfg.cbolha);
      root.classList.toggle('ch--cima', cfg.cdir === 'cima');
      root.classList.toggle('ch--nick-linha', cfg.cnickpos === 'linha');
      root.classList.toggle('ch--nick-abaixo', cfg.cnickpos === 'abaixo');
      root.classList.toggle('ch--sem-nick', !cfg.cnick);

      /* A PERSPECTIVA.
         perspective() menor = deformação mais forte. Fica no palco e não em
         cada mensagem: aplicada por mensagem, cada uma teria a própria fuga
         e o conjunto não pareceria um plano só. */
      var t = [];
      /* O deslocamento do arrasto vem PRIMEIRO: transform aplica da direita
         pra esquerda, e translate depois do rotate faria a caixa andar no
         eixo já girado — arrastar pra direita a mandaria na diagonal. */
      if (cfg.nx || cfg.ny) t.push('translate(' + cfg.nx + 'px,' + cfg.ny + 'px)');
      if (cfg.c3d) t.push('perspective(' + cfg.c3dprof + 'px) rotateX(' + cfg.c3dang + 'deg)');
      if (cfg.cgirar) t.push('rotate(' + cfg.cgirar + 'deg)');
      palco.style.transform = t.join(' ');
      palco.style.transformOrigin = cfg.cdir === 'cima' ? 'center top' : 'center bottom';
    }

    /* ---------------- mensagens ---------------- */
    function poe(tags, texto) {
      var el = document.createElement('div');
      el.className = 'ch__msg ch__entra';

      var quem = tags['display-name'] || tags.login || 'alguém';
      var cor = (cfg.cnickauto && tags.color) ? tags.color : cfg.cnickcor;

      var html = '';
      if (cfg.cnick) {
        html += '<span class="ch__nick" style="color:' + esc(cor) + '">' + esc(quem)
             +  (cfg.cnickpos === 'linha' ? '<i>' + esc(cfg.csep) + '</i>' : '') + '</span>';
      }
      html += '<span class="ch__txt">' + comEmotes(texto, tags.emotes, cfg.cemotes) + '</span>';
      el.innerHTML = html;

      if (cfg.cdir === 'cima') pista.insertBefore(el, pista.firstChild);
      else pista.appendChild(el);

      mensagens.push({ el: el, nasceu: Date.now() });
      /* A animação de entrada some sozinha: deixá-la na classe faria o
         navegador reanimar tudo a cada repintura da lista. */
      setTimeout(function () { el.classList.remove('ch__entra'); }, 600);

      apara();
    }

    function tira(m) {
      m.el.classList.add('ch__sai');
      setTimeout(function () { if (m.el.parentNode) m.el.parentNode.removeChild(m.el); }, 400);
    }

    function apara() {
      while (mensagens.length > cfg.cmax) tira(mensagens.shift());
    }

    /* Some com as velhas. Roda no pulso do Worker, não num setTimeout por
       mensagem: com a fonte escondida no OBS os setTimeout congelam, e ao
       voltar todas sumiriam de uma vez. */
    function limpaVelhas() {
      if (!cfg.cvida) return;
      var limite = Date.now() - cfg.cvida * 1000;
      while (mensagens.length && mensagens[0].nasceu < limite) tira(mensagens.shift());
    }

    /* ---------------- ligação com o chat ---------------- */
    function ligar() {
      if (morto || !cfg.canal) return;
      canalLigado = cfg.canal;

      try { soquete = new WebSocket(IRC); } catch (e) { return religar(); }

      soquete.onopen = function () {
        espera = 1000;
        /* justinfan + um número qualquer é como a Twitch deixa entrar sem
           conta. Só leitura, e é exatamente o que a gente precisa. */
        soquete.send('CAP REQ :twitch.tv/tags twitch.tv/commands');
        soquete.send('PASS SCHMOOPIIE');
        soquete.send('NICK justinfan' + Math.floor(Math.random() * 90000 + 10000));
        soquete.send('JOIN #' + cfg.canal);
      };

      soquete.onmessage = function (e) {
        String(e.data).split('\r\n').forEach(function (linha) {
          if (!linha) return;
          /* Sem responder o PING a Twitch derruba a conexão em minutos. */
          if (linha.indexOf('PING') === 0) { soquete.send('PONG :tmi.twitch.tv'); return; }
          var m = analisar(linha);
          if (m) poe(m.tags, m.texto);
        });
      };

      soquete.onclose = religar;
      soquete.onerror = function () { try { soquete.close(); } catch (e) {} };
    }

    function religar() {
      if (morto) return;
      setTimeout(ligar, espera);
      espera = Math.min(espera * 1.7, 20000);   /* devagar: cair em rajada é pior */
    }

    function update(novo) {
      var antes = cfg.canal;
      cfg = R.sanitize(novo);
      aplica();
      apara();
      if (cfg.canal !== antes || (cfg.canal && !soquete)) {
        try { if (soquete) soquete.close(); } catch (e) {}
        soquete = null;
        ligar();
      }
    }

    aplica();
    ligar();
    var pulso = criaPulso(500, limpaVelhas);
    var reserva = setInterval(limpaVelhas, 1000);

    return {
      update: update,
      config: function () { return cfg; },
      /* Só o editor usa: enche a tela com frases de mentira pra dar pra ver
         o desenho sem depender de alguém falar no chat naquele instante. */
      exemplo: function () {
        pista.innerHTML = '';
        mensagens = [];
        [
          ['Fulaninha', '#E4735A', 'boa live!! esse boss é osso'],
          ['zé_do_chat', '#45B8BF', 'kkkkkkkk'],
          ['Marmota', '#8FD07A', 'primeira vez aqui, já segui'],
          ['ovniverde', '#D3A244', 'vai encarar de novo?'],
          ['Tempestade', '#B57BE0', 'clipei isso'],
        ].forEach(function (p) {
          poe({ 'display-name': p[0], color: p[1], login: p[0].toLowerCase() }, p[2]);
        });
      },
      destroy: function () {
        morto = true;
        clearInterval(reserva);
        if (pulso) pulso.terminate();
        try { if (soquete) soquete.close(); } catch (e) {}
        root.innerHTML = '';
      },
    };
  }

  global.Chat = { mount: mount };
})(window);
