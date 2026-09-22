/* tts.js — o que o chat manda falar, falado na máquina de quem transmite.
 *
 * O SOM SAI DAQUI, E NUNCA DO NOSSO SERVIDOR. Esta fonte já roda no OBS,
 * na máquina da pessoa, e o navegador já sabe falar. TTS cobrado por
 * caractere numa hospedagem compartilhada seria conta aberta, e um canal
 * movimentado sozinho estouraria ela.
 *
 * AS VOZES SÃO JEITOS DE FALAR. Cada uma é a voz do sistema com outro tom
 * e outra velocidade. O navegador NÃO deixa passar a fala por um filtro de
 * áudio — o som do speechSynthesis vai direto pra saída e não dá pra
 * capturar —, então "robô" aqui é tom muito grave e fala arrastada, e não
 * um efeito por cima. É honesto chamar de jeito de falar, não de filtro.
 *
 * PRA SOMAR UMA VOZ: uma linha em VOZES e a mesma em TTS_VOZES, no
 * api/lib/tts.php. O conferir.js compara as duas e reclama se divergirem.
 */
(function (global) {
  'use strict';

  var R = global.Relogio;

  /* tom 0.1–2.0 (grave embaixo) · vel 0.5–2.0 (devagar embaixo) */
  var VOZES = {
    padrao:    { nome: 'Padrão',    tom: 1.0, vel: 1.0 },
    grave:     { nome: 'Grave',     tom: 0.5, vel: 0.95 },
    agudo:     { nome: 'Agudo',     tom: 1.7, vel: 1.05 },
    crianca:   { nome: 'Criança',   tom: 1.9, vel: 1.15 },
    narrador:  { nome: 'Narrador',  tom: 0.85, vel: 0.85 },
    apressado: { nome: 'Apressado', tom: 1.1, vel: 1.6 },
    arrastado: { nome: 'Arrastado', tom: 0.9, vel: 0.65 },
    robo:      { nome: 'Robô',      tom: 0.2, vel: 0.8 },
    gigante:   { nome: 'Gigante',   tom: 0.1, vel: 0.7 },
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
      var fala;
      try {
        fala = new global.SpeechSynthesisUtterance(item.texto);
      } catch (e) { falando = null; return; }

      fala.pitch = v.tom;
      fala.rate = v.vel;
      fala.volume = Math.max(0, Math.min(1, (cfg.tvol == null ? 100 : cfg.tvol) / 100));
      var base = vozBase();
      if (base) { fala.voice = base; fala.lang = base.lang; }
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
      var reserva = setTimeout(acabou, 4000 + item.texto.length * 120);

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
      calar: function () {
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
