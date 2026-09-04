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

/* ---- ações que o chat pode disparar ---- */
const naPonte = [];
ponte.split('\n').forEach((linha, i, todas) => {
  if (!/minimo:\s*'\w+',\s*espera:/.test(linha)) return;
  for (let j = i - 1; j >= 0 && j > i - 8; j--) {
    const a = todas[j].match(/^\s*(\w+):\s*\{\s*$/);
    const b = todas[j].match(/^ACOES\.(\w+)\s*=\s*\{/);
    if (a || b) { naPonte.push((a || b)[1]); return; }
  }
});
compara('ações do chat', {
  ponte:    naPonte,
  servidor: strings(bloco(cmds, 'ACOES_VALIDAS = [', '];') || ''),
  tela:     [...(bloco(hub, 'const DE_FABRICA = [', '\n];') || '').matchAll(/\['(\w+)'/g)].map((m) => m[1]),
});

/* ---- cargos, e na MESMA ordem: aqui a ordem é a regra ---- */
const cargosPonte = Object.keys(JSON.parse(
  '{' + (ponte.match(/const CARGOS = \{([^}]*)\}/)?.[1] || '').replace(/(\w+):/g, '"$1":') + '}'
));
const cargosCmds  = strings(bloco(cmds, 'QUEM_VALIDO = [', '];') || '');
const cargosMus   = [...(bloco(musica, 'CARGOS_ORDEM = [', '];') || '').matchAll(/'(\w+)'\s*=>/g)].map((m) => m[1]);

if (JSON.stringify(cargosPonte) === JSON.stringify(cargosCmds)
    && JSON.stringify(cargosPonte) === JSON.stringify(cargosMus)) {
  console.log(`✓ cargos: ${cargosPonte.length} iguais e na mesma ordem`);
} else {
  console.log('✗ cargos fora de ordem ou diferentes:');
  console.log('   ponte   ', cargosPonte.join(' < '));
  console.log('   comandos', cargosCmds.join(' < '));
  console.log('   musica  ', cargosMus.join(' < '));
  falhas++;
}

console.log(falhas ? `\n${falhas} lista(s) fora de sincronia.` : '\nTudo batendo.');
process.exit(falhas ? 1 : 0);
