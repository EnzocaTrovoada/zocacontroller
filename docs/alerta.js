/**
 * alerta.js — sub, follow, bits e doação aparecendo na tela.
 *
 * ---------------------------------------------------------------------
 * DE ONDE VÊM OS EVENTOS
 *
 * Da mesma tabela que alimenta o feed. O feed mostra os últimos e pronto; o
 * alerta precisa saber o que JÁ mostrou, senão toda recarga da fonte no OBS
 * dispararia de novo a fila de subs do dia inteiro na cara de quem assiste.
 * Por isso cada evento vem com id, e a primeira resposta só serve pra marcar
 * onde estávamos — ela não toca nada.
 *
 * UM DE CADA VEZ
 *
 * Dez subs de presente não podem virar dez alertas empilhados. Eles entram
 * numa fila e passam um atrás do outro, com um respiro entre eles. (O pacote
 * de presentes já chega junto do servidor como uma linha só.)
 *
 * O SOM É FEITO NA HORA
 *
 * Nada de arquivo pra hospedar, subir ou dar 404 no meio da live: os quatro
 * sons são desenhados em WebAudio na hora de tocar. Também é o único jeito
 * de o alerta funcionar antes de existir uma tela de upload.
 */
(function (global) {
  'use strict';

  var R = global.Relogio;

  var MOLDE =
    '<div class="al__caixa">' +
      '<div class="al__linha"></div>' +
    '</div>';

  /* Quem dispara o quê. O tipo vem do servidor; sub1/2/3 são os três tiers. */
  var LIGA = {
    sub1: 'asub', sub2: 'asub', sub3: 'asub',
    follow: 'afollow', bits: 'abits', real: 'areal',
  };

  function textoDe(cfg, e) {
    var molde =
        e.tipo === 'follow' ? cfg.atfollow
      : e.tipo === 'bits'   ? cfg.atbits
      : e.tipo === 'real'   ? cfg.atreal
      : (e.presente ? cfg.atpres : cfg.atsub);

    return String(molde || '')
      .replace(/\{quem\}/g, e.quem || 'alguém')
      .replace(/\{quanto\}/g, String(e.quantidade == null ? 1 : e.quantidade));
  }

  /* Passa no filtro? O mínimo de bits e de reais existe pra live movimentada
     não virar um alerta a cada dois segundos por causa de 1 bit. */
  function passa(cfg, e) {
    var chave = LIGA[e.tipo];
    if (!chave || !cfg[chave]) return false;
    if (e.tipo === 'bits' && (e.quantidade || 0) < cfg.abitsmin) return false;
    if (e.tipo === 'real' && (e.quantidade || 0) < cfg.arealmin) return false;
    return true;
  }

  /* ------------------------------------------------------------------
     O SOM

     Quatro timbres desenhados na hora. O contexto de áudio nasce suspenso em
     navegador comum e só destrava depois de um clique — dentro do OBS não há
     clique nenhum, então tentamos destravar a cada alerta. Se não destravar,
     o alerta continua aparecendo: som é enfeite, a mensagem é o recado.
     ------------------------------------------------------------------ */
  var TIMBRES = {
    /* [frequência inicial, frequência final, duração, forma de onda] por nota */
    sino:     [[880, 880, 0.5, 'sine'], [1320, 1320, 0.6, 'sine']],
    moeda:    [[988, 988, 0.08, 'square'], [1319, 1319, 0.36, 'square']],
    fanfarra: [[523, 523, 0.14, 'triangle'], [659, 659, 0.14, 'triangle'],
               [784, 784, 0.14, 'triangle'], [1047, 1047, 0.5, 'triangle']],
    sopro:    [[180, 900, 0.42, 'sawtooth']],
  };

  function fazSom(ctx, nome, volume) {
    var notas = TIMBRES[nome];
    if (!ctx || !notas || volume <= 0) return;
    var t0 = ctx.currentTime + 0.02;

    notas.forEach(function (n, i) {
      var osc = ctx.createOscillator();
      var g = ctx.createGain();
      var comeca = t0 + (nome === 'fanfarra' || nome === 'moeda' ? i * 0.12 : i * 0.06);
      var dura = n[2];

      osc.type = n[3];
      osc.frequency.setValueAtTime(n[0], comeca);
      if (n[1] !== n[0]) osc.frequency.exponentialRampToValueAtTime(n[1], comeca + dura);

      /* Ataque rápido e queda longa: sem a queda, o som corta seco e parece
         defeito de áudio em vez de aviso. */
      g.gain.setValueAtTime(0.0001, comeca);
      g.gain.exponentialRampToValueAtTime(volume, comeca + 0.012);
      g.gain.exponentialRampToValueAtTime(0.0001, comeca + dura);

      osc.connect(g); g.connect(ctx.destination);
      osc.start(comeca);
      osc.stop(comeca + dura + 0.05);
    });
  }

  function mount(root, cfgInicial) {
    root.classList.add('al', 'rl');
    root.innerHTML = MOLDE;

    var caixa = root.querySelector('.al__caixa');
    var linha = root.querySelector('.al__linha');

    var cfg = R.sanitize(cfgInicial);
    var fila = [];
    var mostrando = null;
    var trocaEm = 0;
    var vistoAte = -1;        /* -1 = ainda não sei onde estamos */
    var som = null;
    var pulso = null, reserva = null;
    var somProprio = null;      /* <audio> do arquivo que a pessoa mandou */

    function audio() {
      try {
        if (!som) {
          var AC = global.AudioContext || global.webkitAudioContext;
          if (AC) som = new AC();
        }
        /* Destravar é assíncrono e pode falhar; não dá pra esperar por ele. */
        if (som && som.state === 'suspended') som.resume();
      } catch (e) { som = null; }
      return som;
    }

    /* O ENDEREÇO DO SOM PRÓPRIO VEM DO SERVIDOR, NÃO DA CONFIG.
        A config guarda só qual som foi escolhido; montar a URL aqui exigiria
        que a fonte do OBS soubesse onde a API mora e qual é a chave — e ela
        não tem por que saber nem uma coisa nem outra. */
    function poeSom(url) {
      if (!url) { somProprio = null; return; }
      if (somProprio && somProprio.src === url) return;
      somProprio = new Audio(url);
      /* Carrega antes de precisar: buscar o arquivo no instante do sub
         atrasaria o som em relação ao que aparece na tela. */
      somProprio.preload = 'auto';
      try { somProprio.load(); } catch (e) {}
    }

    function tocaSom() {
      if (cfg.asom === 'nenhum' || cfg.avol <= 0) return;

      if (cfg.asom === 'proprio') {
        if (!somProprio) return;
        try {
          somProprio.volume = Math.max(0, Math.min(1, cfg.avol / 100));
          /* Volta pro começo: dois alertas seguidos com o mesmo arquivo, sem
             isto, o segundo não tocaria — o áudio já estava no fim. */
          somProprio.currentTime = 0;
          var p = somProprio.play();
          if (p && p.catch) p.catch(function () {});
        } catch (e) {}
        return;
      }
      fazSom(audio(), cfg.asom, cfg.avol / 100);
    }

    function aplica() {
      R.aplicaEstilo(root, cfg);
      root.style.setProperty('--al-nome', cfg.anome);
      root.classList.remove('al--descer', 'al--zoom', 'al--surgir', 'al--lado');
      root.classList.add('al--' + cfg.aentre);
    }

    /* Os eventos chegam do servidor do mais novo pro mais velho. */
    function eventos(lista) {
      if (!lista || !lista.length) return;

      /* PRIMEIRA RESPOSTA NÃO TOCA NADA.
         Ela só diz onde a live está agora. Sem isto, abrir o OBS no meio da
         tarde despejaria todos os subs da manhã de uma vez. */
      if (vistoAte < 0) {
        vistoAte = lista[0].id || 0;
        return;
      }

      /* PACOTE DE PRESENTES AINDA CHEGANDO: ESPERA A PRÓXIMA CONSULTA.

         Dez subs de presente entram na tabela um a um. Se a consulta cai no
         meio, o servidor junta só os que chegaram e o alerta diz "presenteou
         4 subs" — e na consulta seguinte, com o pacote fechado, o id do grupo
         mudou e ele dispara DE NOVO dizendo 10. Dois alertas para um presente
         só, e o primeiro com o número errado.

         Segurar os presentes recém-chegados resolve: a consulta é de 15 em 15
         segundos, então esperar um ciclo é esperar o pacote fechar. A hora vem
         do servidor, não da máquina de quem transmite. */
      var agora = Math.floor(R.agoraServidor() / 1000);
      var novos = [];
      var ateAqui = vistoAte;

      for (var i = lista.length - 1; i >= 0; i--) {     /* do mais velho pro mais novo */
        var e = lista[i];
        if (!e.id || e.id <= vistoAte) continue;        /* já passou */
        if (e.presente && e.quando && agora - e.quando < 12) break;   /* ainda chegando */
        ateAqui = e.id;
        if (passa(cfg, e)) novos.push(e);
      }
      vistoAte = ateAqui;

      /* O laço já percorreu do mais velho pro mais novo, que é a ordem em
         que as coisas aconteceram — a fila sai na ordem certa sem inverter. */
      for (var j = 0; j < novos.length; j++) fila.push(novos[j]);
    }

    function toca(e) {
      mostrando = e;
      linha.innerHTML = '';

      var texto = textoDe(cfg, e);
      /* O nome vai numa peça própria pra poder ter cor própria — é o que o
         olho procura primeiro quando o alerta pisca na tela. */
      var quem = e.quem || 'alguém';
      var corte = texto.indexOf(quem);
      if (corte >= 0) {
        if (corte > 0) linha.appendChild(document.createTextNode(texto.slice(0, corte)));
        var b = document.createElement('b');
        b.className = 'al__quem';
        b.textContent = quem;
        linha.appendChild(b);
        linha.appendChild(document.createTextNode(texto.slice(corte + quem.length)));
      } else {
        linha.textContent = texto;
      }

      root.classList.remove('al--vazio', 'al--saindo');
      /* Tira e devolve pra animação recomeçar no alerta seguinte. */
      root.classList.remove('al--entrando');
      void root.offsetWidth;
      root.classList.add('al--entrando');

      tocaSom();
      trocaEm = Date.now() + cfg.atempo * 1000;
    }

    function ciclo() {
      var agora = Date.now();

      if (mostrando) {
        if (agora < trocaEm) return;
        if (!root.classList.contains('al--saindo')) {
          root.classList.add('al--saindo');
          trocaEm = agora + 420;                 /* o tempo da saída no CSS */
          return;
        }
        mostrando = null;
        root.classList.add('al--vazio');
        root.classList.remove('al--saindo', 'al--entrando');
        /* Um respiro entre um alerta e o outro: emendados viram borrão. */
        trocaEm = agora + 500;
        return;
      }

      if (agora < trocaEm) return;
      if (fila.length) toca(fila.shift());
    }

    function update(novo) {
      cfg = R.sanitize(novo);
      aplica();
    }

    aplica();
    root.classList.add('al--vazio');

    /* O pulso é de Worker porque fonte escondida no OBS tem o setInterval
       preso em uma vez por minuto — e um alerta que aparece um minuto depois
       do sub não é um alerta. */
    pulso = (function () {
      try {
        var f = 'onmessage=function(){setInterval(function(){postMessage(0)},200)}';
        var w = new Worker(URL.createObjectURL(new Blob([f], { type: 'text/javascript' })));
        w.onmessage = ciclo;
        w.postMessage(0);
        return w;
      } catch (e) { return null; }
    })();
    reserva = setInterval(ciclo, 500);

    return {
      update: update,
      config: function () { return cfg; },
      feed: eventos,          /* o overlay.html entrega os eventos por aqui */
      som: poeSom,            /* e o endereço do som próprio, por aqui */
      ouvir: tocaSom,         /* o botão "Ouvir" do editor */
      exemplo: function () {
        vistoAte = 0;
        fila.push({ id: 1, tipo: 'sub1', quem: 'fulaninha', quantidade: 1, presente: false });
        fila.push({ id: 2, tipo: 'bits', quem: 'beltrano', quantidade: 500 });
        fila.push({ id: 3, tipo: 'sub1', quem: 'ciclana', quantidade: 5, presente: true });
      },
      destroy: function () {
        clearInterval(reserva);
        if (pulso) pulso.terminate();
        try { if (som) som.close(); } catch (e) {}
        root.innerHTML = '';
      },
    };
  }

  global.Alerta = { mount: mount };
})(window);
