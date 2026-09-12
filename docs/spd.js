/* Cronômetro de speedrun — o desenhista.
 *
 * A ideia é a mesma do LiveSplit: uma lista de trechos, o tempo acumulado em
 * cada um, a diferença contra o seu recorde, e o relógio grande embaixo.
 *
 * O QUE ESTE ARQUIVO NÃO FAZ: contar o tempo por conta própria. Ele lê
 * "quando a corrida começou" e faz a subtração a cada quadro. Isso é o que
 * mantém o número certo mesmo quando o OBS engasga, quando a fonte fica
 * escondida (o navegador freia o timer) ou quando a página recarrega no meio
 * da run — três coisas que um contador incremental erraria em silêncio, e
 * numa speedrun errar o tempo em silêncio é o pior defeito possível.
 */
(function (global) {
  'use strict';

  var R = global.Relogio;

  function agora() { return R.agoraServidor(); }

  /* ------------------------------------------------------------------ *
   *  Números virando texto
   * ------------------------------------------------------------------ */

  /* Abaixo de uma hora mostra décimo de segundo, acima troca por hora cheia:
     décimo de segundo numa run de três horas é ruído, e numa de dois minutos
     é a informação toda. */
  function tempo(ms, comDecimo) {
    if (ms == null || !isFinite(ms)) return '—';
    var neg = ms < 0;
    ms = Math.abs(ms);

    var total = Math.floor(ms / 1000);
    var h = Math.floor(total / 3600);
    var m = Math.floor((total % 3600) / 60);
    var s = total % 60;
    var d = Math.floor((ms % 1000) / 100);

    var txt;
    if (h > 0) txt = h + ':' + dois(m) + ':' + dois(s);
    else if (comDecimo) txt = m + ':' + dois(s) + '.' + d;
    else txt = m + ':' + dois(s);

    return (neg ? '-' : '') + txt;
  }

  /* A diferença é sempre curta e sempre assinada: quem olha quer saber se
     está ganhando ou perdendo, não que horas são. */
  function delta(ms) {
    if (ms == null || !isFinite(ms)) return '';
    var sinal = ms < 0 ? '−' : '+';       /* menos de verdade, não hífen */
    var a = Math.abs(ms);
    var total = Math.floor(a / 1000);

    if (total >= 60) {
      var m = Math.floor(total / 60);
      if (m >= 60) return sinal + Math.floor(m / 60) + ':' + dois(m % 60) + ':' + dois(total % 60);
      return sinal + m + ':' + dois(total % 60);
    }
    return sinal + total + '.' + Math.floor((a % 1000) / 100);
  }

  function dois(n) { return n < 10 ? '0' + n : String(n); }

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
    });
  }

  /* ------------------------------------------------------------------ *
   *  A corrida
   * ------------------------------------------------------------------ */

  /* Duas representações do mesmo tempo, e só uma vale por vez — igual ao
     subathon. Correndo, o que vale é QUANDO COMEÇOU; parado, é QUANTO JÁ
     CORREU. Guardar as duas ao mesmo tempo é o caminho curto pra elas
     discordarem. */
  function decorrido(corr) {
    if (!corr) return 0;
    if (corr.e === 'correndo') return Math.max(0, agora() - (corr.i || 0));
    return Math.max(0, corr.pa || 0);
  }

  function lista(v) { return Object.prototype.toString.call(v) === '[object Array]' ? v : []; }

  /* ------------------------------------------------------------------ *
   *  Montagem
   * ------------------------------------------------------------------ */

  function mount(root, cfgInicial) {
    var cfg = cfgInicial;
    var relogio = null;

    root.className = 'sp';
    root.innerHTML =
      '<div class="sp__caixa">' +
        '<div class="sp__topo">' +
          '<div class="sp__jogo"></div>' +
          '<div class="sp__cat"></div>' +
        '</div>' +
        '<div class="sp__trechos"></div>' +
        '<div class="sp__relogio"><span class="sp__t"></span></div>' +
        '<div class="sp__rodape"><span class="sp__somaR"></span><span class="sp__soma"></span></div>' +
      '</div>';

    var elJogo    = root.querySelector('.sp__jogo');
    var elCat     = root.querySelector('.sp__cat');
    var elTrechos = root.querySelector('.sp__trechos');
    var elT       = root.querySelector('.sp__t');
    var elRodape  = root.querySelector('.sp__rodape');
    var elSomaR   = root.querySelector('.sp__somaR');
    var elSoma    = root.querySelector('.sp__soma');

    /* ------------------------------------------------------ o estilo */
    function estilo() {
      R.aplicaEstilo(root, cfg);
      var s = root.style;
      s.setProperty('--sp-linha', cfg.sptam + 'px');
      s.setProperty('--sp-ganho', cfg.spgan);
      s.setProperty('--sp-perda', cfg.spper);
      s.setProperty('--sp-ouro', cfg.spour);
      s.setProperty('--sp-larg', cfg.splarg + 'px');
      root.classList.toggle('sp--sem-pb', !cfg.sppb);
      root.classList.toggle('sp--sem-delta', !cfg.spdel);
      elRodape.style.display = cfg.spsob ? '' : 'none';
    }

    /* --------------------------------------------- a lista de trechos */
    function pintaTrechos() {
      var trechos = lista(cfg.sptre);
      var corr = cfg.spcor || {};
      var feitos = lista(corr.s);
      var atual = feitos.length;               /* o trecho em que estamos */

      if (!trechos.length) {
        elTrechos.innerHTML = '<div class="sp__vazio">Nenhum trecho ainda</div>';
        return;
      }

      /* A JANELA. Uma run de 40 trechos não cabe na tela e nem deveria: o
         que interessa é onde você está. A janela segue o trecho atual e só
         encosta nas pontas quando chega nelas. */
      var quantos = Math.min(cfg.spver, trechos.length);
      var de = Math.max(0, Math.min(atual - Math.floor(quantos / 2), trechos.length - quantos));

      var html = '';
      for (var i = de; i < de + quantos; i++) {
        var tr = trechos[i] || {};
        var passou = i < feitos.length;
        var ehAtual = (i === atual && corr.e === 'correndo');

        var cls = 'sp__l';
        if (ehAtual) cls += ' sp__l--atual';
        if (passou) cls += ' sp__l--feito';

        /* A coluna do meio: o tempo de referência quando o trecho ainda não
           chegou, a diferença quando já passou. */
        var meio = '', meioCls = '';
        if (passou) {
          if (tr.p != null) {
            var d = feitos[i] - tr.p;
            meio = delta(d);
            meioCls = d < 0 ? 'sp__ganho' : 'sp__perda';
          }
          /* Ouro por cima de tudo: trecho mais rápido que você já fez vale
             mais do que estar na frente do recorde geral. */
          var seg = feitos[i] - (i > 0 ? feitos[i - 1] : 0);
          if (tr.b != null && seg < tr.b) meioCls = 'sp__ouro';
        }

        var direita = passou ? tempo(feitos[i], false)
                             : (tr.p != null ? tempo(tr.p, false) : '—');

        /* O ícone vem com o endereço pronto do servidor: a fonte do OBS não
           sabe onde a API mora. */
        var ic = tr.iu ? '<img class="sp__ic" src="' + esc(tr.iu) + '" alt="">' : '';

        html += '<div class="' + cls + '">'
              +   '<span class="sp__nome">' + ic + esc(tr.n || ('Trecho ' + (i + 1))) + '</span>'
              +   '<span class="sp__d ' + meioCls + '">' + meio + '</span>'
              +   '<span class="sp__pb">' + direita + '</span>'
              + '</div>';
      }
      elTrechos.innerHTML = html;
    }

    /* ------------------------------------------------- soma dos melhores */
    function pintaRodape() {
      if (!cfg.spsob) return;
      var trechos = lista(cfg.sptre);
      var soma = 0, temTodos = true;
      for (var i = 0; i < trechos.length; i++) {
        if (trechos[i] && trechos[i].b != null) soma += trechos[i].b;
        else temTodos = false;
      }
      elSomaR.textContent = 'Soma dos melhores';
      /* Sem todos os trechos medidos a soma seria uma meta mentirosa, menor
         do que qualquer run possível. Melhor dizer que ainda não dá. */
      elSoma.textContent = (trechos.length && temTodos) ? tempo(soma, false) : '—';
    }

    /* ------------------------------------------------------- o relógio */
    function pintaRelogio() {
      var corr = cfg.spcor || {};
      var ms = decorrido(corr);
      elT.textContent = tempo(ms, true);

      root.classList.toggle('sp--correndo', corr.e === 'correndo');
      root.classList.toggle('sp--pausado', corr.e === 'pausado');
      root.classList.toggle('sp--fim', corr.e === 'fim');

      /* A cor do relógio conta a mesma história da lista: à frente do
         recorde, verde; atrás, vermelho. Só depois do primeiro trecho, que
         antes disso não há com o que comparar. */
      var trechos = lista(cfg.sptre);
      var feitos = lista(corr.s);
      var ref = null;
      if (corr.e === 'fim' && trechos.length && trechos[trechos.length - 1].p != null) {
        ref = ms - trechos[trechos.length - 1].p;
      } else if (feitos.length > 0) {
        var ult = trechos[feitos.length - 1];
        if (ult && ult.p != null) ref = feitos[feitos.length - 1] - ult.p;
      }
      root.classList.toggle('sp--frente', ref != null && ref < 0);
      root.classList.toggle('sp--atras', ref != null && ref >= 0);
    }

    function tudo() {
      estilo();
      elJogo.textContent = cfg.sptit || '';
      elCat.textContent = cfg.spcat || '';
      elJogo.style.display = cfg.sptit ? '' : 'none';
      elCat.style.display = cfg.spcat ? '' : 'none';
      pintaTrechos();
      pintaRodape();
      pintaRelogio();
    }

    /* O PULSO É SÓ DO RELÓGIO. A lista só muda quando a config muda, e
       redesenhar HTML cinquenta vezes por segundo por nada é o tipo de coisa
       que aparece como queda de quadro na live de quem já está no limite. */
    relogio = setInterval(pintaRelogio, 50);

    function update(novo) {
      var antesTre = JSON.stringify(cfg.sptre);
      var antesS = JSON.stringify((cfg.spcor || {}).s);
      cfg = novo;
      tudo();
      /* nada a fazer com o resultado: o tudo() já redesenhou. As variáveis
         acima existem só pra deixar claro o que muda a lista. */
      void antesTre; void antesS;
    }

    tudo();

    return {
      update: update,
      destroy: function () { clearInterval(relogio); }
    };
  }

  global.Speedrun = { mount: mount, tempo: tempo, delta: delta, decorrido: decorrido };
})(window);
