/**
 * conferir.js — as listas que precisam bater.
 *
 * Rode com:  node conferir.js
 *
 * Existe por causa de um bug que já aconteceu: o desenhista aprendeu três
 * tipos novos de overlay e a lista do servidor ficou nos cinco antigos. O
 * resultado foi "tipo de overlay desconhecido" na cara de quem tentava criar
 * um — uma mensagem que não diz onde está o problema.
 *
 * Toda lista aqui existe em dois ou três lugares por um motivo real (o
 * servidor não pode confiar no navegador), mas duas cópias de uma verdade só
 * divergem sozinhas com o tempo. Isto é o que avisa quando divergem.
 */
'use strict';

const fs = require('fs');
const ler = (p) => fs.readFileSync(p, 'utf8');

const clock  = ler('docs/clock.js');
const hub    = ler('docs/index.html');
const ponte  = ler('docs/ponte.html');
const perfil = ler('api/perfil.php');
const cmds   = ler('api/comandos.php');
const musica = ler('api/musica.php');
const luzes  = ler('api/lib/luzes.php');

const strings = (txt) => (txt.match(/'([\w:-]+)'/g) || []).map((s) => s.replace(/'/g, ''));
const bloco = (txt, ini, fim) => {
  const i = txt.indexOf(ini);
  if (i === -1) return null;
  const j = txt.indexOf(fim, i + ini.length);
  return j === -1 ? null : txt.slice(i + ini.length, j);
};

let falhas = 0;

function compara(nome, listas) {
  const nomes = Object.keys(listas);
  const faltando = nomes.filter((n) => !listas[n]);
  if (faltando.length) {
    console.log(`✗ ${nome}: não consegui ler de ${faltando.join(', ')}`);
    falhas++;
    return;
  }
  const conjuntos = {};
  nomes.forEach((n) => { conjuntos[n] = new Set(listas[n]); });

  const uniao = new Set(nomes.flatMap((n) => [...conjuntos[n]]));
  const problemas = [];
  uniao.forEach((item) => {
    const tem = nomes.filter((n) => conjuntos[n].has(item));
    if (tem.length !== nomes.length) {
      problemas.push(`${item} (só em ${tem.join(', ')})`);
    }
  });

  if (problemas.length) {
    console.log(`✗ ${nome}: ${problemas.join(' · ')}`);
    falhas++;
  } else {
    console.log(`✓ ${nome}: ${uniao.size} iguais em ${nomes.length} lugares`);
  }
}

/* ---- tipos de overlay ---- */
compara('tipos de overlay', {
  desenhista: strings(clock.match(/tipo:\s*\{[^}]*v:\s*\[([^\]]*)\]/s)?.[1] || ''),
  servidor:   strings(bloco(perfil, 'PERFIL_TIPOS = [', '];') || ''),
  tela:       [...hub.matchAll(/^  (\w+):\s+\{ nome: '/gm)].map((m) => m[1]),
});

/* ---- as vozes do TTS ----

   Quatro lugares, e somar voz num só é o defeito silencioso: a voz aparece
   na tela, o servidor recusa ela, e ninguém liga uma coisa na outra. */
const ttsLib = ler('api/lib/tts.php');
const ttsMotor = ler('docs/tts.js');
compara('vozes do TTS', {
  servidor:  strings(bloco(ttsLib, 'TTS_VOZES = [', '];') || ''),
  motor:     [...ttsMotor.matchAll(/^    (\w+):\s+\{ nome:/gm)].map((m) => m[1]),
  tela:      [...(bloco(hub, 'const TTS_NOMES = {', '};') || '').matchAll(/(\w+):\s*'/g)].map((m) => m[1]),
  desenhista: strings(clock.match(/tvoz:\s*\{[^}]*v:\s*\[([^\]]*)\]/s)?.[1] || ''),
});

/* ---- as seções do painel do OBS ----

   Esconder uma seção que o servidor não conhece não faz nada; e um nome
   que só existe no servidor vira uma linha na tela que não mexe em nada. */
const painelCfg = ler('api/painel-config.php');
const dock = ler('docs/painel.html');
compara('seções do painel', {
  servidor: strings(bloco(painelCfg, 'PAINEL_SECOES = [', '];') || ''),
  dock:     [...dock.matchAll(/data-secao="(\w+)"/g)].map((m) => m[1]),
  tela:     [...(bloco(hub, 'const PAINEL_NOMES = {', '};') || '').matchAll(/^  (\w+):/gm)].map((m) => m[1]),
});

/* ---- ações que o chat pode disparar ---- */
const naPonte = [];
ponte.split('\n').forEach((linha, i, todas) => {
  if (!/minimo:\s*'\w+',\s*espera:/.test(linha)) return;
  const minimo = (linha.match(/minimo:\s*'(\w+)'/) || [])[1] || '?';
  for (let j = i - 1; j >= 0 && j > i - 8; j--) {
    const a = todas[j].match(/^\s*(\w+):\s*\{\s*$/);
    const b = todas[j].match(/^ACOES\.(\w+)\s*=\s*\{/);
    if (a || b) { naPonte.push({ nome: (a || b)[1], minimo }); return; }
  }
});
compara('ações do chat', {
  ponte:    naPonte.map((x) => x.nome),
  servidor: strings(bloco(cmds, 'ACOES_VALIDAS = [', '];') || ''),
  tela:     [...(bloco(hub, 'const DE_FABRICA = [', '\n];') || '').matchAll(/\['(\w+)'/g)].map((m) => m[1]),
});

/* ---- E QUEM PODE USAR CADA UMA ----

   Comparar só os nomes não bastava. Em 24/09 eu abri o !musica pro chat
   inteiro na ponte e deixei 'mod' na tela: o site seguiu dizendo que era
   de moderador, com etiqueta e tudo, enquanto qualquer um já podia usar.

   Quem MANDA é a ponte, que é quem recusa. A tela só descreve — e
   descrever errado é pior que não descrever, porque a pessoa nem tenta. */
const cargoNaTela = {};
const deFabrica = bloco(hub, 'const DE_FABRICA = [', '\n];') || '';
for (const m of deFabrica.matchAll(/\['(\w+)',\s*'(\w+)'/g)) cargoNaTela[m[1]] = m[2];

const cargoDivergente = naPonte
  .filter((a) => cargoNaTela[a.nome] && cargoNaTela[a.nome] !== a.minimo)
  .map((a) => `${a.nome}: a ponte deixa ${a.minimo} usar, a tela diz ${cargoNaTela[a.nome]}`);

if (cargoDivergente.length) {
  console.log(`\u2717 quem pode usar: ${cargoDivergente.length} com cargo diferente`);
  cargoDivergente.forEach((d) => console.log('  ' + d));
  falhas++;
} else {
  console.log(`\u2713 quem pode usar: ${Object.keys(cargoNaTela).length} ações com o mesmo cargo nos dois lugares`);
}

/* ---- a versão da ponte ----

   O painel usa ela pra dizer "a sua fonte do OBS está com o código antigo".
   Se as duas divergirem, o painel acusa cache em todo mundo, pra sempre. */
const versaoPonte = ponte.match(/const PONTE_VERSAO = '([^']+)'/)?.[1];
const versaoHub   = hub.match(/const PONTE_VERSAO = '([^']+)'/)?.[1];
if (versaoPonte && versaoPonte === versaoHub) {
  console.log(`✓ versão da ponte: ${versaoPonte} nos dois lugares`);
} else {
  console.log(`✗ versão da ponte: ponte.html=${versaoPonte} e index.html=${versaoHub}`);
  falhas++;
}

/* A VERSÃO TEM DE SUBIR QUANDO A PONTE MUDA.

   Conferir que os dois lugares batem não basta: em 23/09 eu mexi na ponte
   e deixei a versão velha nos DOIS. Os números batiam, o site não avisou
   ninguém, e o OBS de todo mundo seguiu rodando o código antigo — a troca
   de cena da contagem simplesmente não acontecia, sem erro nenhum. */
try {
  const mudou = require('child_process')
    .execSync('git log -1 --format=%cs -- docs/ponte.html', { encoding: 'utf8' }).trim();
  if (mudou && versaoPonte && mudou > versaoPonte) {
    console.log(`✗ a ponte mudou em ${mudou} e a versão ainda é ${versaoPonte}`);
    console.log('  suba o PONTE_VERSAO no docs/ponte.html E no docs/index.html');
    falhas++;
  }
} catch (e) { /* sem git: não dá pra conferir, e não é motivo pra falhar */ }

/* ---- o ?v= dos overlays subiu junto com os arquivos? ----

   Mesma armadilha da ponte, noutro arquivo. O OBS guarda clock.js, tts.js
   e companhia em cache, e quem manda buscar de novo é o ?v= do
   overlay.html. Mexer num desenhista sem subir o número deixa o OBS de
   todo mundo rodando o código velho — foi assim que as vozes de tom 0.2
   continuaram chiando depois de eu já ter corrigido. */
const vOverlay = Number((ler('docs/overlay.html').match(/\?v=(\d+)/) || [])[1] || 0);
try {
  const gitData = (arq) => require('child_process')
    .execSync(`git log -1 --format=%ct -- ${arq}`, { encoding: 'utf8' }).trim();

  const desenhistas = ['docs/clock.js', 'docs/chat.js', 'docs/musica.js',
                       'docs/alerta.js', 'docs/tts.js', 'docs/spd.js'];
  const quandoOverlay = Number(gitData('docs/overlay.html') || 0);

  const maisNovos = desenhistas.filter((d) => Number(gitData(d) || 0) > quandoOverlay);

  if (maisNovos.length) {
    console.log(`\u2717 ?v= dos overlays: ${maisNovos.join(', ')} mudou depois do overlay.html`);
    console.log(`  suba o ?v=${vOverlay} pra ${vOverlay + 1} no docs/overlay.html`);
    falhas++;
  } else {
    console.log(`\u2713 ?v= dos overlays: v=${vOverlay}, mais novo que os desenhistas`);
  }
} catch (e) { /* sem git: n\u00e3o d\u00e1 pra conferir */ }

/* ---- o caminho do dinheiro ----

   O desconto por dias de raid mexe em dinheiro e a cobrança já está em
   produção. O ensaio prova as regras que quebram em silêncio: o teto de
   cinco dias por mês, o cupom por cima sem zerar a fatura, e o estorno
   devolvendo os dias uma vez só. */
try {
  /* path.join, e não barras à mão: escapar barra invertida dentro de
     string é onde se erra, e o erro vira "o ensaio falhou" quando o que
     falhou foi achar o PHP. */
  const php = process.env.LOCALAPPDATA
    ? require('path').join(process.env.LOCALAPPDATA, 'php', 'php.exe')
    : 'php';
  require('child_process').execSync('"' + php + '" testes/pagamento.php', { stdio: 'pipe' });
  console.log('✓ caminho do dinheiro: o ensaio passou');
} catch (e) {
  console.log('✗ caminho do dinheiro: o ensaio FALHOU — rode testes/pagamento.php');
  falhas++;
}

/* ---- cargos, e na MESMA ordem: aqui a ordem é a regra ---- */
const cargosPonte = Object.keys(JSON.parse(
  '{' + (ponte.match(/const CARGOS = \{([^}]*)\}/)?.[1] || '').replace(/(\w+):/g, '"$1":') + '}'
));
const cargosCmds  = strings(bloco(cmds, 'QUEM_VALIDO = [', '];') || '');
const cargosMus   = [...(bloco(musica, 'CARGOS_ORDEM = [', '];') || '').matchAll(/'(\w+)'\s*=>/g)].map((m) => m[1]);
const cargosLuz   = strings(bloco(luzes, 'LUZ_CARGOS = [', '];') || '');

if (JSON.stringify(cargosPonte) === JSON.stringify(cargosCmds)
    && JSON.stringify(cargosPonte) === JSON.stringify(cargosMus)
    && JSON.stringify(cargosPonte) === JSON.stringify(cargosLuz)) {
  console.log(`✓ cargos: ${cargosPonte.length} iguais e na mesma ordem`);
} else {
  console.log('✗ cargos fora de ordem ou diferentes:');
  console.log('   ponte   ', cargosPonte.join(' < '));
  console.log('   comandos', cargosCmds.join(' < '));
  console.log('   musica  ', cargosMus.join(' < '));
  console.log('   luzes   ', cargosLuz.join(' < '));
  falhas++;
}

/* ---- edições de texto que o código deixou sem par ----

   A edição feita no modo de edição é guardada pelo texto ORIGINAL do
   código. Reescrever esse texto aqui sem avisar o motor deixava a edição
   sem par: ela continuava no banco e sumia do site. Isto lê as edições que
   estão no ar e confere se cada original ainda existe no código (o
   index.html e as telas de admin) — direto, ou como texto antigo em
   TEXTOS_ANTIGOS, no docs/index.html. */
const TEXTOS_DINAMICOS = [
  'Novo Meta',   // título montado na hora: 'Novo ' + o nome do tipo
  // Montado com o nome do bot, que vem do config: no código a frase está
  // partida em volta da variável e nunca vai bater inteira aqui.
  'Quem fala no seu chat é a conta ZocaHub.',
];

async function confereTextos() {
  let d;
  try {
    const r = await fetch('https://api.zocahop.com/textos.php', { signal: AbortSignal.timeout(8000) });
    d = await r.json();
  } catch (e) {
    console.log('? edições de texto: não consegui ler as que estão no ar (' + e.message + ')');
    return;
  }

  /* Texto comprido fica quebrado em 'pedaço' + 'pedaço' no código; juntar
     os pedaços é o que deixa achar a frase inteira. */
  const juntos = (hub + ler('api/admin/tela.js')).replace(/'\s*\+\s*'/g, '');
  const todas = Object.keys((d && d.textos) || {});
  const sem = todas.filter((k) => !juntos.includes(k) && !TEXTOS_DINAMICOS.includes(k));

  if (sem.length) {
    console.log('✗ edições de texto sem par no código (o texto original mudou):');
    sem.forEach((k) => console.log('   "' + k.slice(0, 90) + (k.length > 90 ? '…' : '') + '"'));
    console.log('   → no docs/index.html, em TEXTOS_ANTIGOS: texto novo apontando pro antigo');
    falhas++;
  } else {
    console.log(`✓ edições de texto: ${todas.length} no ar, todas com par no código`);
  }
}

/* ---------- a tela de saúde aponta pro SQL certo ----------

   A tela diz "falta rodar o 071-cor-nick.sql" e a pessoa vai procurar um
   arquivo com esse nome. Se o nome estiver errado — e já esteve — o aviso
   vira uma caça ao tesouro pior que nenhum aviso.

   Não dá pra exigir que toda migração esteja citada: são sessenta e poucas,
   e a tela só lista as que valem checar. Dá pra exigir que o que está
   citado exista, e que a coluna prometida esteja mesmo naquele arquivo. */
(function confereSql() {
  const saude = ler('api/saude.php');
  const lista = saude.match(/\[\s*'[a-z_]+',\s*(?:null|'[a-z_]+'),\s*'[^']*',\s*'[^']*\.sql'\s*\]/g) || [];
  if (!lista.length) {
    console.log('? SQL da tela de saúde: não achei a lista');
    return;
  }

  const ruins = [];
  lista.forEach((linha) => {
    const partes = linha.match(/'([^']*)'|null/g).map((x) => x.replace(/'/g, ''));
    const coluna = partes[1] === 'null' ? '' : partes[1];
    const arquivo = partes[3];

    if (!fs.existsSync('sql/' + arquivo)) {
      ruins.push(arquivo + ' não existe em sql/');
      return;
    }
    if (coluna && !ler('sql/' + arquivo).includes(coluna)) {
      ruins.push(arquivo + ' não mexe na coluna ' + coluna);
    }
  });

  if (ruins.length) {
    console.log('✗ a tela de saúde manda rodar SQL que não confere:');
    ruins.forEach((r) => console.log('   ' + r));
    console.log('   → em api/saude.php, na lista SEM_BANCO');
    falhas++;
  } else {
    console.log(`✓ SQL da tela de saúde: ${lista.length} avisos, todos apontando certo`);
  }
})();

/* ---------- ramo que nunca é alcançado ----------

   O ramo geral de GET responde e ENCERRA. Qualquer "if (isset($_GET[...]))"
   escrito depois dele é código que nunca roda — e não dá erro, não dá aviso,
   não dá nada: a tela só recebe a resposta errada.

   Já aconteceu no tts.php, no raid.php e no checkout.php. Três vezes o mesmo
   defeito, e as três descobertas na mão, procurando. */
(function confereRamos() {
  const arquivos = fs.readdirSync('api').filter((f) => f.endsWith('.php'));
  const ruins = [];

  arquivos.forEach((nome) => {
    const linhas = ler('api/' + nome).split('\n');

    /* SÓ o ramo GERAL de GET, e não qualquer menção a REQUEST_METHOD: o
       "!== 'POST'" dentro de um ramo é guarda de método e encerra só aquele
       ramo. Confundir os dois enche a saída de alarme falso — e alarme falso
       é como um conferidor passa a ser ignorado. */
    const geral = linhas.findIndex((l) =>
      /^if \(\(\$_SERVER\['REQUEST_METHOD'\][^)]*\)\s*===\s*'GET'\)/.test(l.trim()));
    if (geral === -1) return;

    linhas.forEach((l, i) => {
      if (i > geral && /^if \(isset\(\$_GET\[/.test(l.trim())) {
        ruins.push(nome + ':' + (i + 1) + '  ' + l.trim().slice(0, 50));
      }
    });
  });

  if (ruins.length) {
    console.log('✗ ramos de GET escritos DEPOIS do ramo geral (nunca rodam):');
    ruins.forEach((r) => console.log('   ' + r));
    console.log('   → mova o ramo pra ANTES do "if (REQUEST_METHOD === GET)"');
    falhas++;
  } else {
    console.log('✓ ordem dos ramos: nenhum ramo de GET inalcançável');
  }
})();

confereTextos().then(() => {
  console.log(falhas ? `\n${falhas} lista(s) fora de sincronia.` : '\nTudo batendo.');
  process.exit(falhas ? 1 : 0);
});
