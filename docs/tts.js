/* tts.js — o que o chat manda falar, falado na máquina de quem transmite.
 *
 * O SOM SAI DAQUI, E NUNCA DO NOSSO SERVIDOR. Esta fonte já roda no OBS,
 * na máquina da pessoa, e o navegador já sabe falar. TTS cobrado por
 * caractere numa hospedagem compartilhada seria conta aberta, e um canal
 * movimentado sozinho estouraria ela.
 *
 * AS VOZES SÃO TOM, VELOCIDADE E VOLUME. É o que o navegador dá: o som do
 * speechSynthesis vai direto pra saída e não dá pra passar por filtro.
 *
 * TOM ABAIXO DE 0.6 NÃO É VOZ, É RUÍDO. A primeira versão tinha "robô" em
 * 0.2 e "gigante" em 0.1 — o navegador aceita esses números sem reclamar,
 * e o motor de voz devolve um chiado que ninguém entende. Era o "som
 * maluco" que apareceu na live. A faixa que se entende é 0.6 a 1.8, e
 * nada aqui sai dela.
 *
 * Por isso não há "robô": sem filtro de áudio, ele só sairia com um tom
 * que estraga a fala. No lugar entrou o sussurro, que usa o volume — uma
 * terceira manopla, e essa funciona.
 *
 * PRA SOMAR UMA VOZ: uma linha em VOZES e a mesma em TTS_VOZES, no
 * api/lib/tts.php. O conferir.js compara as duas e reclama se divergirem.
 */
(function (global) {
  'use strict';

  var R = global.Relogio;

  /* tom 0.6–1.8 · vel 0.6–1.6 · vol 0–1 (multiplica o volume do overlay) */
  var VOZES = {
    padrao:    { nome: 'Padrão',    tom: 1.00, vel: 1.00 },
    grave:     { nome: 'Grave',     tom: 0.72, vel: 0.95 },
    agudo:     { nome: 'Agudo',     tom: 1.45, vel: 1.05 },
    crianca:   { nome: 'Criança',   tom: 1.70, vel: 1.15 },
    narrador:  { nome: 'Narrador',  tom: 0.86, vel: 0.85 },
    apressado: { nome: 'Apressado', tom: 1.10, vel: 1.50 },
    arrastado: { nome: 'Arrastado', tom: 0.95, vel: 0.68 },
    /* Grave E devagar: é o que separa o gigante do grave, sem sair da
       faixa onde a fala continua sendo fala. */
    gigante:   { nome: 'Gigante',   tom: 0.62, vel: 0.78 },
    sussurro:  { nome: 'Sussurro',  tom: 1.05, vel: 0.92, vol: 0.40 },
  };

  var MOLDE =
    '<div class="tts">' +
    '  <div class="tts__quem"></div>' +
    '  <div class="tts__texto"></div>' +
    '</div>';

  function mount(raiz, config) {
    var cfg = R.sanitize(config);
    raiz.innerHTML = MOLDE;

    var caixa = raiz.querySelector('.tts');
    var quemEl = raiz.querySelector('.tts__quem');
    var textoEl = raiz.querySelector('.tts__texto');

    var fila = [];
    var falando = null;
    var vistos = {};          /* id já enfileirado: a config volta a cada leitura */
    /* O ÚLTIMO "calar" QUE JÁ FOI OBEDECIDO.

       Começa como "ainda não vi nenhum". O PRIMEIRO que chegar é só
       adotado, sem calar nada: ele é de antes desta fonte existir, e pode
       ser de ontem. Obedecer ele apagava a primeira leva de falas depois
       de toda recarga do overlay — quem atualizava a fonte pra testar
       perdia exatamente a mensagem que estava testando. */
    var calouEm = null;
    var viOPrimeiroCalar = false;

    /* AS VOZES DO SISTEMA CHEGAM DEPOIS.
       No Chrome a lista vem vazia na primeira leitura e só enche num evento
       — e é por isso que "não tem voz nenhuma" acontece no primeiro
       carregamento e some no segundo. */
    var doSistema = [];
    function leVozes() {
      try { doSistema = global.speechSynthesis.getVoices() || []; } catch (e) { doSistema = []; }
    }
    leVozes();
    try { global.speechSynthesis.onvoiceschanged = leVozes; } catch (e) {}

    /* A voz do idioma de quem assiste, se houver. Sem ela o navegador usa
       a dele, que costuma ler português com sotaque de inglês. */
    function vozBase() {
      var quer = (cfg.tlingua || 'pt-BR').toLowerCase();
      for (var i = 0; i < doSistema.length; i++) {
        var l = String(doSistema[i].lang || '').toLowerCase().replace('_', '-');
        if (l === quer) return doSistema[i];
      }
      for (var j = 0; j < doSistema.length; j++) {
        if (String(doSistema[j].lang || '').toLowerCase().indexOf(quer.split('-')[0]) === 0) return doSistema[j];
      }
      return null;
    }

    function mostra(item) {
      /* TEXTO DE ESTRANHO É TEXTO. textContent, nunca innerHTML: quem
         escreveu isso foi um espectador, e a regra não tem exceção. */
      quemEl.textContent = item && item.quem ? item.quem : '';
      textoEl.textContent = item ? item.texto : '';
      caixa.classList.toggle('tts--vazio', !item);
    }

    function proxima() {
      if (falando || !fila.length) return;

      var item = fila.shift();
      falando = item;
      mostra(item);

      var v = VOZES[item.voz] || VOZES.padrao;

      /* QUEM MANDOU, DITO ANTES — COM UMA FRASE FIXA NO MEIO.

         A frase entre o nome e a mensagem não é enfeite: ela é o que
         impede alguém de se passar por outra pessoa. Sem separador, uma
         mensagem começando com "fulano disse que" sairia colada no nome
         de quem resgatou e viraria a fala de dois. Com "resgatou uma
         mensagem de voz e falou:" no meio, o chat ouve onde um acaba e o
         outro começa.

         O nome vem da Twitch, e não do que a pessoa digitou. */
      var dizer = item.texto;
      if (cfg.tintro !== 0 && item.quem) {
        dizer = item.quem + ' resgatou uma mensagem de voz e falou: ' + item.texto;
      }

      var fala;
      try {
        fala = new global.SpeechSynthesisUtterance(dizer);
      } catch (e) { falando = null; return; }

      fala.pitch = v.tom;
      fala.rate = v.vel;
      /* O volume do overlay vezes o da voz: o sussurro sai mais baixo que
         o resto, mas continua obedecendo o volume que a pessoa escolheu. */
      var vol = (cfg.tvol == null ? 100 : cfg.tvol) / 100;
      fala.volume = Math.max(0, Math.min(1, vol * (v.vol == null ? 1 : v.vol)));

      var doIdioma = vozBase();
      if (doIdioma) { fala.voice = doIdioma; fala.lang = doIdioma.lang; }
      else fala.lang = cfg.tlingua || 'pt-BR';

      /* Se a fala morrer no meio (erro, voz sumiu, aba dormindo), a fila
         não pode parar pra sempre — o relógio destrava. */
      var destravou = false;
      function acabou() {
        if (destravou) return;
        destravou = true;
        clearTimeout(reserva);
        falando = null;
        mostra(null);
      }
      fala.onend = acabou;
      fala.onerror = acabou;
      var reserva = setTimeout(acabou, 4000 + dizer.length * 120);

      try { global.speechSynthesis.speak(fala); } catch (e) { acabou(); }
    }

    return {
      update: function (novo) { cfg = R.sanitize(novo); },
      config: function () { return cfg; },

      /* O overlay.html entrega a fila por aqui, na mesma leitura que traz a
         config — sem canal novo. */
      falar: function (lista) {
        for (var i = 0; i < (lista || []).length; i++) {
          var it = lista[i];
          if (!it || !it.texto || vistos[it.id]) continue;
          vistos[it.id] = 1;
          fila.push({ id: it.id, texto: String(it.texto), voz: String(it.voz || 'padrao'),
                      quem: String(it.quem || '') });
        }
        proxima();
      },

      /* O botão de ouvir do editor, e o "pular" do painel. */
      exemplo: function (voz) {
        fila.push({ id: 'ex' + Date.now(), voz: voz || cfg.tvoz || 'padrao',
                    quem: 'fulaninha', texto: 'É assim que eu falo nesta voz.' });
        proxima();
      },
      pular: function () {
        try { global.speechSynthesis.cancel(); } catch (e) {}
        falando = null;
        mostra(null);
        proxima();
      },
      /* O servidor manda o instante do último "calar". Obedecer só uma vez
         por instante: sem isso, cada leitura cortaria a fala de novo e o
         TTS nunca mais diria nada. */
      calar: function (quando) {
        if (quando && quando === calouEm) return;

        /* O primeiro só é anotado. Daqui pra frente, um valor novo quer
           dizer que a pessoa clicou agora — e aí sim cala. */
        if (!viOPrimeiroCalar) { viOPrimeiroCalar = true; calouEm = quando || 1; return; }

        calouEm = quando || 1;
        fila.length = 0;
        try { global.speechSynthesis.cancel(); } catch (e) {}
        falando = null;
        mostra(null);
      },

      vozes: VOZES,
      destroy: function () {
        try { global.speechSynthesis.cancel(); } catch (e) {}
        raiz.innerHTML = '';
      },
    };
  }

  global.Tts = { mount: mount, vozes: VOZES };
})(window);
