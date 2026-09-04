/* =====================================================================
   RELÓGIO DO ZOCA — núcleo compartilhado
   Usado pelo overlay.html (o que vai no OBS) e pelo index.html (o
   personalizador). Sem servidor, sem rede, sem banco: a hora vem do
   relógio do PC convertida pro fuso America/Sao_Paulo pelo Intl — acerta
   mesmo com o PC em outro fuso e sobrevive a um eventual retorno do
   horário de verão. Toda a configuração viaja na própria URL.
   ===================================================================== */
(function (global) {
  'use strict';

  var TZ = 'America/Sao_Paulo';
  var LOCALE = 'pt-BR';

  /* ---------------------------------------------------------------
     FONTES — todas verificadas na API css2 do Google Fonts.
     spec = o que vai depois de "family=" (pesos que existem de verdade).
     f    = pilha de fallback caso a fonte não carregue (OBS sem internet).
     --------------------------------------------------------------- */
  var FONTS = [
    { n: 'Orbitron',              spec: 'Orbitron:wght@400..900', f: 'sans',    g: 'Tech / futurista' },
    { n: 'Audiowide',             spec: 'Audiowide',              f: 'sans',    g: 'Tech / futurista' },
    { n: 'Michroma',              spec: 'Michroma',               f: 'sans',    g: 'Tech / futurista' },
    { n: 'Chakra Petch',          spec: 'Chakra+Petch:ital,wght@0,300;0,400;0,500;0,600;0,700;1,400;1,700', f: 'sans', g: 'Tech / futurista' },
    { n: 'Tourney',               spec: 'Tourney:ital,wght@0,100..900;1,100..900', f: 'sans', g: 'Tech / futurista' },
    { n: 'Rajdhani',              spec: 'Rajdhani:wght@300;400;500;600;700', f: 'sans', g: 'Tech / futurista' },

    { n: 'Bebas Neue',            spec: 'Bebas+Neue',             f: 'sans',    g: 'Impacto / condensada' },
    { n: 'Anton',                 spec: 'Anton',                  f: 'sans',    g: 'Impacto / condensada' },
    { n: 'Archivo Black',         spec: 'Archivo+Black',          f: 'sans',    g: 'Impacto / condensada' },
    { n: 'Teko',                  spec: 'Teko:wght@300..700',     f: 'sans',    g: 'Impacto / condensada' },
    { n: 'Oswald',                spec: 'Oswald:wght@200..700',   f: 'sans',    g: 'Impacto / condensada' },
    { n: 'Saira Condensed',       spec: 'Saira+Condensed:wght@100;200;300;400;500;600;700;800;900', f: 'sans', g: 'Impacto / condensada' },
    { n: 'Big Shoulders Display', spec: 'Big+Shoulders+Display:wght@100..900', f: 'sans', g: 'Impacto / condensada' },

    { n: 'Press Start 2P',        spec: 'Press+Start+2P',         f: 'mono',    g: 'Pixel / retrô' },
    { n: 'Silkscreen',            spec: 'Silkscreen:wght@400;700', f: 'mono',   g: 'Pixel / retrô' },
    { n: 'VT323',                 spec: 'VT323',                  f: 'mono',    g: 'Pixel / retrô' },
    { n: 'DotGothic16',           spec: 'DotGothic16',            f: 'sans',    g: 'Pixel / retrô' },
    { n: 'Doto',                  spec: 'Doto:wght@100..900',     f: 'mono',    g: 'Pixel / retrô' },
    { n: 'Sixtyfour',             spec: 'Sixtyfour',              f: 'mono',    g: 'Pixel / retrô' },
    { n: 'Micro 5',               spec: 'Micro+5',                f: 'mono',    g: 'Pixel / retrô' },
    { n: 'Jersey 10',             spec: 'Jersey+10',              f: 'sans',    g: 'Pixel / retrô' },

    { n: 'Share Tech Mono',       spec: 'Share+Tech+Mono',        f: 'mono',    g: 'Monoespaçada' },
    { n: 'JetBrains Mono',        spec: 'JetBrains+Mono:ital,wght@0,100..800;1,100..800', f: 'mono', g: 'Monoespaçada' },
    { n: 'Space Mono',            spec: 'Space+Mono:ital,wght@0,400;0,700;1,400;1,700', f: 'mono', g: 'Monoespaçada' },
    { n: 'IBM Plex Mono',         spec: 'IBM+Plex+Mono:ital,wght@0,100;0,200;0,300;0,400;0,500;0,600;0,700;1,400;1,700', f: 'mono', g: 'Monoespaçada' },
    { n: 'Fira Code',             spec: 'Fira+Code:wght@300..700', f: 'mono',   g: 'Monoespaçada' },
    { n: 'Nova Mono',             spec: 'Nova+Mono',              f: 'mono',    g: 'Monoespaçada' },
    { n: 'Major Mono Display',    spec: 'Major+Mono+Display',     f: 'mono',    g: 'Monoespaçada' },

    { n: 'Monoton',               spec: 'Monoton',                f: 'display', g: 'Neon / decorativa' },
    { n: 'Bungee',                spec: 'Bungee',                 f: 'display', g: 'Neon / decorativa' },
    { n: 'Bungee Shade',          spec: 'Bungee+Shade',           f: 'display', g: 'Neon / decorativa' },
    { n: 'Faster One',            spec: 'Faster+One',             f: 'display', g: 'Neon / decorativa' },
    { n: 'Rubik Glitch',          spec: 'Rubik+Glitch',           f: 'display', g: 'Neon / decorativa' },
    { n: 'Rubik Mono One',        spec: 'Rubik+Mono+One',         f: 'display', g: 'Neon / decorativa' },
    { n: 'Righteous',             spec: 'Righteous',              f: 'display', g: 'Neon / decorativa' },

    { n: 'Inter',                 spec: 'Inter:wght@100..900',    f: 'sans',    g: 'Limpa / neutra' },
    { n: 'Montserrat',            spec: 'Montserrat:ital,wght@0,100..900;1,100..900', f: 'sans', g: 'Limpa / neutra' },
    { n: 'Poppins',               spec: 'Poppins:ital,wght@0,300;0,400;0,500;0,600;0,700;0,800;0,900;1,400;1,700', f: 'sans', g: 'Limpa / neutra' },
    { n: 'Bricolage Grotesque',   spec: 'Bricolage+Grotesque:wght@200..800', f: 'sans', g: 'Limpa / neutra' }
  ];

  var STACKS = {
    mono: 'ui-monospace, Consolas, monospace',
    sans: 'system-ui, "Segoe UI", Arial, sans-serif',
    display: 'Impact, "Arial Black", system-ui, sans-serif'
  };

  /* ---------------------------------------------------------------
     ESQUEMA — define os padrões, valida o que vem da URL e monta a URL.
     t: b = liga/desliga · n = número · c = cor · e = lista fechada · f = fonte
     --------------------------------------------------------------- */
  var SCHEMA = {
    /* que conteúdo este overlay mostra. 'relogio' é o padrão de propósito:
       toda config que já existe por aí não tem esta chave e continua igual. */
    /* Fuso do relogio. 'auto' usa o do computador de quem assiste ao overlay,
       que e o do streamer. O padrao continua Sao Paulo para nao mudar nada nos
       links que ja estao no ar por ai. */
    tz:       { t: 'e', d: 'America/Sao_Paulo', v: [
      'auto',
      'America/Sao_Paulo', 'America/Manaus', 'America/Rio_Branco', 'America/Noronha',
      'America/New_York', 'America/Chicago', 'America/Denver', 'America/Los_Angeles',
      'America/Anchorage', 'Pacific/Honolulu',
      'America/Mexico_City', 'America/Bogota', 'America/Lima', 'America/Argentina/Buenos_Aires',
      'Europe/London', 'Europe/Lisbon', 'Europe/Madrid', 'Europe/Paris', 'Europe/Berlin',
      'Europe/Moscow', 'Africa/Luanda', 'Asia/Tokyo', 'Asia/Seoul', 'Asia/Shanghai',
      'Asia/Dubai', 'Australia/Sydney', 'Pacific/Auckland', 'UTC'
    ] },
    tipo:     { t: 'e', d: 'relogio', v: ['relogio', 'contador', 'placar', 'subathon', 'meta', 'chat', 'feed'] },
    /* ---- feed de eventos ----
       Divide as chaves com o chat: os dois sao a mesma lista com origem
       diferente, e duplicar as opcoes so criaria dois lugares pra ajustar
       a mesma coisa. */
    fcor:     { t: 'c', d: '#8fd07a' },
    fseg:     { t: 'b', d: 1 },
    fsub:     { t: 'b', d: 1 },
    fbits:    { t: 'b', d: 1 },
    freal:    { t: 'b', d: 1 },
    /* ---- chat na tela ----
       Mora no mesmo esquema dos outros de proposito: assim o chatbox herda
       fonte, cor, contorno, sombra, brilho e caixa de fundo sem existir um
       segundo sistema de aparencia pra divergir com o tempo. */
    canal:    { t: 't', d: '', max: 25 },
    cmax:     { t: 'n', d: 8,   min: 1,  max: 40 },
    cdir:     { t: 'e', d: 'baixo', v: ['baixo', 'cima'] },
    cvida:    { t: 'n', d: 0,   min: 0,  max: 600 },
    clarg:    { t: 'n', d: 420, min: 120, max: 1600 },
    cgap:     { t: 'n', d: 10,  min: 0,  max: 60 },
    cnick:    { t: 'b', d: 1 },
    cnickpos: { t: 'e', d: 'acima', v: ['acima', 'linha', 'abaixo'] },
    cnicksize:{ t: 'n', d: 90,  min: 30, max: 200 },
    cnickcor: { t: 'c', d: '#8fd07a' },
    cnickauto:{ t: 'b', d: 1 },
    csep:     { t: 't', d: ':', max: 4 },
    cemotes:  { t: 'b', d: 1 },
    cbolha:   { t: 'b', d: 0 },
    cbcor:    { t: 'c', d: '#000000' },
    cbopac:   { t: 'n', d: 45,  min: 0,  max: 100 },
    cbraio:   { t: 'n', d: 10,  min: 0,  max: 60 },
    cbpad:    { t: 'n', d: 8,   min: 0,  max: 40 },
    cbforma:  { t: 'e', d: 'reta', v: ['reta', 'pilula', 'chanfro', 'fita', 'seta'] },
    cbincl:   { t: 'n', d: 0,   min: -25, max: 25 },
    cbborda:  { t: 'n', d: 0,   min: 0,  max: 8 },
    cbbcor:   { t: 'c', d: '#ffffff' },
    /* ---- a caixa do NOME, separada da caixa da mensagem ----
       Sao duas pecas com vida propria: quase todo overlay bonito tem uma
       etiqueta colorida no nome e nada atras do texto. */
    nkcase:   { t: 'b', d: 0 },
    nkforma:  { t: 'e', d: 'pilula', v: ['reta', 'pilula', 'chanfro', 'fita', 'seta'] },
    nkcor:    { t: 'c', d: '#12a150' },
    nkopac:   { t: 'n', d: 100, min: 0,  max: 100 },
    nkpad:    { t: 'n', d: 5,   min: 0,  max: 40 },
    nkraio:   { t: 'n', d: 6,   min: 0,  max: 60 },
    nkincl:   { t: 'n', d: 0,   min: -25, max: 25 },
    nkborda:  { t: 'n', d: 0,   min: 0,  max: 8 },
    nkbcor:   { t: 'c', d: '#ffffff' },
    /* Com caixa, a cor do nome e OUTRA decisao — e o padrao das duas era a
       cor da marca, entao ligar a caixa apagava o nome. */
    nkauto:   { t: 'b', d: 1 },
    nktxt:    { t: 'c', d: '#ffffff' },
    nkgap:    { t: 'n', d: 8,  min: 0, max: 60 },
    nkalin:   { t: 'e', d: 'esquerda', v: ['esquerda', 'centro', 'direita'] },
    /* Quinas uma a uma. Cantos iguais nos quatro e o caso comum, mas o que
       da personalidade e o canto solto — o balao de fala e um retangulo com
       tres cantos redondos e um reto. */
    nkq1:     { t: 'n', d: -1, min: -1, max: 80 },
    nkq2:     { t: 'n', d: -1, min: -1, max: 80 },
    nkq3:     { t: 'n', d: -1, min: -1, max: 80 },
    nkq4:     { t: 'n', d: -1, min: -1, max: 80 },
    nkgrad:   { t: 'b', d: 0 },
    nkcor2:   { t: 'c', d: '#0b7a3b' },
    nkang:    { t: 'n', d: 135, min: 0, max: 360 },
    cbq1:     { t: 'n', d: -1, min: -1, max: 80 },
    cbq2:     { t: 'n', d: -1, min: -1, max: 80 },
    cbq3:     { t: 'n', d: -1, min: -1, max: 80 },
    cbq4:     { t: 'n', d: -1, min: -1, max: 80 },
    cbgrad:   { t: 'b', d: 0 },
    cbcor2:   { t: 'c', d: '#000000' },
    cbang:    { t: 'n', d: 135, min: 0, max: 360 },
    nkdx:     { t: 'n', d: 0,  min: -400, max: 400 },
    nkdy:     { t: 'n', d: 0,  min: -200, max: 200 },
    /* ---- o que nao entra na tela ---- */
    csemcmd:  { t: 'b', d: 1 },
    cignora:  { t: 't', d: 'nightbot,streamelements,streamlabs,moobot,fossabot', max: 200 },
    cmaxlen:  { t: 'n', d: 0,   min: 0,  max: 500 },
    c3d:      { t: 'b', d: 0 },
    c3dang:   { t: 'n', d: 18,  min: -60, max: 60 },
    c3dprof:  { t: 'n', d: 600, min: 120, max: 3000 },
    cgirar:   { t: 'n', d: 0,   min: -30, max: 30 },
    /* Onde cada peca fica. Sai de zero, entao overlay antigo nao se mexe.
       Quem escreve aqui e o arrasto no editor, nao a pessoa digitando. */
    nx:       { t: 'n', d: 0, min: -2000, max: 2000 },
    ny:       { t: 'n', d: 0, min: -2000, max: 2000 },
    tx:       { t: 'n', d: 0, min: -2000, max: 2000 },
    ty:       { t: 'n', d: 0, min: -2000, max: 2000 },
    bx:       { t: 'n', d: 0, min: -2000, max: 2000 },
    by:       { t: 'n', d: 0, min: -2000, max: 2000 },
    /* O numero por cima da barra, em vez de acima dela. Ocupa bem menos
       altura na tela, que e o que a maioria quer numa meta. */
    bdentro:  { t: 'b', d: 0 },
    /* subathon: o que acontece quando entra tempo */
    sanim:    { t: 'e', d: 'subir', v: ['subir', 'rolar', 'ambos', 'nenhum'] },
    spopc:    { t: 'c', d: '#7ce07c' },
    spopt:    { t: 'n', d: 55,  min: 15, max: 200 },
    spulso:   { t: 'b', d: 1 },
    surg:     { t: 'n', d: 5,   min: 0,  max: 180 },
    surgc:    { t: 'c', d: '#ff4d4d' },
    /* subathon: a legenda "o que dá quanto". Os valores vêm prontos do painel
       do ZocaController — quem usa o relógio sozinho preenche na mão. */
    leg:      { t: 'b', d: 1 },
    vsub1:    { t: 'n', d: 0, min: 0, max: 86400 },
    vsub2:    { t: 'n', d: 0, min: 0, max: 86400 },
    vsub3:    { t: 'n', d: 0, min: 0, max: 86400 },
    vbits:    { t: 'n', d: 0, min: 0, max: 86400 },
    vfollow:  { t: 'n', d: 0, min: 0, max: 86400 },
    vreal:    { t: 'n', d: 0, min: 0, max: 86400 },
    legsize:  { t: 'n', d: 16, min: 5, max: 60 },
    legcolor: { t: 'c', d: '#ffffff' },
    legopac:  { t: 'n', d: 55, min: 0, max: 100 },
    leggap:   { t: 'n', d: 10, min: 0, max: 80 },
    legcaps:  { t: 'b', d: 1 },
    /* meta: quanto ja tem e quanto falta */
    atual:    { t: 'n', d: 0,    min: 0, max: 9999999 },
    alvo:     { t: 'n', d: 1000, min: 1, max: 9999999 },
    mfmt:     { t: 'e', d: 'fracao', v: ['fracao', 'numero', 'porcento', 'falta'] },
    barra:    { t: 'b', d: 1 },
    baltura:  { t: 'n', d: 18,  min: 2,  max: 120 },
    bcantos:  { t: 'n', d: 9,   min: 0,  max: 60 },
    bcor:     { t: 'c', d: '#8fd07a' },
    bfundo:   { t: 'c', d: '#ffffff' },
    bfopac:   { t: 'n', d: 18,  min: 0,  max: 100 },
    bgap:     { t: 'n', d: 10,  min: 0,  max: 120 },
    bacima:   { t: 'b', d: 0 },
    /* contador e placar */
    titulo:   { t: 't', d: '', max: 30 },
    valor:    { t: 'n', d: 0, min: 0, max: 9999 },
    titulo2:  { t: 't', d: '', max: 30 },
    valor2:   { t: 'n', d: 0, min: 0, max: 9999 },
    sep:      { t: 't', d: '×', max: 3 },
    /* subathon: pausado guarda quanto falta, rodando guarda quando acaba.
       Guardar "quando acaba" e nao "quanto falta" e o que faz o numero
       continuar certo depois do OBS passar horas fechado. */
    modo:     { t: 'e', d: 'pausado', v: ['pausado', 'rodando'] },
    restante: { t: 'n', d: 3600, min: 0, max: 2592000 },
    fim:      { t: 'n', d: 0, min: 0, max: 4102444800 },
    horas:    { t: 'b', d: 1 },
    fimtxt:   { t: 't', d: 'ACABOU', max: 20 },
    /* relógio */
    h24:      { t: 'b', d: 1 },
    sec:      { t: 'b', d: 1 },
    pad:      { t: 'b', d: 1 },
    ampm:     { t: 'b', d: 1 },
    blink:    { t: 'b', d: 0 },
    tnum:     { t: 'b', d: 1 },
    /* data */
    date:     { t: 'b', d: 0 },
    dfmt:     { t: 'e', d: 'full', v: ['full', 'long', 'dm', 'weekday', 'short', 'numeric'] },
    dpos:     { t: 'e', d: 'below', v: ['below', 'above'] },
    dsize:    { t: 'n', d: 26,  min: 5,    max: 200 },
    dcolor:   { t: 'c', d: '#ffffff' },
    dopacity: { t: 'n', d: 85,  min: 0,    max: 100 },
    dweight:  { t: 'n', d: 500, min: 100,  max: 900 },
    dtrack:   { t: 'n', d: 2,   min: -20,  max: 60 },
    dcaps:    { t: 'b', d: 0 },
    dgap:     { t: 'n', d: 6,   min: -100, max: 200 },
    /* tipografia */
    font:     { t: 'f', d: 'Orbitron' },
    size:     { t: 'n', d: 96,  min: 8,    max: 600 },
    weight:   { t: 'n', d: 700, min: 100,  max: 900 },
    italic:   { t: 'b', d: 0 },
    track:    { t: 'n', d: 0,   min: -20,  max: 80 },
    caps:     { t: 'b', d: 0 },
    /* preenchimento */
    fill:     { t: 'b', d: 1 },
    mode:     { t: 'e', d: 'solid', v: ['solid', 'grad'] },
    color:    { t: 'c', d: '#ffffff' },
    c2:       { t: 'c', d: '#8fd07a' },
    gangle:   { t: 'n', d: 180, min: 0,    max: 360 },
    /* traçado */
    sw:       { t: 'n', d: 0,   min: 0,    max: 30 },
    sc:       { t: 'c', d: '#000000' },
    /* sombra projetada */
    shx:      { t: 'n', d: 0,   min: -100, max: 100 },
    shy:      { t: 'n', d: 4,   min: -100, max: 100 },
    shb:      { t: 'n', d: 10,  min: 0,    max: 150 },
    shc:      { t: 'c', d: '#000000' },
    sho:      { t: 'n', d: 60,  min: 0,    max: 100 },
    /* brilho / neon */
    glow:     { t: 'n', d: 0,   min: 0,    max: 120 },
    gc:       { t: 'c', d: '#8fd07a' },
    gi:       { t: 'n', d: 70,  min: 0,    max: 100 },
    /* fundo */
    bg:       { t: 'e', d: 'none', v: ['none', 'solid', 'blur'] },
    bgc:      { t: 'c', d: '#000000' },
    bga:      { t: 'n', d: 55,  min: 0,    max: 100 },
    bgr:      { t: 'n', d: 14,  min: 0,    max: 200 },
    bgp:      { t: 'n', d: 18,  min: 0,    max: 200 },
    bd:       { t: 'n', d: 0,   min: 0,    max: 30 },
    bdc:      { t: 'c', d: '#ffffff' },
    bdo:      { t: 'n', d: 100, min: 0,    max: 100 },
    /* posição */
    align:    { t: 'e', d: 'center', v: ['left', 'center', 'right'] },
    valign:   { t: 'e', d: 'middle', v: ['top', 'middle', 'bottom'] },
    mx:       { t: 'n', d: 0,   min: 0,    max: 1000 },
    my:       { t: 'n', d: 0,   min: 0,    max: 1000 }
  };

  var KEYS = Object.keys(SCHEMA);

  var DEFAULTS = (function () {
    var o = {};
    for (var i = 0; i < KEYS.length; i++) o[KEYS[i]] = SCHEMA[KEYS[i]].d;
    return o;
  })();

  /* Trocar de estilo mexe só na aparência: o que está sendo EXIBIDO
     (24h, segundos, data) continua como você deixou. */
  var PRESERVA = ['h24', 'sec', 'pad', 'ampm', 'date', 'dfmt', 'dpos'];

  var PRESETS = {
    trovao: {
      label: 'Trovão', hint: 'verde-raio da live',
      cfg: { font: 'Orbitron', size: 108, weight: 800, track: 4, mode: 'grad',
             color: '#eafff1', c2: '#7dd87a', gangle: 170, sw: 3, sc: '#04170a',
             glow: 22, gc: '#7dff9b', gi: 85, shx: 0, shy: 6, shb: 16, shc: '#000000', sho: 70,
             dcolor: '#8fd07a', dsize: 22, dcaps: 1, dtrack: 6 }
    },
    neon: {
      label: 'Neon', hint: 'tubo de gás',
      cfg: { font: 'Monoton', size: 112, weight: 400, track: 3, color: '#ff7ad4',
             sw: 0, glow: 26, gc: '#ff2fb0', gi: 90, shb: 0, sho: 0,
             dcolor: '#ff7ad4', dsize: 20, dtrack: 8, dcaps: 1 }
    },
    minimal: {
      label: 'Minimal', hint: 'limpo e discreto',
      cfg: { font: 'Inter', size: 84, weight: 600, track: -1, color: '#ffffff',
             sw: 0, glow: 0, shx: 0, shy: 2, shb: 10, shc: '#000000', sho: 45,
             dcolor: '#ffffff', dopacity: 60, dsize: 24, dweight: 400, dtrack: 0 }
    },
    pixel: {
      label: 'Pixel', hint: 'sombra dura, sem blur',
      cfg: { font: 'Press Start 2P', size: 44, weight: 400, track: 2, color: '#9dff6b',
             sw: 0, glow: 0, shx: 5, shy: 5, shb: 0, shc: '#000000', sho: 100,
             dcolor: '#ffffff', dsize: 40, dtrack: 0, dgap: 14 }
    },
    contorno: {
      label: 'Contorno', hint: 'só traçado, sem preenchimento',
      cfg: { font: 'Anton', size: 124, weight: 400, track: 4, fill: 0,
             sw: 3, sc: '#ffffff', glow: 0, shx: 0, shy: 0, shb: 0, sho: 0,
             dcolor: '#ffffff', dsize: 18, dcaps: 1, dtrack: 10 }
    },
    terminal: {
      label: 'Terminal', hint: 'CRT verde em caixa',
      cfg: { font: 'VT323', size: 116, weight: 400, track: 2, color: '#39ff85',
             sw: 0, glow: 14, gc: '#0dff6a', gi: 60, shb: 0, sho: 0,
             bg: 'solid', bgc: '#020a05', bga: 72, bgr: 6, bgp: 20, bd: 1, bdc: '#39ff85', bdo: 40,
             dcolor: '#39ff85', dsize: 26, dopacity: 70 }
    },
    matriz: {
      label: 'Matriz', hint: 'painel de LED âmbar',
      cfg: { font: 'Doto', size: 132, weight: 700, track: 4, color: '#ffb347',
             sw: 0, glow: 12, gc: '#ff7b00', gi: 75, shb: 0, sho: 0,
             dcolor: '#ffb347', dsize: 22, dcaps: 1, dtrack: 6 }
    },
    caixa: {
      label: 'Caixa', hint: 'pílula clara sobre a gameplay',
      cfg: { font: 'Bebas Neue', size: 92, weight: 400, track: 3, color: '#141414',
             sw: 0, glow: 0, shx: 0, shy: 8, shb: 20, shc: '#000000', sho: 35,
             bg: 'solid', bgc: '#ffffff', bga: 92, bgr: 18, bgp: 22, bd: 0,
             dcolor: '#141414', dopacity: 65, dsize: 22, dtrack: 4, dcaps: 1 }
    }
  };

  /* ------------------------- validação -------------------------
     Tudo que chega pela URL passa por aqui antes de virar CSS. É isso
     que impede alguém de injetar estilo/script numa URL compartilhada.
     ------------------------------------------------------------- */

  function limpaFonte(v) {
    var s = String(v || '').replace(/[^A-Za-z0-9 +_-]/g, '').replace(/\s+/g, ' ').trim();
    return s.slice(0, 40) || DEFAULTS.font;
  }

  /* Texto livre e curto. Some com quebra de linha e caractere de controle,
     que num overlay só servem para quebrar o layout. O escape de HTML acontece
     na hora de pintar, não aqui. */
  function limpaTexto(v, spec) {
    var s = String(v == null ? '' : v).replace(/[\u0000-\u001f\u007f]/g, ' ');
    return s.replace(/\s+/g, ' ').trim().slice(0, spec.max || 60);
  }

  function limpaCor(v, fb) {
    var s = String(v == null ? '' : v).trim().replace(/^#/, '');
    if (/^[0-9a-fA-F]{3}$/.test(s)) s = s[0] + s[0] + s[1] + s[1] + s[2] + s[2];
    return /^[0-9a-fA-F]{6}$/.test(s) ? '#' + s.toLowerCase() : fb;
  }

  function limpaNum(v, spec) {
    var n = parseFloat(v);
    if (!isFinite(n)) return spec.d;
    n = Math.round(n * 10) / 10;
    return Math.min(spec.max, Math.max(spec.min, n));
  }

  function sanitize(raw) {
    var src = raw || {};
    var out = {};
    for (var i = 0; i < KEYS.length; i++) {
      var k = KEYS[i], s = SCHEMA[k], v = src[k];
      if (v === undefined || v === null || v === '') { out[k] = s.d; continue; }
      if (s.t === 'b') out[k] = (v === 1 || v === '1' || v === true || v === 'true') ? 1 : 0;
      else if (s.t === 'n') out[k] = limpaNum(v, s);
      else if (s.t === 'c') out[k] = limpaCor(v, s.d);
      else if (s.t === 'e') out[k] = s.v.indexOf(String(v)) >= 0 ? String(v) : s.d;
      else if (s.t === 'f') out[k] = limpaFonte(v);
      else if (s.t === 't') out[k] = limpaTexto(v, s);
      else out[k] = s.d;
    }
    return out;
  }

  function assign(a, b) {
    for (var k in b) if (Object.prototype.hasOwnProperty.call(b, k)) a[k] = b[k];
    return a;
  }

  /* --------------------- URL <-> configuração --------------------- */

  function parseConfig(search) {
    var q = new URLSearchParams(search !== undefined ? search : (global.location ? global.location.search : ''));
    var base = DEFAULTS;
    var p = q.get('p');
    if (p && PRESETS[p]) base = assign(assign({}, DEFAULTS), PRESETS[p].cfg);
    var raw = assign({}, base);
    q.forEach(function (val, key) {
      if (key !== 'p' && SCHEMA[key]) raw[key] = val;
    });
    return sanitize(raw);
  }

  /* Só o que fugiu do padrão entra na URL — assim ela fica curta. */
  function toQuery(cfg) {
    var c = sanitize(cfg);
    var parts = [];
    for (var i = 0; i < KEYS.length; i++) {
      var k = KEYS[i];
      if (c[k] === DEFAULTS[k]) continue;
      var v = SCHEMA[k].t === 'c' ? String(c[k]).replace('#', '') : c[k];
      parts.push(encodeURIComponent(k) + '=' + encodeURIComponent(v));
    }
    return parts.join('&');
  }

  function aplicaPreset(nome, atual) {
    var novo = assign(assign({}, DEFAULTS), (PRESETS[nome] || {}).cfg || {});
    if (atual) for (var i = 0; i < PRESERVA.length; i++) novo[PRESERVA[i]] = atual[PRESERVA[i]];
    return sanitize(novo);
  }

  /* ------------------------- formatação ------------------------- */

  var fmtCache = {};
  function fmt(opts) {
    var k = JSON.stringify(opts);
    if (!fmtCache[k]) fmtCache[k] = new Intl.DateTimeFormat(LOCALE, opts);
    return fmtCache[k];
  }

  var DATE_OPTS = {
    full:    { weekday: 'long',  day: 'numeric',   month: 'long',  year: 'numeric' },
    long:    { day: 'numeric',   month: 'long',    year: 'numeric' },
    dm:      { day: 'numeric',   month: 'long' },
    weekday: { weekday: 'long' },
    short:   { weekday: 'short', day: 'numeric',   month: 'short' },
    numeric: { day: '2-digit',   month: '2-digit', year: 'numeric' }
  };

  function esc(s) {
    return String(s).replace(/[&<>"]/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
    });
  }

  /* 'auto' devolve nada, e o Intl usa o fuso do proprio computador. */
  function fuso(cfg) { return (cfg.tz && cfg.tz !== 'auto') ? cfg.tz : undefined; }

  function optsHora(cfg) {
    var o = {
      timeZone: fuso(cfg),
      hour: cfg.pad ? '2-digit' : 'numeric',
      minute: '2-digit',
      hourCycle: cfg.h24 ? 'h23' : 'h12'
    };
    if (cfg.sec) o.second = '2-digit';
    return o;
  }

  function optsData(cfg) {
    return assign({ timeZone: fuso(cfg) }, DATE_OPTS[cfg.dfmt] || DATE_OPTS.full);
  }

  /* o formatador vem pronto de fora: isso roda a cada quadro */
  function horaHTML(cfg, agora, f) {
    var parts = (f || fmt(optsHora(cfg))).formatToParts(agora);
    var m = {};
    for (var i = 0; i < parts.length; i++) m[parts[i].type] = parts[i].value;

    var html = '<span class="rl__n">' + esc(m.hour || '') + '</span>'
             + '<span class="rl__sep">:</span>'
             + '<span class="rl__n">' + esc(m.minute || '') + '</span>';
    if (cfg.sec) {
      html += '<span class="rl__sep">:</span><span class="rl__n">' + esc(m.second || '') + '</span>';
    }
    if (!cfg.h24 && cfg.ampm && m.dayPeriod) {
      html += '<span class="rl__ap">' + esc(m.dayPeriod.replace(/\./g, '').toUpperCase()) + '</span>';
    }
    return html;
  }

  function dataTexto(cfg, agora, f) {
    return (f || fmt(optsData(cfg))).format(agora).replace(/\.$/, '');
  }

  /* usado pelo personalizador pra mostrar cada formato com a data de hoje */
  function exemploData(nome) {
    return dataTexto({ dfmt: nome }, new Date());
  }

  /* --------------------------- estilo --------------------------- */

  function rgba(hex, pct) {
    var h = limpaCor(hex, '#000000').slice(1);
    var r = parseInt(h.slice(0, 2), 16),
        g = parseInt(h.slice(2, 4), 16),
        b = parseInt(h.slice(4, 6), 16);
    return 'rgba(' + r + ',' + g + ',' + b + ',' + (Math.max(0, Math.min(100, pct)) / 100) + ')';
  }

  function fonteInfo(nome) {
    for (var i = 0; i < FONTS.length; i++) if (FONTS[i].n === nome) return FONTS[i];
    return null;
  }

  var fontesPedidas = {};
  function carregaFonte(doc, nome) {
    var info = fonteInfo(nome);
    var spec = info ? info.spec : limpaFonte(nome).replace(/ /g, '+');
    var id = 'rl-font-' + spec.replace(/[^A-Za-z0-9]+/g, '-');
    if (fontesPedidas[id] || doc.getElementById(id)) return;
    fontesPedidas[id] = true;
    var l = doc.createElement('link');
    l.rel = 'stylesheet';
    l.id = id;
    l.href = 'https://fonts.googleapis.com/css2?family=' + spec + '&display=swap';
    doc.head.appendChild(l);
  }

  var ALIGN_FLEX = { left: 'flex-start', center: 'center', right: 'flex-end' };
  var VALIGN_FLEX = { top: 'flex-start', middle: 'center', bottom: 'flex-end' };

  function aplicaEstilo(root, cfg) {
    var info = fonteInfo(cfg.font);
    var s = root.style;

    s.setProperty('--rl-font', '"' + cfg.font + '", ' + STACKS[info ? info.f : 'sans']);
    s.setProperty('--rl-size', cfg.size + 'px');
    s.setProperty('--rl-weight', String(cfg.weight));
    s.setProperty('--rl-style', cfg.italic ? 'italic' : 'normal');
    s.setProperty('--rl-track', cfg.track + 'px');
    s.setProperty('--rl-caps', cfg.caps ? 'uppercase' : 'none');
    s.setProperty('--rl-numeric', cfg.tnum ? 'tabular-nums' : 'normal');

    /* efeitos do subathon: tamanho e distancia acompanham o tamanho do
       relogio, senao o "+X" fica um grao num relogio grande */
    var tamPop = Math.round(cfg.size * cfg.spopt / 100);
    s.setProperty('--rl-spops', tamPop + 'px');
    s.setProperty('--rl-spopd', Math.round(tamPop * 1.9) + 'px');
    s.setProperty('--rl-spopc', cfg.spopc);
    s.setProperty('--rl-surgc', cfg.surgc);

    /* Cada peca leva seu proprio deslocamento. Em translate, e nao em
       margem: nao empurra as vizinhas e nao muda o tamanho da caixa. */
    s.setProperty('--rl-nx', cfg.nx + 'px');
    s.setProperty('--rl-ny', cfg.ny + 'px');
    s.setProperty('--rl-tx', cfg.tx + 'px');
    s.setProperty('--rl-ty', cfg.ty + 'px');
    s.setProperty('--rl-bx', cfg.bx + 'px');
    s.setProperty('--rl-by', cfg.by + 'px');

    s.setProperty('--rl-color', cfg.color);
    s.setProperty('--rl-c2', cfg.c2);
    s.setProperty('--rl-gangle', cfg.gangle + 'deg');

    /* traçado dobrado: metade fica pra dentro da letra e o preenchimento
       cobre ela por cima — dá contorno limpo sem comer a tipografia */
    s.setProperty('--rl-sw', (cfg.sw * 2) + 'px');
    s.setProperty('--rl-sc', cfg.sc);

    /* sombra e brilho num filter só, no TEXTO (não na caixa de fundo) */
    var filtros = [];
    if (cfg.sho > 0 && (cfg.shx || cfg.shy || cfg.shb)) {
      filtros.push('drop-shadow(' + cfg.shx + 'px ' + cfg.shy + 'px ' + cfg.shb + 'px ' + rgba(cfg.shc, cfg.sho) + ')');
    }
    if (cfg.glow > 0 && cfg.gi > 0) {
      var g = rgba(cfg.gc, cfg.gi);
      filtros.push('drop-shadow(0 0 ' + cfg.glow + 'px ' + g + ')');
      filtros.push('drop-shadow(0 0 ' + (cfg.glow * 2) + 'px ' + g + ')');
    }
    s.setProperty('--rl-filter', filtros.length ? filtros.join(' ') : 'none');

    /* Na reta final o brilho tambem vira vermelho. Sem isto o numero fica
       vermelho com um halo verde em volta, que e pior do que nao ter halo. */
    if (cfg.glow > 0 && cfg.gi > 0) {
      var gu = rgba(cfg.surgc, cfg.gi);
      var urg = filtros.slice(0, filtros.length - 2);
      urg.push('drop-shadow(0 0 ' + cfg.glow + 'px ' + gu + ')');
      urg.push('drop-shadow(0 0 ' + (cfg.glow * 2) + 'px ' + gu + ')');
      s.setProperty('--rl-urgfilter', urg.join(' '));
    } else {
      s.setProperty('--rl-urgfilter', filtros.length ? filtros.join(' ') : 'none');
    }

    s.setProperty('--rl-bg', cfg.bg === 'none' ? 'transparent' : rgba(cfg.bgc, cfg.bga));
    s.setProperty('--rl-bgr', cfg.bgr + 'px');
    s.setProperty('--rl-bgp', cfg.bgp + 'px ' + Math.round(cfg.bgp * 1.4) + 'px');
    s.setProperty('--rl-bd', cfg.bd + 'px');
    s.setProperty('--rl-bdc', cfg.bd > 0 ? rgba(cfg.bdc, cfg.bdo) : 'transparent');
    s.setProperty('--rl-blur', cfg.bg === 'blur' ? 'blur(10px)' : 'none');

    s.setProperty('--rl-legsize', 'calc(' + cfg.size + 'px * ' + (cfg.legsize / 100) + ')');
    s.setProperty('--rl-legcolor', rgba(cfg.legcolor, cfg.legopac));
    s.setProperty('--rl-legcaps', cfg.legcaps ? 'uppercase' : 'none');
    s.setProperty('--rl-leggap', cfg.leggap + 'px');

    s.setProperty('--rl-dsize', 'calc(' + cfg.size + 'px * ' + (cfg.dsize / 100) + ')');
    s.setProperty('--rl-dcolor', rgba(cfg.dcolor, cfg.dopacity));
    s.setProperty('--rl-dweight', String(cfg.dweight));
    s.setProperty('--rl-dtrack', cfg.dtrack + 'px');
    s.setProperty('--rl-dcaps', cfg.dcaps ? 'uppercase' : 'none');
    s.setProperty('--rl-dgap', cfg.dgap + 'px');
    s.setProperty('--rl-dorder', cfg.dpos === 'above' ? '-1' : '1');

    s.setProperty('--rl-justify', ALIGN_FLEX[cfg.align]);
    s.setProperty('--rl-valign', VALIGN_FLEX[cfg.valign]);
    s.setProperty('--rl-items', ALIGN_FLEX[cfg.align]);
    s.setProperty('--rl-text-align', cfg.align);
    s.setProperty('--rl-mx', cfg.mx + 'px');
    s.setProperty('--rl-my', cfg.my + 'px');

    root.classList.toggle('is-grad', cfg.mode === 'grad');
    root.classList.toggle('is-nofill', !cfg.fill);
    root.classList.toggle('is-blink', !!cfg.blink);
    root.classList.toggle('is-date-off', !cfg.date);
    root.classList.toggle('has-bg', cfg.bg !== 'none');
  }

  /* ---------------------------------------------------------------
     BATIDA — o coração do relógio, dentro de um Web Worker.

     Por quê: numa Fonte de Navegador do OBS, quando a fonte não está
     visível em lugar nenhum o OBS chama WasHidden(true) no navegador
     embutido (obs-browser-source.cpp, SetShowing). Aí o Chromium trata
     a página como aba de segundo plano:
        · requestAnimationFrame PARA de vez (não desacelera: para);
        · setTimeout/setInterval caem pra 1x por segundo, e pra 1x por
          MINUTO depois de 5 minutos escondida.
     Isso mata o tique do relógio e mata junto a busca periódica do
     estilo publicado — os dois defeitos vinham daí.

     Temporizador dentro de um dedicated Worker não sofre esse freio: a
     trava existe no Blink (kDedicatedWorkerThrottling) mas vem
     DESLIGADA de fábrica. Medido: página escondida, worker manteve
     200ms cravados enquanto a thread principal caiu pra ~1000ms.

     O worker nasce de um Blob, então não há arquivo novo pra subir nem
     cache novo pra furar.
     --------------------------------------------------------------- */

  var FONTE_BATIDA =
    'var t=null;' +
    'onmessage=function(e){' +
      'var ms=(e.data&&e.data.ms)||200;' +
      'if(t)clearInterval(t);' +
      't=setInterval(function(){postMessage(Date.now());},ms);' +
    '};';

  function criaBatida(ms, aoBater) {
    if (!global.Worker || !global.Blob || !global.URL || !global.URL.createObjectURL) return null;
    try {
      var url = global.URL.createObjectURL(new Blob([FONTE_BATIDA], { type: 'text/javascript' }));
      var w = new global.Worker(url);
      w.onmessage = function () { aoBater(); };
      w.postMessage({ ms: ms });
      return w;
    } catch (e) {
      return null;   /* sem worker: sobram o rAF e o setTimeout */
    }
  }

  /* --------------------------- montagem --------------------------- */

  /* ------------------------- conteúdos -------------------------
     O motor de aparência (fonte, traçado, sombra, brilho, fundo) não sabe o
     que está escrito — e é justamente isso que deixa reaproveitar tudo. Cada
     conteúdo só diz o que vai na linha de cima e o que vai na de baixo.

     'principal' devolve HTML (por causa do relogio, que precisa de <span>
     separando hora e minuto). 'secundario' devolve texto puro.
     ---------------------------------------------------------------- */

  /* O relogio do servidor, em milissegundos de diferenca para o da maquina.
     Quem preenche e o overlay.html, lendo o cabecalho Date da resposta. Sem
     isso, maquina com relogio torto mostraria um subathon errado — e relogio
     torto e mais comum do que parece. */
  var desvioServidor = 0;
  function agoraServidor() { return Date.now() + desvioServidor; }

  function doisDig(n) { return (n < 10 ? '0' : '') + n; }

  /* Segundos que faltam, do jeito que a pessoa le. Nunca passa de zero para
     baixo: subathon negativo so assusta. */
  function faltamSegundos(cfg) {
    var s = cfg.modo === 'rodando'
      ? Math.round(cfg.fim - agoraServidor() / 1000)
      : Math.round(cfg.restante);
    return s > 0 ? s : 0;
  }

  function tempoHMS(seg, comHoras) {
    /* Fracao aqui vira "59.99989711934177" na tela, porque o resto de uma
       divisao fracionaria continua fracionario. Arredondar na entrada faz o
       formatador aguentar qualquer chamador, hoje e amanha. */
    seg = Math.floor(seg);
    var h = Math.floor(seg / 3600), m = Math.floor((seg % 3600) / 60), s = seg % 60;
    if (!comHoras) { m += h * 60; return doisDig(m) + ':' + doisDig(s); }
    return doisDig(h) + ':' + doisDig(m) + ':' + doisDig(s);
  }

  var CONTEUDOS = {
    /* relogio fica null: ele mora dentro do mount, que é quem guarda os
       formatadores de data já prontos em cache. */
    relogio: null,

    contador: {
      tique: false,
      principal: function (cfg) {
        return '<span class="rl__n">' + esc(String(Math.round(cfg.valor))) + '</span>';
      },
      secundario: function (cfg) { return cfg.titulo || null; }
    },

    placar: {
      tique: false,
      principal: function (cfg) {
        return '<span class="rl__n">' + esc(String(Math.round(cfg.valor))) + '</span>'
             + '<span class="rl__sep">' + esc(cfg.sep) + '</span>'
             + '<span class="rl__n">' + esc(String(Math.round(cfg.valor2))) + '</span>';
      },
      secundario: function (cfg) {
        var a = cfg.titulo || '', b = cfg.titulo2 || '';
        if (!a && !b) return null;
        return a + '   ' + cfg.sep + '   ' + b;
      }
    },

    meta: {
      tique: false,
      principal: function (cfg) {
        var a = Math.round(cfg.atual), b = Math.max(1, Math.round(cfg.alvo));
        var txt;
        if (cfg.mfmt === 'numero')        txt = String(a);
        else if (cfg.mfmt === 'porcento') txt = Math.min(100, Math.floor(a * 100 / b)) + '%';
        else if (cfg.mfmt === 'falta')    txt = String(Math.max(0, b - a));
        else                              txt = a + ' / ' + b;

        var html = '';
        for (var i = 0; i < txt.length; i++) {
          html += txt[i] === '/'
            ? '<span class="rl__sep">/</span>'
            : '<span class="rl__n">' + esc(txt[i]) + '</span>';
        }
        return html;
      },
      secundario: function (cfg) { return cfg.titulo || null; }
    },

    subathon: {
      tique: true,
      principal: function (cfg) {
        var seg = faltamSegundos(cfg);
        if (seg <= 0 && cfg.fimtxt) {
          return '<span class="rl__n">' + esc(cfg.fimtxt) + '</span>';
        }
        var txt = tempoHMS(seg, !!cfg.horas);
        var html = '';
        for (var i = 0; i < txt.length; i++) {
          html += txt[i] === ':'
            ? '<span class="rl__sep">:</span>'
            : '<span class="rl__n">' + txt[i] + '</span>';
        }
        return html;
      },
      secundario: function (cfg) { return cfg.titulo || null; }
    }
  };

  /* A segunda linha existe ou não conforme o conteúdo: no relógio quem manda
     é o botão de data, no contador é ter título. Assim ninguém precisa entender
     por que "mostrar data" liga o nome do contador. */
  /* "5 min", "30s", "1h30" — a unidade some quando nao ajuda a ler rapido */
  function porExtenso(s) {
    if (s < 60) return s + 's';
    var m = Math.floor(s / 60), r = s % 60;
    if (m < 60) return r ? m + 'min' + (r < 10 ? '0' : '') + r : m + ' min';
    var h = Math.floor(m / 60);
    m = m % 60;
    return h + 'h' + (m ? (m < 10 ? '0' : '') + m : '');
  }

  /* A LINHA "O QUE DA QUANTO".
     Quem chega no meio da live nao faz ideia de que uma sub vale 5 minutos —
     e quem nao sabe disso nao tem motivo pra assinar. Zerado nao aparece:
     anunciar "follow = 0" so faz o quadro parecer quebrado.
     Os tiers 2 e 3 so entram quando valem diferente do tier 1. */
  function legendaTexto(cfg) {
    var p = [];
    if (cfg.vsub1)   p.push('sub = ' + porExtenso(cfg.vsub1));
    if (cfg.vsub2 && cfg.vsub2 !== cfg.vsub1) p.push('tier 2 = ' + porExtenso(cfg.vsub2));
    if (cfg.vsub3 && cfg.vsub3 !== cfg.vsub1) p.push('tier 3 = ' + porExtenso(cfg.vsub3));
    if (cfg.vbits)   p.push('100 bits = ' + porExtenso(cfg.vbits));
    if (cfg.vfollow) p.push('follow = ' + porExtenso(cfg.vfollow));
    if (cfg.vreal)   p.push('R$1 = ' + porExtenso(cfg.vreal));
    return p.join('   ·   ');
  }

  function aplicaLegenda(root, cfg) {
    var el = root.querySelector('.rl__leg');
    if (!el) return;
    var txt = (cfg.tipo === 'subathon' && cfg.leg) ? legendaTexto(cfg) : '';
    el.textContent = txt;
    /* 'block', nao '': limpar o inline devolveria o display:none do CSS */
    el.style.display = txt ? 'block' : 'none';
  }

  /* A barra e o unico conteudo que desenha algo alem de texto, entao ela mora
     fora das camadas de texto e liga e desliga sozinha. */
  function aplicaBarra(root, cfg) {
    var cx = root.querySelector('.rl__barra');
    if (!cx) return;
    var mostra = cfg.tipo === 'meta' && cfg.barra;
    /* 'block', nao '': limpar o inline devolve o display:none do CSS, e a
       barra nunca aparecia. */
    cx.style.display = mostra ? 'block' : 'none';
    if (!mostra) return;

    var pct = Math.max(0, Math.min(100, cfg.atual * 100 / Math.max(1, cfg.alvo)));
    cx.style.height       = cfg.baltura + 'px';
    cx.style.borderRadius = cfg.bcantos + 'px';
    cx.style.background   = rgba(cfg.bfundo, cfg.bfopac);
    cx.style.marginTop    = cfg.bacima ? '0' : cfg.bgap + 'px';
    cx.style.marginBottom = cfg.bacima ? cfg.bgap + 'px' : '0';
    cx.style.order        = cfg.bacima ? '-1' : '';

    var dentro = cx.firstElementChild;
    dentro.style.width        = pct + '%';
    dentro.style.background   = cfg.bcor;
    dentro.style.borderRadius = cfg.bcantos + 'px';
  }

  function normaliza(cfg) {
    if (cfg.tipo === 'contador') cfg.date = cfg.titulo ? 1 : 0;
    else if (cfg.tipo === 'placar') cfg.date = (cfg.titulo || cfg.titulo2) ? 1 : 0;
    else if (cfg.tipo === 'subathon') cfg.date = cfg.titulo ? 1 : 0;
    else if (cfg.tipo === 'meta') cfg.date = cfg.titulo ? 1 : 0;
    return cfg;
  }

  var TEMPLATE =
    '<div class="rl__box">' +
      /* Os "+X" moram numa camada absoluta: se entrassem no fluxo, cada um
         empurraria o relogio pro lado no meio da live. */
      '<div class="rl__pops" aria-hidden="true"></div>' +
      '<div class="rl__time">' +
        '<span class="rl__layer rl__layer--stroke" aria-hidden="true"></span>' +
        '<span class="rl__layer rl__layer--fill"></span>' +
      '</div>' +
      '<div class="rl__barra"><i></i></div>' +
      '<div class="rl__date">' +
        '<span class="rl__layer rl__layer--stroke" aria-hidden="true"></span>' +
        '<span class="rl__layer rl__layer--fill"></span>' +
      '</div>' +
      '<div class="rl__leg"></div>' +
    '</div>';

  function mount(root, cfgInicial, onSize) {
    var doc = root.ownerDocument;
    root.classList.add('rl');
    root.innerHTML = TEMPLATE;

    var box = root.querySelector('.rl__box');
    var camposHora = root.querySelectorAll('.rl__time .rl__layer');
    var camposData = root.querySelectorAll('.rl__date .rl__layer');

    var cfg = normaliza(sanitize(cfgInicial));
    var fmtHora = null, fmtData = null;

    /* O relógio precisa dos formatadores que vivem neste escopo, então ele é
       montado aqui em vez de ficar no registro lá em cima. */
    var CONTEUDO_RELOGIO = {
      tique: true,
      principal:  function (c, agora) { return horaHTML(c, agora, fmtHora); },
      secundario: function (c, agora) { return c.date ? dataTexto(c, agora, fmtData) : null; }
    };
    function conteudoDe(c) { return CONTEUDOS[c.tipo] || CONTEUDO_RELOGIO; }
    var ultimaHora = null, ultimaData = null, ultimoSeg = -1;
    var timer = null, raf = null, batida = null, ultimoTam = '';
    var ouvintes = [];   /* quem mais quer carona na batida (ex.: buscar estilo) */

    /* Roda a cada quadro, então sai cedo quando nada mudou: dentro do mesmo
       segundo isso é uma comparação de inteiros e nada mais. Só toca no DOM
       (e só mede a caixa) na virada do segundo. */
    function pinta(forcar) {
      var ms = Date.now();
      var seg = Math.floor(ms / 1000);
      if (!forcar && seg === ultimoSeg) return;
      ultimoSeg = seg;
      veUrgencia();

      var agora = new Date(ms);
      var conteudo = conteudoDe(cfg);
      /* Durante a rolagem entrego ao formatador uma COPIA com o fim puxado
         pra tras. Mexer na config de verdade faria o tempo real andar junto
         com a animacao — e o subathon passaria a mentir. */
      var cVis = cfg;
      var atraso = rolAtual();
      if (atraso) {
        cVis = assign({}, cfg);
        /* o atraso e fracionario (e o que deixa a rolagem lisa), mas o que sai
           daqui e tempo em segundos inteiros — senao a tela mostra 59.9998 */
        if (cVis.modo === 'rodando') cVis.fim = Math.round(cVis.fim + atraso);
        else cVis.restante = Math.max(0, Math.round(cVis.restante + atraso));
      }
      var h = conteudo.principal(cVis, agora);
      if (!forcar && h === ultimaHora) return;

      ultimaHora = h;
      camposHora[0].innerHTML = h;
      camposHora[1].innerHTML = h;

      /* os separadores acabaram de ser recriados: alinho a animação do
         pisca-pisca com a virada real do segundo (delay negativo) */
      if (cfg.blink && cfg.tipo === 'relogio') {
        var off = agora.getTime() % 1000;
        var seps = root.querySelectorAll('.rl__sep');
        for (var i = 0; i < seps.length; i++) {
          seps[i].style.animationDelay = '-' + off + 'ms';
        }
      }

      var d = conteudo.secundario ? conteudo.secundario(cfg, agora) : null;
      if (d != null && d !== ultimaData) {
        ultimaData = d;
        camposData[0].textContent = d;
        camposData[1].textContent = d;
      }

      if (onSize) medir();
    }

    /* --------------------------------------------------------------
       QUANDO ENTRA TEMPO NO SUBATHON

       Nada aqui fala com servidor: o overlay ja recebe o estilo novo de
       15 em 15 segundos. Se o "quando acaba" pulou pra frente, entrou
       tempo — e e so isso que precisa saber pra fazer o "+X" subir.
       -------------------------------------------------------------- */

    var nPop = 0, estaUrgente = false, primeiraCarga = true, rol = null;

    /* ROLAGEM — o numero corre pra frente ate o novo tempo.
       Guardo um ATRASO em segundos que anda ate zero, em vez de guardar
       "de onde ate onde": assim o relogio continua descontando o segundo
       normalmente por baixo, e a rolagem so monta em cima disso. */
    function rolAtual() {
      if (!rol) return 0;
      var t = (Date.now() - rol.inicio) / rol.dur;
      if (t >= 1) { rol = null; return 0; }
      /* freia no fim: parece contador mecanico alcancando o numero, e nao
         uma barra de progresso subindo em velocidade constante */
      var e = 1 - Math.pow(1 - t, 3);
      return -rol.d * (1 - e);
    }

    function rolar(d) {
      /* chegou outro ganho no meio da rolagem (raid): soma no que ainda
         falta alcancar, em vez de recomecar e perder o que ja subiu */
      rol = { d: d - rolAtual(), inicio: Date.now(), dur: 900 };
    }

    function doisDig(n) { return (n < 10 ? '0' : '') + n; }

    /* "+45s", "+5 min", "+7:30", "+2h15" — curto pra ler de relance na live */
    function curto(s) {
      if (s < 60) return s + 's';
      var m = Math.floor(s / 60), r = s % 60;
      if (m < 60) return r ? m + ':' + doisDig(r) : m + ' min';
      var h = Math.floor(m / 60);
      m = m % 60;
      return h + 'h' + (m ? doisDig(m) : '');
    }

    function solta(seg) {
      var pops = root.querySelector('.rl__pops');
      if (!pops) return;
      var el = doc.createElement('span');
      el.className = 'rl__pop';
      el.textContent = '+' + curto(seg);
      /* Num raid choveu sub de presente: varios "+X" no mesmo segundo
         viravam um borrao ilegivel. Cada um sai um pouco pro lado e um
         pouco depois do anterior, entao da pra ler todos. */
      var i = nPop++ % 5;
      el.style.marginLeft = ((i - 2) * 16) + 'px';
      el.style.animationDelay = (i * 100) + 'ms';
      el.setAttribute('data-nasceu', String(Date.now()));
      pops.appendChild(el);
    }

    /* A limpeza pega carona na batida do Worker, e nao num setTimeout: com a
       fonte escondida no OBS o setTimeout congela, e numa subathon de 12 horas
       os "+X" invisiveis iriam se empilhando na pagina pra sempre. */
    function limpaPops() {
      var pops = root.querySelector('.rl__pops');
      if (!pops || !pops.firstChild) return;
      var agora = Date.now();
      for (var i = pops.children.length - 1; i >= 0; i--) {
        var f = pops.children[i];
        if (agora - (+f.getAttribute('data-nasceu') || 0) > 4000) pops.removeChild(f);
      }
    }

    function baque() {
      var alvo = root.querySelector('.rl__time');
      if (!alvo) return;
      alvo.classList.remove('rl--baque');
      void alvo.offsetWidth;      /* reinicia a animacao se ainda estava rodando */
      alvo.classList.add('rl--baque');
    }

    function viuGanho(antes, agora) {
      if (antes.tipo !== 'subathon' || agora.tipo !== 'subathon') return;
      if (antes.modo !== agora.modo) return;   /* pausou/despausou nao e ganho */
      /* correndo guarda QUANDO acaba (absoluto, nao decai);
         pausado guarda QUANTO falta. Comparar o campo errado inventaria
         ganho a cada segundo. */
      var d = agora.modo === 'rodando'
        ? Math.round(agora.fim - antes.fim)
        : Math.round(agora.restante - antes.restante);
      if (d <= 0) return;
      var a = agora.sanim;
      if (a === 'subir' || a === 'ambos') solta(d);
      if (a === 'rolar' || a === 'ambos') rolar(d);
      if (agora.spulso) baque();
    }

    function veUrgencia() {
      var liga = cfg.tipo === 'subathon' && cfg.surg > 0;
      var falta = liga ? faltamSegundos(cfg) : 0;
      var urgente = liga && falta > 0 && falta <= cfg.surg * 60;
      if (urgente === estaUrgente) return;
      estaUrgente = urgente;
      if (urgente) root.classList.add('rl--urgente');
      else root.classList.remove('rl--urgente');
    }

    function medir() {
      var r = box.getBoundingClientRect();
      var w = Math.ceil(r.width + cfg.mx * 2);
      /* O "+X" sobe pra FORA da caixa. A sugestao de tamanho pro OBS precisa
         contar com isso, senao a Fonte de Navegador corta o efeito no meio. */
      var sobe = cfg.sanim === 'subir' || cfg.sanim === 'ambos';
      var sobra = (cfg.tipo === 'subathon' && sobe)
        ? Math.round(cfg.size * cfg.spopt / 100 * 2.4) : 0;
      var h = Math.ceil(r.height + cfg.my * 2 + sobra);
      var k = w + 'x' + h;
      if (k !== ultimoTam) { ultimoTam = k; onSize({ w: w, h: h }); }
    }

    /* MOTOR DO RELÓGIO — três fontes de tique, todas no mesmo funil.

       1) BATIDA (Web Worker, 200ms) — a única que sobrevive quando o OBS
          esconde a fonte. É a principal.
       2) requestAnimationFrame — o mais preciso quando a página está
          visível (erro de ~16ms), mas morre quando ela é escondida.
       3) setTimeout alinhado à virada do segundo — rede de segurança se
          o worker não puder ser criado.

       Todas chamam bateu(). Como pinta() sai fora quando o segundo não
       mudou, chamar demais não custa nada — e basta UMA das três estar
       viva pra tudo continuar funcionando. */

    function bateu() {
      /* Enquanto rola, o numero muda muitas vezes por segundo: o corte por
         segundo do pinta() faria a rolagem andar aos trancos. */
      pinta(!!rol);
      limpaPops();
      for (var i = 0; i < ouvintes.length; i++) {
        try { ouvintes[i](); } catch (e) {}
      }
    }

    function laco() {
      bateu();
      raf = global.requestAnimationFrame(laco);
    }

    function agenda() {
      var espera = 1000 - (Date.now() % 1000) + 15;
      timer = setTimeout(function () { bateu(); agenda(); }, espera);
    }

    function acordou() { pinta(true); bateu(); }

    function update(novo) {
      var antes = cfg;
      cfg = normaliza(sanitize(novo));
      fmtHora = fmt(optsHora(cfg));
      fmtData = fmt(optsData(cfg));
      carregaFonte(doc, cfg.font);
      aplicaEstilo(root, cfg);
      aplicaBarra(root, cfg);
      aplicaLegenda(root, cfg);
      /* Com o numero por cima da barra a caixa vira grade e os dois dividem a
         mesma celula. Nao mexe no DOM: so muda como as pecas se empilham. */
      root.classList.toggle('is-dentro', cfg.tipo === 'meta' && !!cfg.bdentro && !!cfg.barra);
      pinta(true);
      /* na primeira carga tudo e "novo": sem isto o overlay daria um "+X"
         toda vez que o OBS abrisse a cena */
      if (!primeiraCarga) viuGanho(antes, cfg);
      primeiraCarga = false;
    }

    update(cfg);
    batida = criaBatida(200, bateu);
    if (global.requestAnimationFrame) laco();
    agenda();

    doc.addEventListener('visibilitychange', acordou);
    global.addEventListener('pageshow', acordou);
    global.addEventListener('focus', acordou);
    /* eventos próprios do OBS: chegam ao trocar de cena */
    global.addEventListener('obsSourceVisibleChanged', acordou);
    global.addEventListener('obsSourceActiveChanged', acordou);

    /* a fonte chega depois e muda a largura: remede quando terminar */
    if (doc.fonts && doc.fonts.ready) {
      doc.fonts.ready.then(function () { pinta(true); })['catch'](function () {});
    }

    return {
      update: update,
      config: function () { return assign({}, cfg); },
      /* pega carona na batida — sobrevive ao OBS esconder a fonte */
      aoTique: function (fn) { ouvintes.push(fn); },
      temBatida: function () { return !!batida; },
      destroy: function () {
        clearTimeout(timer);
        if (raf) global.cancelAnimationFrame(raf);
        if (batida) batida.terminate();
        doc.removeEventListener('visibilitychange', acordou);
        global.removeEventListener('pageshow', acordou);
        global.removeEventListener('focus', acordou);
        global.removeEventListener('obsSourceVisibleChanged', acordou);
        global.removeEventListener('obsSourceActiveChanged', acordou);
        root.innerHTML = '';
      }
    };
  }

  global.Relogio = {
    /* O chat.js usa os dois. Sao a MESMA aparencia dos outros overlays: se
       ele tivesse a propria, existiriam dois sistemas de cor e tipografia
       pra divergir com o tempo. */
    rgba: rgba,
    aplicaEstilo: aplicaEstilo,
    TZ: TZ,
    FONTS: FONTS,
    SCHEMA: SCHEMA,
    KEYS: KEYS,
    DEFAULTS: DEFAULTS,
    PRESETS: PRESETS,
    CONTEUDOS: CONTEUDOS,
    faltamSegundos: faltamSegundos,
    tempoHMS: tempoHMS,
    agoraServidor: agoraServidor,
    ajustaRelogioDoServidor: function (ms) { desvioServidor = ms || 0; },
    sanitize: sanitize,
    exemploData: exemploData,
    parseConfig: parseConfig,
    toQuery: toQuery,
    aplicaPreset: aplicaPreset,
    mount: mount
  };
})(window);
