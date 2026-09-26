/* Telas de admin do ZocaController.

   Não é arquivo público: o admin-tela.php só entrega isto pra conta admin,
   e roda dentro do index.html, com as funções dele (h, api, el, recado...). */

/* ---------------- a edição em si ---------------- */

/* Marca o ELEMENTO que contém o texto, e não o nó: nó de texto não recebe
   contorno nem clique. */
function marcaEditaveis() {
  textoNos(document.body).forEach((no) => {
    const pai = no.parentElement;
    if (!pai || pai.hasAttribute('data-edit')) return;
    pai.setAttribute('data-edit', '1');
    if (textoEditado(no.nodeValue.trim())) pai.setAttribute('data-edit-mudado', '1');
  });
}

function limpaEditaveis() {
  document.querySelectorAll('[data-edit]').forEach((e) => {
    e.removeAttribute('data-edit');
    e.removeAttribute('data-edit-mudado');
  });
}

let caixaEdicao = null;

function fechaEdicao() {
  if (caixaEdicao) { caixaEdicao.remove(); caixaEdicao = null; }
}

function abreEdicao(no) {
  fechaEdicao();

  const atual = no.nodeValue.trim();
  /* O original é o que está no CÓDIGO, e não o que está na tela: se já houve
     substituição, editar de novo precisa continuar apontando pra mesma
     chave, senão cada edição criaria uma linha nova. */
  let original = atual;
  Object.keys(TEXTOS).forEach((k) => { if (TEXTOS[k] === atual) original = k; });

  const area = h('textarea');
  area.value = atual;

  const salvar = h('button', { cls: 'bt principal mini', type: 'button', txt: 'Salvar' });
  const voltar = h('button', { cls: 'bt mini', type: 'button', txt: 'Voltar ao original' });
  const fechar = h('button', { cls: 'bt fraco mini', type: 'button', txt: 'Fechar' });
  const diz = h('span', { cls: 'd', style: 'font-size:12px' });

  voltar.disabled = !TEXTOS[original];

  salvar.onclick = async () => {
    salvar.disabled = true;
    diz.textContent = 'salvando…';
    try {
      await api('/textos.php', { method: 'POST',
        body: JSON.stringify({ acao: 'salvar', original, valor: area.value }) });
      if (area.value.trim() === original) delete TEXTOS[original];
      else TEXTOS[original] = area.value.trim();
      no.nodeValue = no.nodeValue.replace(atual, area.value.trim());
      fechaEdicao();
      recado('Texto salvo.', 'bom');
    } catch (e) {
      diz.textContent = e.message;
      salvar.disabled = false;
    }
  };

  voltar.onclick = async () => {
    voltar.disabled = true;
    try {
      await api('/textos.php', { method: 'POST',
        body: JSON.stringify({ acao: 'apagar', original }) });
      delete TEXTOS[original];
      no.nodeValue = no.nodeValue.replace(atual, original);
      fechaEdicao();
      recado('Voltou ao texto original.', 'bom');
    } catch (e) { diz.textContent = e.message; voltar.disabled = false; }
  };

  fechar.onclick = fechaEdicao;

  caixaEdicao = h('div', { cls: 'edicao' }, [
    h('div', { cls: 'w2' }, [
      h('div', { cls: 'antes', txt: 'Original: ' + original }),
      area,
      h('div', { cls: 'botoes' }, [salvar, voltar, fechar, diz]),
    ]),
  ]);
  document.body.appendChild(caixaEdicao);
  area.focus();
}

function ligaModoEdicao(liga) {
  modoEdicao = liga;
  document.body.classList.toggle('editando', liga);
  el('abaTextos').classList.toggle('editando', liga);
  el('abaTextos').textContent = liga ? 'Sair da edição' : 'Editar textos';
  if (liga) marcaEditaveis(); else { limpaEditaveis(); fechaEdicao(); }
}

document.addEventListener('click', (e) => {
  if (!modoEdicao) return;
  /* A barra continua clicável: é por ela que se sai do modo e se troca de
     tela. Sem esta linha, ligar a edição prendia a pessoa onde estava. */
  if (e.target.closest('.barra')) return;

  const alvo = e.target.closest('[data-edit]');
  if (!alvo || alvo.closest('.edicao')) return;

  /* Pega o nó de texto de verdade dentro do elemento clicado. */
  const no = [...alvo.childNodes].find((n) => n.nodeType === 3 && n.nodeValue.trim());
  if (!no) return;

  /* Em modo de edição o clique é pra editar, nunca pra acionar o botão. */
  e.preventDefault();
  e.stopPropagation();
  abreEdicao(no);
}, true);

/* ----- conferir a credencial do Mercado Pago -----

   O 403 deles é sempre o mesmo texto pra três causas diferentes, e
   descobrir qual delas é exige comparar credencial com conta. Botão porque
   a alternativa era mandar montar cabeçalho na mão no navegador — e quem
   está tentando abrir a cobrança já está com problema demais pra isso. */
/* Em produção TAMBÉM, e principalmente: era só no modo de teste, e a
   hora de conferir de quem é o token é justamente quando ele passa a
   mover dinheiro de verdade. */
function blocoCredencialMP() {
  const saida = h('pre', { cls: 'diag escondido' });
  const bt = h('button', { cls: 'bt', type: 'button', txt: 'Conferir a credencial do Mercado Pago' });

  bt.onclick = async () => {
    bt.disabled = true;
    saida.classList.remove('escondido');
    saida.textContent = 'perguntando ao Mercado Pago…';
    try {
      const d = await api('/checkout.php?diagnostico=1');
      const linhas = [];

      linhas.push('A credencial no config é: ' + (d.token_forma || '?'));
      linhas.push('  começa com "' + (d.token_prefixo || '—') + '", ' + d.token_tamanho + ' caracteres');
      linhas.push('Assinatura secreta preenchida: ' + (d.tem_segredo ? 'sim' : 'NÃO'));
      linhas.push('Modo: ' + d.modo + '   ·   Cobrança ligada: ' + (d.ligado ? 'sim' : 'não'));
      linhas.push('');

      if (d.conta) {
        linhas.push('O token é da conta: ' + (d.conta.apelido || '?')
          + '  (id ' + (d.conta.id || '?') + ', país ' + (d.conta.site || '?') + ')');

        /* O NOME QUE O COMPRADOR VÊ, dito aqui em vez de descoberto pagando.

           O checkout e o recibo mostram o nome da conta do Mercado Pago, e em
           conta de pessoa física esse nome é o nome civil do dono. Quem vende
           com marca própria precisa saber disso antes do primeiro cliente ver. */
        if (d.conta.na_fatura === undefined) {
          linhas.push('');
          linhas.push('O seu api/lib/mercadopago.php ESTÁ DESATUALIZADO no servidor:');
          linhas.push('esta tela deveria mostrar o nome que aparece pro comprador, e o');
          linhas.push('servidor não mandou. Suba o arquivo de novo.');
        } else {
          linhas.push('');
          linhas.push('O comprador vê: ' + (d.conta.fantasia || d.conta.nome || '?')
            + (d.conta.fantasia ? '  (nome fantasia)' : '  (nome da conta)'));
          if (d.conta.razao) linhas.push('Razão social: ' + d.conta.razao);
          linhas.push('Na fatura do cartão: ' + d.conta.na_fatura);
          if (!d.conta.fantasia) {
            linhas.push('  Conta sem nome fantasia mostra o nome do titular no');
            linhas.push('  checkout e no recibo. Nome fantasia é coisa de conta PJ.');
          }
        }
      } else {
        linhas.push('Não consegui saber de quem é o token: ' + (d.users_me_erro || d.users_me_http));
      }
      linhas.push('');

      if (d.preferencia) {
        linhas.push('Criar cobrança: FUNCIONOU. A credencial serve.');
      } else {
        linhas.push('Criar cobrança: falhou (' + d.preferencia_http + ')');
        linhas.push('  ' + (d.preferencia_erro || '—'));
        linhas.push('');
        /* O veredito, não só os dados: a lista crua acima já existia no
           JSON e não estava ajudando ninguém a decidir o que fazer. */
        if (/public/i.test(d.token_forma || '')) {
          linhas.push('O QUE FAZER: você copiou a Public Key. Volte em');
          linhas.push('Suas integrações > sua aplicação > Credenciais de teste');
          linhas.push('e copie o ACCESS TOKEN, que é o campo de baixo e bem mais longo.');
        } else if (d.preferencia_http === 403) {
          linhas.push('O QUE FAZER: o token é válido mas não pode cobrar por esta conta.');
          linhas.push('Quase sempre é token de USUÁRIO DE TESTE no lugar do token da');
          linhas.push('sua aplicação. Use Credenciais de teste da APLICAÇÃO.');
        } else if (d.preferencia_http === 401) {
          linhas.push('O QUE FAZER: o token não vale mais. Gere outro no painel deles.');
        }
      }
      saida.textContent = linhas.join('\n');
    } catch (e) {
      saida.textContent = 'Não consegui conferir: ' + e.message;
    }
    bt.disabled = false;
  };

  return h('div', { cls: 'so-adm' }, [
    h('p', { style: 'margin:0 0 8px', txt: 'Diagnóstico da credencial. Não mostra o token.' }),
    bt, saida,
  ]);
}

/* ---------------- a vitrine, no admin ---------------- */

const VITRINE_FATIAS = [
  ['usuario', 'Quem usa o site', 'sorteado entre as contas do ZocaController que estiverem no ar'],
  ['pro',     'Assinante',       'só entre quem paga — é o que faz assinar valer algo além dos recursos'],
  ['twitch',  'Descoberta',      'qualquer canal pequeno em português, usuário do site ou não'],
];

function blocoVitrine() {
  const cx = h('div', { cls: 'fatia' }, [
    h('h3', { txt: 'Vitrine da página inicial' }),
    h('p', { cls: 'd', txt: 'Três painéis. Sem ninguém no ar, o painel não aparece.' }),
  ]);

  const corpo = h('div');
  cx.appendChild(corpo);

  const carrega = () => {
    corpo.innerHTML = '';
    api('/vitrine.php', { method: 'POST', body: JSON.stringify({ acao: 'estado' }) }).then((d) => {
      const cfg = d.config || {};
      const campos = {};

      VITRINE_FATIAS.forEach(([k, nome, dica]) => {
        const c = cfg[k] || {};

        const liga = h('input', { type: 'checkbox' });
        liga.checked = c.ligado !== false;

        const fixo = h('input', { type: 'text', placeholder: 'sortear', style: 'flex:0 0 150px' });
        fixo.value = c.fixo || '';

        const cat = h('input', { type: 'text', placeholder: 'qualquer categoria', style: 'flex:0 0 170px' });
        cat.value = c.categoria || '';

        const dinovo = h('button', { cls: 'bt mini', type: 'button', txt: 'Sortear de novo' });
        dinovo.onclick = async () => {
          dinovo.disabled = true;
          try {
            await api('/vitrine.php', { method: 'POST', body: JSON.stringify({ acao: 'sortear', fatia: k }) });
            recado('Pronto. O próximo a abrir a página inicial já vê outro canal.', 'bom');
          } catch (e) { recado(e.message, 'ruim'); }
          dinovo.disabled = false;
        };

        campos[k] = { liga, fixo, cat };

        const linha = h('div', { cls: 'campo', style: 'flex-wrap:wrap;gap:10px' }, [
          h('label', { style: 'flex:0 0 130px' }, [liga, document.createTextNode(' ' + nome)]),
          h('span', { cls: 'd', style: 'flex:0 0 90px', txt: 'canal fixo' }), fixo,
        ]);
        /* Categoria só faz sentido onde existe sorteio na Twitch: nas outras
           duas o conjunto já é "quem usa o site", e filtrar por jogo ali só
           encolheria um grupo que já é pequeno. */
        if (k === 'twitch') {
          linha.appendChild(h('span', { cls: 'd', style: 'flex:0 0 70px', txt: 'categoria' }));
          linha.appendChild(cat);
        }
        linha.appendChild(dinovo);

        corpo.appendChild(linha);
        corpo.appendChild(h('p', { cls: 'd', style: 'margin:0 0 12px', txt: dica
          + '. Escrevendo um canal em "canal fixo", ele manda em cima do sorteio.' }));
      });

      const salvar = h('button', { cls: 'bt principal', type: 'button', txt: 'Salvar a vitrine' });
      salvar.onclick = async () => {
        salvar.disabled = true;
        const corpoReq = { acao: 'config' };
        VITRINE_FATIAS.forEach(([k]) => {
          corpoReq[k] = {
            ligado: campos[k].liga.checked,
            fixo: campos[k].fixo.value.trim(),
            categoria: campos[k].cat.value.trim(),
          };
        });
        try {
          await api('/vitrine.php', { method: 'POST', body: JSON.stringify(corpoReq) });
          recado('Vitrine salva. Mudar a regra apaga o sorteio de hoje, então já vale agora.', 'bom');
        } catch (e) { recado(e.message, 'ruim'); }
        salvar.disabled = false;
      };
      corpo.appendChild(salvar);

      /* ----- a lista de banidos ----- */
      corpo.appendChild(h('h3', { style: 'margin-top:22px', txt: 'Quem não pode aparecer' }));
      corpo.appendChild(h('p', { cls: 'd', txt: 'Vale pros três painéis, e tira da tela na hora.' }));

      const nome = h('input', { type: 'text', placeholder: 'canal na Twitch', style: 'flex:0 0 170px' });
      const motivo = h('input', { type: 'text', placeholder: 'motivo (só você vê)', style: 'flex:1 1 200px' });
      const banir = h('button', { cls: 'bt mini perigo', type: 'button', txt: 'Banir' });
      banir.onclick = async () => {
        if (!nome.value.trim()) return recado('Escreva o canal.', 'ruim');
        banir.disabled = true;
        try {
          await api('/vitrine.php', { method: 'POST', body: JSON.stringify({
            acao: 'banir', login: nome.value.trim(), motivo: motivo.value.trim() }) });
          nome.value = ''; motivo.value = '';
          carrega();
        } catch (e) { recado(e.message, 'ruim'); }
        banir.disabled = false;
      };
      corpo.appendChild(h('div', { cls: 'campo', style: 'flex-wrap:wrap;gap:8px' }, [nome, motivo, banir]));

      const bans = d.bloqueados || [];
      if (!bans.length) {
        corpo.appendChild(h('p', { cls: 'd', txt: 'Ninguém banido.' }));
      } else {
        bans.forEach((b) => {
          const tirar = h('button', { cls: 'bt mini', type: 'button', txt: 'Tirar' });
          tirar.onclick = async () => {
            try {
              await api('/vitrine.php', { method: 'POST', body: JSON.stringify({ acao: 'desbanir', login: b.login }) });
              carrega();
            } catch (e) { recado(e.message, 'ruim'); }
          };
          corpo.appendChild(h('div', { cls: 'campo', style: 'gap:10px' }, [
            h('b', { style: 'flex:0 0 170px', txt: b.login }),
            h('span', { cls: 'd', style: 'flex:1', txt: b.motivo || '—' }),
            tirar,
          ]));
        });
      }
    }).catch((e) => {
      corpo.appendChild(h('p', { cls: 'd', txt: 'Não consegui ler a vitrine: ' + e.message }));
    });
  };

  carrega();
  return cx;
}

/* ---------------- cupons e parceiros ---------------- */


/* A CONTA DA COMISSÃO, ANTES DE PROMETER QUALQUER COISA.

   Cupom e comissão são números independentes que saem de bolsos diferentes:
   o cupom sai do preço, a comissão sai do que sobrou pra você. Somar os dois
   de cabeça erra, e errar aqui é fechar parceria no prejuízo.

   As taxas do Mercado Pago são de 2026, recebendo na hora. */
const CALC_TAXAS = { pix: 0.99, credito: 4.98, debito: 1.99 };

function calculadoraComissao() {
  const campo = (rot, el2) => h('label', {}, [document.createTextNode(rot), el2]);

  const preco = h('input', { type: 'text', value: '13,99' });
  const desc = h('input', { type: 'number', value: '20', min: '0', max: '100' });
  const com = h('input', { type: 'number', value: '20', min: '0', max: '100' });
  const meio = h('select');
  [['pix', 'Pix'], ['credito', 'Crédito'], ['debito', 'Débito']]
    .forEach(([v, r]) => meio.appendChild(h('option', { value: v, txt: r })));

  const saida = h('div', { cls: 'calc-saida' });

  const conta = () => {
    const tab = Math.round((parseFloat(String(preco.value).replace(',', '.')) || 0) * 100);
    const d = Math.max(0, Math.min(100, parseFloat(desc.value) || 0));
    const c = Math.max(0, Math.min(100, parseFloat(com.value) || 0));
    const tx = CALC_TAXAS[meio.value];

    const paga = Math.round(tab * (100 - d) / 100);
    const comis = Math.round(paga * c / 100);
    const taxa = Math.round(paga * tx / 100);
    const sobra = paga - comis - taxa;
    const pct = tab > 0 ? Math.round(sobra / tab * 100) : 0;

    const reais = (v) => (v / 100).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });

    saida.innerHTML = '';
    const linha = (rot, val, cls) => {
      saida.appendChild(h('span', { cls: cls || '', txt: rot }));
      saida.appendChild(h('span', { cls: 'v ' + (cls || ''), txt: val }));
    };

    linha('Tabela', reais(tab));
    linha('Cliente paga (−' + d + '%)', reais(paga));
    linha('Parceiro leva (' + c + '%)', '− ' + reais(comis));
    /* Vírgula, não ponto: é número em português no meio de reais. */
    linha('Taxa ' + meio.options[meio.selectedIndex].text
          + ' (' + String(tx).replace('.', ',') + '%)', '− ' + reais(taxa));
    linha('Sobra pra você', reais(sobra) + '  (' + pct + '%)', 'forte ' + (pct >= 50 ? 'bom' : 'ruim'));

    if (pct < 50) {
      saida.appendChild(h('span', { cls: 'ruim', style: 'grid-column:1/-1;margin-top:6px;font-size:12px',
        txt: pct <= 0 ? 'Esta combinação dá PREJUÍZO.' : 'Sobra menos da metade da tabela.' }));
    }
  };

  [preco, desc, com].forEach((e) => { e.oninput = conta; });
  meio.onchange = conta;
  conta();

  return h('div', { cls: 'so-adm' }, [
    h('p', { style: 'margin:0 0 8px', txt: 'Calculadora: o desconto sai do preço, a comissão sai do que sobra.' }),
    h('div', { cls: 'calc' }, [
      campo('preço', preco), campo('cupom %', desc),
      campo('comissão %', com), campo('pago com', meio),
    ]),
    saida,
  ]);
}

function blocoParceiros() {
  const cx = h('div', { cls: 'fatia' }, [
    h('h3', { txt: 'Cupons e parceiros' }),
    h('p', { cls: 'd', txt: 'Cupom sem parceiro é promoção. Com parceiro, gera comissão sobre o valor pago.' }),
  ]);

  cx.appendChild(calculadoraComissao());

  const corpo = h('div');
  cx.appendChild(corpo);

  const carrega = () => {
    corpo.innerHTML = '<p class="d">vendo…</p>';

    api('/admin.php').then((d) => {
      corpo.innerHTML = '';
      const parceiros = d.parceiros || [];
      const cupons = d.cupons || [];

      /* ---------- parceiros ---------- */
      corpo.appendChild(h('h3', { style: 'margin-top:6px', txt: 'Parceiros' }));

      parceiros.forEach((pa) => {
        const quitar = h('button', { cls: 'bt mini', type: 'button', txt: 'Marcar como pago' });
        quitar.disabled = !Number(pa.a_pagar);
        quitar.onclick = async () => {
          if (!confirm('Marcar ' + reaisDe(pa.a_pagar) + ' como pago para ' + pa.nome + '?')) return;
          quitar.disabled = true;
          try {
            const r = await api('/admin.php', { method: 'POST',
              body: JSON.stringify({ acao: 'comissao_pagar', parceiro_id: pa.id }) });
            recado(r.quitadas + ' comissões quitadas.', 'bom');
            carrega();
          } catch (e) { recado(e.message, 'ruim'); }
        };

        corpo.appendChild(h('div', { cls: 'campo', style: 'gap:12px;flex-wrap:wrap' }, [
          h('b', { style: 'flex:0 0 150px', txt: pa.nome + (Number(pa.ligado) ? '' : ' (desligado)') }),
          h('span', { cls: 'd', style: 'flex:0 0 70px', txt: Number(pa.comissao_pct) + '%' }),
          h('span', { cls: 'd', style: 'flex:0 0 90px', txt: pa.vendas + ' vendas' }),
          h('span', { style: 'flex:0 0 130px;color:var(--dourado);font:600 13px var(--mono)',
            txt: 'deve ' + reaisDe(pa.a_pagar) }),
          h('span', { cls: 'd', style: 'flex:0 0 130px', txt: 'pago ' + reaisDe(pa.ja_pago) }),
          quitar,
        ]));
      });
      if (!parceiros.length) corpo.appendChild(h('p', { cls: 'd', txt: 'Nenhum parceiro ainda.' }));

      const pNome = h('input', { type: 'text', placeholder: 'nome do parceiro', style: 'flex:0 0 170px' });
      const pCont = h('input', { type: 'text', placeholder: 'contato / chave Pix', style: 'flex:1 1 170px' });
      const pPct = h('input', { type: 'number', min: '0', max: '100', step: '0.5',
        placeholder: '%', style: 'flex:0 0 70px' });
      const pAdd = h('button', { cls: 'bt mini', type: 'button', txt: '+ Parceiro' });
      pAdd.onclick = async () => {
        if (!pNome.value.trim()) return recado('O parceiro precisa de nome.', 'ruim');
        try {
          await api('/admin.php', { method: 'POST', body: JSON.stringify({
            acao: 'parceiro_salvar', nome: pNome.value, contato: pCont.value,
            comissao_pct: parseFloat(pPct.value) || 0, ligado: true }) });
          pNome.value = ''; pCont.value = ''; pPct.value = '';
          carrega();
        } catch (e) { recado(e.message, 'ruim'); }
      };
      corpo.appendChild(h('div', { cls: 'campo', style: 'gap:8px;flex-wrap:wrap;margin-top:10px' },
        [pNome, pCont, pPct, pAdd]));

      /* ---------- cupons ---------- */
      corpo.appendChild(h('h3', { style: 'margin-top:22px', txt: 'Cupons' }));

      cupons.forEach((cu) => {
        const venceu = cu.vale_ate && new Date(String(cu.vale_ate).replace(' ', 'T')) < new Date();
        const esgotou = cu.usos_max !== null && Number(cu.usos) >= Number(cu.usos_max);

        const apagar = h('button', { cls: 'bt mini perigo', type: 'button', txt: '×' });
        apagar.onclick = async () => {
          if (!confirm('Apagar o cupom ' + cu.codigo + '? As comissões que ele já gerou continuam.')) return;
          try {
            await api('/admin.php', { method: 'POST',
              body: JSON.stringify({ acao: 'cupom_apagar', codigo: cu.codigo }) });
            carrega();
          } catch (e) { recado(e.message, 'ruim'); }
        };

        const estado = !Number(cu.ligado) ? 'desligado' : venceu ? 'vencido' : esgotou ? 'esgotado' : 'valendo';

        corpo.appendChild(h('div', { cls: 'campo', style: 'gap:12px;flex-wrap:wrap' }, [
          h('b', { style: 'flex:0 0 130px;font-family:var(--mono)', txt: cu.codigo }),
          h('span', { cls: 'd', style: 'flex:0 0 110px', txt: cu.tipo === 'percentual'
            ? cu.valor + '% off' : reaisDe(cu.valor) + ' off' }),
          h('span', { cls: 'd', style: 'flex:0 0 140px', txt: cu.parceiro || 'sem parceiro' }),
          h('span', { cls: 'd', style: 'flex:0 0 90px',
            txt: cu.usos + (cu.usos_max === null ? ' usos' : '/' + cu.usos_max) }),
          h('span', { style: 'flex:0 0 90px;font-size:12.5px;color:'
            + (estado === 'valendo' ? 'var(--verde-forte)' : 'var(--apagado)'), txt: estado }),
          naLista(cu),
          editar(cu),
          apagar,
        ]));
      });
      if (!cupons.length) corpo.appendChild(h('p', { cls: 'd', txt: 'Nenhum cupom ainda.' }));

      /* SEM ISTO NÃO DAVA PRA SABER POR QUE A LISTA ESTAVA VAZIA.

         O cupom só aparece pra quem está comprando se estiver marcado como
         público, e a lista aqui não mostrava essa marca — dava pra criar
         dez cupons e continuar vendo "nenhum cupom aberto agora" sem
         nenhuma pista do motivo. */
      function naLista(cu) {
        const b = h('button', { cls: 'bt mini', type: 'button', style: 'flex:0 0 110px' });
        const pinta2 = () => {
          b.textContent = Number(cu.publico) ? 'na lista' : 'escondido';
          b.style.color = Number(cu.publico) ? 'var(--verde-forte)' : 'var(--apagado)';
        };
        b.title = 'Mostrar ou esconder este cupom de quem está comprando';
        b.onclick = async () => {
          const liga = !Number(cu.publico);
          if (liga && cu.parceiro && !confirm('Este cupom é do parceiro ' + cu.parceiro
            + '. Na lista, quem pegar daqui gera comissão pra ele mesmo sem ter vindo pelo link dele. '
            + 'Mostrar mesmo assim?')) return;
          try {
            cu.publico = liga ? 1 : 0;
            pinta2();
            await api('/admin.php', { method: 'POST', body: JSON.stringify({
              acao: 'cupom_publico', codigo: cu.codigo, publico: !!Number(cu.publico) }) });
          } catch (e) { cu.publico = Number(cu.publico) ? 0 : 1; pinta2(); recado(e.message, 'ruim'); }
        };
        pinta2();
        return b;
      }

      const cCod = h('input', { type: 'text', placeholder: 'CÓDIGO', style: 'flex:0 0 130px' });
      const cTipo = h('select', { style: 'flex:0 0 120px' });
      [['percentual', '% de desconto'], ['valor', 'R$ de desconto']]
        .forEach(([v, r]) => cTipo.appendChild(h('option', { value: v, txt: r })));
      const cVal = h('input', { type: 'text', placeholder: 'valor', style: 'flex:0 0 80px' });
      const cParc = h('select', { style: 'flex:0 0 150px' });
      cParc.appendChild(h('option', { value: '', txt: 'sem parceiro' }));
      parceiros.forEach((pa) => cParc.appendChild(h('option', { value: String(pa.id), txt: pa.nome })));
      const cUsos = h('input', { type: 'number', min: '1', placeholder: 'usos', style: 'flex:0 0 80px' });
      const cAte = h('input', { type: 'date', style: 'flex:0 0 140px' });
      const cDesc = h('input', { type: 'text', maxlength: '120', style: 'flex:1 1 220px',
        placeholder: 'descrição (aparece pra quem compra)' });
      const cPub = h('input', { type: 'checkbox' });
      const cLig = h('input', { type: 'checkbox' });
      cLig.checked = true;
      const cAdd = h('button', { cls: 'bt mini', type: 'button', txt: '+ Cupom' });
      const cCancela = h('button', { cls: 'bt mini fraco', type: 'button', txt: 'cancelar', style: 'display:none' });

      /* EDITAR É SALVAR DE NOVO COM O MESMO CÓDIGO.

         O código é a chave do cupom: o servidor atualiza o que já existe e
         mantém quantas vezes ele foi usado. Por isso o código trava durante
         a edição — mudá-lo criaria um cupom novo e deixaria o velho lá. */
      const limpaForm = () => {
        cCod.readOnly = false;
        cCod.value = ''; cVal.value = ''; cUsos.value = ''; cAte.value = ''; cDesc.value = '';
        cParc.value = ''; cTipo.value = 'percentual'; cPub.checked = false; cLig.checked = true;
        cAdd.textContent = '+ Cupom';
        cCancela.style.display = 'none';
      };
      cCancela.onclick = limpaForm;

      function editar(cu) {
        const b = h('button', { cls: 'bt mini', type: 'button', txt: 'editar' });
        b.onclick = () => {
          cCod.value = cu.codigo;
          cCod.readOnly = true;
          cTipo.value = cu.tipo;
          cVal.value = cu.tipo === 'percentual' ? String(cu.valor)
            : (Number(cu.valor) / 100).toFixed(2).replace('.', ',');
          cParc.value = cu.parceiro_id ? String(cu.parceiro_id) : '';
          cUsos.value = cu.usos_max === null ? '' : String(cu.usos_max);
          cAte.value = cu.vale_ate ? String(cu.vale_ate).slice(0, 10) : '';
          cDesc.value = cu.descricao || '';
          cPub.checked = !!Number(cu.publico);
          cLig.checked = !!Number(cu.ligado);
          cAdd.textContent = 'Salvar ' + cu.codigo;
          cCancela.style.display = '';
          cCod.scrollIntoView({ block: 'center', behavior: 'smooth' });
        };
        return b;
      }

      cAdd.onclick = async () => {
        if (!cCod.value.trim()) return recado('O cupom precisa de código.', 'ruim');
        if (cPub.checked && cParc.value && !confirm('Cupom de parceiro na lista: quem pegar daqui gera '
          + 'comissão pro parceiro mesmo sem ter vindo pelo link dele. Continuar?')) return;
        try {
          await api('/admin.php', { method: 'POST', body: JSON.stringify({
            acao: 'cupom_salvar', codigo: cCod.value, tipo: cTipo.value, valor: cVal.value,
            descricao: cDesc.value, parceiro_id: cParc.value ? parseInt(cParc.value, 10) : 0,
            usos_max: cUsos.value, vale_ate: cAte.value, ligado: cLig.checked,
            publico: cPub.checked }) });
          limpaForm();
          carrega();
        } catch (e) { recado(e.message, 'ruim'); }
      };

      corpo.appendChild(h('div', { cls: 'campo', style: 'gap:8px;flex-wrap:wrap;margin-top:10px' }, [
        cCod, cTipo, cVal, cParc, cUsos, cAte, cDesc,
        h('label', { style: 'display:flex;align-items:center;gap:5px;font-size:12px' },
          [cPub, document.createTextNode('aparece na lista')]),
        h('label', { style: 'display:flex;align-items:center;gap:5px;font-size:12px' },
          [cLig, document.createTextNode('ligado')]),
        cAdd, cCancela,
      ]));
      corpo.appendChild(h('p', { cls: 'd', style: 'margin-top:6px',
        txt: 'No percentual escreva o número (20 = 20%). No de valor, escreva em reais (5,00). '
           + 'Usos e data em branco: sem limite e sem vencimento. '
           + '"Aparece na lista" mostra o cupom pra quem está comprando; o de desconto maior, feito '
           + 'pra alguém específico, deixe escondido. Cupom de parceiro também pode ir pra lista, mas '
           + 'aí quem pegar de lá gera comissão pra ele.' }));

    }).catch((e) => {
      corpo.innerHTML = '';
      corpo.appendChild(h('p', { cls: 'd', txt: 'Não consegui ler: ' + e.message }));
    });
  };

  carrega();
  return cx;
}

function blocoEstilo() {
  const area = h('textarea', { style: 'width:100%;min-height:150px;font:12.5px/1.5 var(--mono);'
    + 'color:var(--tinta);background:var(--fundo);border:1px solid var(--linha);padding:10px;border-radius:4px' });
  const salvar = h('button', { cls: 'bt principal', type: 'button', txt: 'Salvar CSS' });
  const prever = h('button', { cls: 'bt mini', type: 'button', txt: 'Testar sem salvar' });
  const limpar = h('button', { cls: 'bt mini perigo', type: 'button', txt: 'Apagar tudo' });
  const diz = h('span', { cls: 'd', style: 'font-size:12px' });

  const aplica = (css) => {
    const antiga = el('cssExtra');
    if (antiga) antiga.remove();
    const tag = document.createElement('style');
    tag.id = 'cssExtra';
    tag.textContent = css;
    document.head.appendChild(tag);
  };

  prever.onclick = () => {
    aplica(area.value);
    diz.textContent = 'testando — recarregue a página pra desfazer';
    diz.style.color = 'var(--dourado)';
  };

  limpar.onclick = async () => {
    if (!confirm('Apagar todo o CSS extra?')) return;
    area.value = '';
    salvar.click();
  };

  api('/estilo.php').then((d) => { area.value = d.css || ''; }).catch(() => {});

  salvar.onclick = async () => {
    salvar.disabled = true;
    diz.textContent = 'salvando…';
    try {
      await api('/estilo.php', { method: 'POST', body: JSON.stringify({ css: area.value }) });
      aplica(area.value);
      diz.textContent = 'salvo e aplicado';
      diz.style.color = 'var(--verde-forte)';
    } catch (e) {
      diz.textContent = e.message;
      diz.style.color = 'var(--perigo)';
    }
    salvar.disabled = false;
  };

  return h('div', { cls: 'fatia' }, [
    h('h3', { txt: 'CSS extra' }),
    h('div', { cls: 'so-adm' }, [
      h('p', { style: 'margin:0 0 8px', txt: 'Entra depois da folha do site e vale pra todo mundo. '
        + 'Se quebrar o painel, abra ?semcss=1 no fim do endereço: o site ignora este CSS e você volta aqui pra apagar.' }),
      area,
      h('div', { style: 'margin-top:8px;display:flex;gap:8px;align-items:center;flex-wrap:wrap' },
        [salvar, prever, limpar, diz]),
    ]),
  ]);
}

/* ================== O QUE AS PESSOAS USAM ==================

   A PERGUNTA NÃO É "QUANTOS CLIQUES", É QUANTAS CONTAS.

   Um canal grande usando o TTS mil vezes por dia não diz mais que mil
   canais usando uma vez. O que decide o que construir e o que aposentar é
   quantas pessoas DIFERENTES encostam em cada coisa.

   E o fim da lista vale mais que o começo: recurso com zero é uma decisão
   esperando pra ser tomada. Por isso os zeros aparecem, em vez de sumirem
   por não terem linha no banco. */
/* ================== O QUE ESTÁ QUEBRADO ==================

   Sem esta tela, o primeiro a saber que algo quebrou é quem escreve
   reclamando — e aí já são vários, calados, achando que o site é ruim.

   Duas fontes que nunca se encontravam: os erros do servidor, que iam pro
   log inalcançável, e os erros da ponte, que ficavam guardados por conta
   e só o dono via. */
function blocoErros() {
  const cx = h('div', { cls: 'fatia' });
  const corpo = h('div');
  const rever = h('button', { cls: 'bt fraco', type: 'button', txt: 'Ver de novo' });

  const quando = (t) => {
    const d = new Date(String(t).replace(' ', 'T'));
    const min = Math.round((Date.now() - d) / 60000);
    if (min < 60) return 'há ' + Math.max(1, min) + ' min';
    if (min < 1440) return 'há ' + Math.round(min / 60) + 'h';
    return 'há ' + Math.round(min / 1440) + ' dias';
  };

  async function carrega() {
    rever.disabled = true;
    corpo.innerHTML = '';
    let d;
    try { d = await api('/admin.php?erros=1'); }
    catch (e) {
      corpo.appendChild(h('p', { cls: 'd', txt: 'Este servidor ainda não guarda erros. Suba a pasta api e rode o SQL 070.' }));
      rever.disabled = false;
      return;
    }

    const pontes = d.pontes || [];
    const servidor = d.servidor || [];

    if (!pontes.length && !servidor.length) {
      corpo.appendChild(h('p', { cls: 'd', style: 'color:var(--verde-forte)',
        txt: 'Nenhum erro nos últimos dias.' }));
      rever.disabled = false;
      return;
    }

    /* A PONTE PRIMEIRO, E DE PROPÓSITO: erro de ponte é gente que está
       tentando usar agora e não consegue. Erro de servidor pode ser uma
       rotina de madrugada que ninguém viu. */
    if (pontes.length) {
      corpo.appendChild(h('h4', { cls: 'sub-secao', txt: 'Na fonte do OBS de quem usa' }));
      pontes.forEach((p) => {
        corpo.appendChild(h('div', { cls: 'er-um' }, [
          h('span', { cls: 'er-quantos', txt: String(p.quantas) }),
          h('div', { style: 'flex:1' }, [
            h('b', { txt: p.mensagem }),
            h('small', { txt: p.contas.join(', ')
              + (p.quantas > p.contas.length ? ' e mais ' + (p.quantas - p.contas.length) : '')
              + (p.versoes.length ? '  ·  ponte ' + p.versoes.join(', ') : '') }),
          ]),
        ]));
      });
    }

    if (servidor.length) {
      corpo.appendChild(h('h4', { cls: 'sub-secao', txt: 'No servidor' }));
      servidor.forEach((e) => {
        corpo.appendChild(h('div', { cls: 'er-um' }, [
          h('span', { cls: 'er-quantos', txt: String(e.quantos) }),
          h('div', { style: 'flex:1' }, [
            h('b', { txt: e.mensagem }),
            h('small', { txt: e.tipo + '  ·  ' + e.onde + '  ·  ' + quando(e.ultimo) }),
          ]),
        ]));
      });
    }
    rever.disabled = false;
  }

  rever.onclick = carrega;
  cx.append(
    h('h3', { txt: 'O que está quebrado' }),
    h('p', { cls: 'd', txt: 'Erros agrupados: o mesmo defeito acontecendo mil vezes é uma linha, '
      + 'com o número de vezes ao lado. O da ponte é gente tentando usar agora e não conseguindo.' }),
    corpo,
    h('p', { style: 'margin:12px 0 0' }, [rever]),
  );
  carrega();
  return cx;
}

/* O AVISO DO MERCADO PAGO CHEGOU OU NÃO?

   Nasceu de um pagamento que funcionou e que o site não percebeu: quem
   comprou teve que clicar em "já paguei e não liberou" pra receber. O
   dinheiro entrou, o acesso não saiu, e não havia onde perguntar por quê —
   mesmo com a resposta guardada no banco desde sempre.

   Esta tela dá o VEREDITO, e não a tabela. Saber que existem zero avisos
   não ajuda ninguém; saber que zero avisos com zero recusas significa "o
   Mercado Pago não está mandando pra este endereço" é o que diz o que
   fazer em seguida. */
function blocoWebhooks() {
  const cx = h('div', { cls: 'fatia' });
  const corpo = h('div');
  const rever = h('button', { cls: 'bt fraco', type: 'button', txt: 'Ver de novo' });

  const quando = (t) => {
    const d = new Date(String(t).replace(' ', 'T'));
    const min = Math.round((Date.now() - d) / 60000);
    if (min < 60) return 'há ' + Math.max(1, min) + ' min';
    if (min < 1440) return 'há ' + Math.round(min / 60) + 'h';
    return 'há ' + Math.round(min / 1440) + ' dias';
  };

  function veredito(d) {
    const avisos = d.avisos || [];
    const falhos = avisos.filter((a) => a.erro && !/^tratado|^ignorado/.test(a.erro));

    if (!avisos.length && d.recusados > 0) {
      return ['ruim', 'O Mercado Pago está mandando, e o site está RECUSANDO.',
        d.recusados + ' aviso(s) barrados na conferência da assinatura, o último '
        + quando(d.ultima_recusa) + '. Nada foi processado. Quase sempre é a '
        + 'Assinatura secreta do webhook diferente da que está no config, ou a '
        + 'notificação de assinatura vindo sem assinatura nenhuma.'];
    }
    if (!avisos.length) {
      return ['ruim', 'Nenhum aviso chegou.',
        'O Mercado Pago não está notificando este endereço. Confira em Suas '
        + 'integrações > sua aplicação > Webhooks se a URL está salva e se os '
        + 'eventos Pagamentos e Planos e Assinaturas estão marcados.'];
    }
    if (falhos.length) {
      return ['ruim', falhos.length + ' aviso(s) chegaram e falharam processando.',
        'O texto do erro em cada linha diz onde parou.'];
    }
    return ['bom', 'Os avisos estão chegando e sendo processados.',
      avisos.length + ' nos últimos 30 dias.'];
  }

  async function carrega() {
    rever.disabled = true;
    corpo.innerHTML = '';

    let d;
    try { d = await api('/admin.php?webhooks=1'); }
    catch (e) {
      corpo.appendChild(h('p', { cls: 'd', txt: 'Este servidor ainda não tem esta tela. Suba a pasta api.' }));
      rever.disabled = false;
      return;
    }

    const [cor, titulo, detalhe] = veredito(d);
    corpo.appendChild(h('p', { style: 'margin:0 0 4px;font-weight:600;color:var('
      + (cor === 'bom' ? '--verde-forte' : '--perigo') + ')', txt: titulo }));
    corpo.appendChild(h('p', { cls: 'd', style: 'margin:0 0 12px', txt: detalhe }));

    (d.avisos || []).forEach((a) => {
      /* 'tratado' e 'ignorado' não são falha: são o webhook dizendo que
         olhou e não havia o que fazer. Pintar de vermelho faria parecer
         que o site está quebrado quando está funcionando. */
      const ok = !a.erro || /^tratado|^ignorado/.test(a.erro);
      corpo.appendChild(h('div', { cls: 'er-um' }, [
        h('span', { cls: 'er-quantos', style: ok ? '' : 'color:var(--perigo)', txt: ok ? '✓' : '✗' }),
        h('div', { style: 'flex:1' }, [
          h('b', { txt: a.tipo }),
          h('small', { txt: quando(a.recebido) + (a.erro ? '  ·  ' + a.erro : '  ·  processado') }),
        ]),
      ]));
    });

    /* A REDE DE SEGURANÇA, COM O COMANDO PRONTO.

       Dita mesmo quando os avisos estão chegando: o dia em que pararem é
       tarde demais pra descobrir que ela nunca foi pendurada.

       O comando vem inteiro do servidor, com o segredo já dentro. Pedir pra
       inventar um segredo e repetir em dois lugares era transferir pra
       pessoa um trabalho que o computador faz melhor — e um erro de cópia
       só apareceria semanas depois, quando a rede não pegasse ninguém. */
    if (d.rede_comando) {
      corpo.appendChild(h('h4', { cls: 'sub-secao', txt: 'A rede embaixo dos avisos' }));
      corpo.appendChild(h('p', { cls: 'd', style: 'margin:0 0 6px',
        txt: 'Ponha esta linha no cron da hospedagem, de 10 em 10 minutos. Com ela, '
          + 'quem pagou recebe sozinho mesmo quando o aviso se perde.' }));

      const linha = h('pre', { cls: 'diag', style: 'margin:0;white-space:pre-wrap;overflow-wrap:anywhere',
        txt: d.rede_comando });
      const copiar = h('button', { cls: 'bt fraco', type: 'button', txt: 'Copiar o comando' });
      copiar.onclick = async () => {
        try {
          await navigator.clipboard.writeText(d.rede_comando);
          copiar.textContent = 'copiado';
          setTimeout(() => { copiar.textContent = 'Copiar o comando'; }, 2000);
        } catch (e) {
          /* Sem permissão de área de transferência o texto continua na tela
             pra selecionar — o botão é atalho, e não o único caminho. */
          copiar.textContent = 'selecione e copie acima';
        }
      };
      corpo.appendChild(linha);
      corpo.appendChild(h('p', { style: 'margin:6px 0 0' }, [copiar]));
    } else {
      corpo.appendChild(h('p', { cls: 'd', style: 'margin:12px 0 0;color:var(--perigo)',
        txt: 'Rede de segurança DESLIGADA e não consegui ligar: falta a tabela '
          + 'ajustes (SQL 031). Sem ela, quem pagar e não receber o aviso depende '
          + 'de achar o botão "já paguei e não liberou".' }));
    }

    const ass = d.assinaturas || [];
    if (ass.length) {
      corpo.appendChild(h('h4', { cls: 'sub-secao', txt: 'As cobranças abertas' }));
      ass.forEach((a) => {
        /* Linha ainda 'pendente' com assinatura criada é o sintoma exato de
           aviso perdido: o Mercado Pago autorizou e o site não soube. */
        const preso = a.status === 'pendente';
        corpo.appendChild(h('div', { cls: 'er-um' }, [
          h('span', { cls: 'er-quantos', style: preso ? 'color:var(--perigo)' : '',
            txt: preso ? '!' : '✓' }),
          h('div', { style: 'flex:1' }, [
            h('b', { txt: a.login + '  ·  ' + a.status + (a.renova ? '  ·  renova' : '') }),
            h('small', { txt: (a.assinatura ? 'assinatura' : 'avulso')
              + (a.vale_ate ? '  ·  vale até ' + String(a.vale_ate).slice(0, 10) : '')
              + (a.dias_raid ? '  ·  ' + a.dias_raid + ' dias de raid' : '')
              + '  ·  ' + quando(a.quando) }),
          ]),
        ]));
      });
    }

    rever.disabled = false;
  }

  rever.onclick = carrega;
  cx.append(
    h('h3', { txt: 'Os avisos do Mercado Pago' }),
    h('p', { cls: 'd', txt: 'Quando alguém paga, o Mercado Pago avisa aqui e o acesso é liberado '
      + 'sozinho. Se quem pagou precisou clicar em "já paguei e não liberou", o aviso se perdeu — '
      + 'e é aqui que dá pra ver onde.' }),
    corpo,
    h('p', { style: 'margin:12px 0 0' }, [rever]),
  );
  carrega();
  return cx;
}

function blocoUso() {
  const cx = h('div', { cls: 'fatia' });
  const lista = h('div');
  const resumo = h('p', { cls: 'd' });

  const periodo = h('select', { style: 'width:140px' });
  [['7', 'últimos 7 dias'], ['30', 'últimos 30 dias'], ['90', 'últimos 90 dias']]
    .forEach(([v, r]) => periodo.appendChild(h('option', { value: v, txt: r })));
  periodo.value = '30';

  async function carrega() {
    lista.innerHTML = '';
    let d;
    try { d = await api('/admin.php?uso=1&dias=' + periodo.value); }
    catch (e) {
      resumo.textContent = 'Este servidor ainda não mede uso. Suba a pasta api e rode o SQL 069.';
      return;
    }

    const recursos = Object.values(d.recursos || {});
    const maior = Math.max(1, ...recursos.map((r) => r.contas));

    recursos.forEach((r) => {
      lista.appendChild(h('div', { cls: 'st-linha' + (r.contas ? '' : ' st-zero') }, [
        h('span', { cls: 'st-nome', txt: r.nome }),
        h('div', { cls: 'st-barra' }, [
          h('i', { style: 'width:' + Math.round((r.contas / maior) * 100) + '%' }),
        ]),
        h('span', { cls: 'st-num', txt: String(r.contas) }),
      ]));
    });

    const v = d.voltaram || {};
    /* Quantos voltaram diz se o produto gruda. Um pico de gente nova com
       pouca volta quer dizer que alguém divulgou, e não que ficou melhor. */
    const taxa = v.passada ? Math.round((v.voltaram / v.passada) * 100) : 0;
    resumo.textContent = (v.semana || 0) + ' contas ativas nesta semana · '
      + (v.voltaram || 0) + ' voltaram da semana passada'
      + (v.passada ? ' (' + taxa + '%)' : '');

    const semUso = recursos.filter((r) => !r.contas).map((r) => r.nome);
    if (semUso.length) {
      lista.appendChild(h('p', { cls: 'd', style: 'margin-top:14px',
        txt: 'Ninguém usou no período: ' + semUso.join(', ') + '.' }));
    }
  }

  periodo.onchange = carrega;
  cx.append(
    h('h3', { txt: 'O que as pessoas usam' }),
    h('p', { cls: 'd', txt: 'Quantas contas diferentes encostaram em cada recurso. '
      + 'Não conta cliques: um canal usando mil vezes conta como um.' }),
    h('div', { cls: 'campo' }, [h('span', { cls: 'rotulo', txt: 'Período' }), periodo]),
    resumo,
    lista,
  );
  carrega();
  return cx;
}

function telaAdmin() {
  const tela = el('tela');
  tela.innerHTML = '';
  tela.appendChild(h('h1', { txt: 'Administração' }));
  tela.appendChild(h('p', { cls: 'sub', txt: 'Quem entrou e o que cada um pode. Campo vazio usa o valor do plano.' }));

  tela.appendChild(blocoErros());
  tela.appendChild(blocoWebhooks());
  tela.appendChild(blocoUso());
  tela.appendChild(blocoVitrine());
  tela.appendChild(blocoParceiros());
  tela.appendChild(blocoSuporte());
  tela.appendChild(blocoAvisos());
  tela.appendChild(blocoSelos());
  tela.appendChild(blocoConquistasAdmin());
  tela.appendChild(blocoArtistas());
  tela.appendChild(blocoEstilo());

  const lista = h('div');
  tela.appendChild(h('h3', { style: 'margin-top:26px', txt: 'Contas' }));
  tela.appendChild(lista);

  api('/admin.php').then((d) => {
    lista.innerHTML = '';
    const us = d.usuarios || [];
    if (!us.length) return lista.appendChild(h('p', { cls: 'd', txt: 'Ninguém ainda.' }));

    tela.insertBefore(h('p', { cls: 'd', txt: us.length + (us.length === 1 ? ' conta' : ' contas') }), lista);

    us.forEach((u) => {
      const quando = (s) => s ? new Date(s.replace(' ', 'T')).toLocaleString('pt-BR') : 'nunca';

      const maxi = h('input', { type: 'number', min: '0', style: 'flex:0 0 90px',
                                placeholder: String(u.vale.perfis_max) });
      if (u.perfis_max !== null) maxi.value = u.perfis_max;

      const marcas = {};
      const linhaMarcas = h('div', { cls: 'campo', style: 'gap:14px;flex-wrap:wrap' });
      [['multiplataforma', 'Multiplataforma'], ['pokebot', 'Pokébot']]
        .forEach(([k, rot]) => {
          const cx = h('input', { type: 'checkbox' });
          cx.checked = !!u.vale[k];
          marcas[k] = cx;
          linhaMarcas.appendChild(h('label', { style: 'display:flex;align-items:center;gap:6px;font-size:12.5px' },
            [cx, document.createTextNode(rot)]));
        });

      /* PRO DADO À MÃO, COM PRAZO.

         Data e não interruptor: cortesia sem prazo é cortesia esquecida, e
         seis meses depois ninguém lembra por que aquela conta tem Pro.
         Campo vazio tira. */
      const cortesia = h('input', { type: 'date', style: 'flex:0 0 150px' });
      if (u.cortesia_ate) cortesia.value = String(u.cortesia_ate).slice(0, 10);

      const betaCx = h('input', { type: 'checkbox' });
      betaCx.checked = !!u.beta;

      /* Um por selo que existir, e quantos quiser na mesma conta. Salva no
         clique, sem esperar o botão: são independentes do resto da ficha. */
      const linhaSelos = h('div', { cls: 'selo-caixas', style: 'margin-bottom:9px' });
      (d.selos_todos || []).forEach((s) => {
        const cxs = h('input', { type: 'checkbox' });
        cxs.checked = (u.selos || []).indexOf(s.slug) >= 0;
        cxs.onchange = async () => {
          try {
            await api('/selos.php', { method: 'POST', body: JSON.stringify({
              acao: 'marcar', usuario_id: u.id, selo_id: s.id, dar: cxs.checked }) });
          } catch (e) { cxs.checked = !cxs.checked; recado(e.message, 'ruim'); }
        };
        const rot = h('label');
        rot.appendChild(cxs);
        rot.appendChild(chipSelo(s));
        linhaSelos.appendChild(rot);
      });

      const salvar = h('button', { cls: 'bt mini', type: 'button', txt: 'Salvar' });
      const recado2 = h('span', { cls: 'd', style: 'font-size:11.5px' });

      salvar.onclick = async () => {
        salvar.disabled = true;
        recado2.textContent = 'salvando…';
        try {
          const recursos = {};
          Object.keys(marcas).forEach((k) => { recursos[k] = marcas[k].checked; });
          await api('/admin.php', { method: 'POST', body: JSON.stringify({
            id: u.id,
            perfis_max: maxi.value === '' ? null : parseInt(maxi.value, 10),
            cortesia_ate: cortesia.value,
            beta: betaCx.checked,
            recursos,
          }) });
          recado2.textContent = 'salvo';
          recado2.style.color = 'var(--verde-forte)';
        } catch (e) {
          recado2.textContent = e.message;
          recado2.style.color = 'var(--perigo)';
        }
        salvar.disabled = false;
      };

      const cabeca = h('h3', { txt: u.login + (u.admin ? '  ·  admin' : '') });

      lista.appendChild(h('div', { cls: 'fatia' }, [
        cabeca,
        h('p', { cls: 'd', txt: 'entrou em ' + quando(u.criado_em)
               + '  ·  última vez ' + quando(u.visto_em)
               + '  ·  plano ' + u.plano
               + '  ·  ' + u.overlays + ' de ' + u.vale.perfis_max + ' overlays' }),
        /* Pro dado à mão. Data e não interruptor: cortesia sem prazo é
           cortesia esquecida — seis meses depois ninguém lembra por que
           aquela conta tem Pro. Campo vazio tira. */
        h('div', { cls: 'campo', style: 'gap:14px;flex-wrap:wrap' }, [
          h('label', { style: 'display:flex;align-items:center;gap:6px;font-size:12.5px' },
            [betaCx, document.createTextNode('Testador (Pro pra sempre)')]),
          h('span', { cls: 'd', style: 'font-size:12.5px', txt: 'Pro até' }),
          cortesia,
        ]),
        h('div', { cls: 'campo' }, [
          h('label', { txt: 'Máximo de overlays' }), maxi,
          h('span', { cls: 'd', style: 'font-size:11.5px',
                      txt: 'vazio = o do plano (' + (d.padrao[u.plano === 'gratis' ? 'gratis' : 'pro'] || {}).perfis_max + ')' }),
        ]),
        linhaSelos,
        linhaMarcas,
        h('div', { cls: 'campo', style: 'gap:8px' }, [salvar, recado2]),
      ]));
    });
  }).catch((e) => {
    lista.appendChild(h('p', { cls: 'd', txt: 'Não deu: ' + e.message }));
  });
}

/* ---------------- a caixa de entrada do suporte ---------------- */

function blocoSuporte() {
  const cx = h('div', { cls: 'fatia' }, [
    h('h3', { txt: 'Suporte' }),
    h('p', { cls: 'd', txt: 'Quem é Pro vem na frente: suporte pessoal é o que o plano promete.' }),
  ]);
  const corpo = h('div');
  cx.appendChild(corpo);

  const carrega = () => {
    corpo.innerHTML = '<p class="d">vendo…</p>';
    api('/suporte.php?a=caixa').then((d) => {
      corpo.innerHTML = '';
      const cs = d.chamados || [];
      if (!cs.length) return corpo.appendChild(h('p', { cls: 'd', txt: 'Nenhum chamado ainda.' }));

      cs.forEach((s) => {
        const resp = h('textarea', { maxlength: '4000', placeholder: 'sua resposta',
          style: 'width:100%;min-height:70px;font:13px inherit;color:var(--tinta);background:var(--fundo);'
               + 'border:1px solid var(--linha);border-radius:4px;padding:8px 9px' });
        resp.value = s.resposta || '';

        const manda = async (acao, extra) => {
          try {
            await api('/suporte.php', { method: 'POST',
              body: JSON.stringify(Object.assign({ acao, id: s.id }, extra || {})) });
            carrega();
          } catch (e) { recado(e.message, 'ruim'); }
        };

        const bResp = h('button', { cls: 'bt mini', type: 'button', txt: 'Responder' });
        bResp.onclick = () => manda('responder', { resposta: resp.value });
        const bFecha = h('button', { cls: 'bt mini fraco', type: 'button', txt: 'Fechar' });
        bFecha.onclick = () => manda('fechar');

        const dentro = [
          h('div', { cls: 'campo', style: 'gap:10px' }, [
            h('b', { style: 'flex:1', txt: s.assunto }),
            s.pro ? h('span', { cls: 'selo', txt: 'Pro' }) : h('span'),
            h('span', { cls: 'd', style: 'font-size:12px', txt: '@' + s.login + '  ·  ' + faz(s.ha) + '  ·  ' + s.estado }),
          ]),
          h('p', { cls: 'd', style: 'white-space:pre-wrap', txt: s.mensagem }),
        ];
        if (s.contato) dentro.push(h('p', { cls: 'd', style: 'font-size:12px', txt: 'contato: ' + s.contato }));
        if (s.print) {
          const b = h('button', { cls: 'bt mini', type: 'button', txt: 'Ver o print' });
          b.onclick = () => window.open(s.print, '_blank', 'noopener');
          dentro.push(h('p', { style: 'margin:6px 0' }, [b]));
        }
        dentro.push(resp, h('div', { cls: 'campo', style: 'gap:6px;margin-top:6px' }, [bResp, bFecha]));

        corpo.appendChild(h('div', { style: 'border-top:1px solid var(--linha);padding-top:12px;margin-top:12px' }, dentro));
      });
    }).catch((e) => { corpo.innerHTML = ''; corpo.appendChild(h('p', { cls: 'd', txt: 'Não deu: ' + e.message })); });
  };

  carrega();
  return cx;
}

/* ---------------- recado pra quem usa o site ---------------- */

function blocoAvisos() {
  const grupo = h('select', { style: 'flex:0 0 210px' });
  [['todos', 'Todo mundo'], ['pro', 'Só quem é Pro'], ['nao_pro', 'Só quem não é Pro'],
   ['testadores', 'Testadores'], ['sem_overlay', 'Quem nunca criou overlay'],
   ['inativos:30', 'Quem sumiu há 30 dias'], ['login', 'Uma pessoa só']]
    .forEach(([v, r]) => grupo.appendChild(h('option', { value: v, txt: r })));

  const login = h('input', { type: 'text', placeholder: '@ da pessoa', style: 'flex:0 0 160px;display:none' });
  grupo.onchange = () => { login.style.display = grupo.value === 'login' ? '' : 'none'; };

  const texto = h('input', { type: 'text', maxlength: '300', placeholder: 'o recado, curto',
    style: 'flex:1 1 260px' });
  const rota = h('input', { type: 'text', maxlength: '80', placeholder: 'abrir em (ex.: #/artistas)',
    style: 'flex:0 0 200px' });
  const diz = h('span', { cls: 'd', style: 'font-size:12px' });
  const bt = h('button', { cls: 'bt mini', type: 'button', txt: 'Enviar' });

  bt.onclick = async () => {
    if (!texto.value.trim()) return recado('Escreva o recado.', 'ruim');
    if (!confirm('Mandar este aviso? Ele aparece no sininho de quem receber.')) return;
    bt.disabled = true;
    diz.textContent = 'enviando…';
    try {
      const r = await api('/notificacoes.php', { method: 'POST', body: JSON.stringify({
        acao: 'enviar', grupo: grupo.value, login: login.value, texto: texto.value, rota: rota.value }) });
      diz.textContent = r.enviados + (r.enviados === 1 ? ' pessoa avisada' : ' pessoas avisadas');
      texto.value = '';
    } catch (e) { diz.textContent = e.message; }
    bt.disabled = false;
  };

  return h('div', { cls: 'fatia' }, [
    h('h3', { txt: 'Mandar aviso' }),
    h('p', { cls: 'd', txt: 'Aparece no sininho de quem receber. O endereço é opcional e só vale pra tela do próprio site.' }),
    h('div', { cls: 'campo', style: 'gap:8px;flex-wrap:wrap' }, [grupo, login, texto, rota, bt]),
    diz,
  ]);
}

/* ---------------- selos ----------------

   O desenho é do Enzo. Aqui só existe o lugar de subir, dar e tirar: selo
   dado por regra automática não vale nada, porque a regra é o que a pessoa
   contorna. */

/* O AJUSTE AUTOMÁTICO DO DESENHO.

   O selo é desenhado numa caixa pequena com "caber inteiro", então quem
   manda no tamanho aparente é a margem transparente: desenho com muita
   sobra fica miúdo, desenho que enche o quadro fica grande. Aqui a sobra é
   cortada e o desenho é centralizado num quadrado.

   PIXEL ART NÃO PODE SER SUAVIZADA. Desenho pequeno (até 64 px de
   conteúdo) cresce em múltiplo inteiro, pixel por pixel — um aumento
   quebrado deixa cada pixel de uma largura, e suavizar deixa tudo borrado.
   Desenho grande é reduzido normalmente, com suavização.

   GIF e SVG ficam como estão: o canvas mataria a animação de um e a nitidez
   do outro. Pra esses existe o acerto fino. */
const SELO_LADO = 128;

async function aparaSelo(blob) {
  const url = URL.createObjectURL(blob);
  try {
    const img = await new Promise((ok, falha) => {
      const i = new Image();
      i.onload = () => ok(i);
      i.onerror = () => falha(new Error('Não consegui abrir esse desenho.'));
      i.src = url;
    });
    const W = img.naturalWidth, H = img.naturalHeight;
    if (!W || !H) throw new Error('O desenho está vazio.');
    if (W > 2048 || H > 2048) throw new Error('Desenho grande demais: até 2048 px de lado.');

    const c = document.createElement('canvas');
    c.width = W; c.height = H;
    const g = c.getContext('2d', { willReadFrequently: true });
    g.drawImage(img, 0, 0);
    const px = g.getImageData(0, 0, W, H).data;

    let x0 = W, y0 = H, x1 = -1, y1 = -1;
    for (let y = 0; y < H; y++) {
      for (let x = 0; x < W; x++) {
        if (px[(y * W + x) * 4 + 3] > 8) {
          if (x < x0) x0 = x;
          if (x > x1) x1 = x;
          if (y < y0) y0 = y;
          if (y > y1) y1 = y;
        }
      }
    }
    if (x1 < 0) throw new Error('O desenho é todo transparente.');

    const w = x1 - x0 + 1, hh = y1 - y0 + 1;
    const L = Math.max(w, hh);
    const pixel = L <= 64;
    const k = pixel ? Math.max(1, Math.round(SELO_LADO / L)) : SELO_LADO / L;
    const lado = pixel ? L * k : SELO_LADO;

    const out = document.createElement('canvas');
    out.width = lado; out.height = lado;
    const o = out.getContext('2d');
    o.imageSmoothingEnabled = !pixel;
    if (!pixel) o.imageSmoothingQuality = 'high';

    /* No pixel art a sobra do centro também é múltiplo de k: assim a grade
       de pixels não sai meio pixel pro lado. */
    const dx = pixel ? Math.floor((L - w) / 2) * k : Math.round((lado - w * k) / 2);
    const dy = pixel ? Math.floor((L - hh) / 2) * k : Math.round((lado - hh * k) / 2);
    o.drawImage(c, x0, y0, w, hh, dx, dy, w * k, hh * k);

    const png = await new Promise((ok) => out.toBlob(ok, 'image/png'));
    if (!png) throw new Error('Não consegui gerar o desenho ajustado.');
    return {
      arquivo: new File([png], 'selo.png', { type: 'image/png' }),
      tirou: [W - w, H - hh],
      pixel,
      lado,
    };
  } finally {
    URL.revokeObjectURL(url);
  }
}

const seloAparavel = (tipo) => tipo === 'image/png' || tipo === 'image/webp';

function blocoSelos() {
  const cx = h('div', { cls: 'fatia' }, [
    h('h3', { txt: 'Selos' }),
    h('p', { cls: 'd', txt: 'Cada selo pode ter um desenho seu. Sem desenho, ele aparece como etiqueta escrita na cor escolhida. Quem tem qual você marca na ficha de cada conta, ali embaixo.' }),
  ]);

  /* A régua fica em cima: é nela que se compara um selo com o outro. */
  const clara = h('div', { cls: 'selo-regua__faixa clara' });
  const escura = h('div', { cls: 'selo-regua__faixa escura' });
  const todos = h('button', { cls: 'bt mini', type: 'button', txt: 'Ajustar todos automaticamente' });
  cx.appendChild(h('div', { cls: 'selo-regua' }, [
    h('p', { cls: 'd', style: 'margin:0', txt: 'Como os selos aparecem no feed. Ao subir um PNG ou WEBP, a margem '
      + 'transparente é cortada sozinha; o tamanho e a altura de cada um você acerta embaixo, olhando aqui.' }),
    clara, escura,
    h('div', { cls: 'campo', style: 'margin:0' }, [todos]),
  ]));

  const lista = h('div', { cls: 'selo-lista' });
  cx.appendChild(lista);

  const manda = (corpo) => api('/selos.php', { method: 'POST', body: JSON.stringify(corpo) });

  /* O desenho que está no ar, cortado e subido de novo no lugar. */
  const ajustaGuardado = async (s, nome, cor, dica) => {
    if (!s.img) return null;
    const r = await fetch(daApi(s.img), { cache: 'no-store' });
    if (!r.ok) throw new Error('Não consegui baixar o desenho de "' + s.nome + '".');
    const b = await r.blob();
    if (!seloAparavel(b.type)) return null;
    const a = await aparaSelo(b);
    return subir(s.slug, nome, cor, a.arquivo, dica);
  };

  const subir = (slug, nome, cor, arquivo, dica) => {
    const fd = new FormData();
    fd.append('slug', slug);
    fd.append('nome', nome);
    fd.append('cor', cor);
    fd.append('descricao', dica || '');
    if (arquivo) fd.append('img', arquivo);
    return fetch(API + '/selos.php', { method: 'POST', headers: { 'X-Chave': chave }, body: fd })
      .then(async (r) => {
        const d = await r.json().catch(() => ({}));
        if (!r.ok) throw new Error(d.erro || 'não deu');
        return d;
      });
  };

  function pinta(selos) {
    lista.innerHTML = '';
    clara.innerHTML = '';
    escura.innerHTML = '';

    /* Cada selo aparece nas duas faixas; o acerto mexe nos dois ao vivo. */
    const naRegua = {};
    selos.forEach((s) => {
      naRegua[s.id] = [clara, escura].map((faixa) => {
        const chip = chipSelo(s);
        faixa.appendChild(h('span', { cls: 'selo-regua__um' }, [document.createTextNode('Enzoca'), chip]));
        return chip.querySelector('img');
      }).filter(Boolean);
    });
    if (!selos.length) {
      clara.textContent = 'Nenhum selo ainda.';
      escura.textContent = 'Nenhum selo ainda.';
    }

    todos.onclick = async () => {
      const com = selos.filter((s) => s.img);
      if (!com.length) return recado('Nenhum selo tem desenho ainda.', 'ruim');
      if (!confirm('Cortar a margem transparente de ' + com.length + ' selos e subir de novo?\n\n'
        + 'GIF e SVG ficam como estão. Os desenhos originais são trocados pelos ajustados.')) return;
      todos.disabled = true;
      let feitos = 0, pulados = 0, ultimo = null;
      for (const s of com) {
        try {
          const r = await ajustaGuardado(s, s.nome, s.cor, s.dica);
          if (r) { feitos++; ultimo = r; } else pulados++;
        } catch (e) { pulados++; }
      }
      todos.disabled = false;
      if (ultimo) pinta(ultimo.selos || []);
      recado(feitos + ' selos ajustados' + (pulados ? ', ' + pulados + ' ficaram como estavam (GIF, SVG ou erro)' : '') + '.', 'bom');
    };

    selos.forEach((s) => {
      const previa = h('div', { cls: 'previa' });
      if (s.img) previa.appendChild(h('img', { src: daApi(s.img), alt: '' }));
      else { previa.textContent = '—'; previa.style.color = s.cor; }

      const nome = h('input', { type: 'text', maxlength: '32', style: 'flex:0 0 140px' });
      nome.value = s.nome;
      const cor = h('input', { type: 'color', style: 'flex:0 0 46px;padding:2px' });
      cor.value = s.cor;
      const dica = h('input', { type: 'text', maxlength: '120', style: 'flex:1 1 220px',
        placeholder: 'o que aparece ao passar o mouse' });
      dica.value = s.dica || '';

      const arq = h('input', { type: 'file', accept: 'image/png,image/webp,image/gif,image/svg+xml',
        style: 'display:none' });
      const desenho = h('button', { cls: 'bt mini', type: 'button', txt: s.img ? 'Trocar desenho' : 'Subir desenho' });
      desenho.onclick = () => arq.click();
      arq.onchange = async () => {
        const f = arq.files && arq.files[0];
        arq.value = '';
        if (!f) return;
        try {
          /* PNG e WEBP passam pelo corte antes de subir. O limite de 256 KB
             vale pro que sobe, e o ajustado é bem menor que o original. */
          let enviar = f;
          let aviso = '';
          if (seloAparavel(f.type)) {
            const a = await aparaSelo(f);
            enviar = a.arquivo;
            aviso = ' Margem cortada' + (a.pixel ? ', pixels preservados' : '') + '.';
          }
          if (enviar.size > 262144) return recado('O desenho pode ter no máximo 256 KB.', 'ruim');
          const r = await subir(s.slug, nome.value, cor.value, enviar, dica.value);
          pinta(r.selos || []);
          recado('Desenho de "' + s.nome + '" trocado.' + aviso, 'bom');
        } catch (e) { recado(e.message, 'ruim'); }
      };

      /* ACERTO FINO: tamanho e altura, a olho, na régua lá em cima. */
      const escala = h('input', { type: 'range', min: '0.5', max: '2', step: '0.05',
        id: 'selo-escala-' + s.id, 'aria-label': 'Tamanho de ' + s.nome });
      escala.value = String(s.escala || 1);
      const altura = h('input', { type: 'range', min: '-8', max: '8', step: '1',
        id: 'selo-altura-' + s.id, 'aria-label': 'Altura de ' + s.nome });
      altura.value = String(s.ajuste || 0);
      const vEscala = h('output', { txt: Math.round((s.escala || 1) * 100) + '%' });
      const vAltura = h('output', { txt: (s.ajuste > 0 ? '+' : '') + (s.ajuste || 0) + ' px' });
      const guardar = h('button', { cls: 'bt mini', type: 'button', txt: 'Guardar tamanho' });
      guardar.disabled = true;

      const aoMexer = () => {
        vEscala.textContent = Math.round(+escala.value * 100) + '%';
        vAltura.textContent = (+altura.value > 0 ? '+' : '') + altura.value + ' px';
        (naRegua[s.id] || []).forEach((img) => acertaSelo(img, +escala.value, +altura.value));
        guardar.disabled = false;
      };
      escala.oninput = aoMexer;
      altura.oninput = aoMexer;
      guardar.onclick = async () => {
        guardar.disabled = true;
        try {
          const r = await manda({ acao: 'ajuste', id: s.id, escala: +escala.value, ajuste: +altura.value });
          pinta(r.selos || []);
          recado('Tamanho de "' + s.nome + '" guardado.', 'bom');
        } catch (e) { recado(e.message, 'ruim'); guardar.disabled = false; }
      };

      const auto = h('button', { cls: 'bt mini', type: 'button', txt: 'Ajustar automático' });
      auto.style.display = s.img ? '' : 'none';
      auto.onclick = async () => {
        auto.disabled = true;
        try {
          const r = await ajustaGuardado(s, nome.value, cor.value, dica.value);
          if (!r) {
            recado('"' + s.nome + '" é GIF ou SVG: esse fica como está. Use o tamanho e a altura.', 'ruim');
          } else {
            pinta(r.selos || []);
            recado('Margem de "' + s.nome + '" cortada.', 'bom');
          }
        } catch (e) { recado(e.message, 'ruim'); }
        auto.disabled = false;
      };

      const acerto = h('div', { cls: 'selo-acerto', style: 'flex:1 1 100%' }, [
        document.createTextNode('Tamanho'), escala, vEscala,
        document.createTextNode('Altura'), altura, vAltura,
        guardar, auto,
      ]);

      const salvar = h('button', { cls: 'bt mini', type: 'button', txt: 'Salvar' });
      salvar.onclick = async () => {
        try { const r = await subir(s.slug, nome.value, cor.value, null, dica.value); pinta(r.selos || []); }
        catch (e) { recado(e.message, 'ruim'); }
      };

      const semDesenho = h('button', { cls: 'bt mini', type: 'button', txt: 'Tirar desenho' });
      semDesenho.style.display = s.img ? '' : 'none';
      semDesenho.onclick = async () => {
        try { const r = await manda({ acao: 'tirar_desenho', id: s.id }); pinta(r.selos || []); }
        catch (e) { recado(e.message, 'ruim'); }
      };

      /* LIGAR E DESLIGAR, QUE NÃO É O MESMO QUE APAGAR.

         Desligado, o selo some do feed mas continua na ficha de quem o
         tem — religar traz todo mundo de volta. Apagar leva junto quem
         ganhou, e isso não se desfaz. Ter os dois é o que deixa tentar
         "menos selos" sem medo de perder o histórico. */
      const liga = h('input', { type: 'checkbox', title: 'aparece no feed' });
      liga.checked = s.ligado !== 0;
      liga.onchange = async () => {
        try {
          const r = await manda({ acao: 'ligado', id: s.id, ligado: liga.checked ? 1 : 0 });
          pinta(r.selos || []);
        } catch (e) { liga.checked = !liga.checked; recado(e.message, 'ruim'); }
      };

      const apagar = h('button', { cls: 'bt mini perigo', type: 'button', txt: '×' });
      apagar.onclick = async () => {
        if (!confirm('Apagar o selo "' + s.nome + '"? Ele some de quem já tem.')) return;
        try { const r = await manda({ acao: 'apagar', id: s.id }); pinta(r.selos || []); telaAdmin(); }
        catch (e) { recado(e.message, 'ruim'); }
      };

      lista.appendChild(h('div', { cls: 'selo-linha',
        style: 'padding:10px 0;border-top:1px solid var(--linha)'
             + (s.ligado === 0 ? ';opacity:.45' : '') }, [
        liga,
        previa,
        h('code', { style: 'flex:0 0 90px;font-size:12px', txt: s.slug }),
        nome, cor, dica, desenho, arq, semDesenho, salvar, apagar,
        s.img ? acerto : document.createTextNode(''),
      ]));
    });

    if (!selos.length) lista.appendChild(h('p', { cls: 'd', txt: 'Nenhum selo. Rode o SQL 036.' }));

    const nSlug = h('input', { type: 'text', maxlength: '24', placeholder: 'apelido (sem espaço)', style: 'flex:0 0 160px' });
    const nNome = h('input', { type: 'text', maxlength: '32', placeholder: 'Nome na tela', style: 'flex:0 0 150px' });
    const nCor = h('input', { type: 'color', value: '#12A150', style: 'flex:0 0 46px;padding:2px' });
    const nAdd = h('button', { cls: 'bt mini', type: 'button', txt: '+ Selo', id: 'selo-novo' });
    nAdd.onclick = async () => {
      if (!nSlug.value.trim()) return recado('O selo precisa de um apelido.', 'ruim');
      try {
        const r = await subir(nSlug.value, nNome.value || nSlug.value, nCor.value, null);
        nSlug.value = ''; nNome.value = '';
        pinta(r.selos || []);
        telaAdmin();
      } catch (e) { recado(e.message, 'ruim'); }
    };
    lista.appendChild(h('div', { cls: 'campo', style: 'gap:8px;flex-wrap:wrap;margin-top:6px' },
      [nSlug, nNome, nCor, nAdd]));
  }

  lista.appendChild(h('p', { cls: 'd', txt: 'vendo…' }));
  fetch(API + '/selos.php').then((r) => r.json()).then((d) => pinta(d.selos || []))
    .catch((e) => { lista.innerHTML = ''; lista.appendChild(h('p', { cls: 'd', txt: 'Não deu: ' + e.message })); });

  return cx;
}

/* ---------------- editor de conquistas ----------------

   A conquista é editada aqui; o que ela mede é escolhido de uma lista que
   vem do código. Mudar o que se mede depois que alguém ganhou é recusado
   pelo servidor, e o formulário já avisa antes. */
function blocoConquistasAdmin() {
  const cx = h('div', { cls: 'fatia' }, [
    h('h3', { txt: 'Conquistas' }),
    h('p', { cls: 'd', txt: 'Conquista só dá: desativar esconde de quem não tem, e quem tem continua com ela e com o prêmio. '
      + 'Selo novo chega na hora em quem já ganhou; dias de Pro valem só pra quem ganhar dali pra frente.' }),
  ]);

  const corpo = h('div', {}, [h('p', { cls: 'd', txt: 'vendo…' })]);
  const nova = h('button', { cls: 'bt mini', type: 'button', txt: '+ Nova conquista', id: 'cqa-nova' });
  cx.append(corpo, h('div', { cls: 'campo', style: 'margin-top:10px' }, [nova]));

  let dados = null;
  const manda = (c) => api('/conquistas.php', { method: 'POST', body: JSON.stringify(c) });
  const nomeGrupo = (g) => (CQ_GRUPOS.find((x) => x[0] === g) || [g, g])[1];
  const nomeMedidor = (m) => ((dados.medidores || []).find((x) => x.id === m) || { nome: m }).nome;

  const textoPremio = (c) => {
    const p = [];
    if (c.vagas) p.push(c.vagas + (c.vagas === 1 ? ' vaga' : ' vagas'));
    if (c.selo) p.push('selo ' + (((dados.selos || []).find((s) => s.slug === c.selo) || {}).nome || c.selo));
    if (c.pro) p.push(c.pro + (c.pro === 1 ? ' dia de Pro' : ' dias de Pro'));
    return p.join(' + ') || 'sem prêmio';
  };

  function pinta(d) {
    dados = d;
    corpo.innerHTML = '';
    const cs = d.conquistas || [];
    if (!cs.length) {
      corpo.appendChild(h('p', { cls: 'd', txt: 'Nenhuma conquista. Rode o SQL 051 — ele já traz as 14 primeiras.' }));
      return;
    }

    CQ_GRUPOS.forEach(([g, titulo]) => {
      const doGrupo = cs.filter((c) => c.grupo === g);
      if (!doGrupo.length) return;
      corpo.appendChild(h('b', { txt: titulo, style: 'display:block;margin:14px 0 2px;font:600 12px var(--mono);'
        + 'letter-spacing:.1em;text-transform:uppercase;color:var(--apagado)' }));

      doGrupo.forEach((c) => {
        const editar = h('button', { cls: 'bt mini', type: 'button', txt: 'Editar' });
        editar.onclick = () => abre(c);

        const liga = h('button', { cls: 'bt mini', type: 'button', txt: c.ativa ? 'Desativar' : 'Ativar' });
        liga.onclick = async () => {
          if (c.ativa && !confirm('Desativar "' + c.nome + '"? Some pra quem não tem; quem tem continua com ela.')) return;
          try { pinta(await manda({ acao: 'ativa', id: c.id, ativa: c.ativa ? 0 : 1 })); }
          catch (e) { recado(e.message, 'ruim'); }
        };

        const acoes = [editar, liga];
        if (!c.quantos) {
          const fora = h('button', { cls: 'bt mini perigo', type: 'button', txt: '×', title: 'Apagar de vez' });
          fora.onclick = async () => {
            if (!confirm('Apagar "' + c.nome + '" de vez? Ninguém ganhou ela ainda.')) return;
            try { pinta(await manda({ acao: 'apagar', id: c.id })); }
            catch (e) { recado(e.message, 'ruim'); }
          };
          acoes.push(fora);
        }

        corpo.appendChild(h('div', { cls: 'cqa-linha' + (c.ativa ? '' : ' desligada') }, [
          h('span', { cls: 'cqa-ic', txt: c.icone }),
          h('div', { cls: 'cqa-meio' }, [
            h('b', { txt: c.nome }),
            h('small', { txt: nomeMedidor(c.medidor) + ' ≥ ' + c.meta + '  ·  ' + textoPremio(c)
              + '  ·  ' + (c.quantos === 1 ? '1 pessoa tem' : c.quantos + ' pessoas têm') }),
          ]),
          h('span', { cls: 'cqa-estado' + (c.ativa ? ' on' : ''),
            txt: !c.ativa ? 'desativada' : c.oculta ? 'escondida até ganhar' : 'ativa' }),
        ].concat(acoes)));
      });
    });
  }

  /* O formulário, no mesmo popup do resto do site. */
  function abre(c) {
    const editando = !!c;
    const v = c || { id: '', nome: '', descricao: '', icone: '🏆', grupo: 'comeco', ordem: 50,
      medidor: (dados.medidores[0] || {}).id || '', meta: 1, vagas: 0, selo: '', pro: 0, ativa: 1, oculta: 0, quantos: 0 };

    fechaPop();
    const caixa = h('div', { cls: 'pop__caixa' });
    const fundo = h('div', { cls: 'pop', id: 'pop' }, [caixa]);
    fundo.onclick = (e) => { if (e.target === fundo) fechaPop(); };
    document.addEventListener('keydown', popTecla);
    const x = h('button', { cls: 'pop__x', type: 'button', txt: '×', title: 'Fechar' });
    x.onclick = fechaPop;

    const campo = (rotulo, el2) => h('label', {}, [document.createTextNode(rotulo), el2]);
    const texto = (id, valor, max, ph) => {
      const i = h('input', { type: 'text', id, maxlength: String(max), placeholder: ph || '' });
      i.value = valor;
      return i;
    };
    const numero = (id, valor, min, max) => {
      const i = h('input', { type: 'number', id, min: String(min), max: String(max) });
      i.value = String(valor);
      return i;
    };
    const escolha = (id, opcoes, valor) => {
      const s = h('select', { id });
      opcoes.forEach(([val, rot]) => {
        const o = h('option', { value: val, txt: rot });
        if (String(val) === String(valor)) o.selected = true;
        s.appendChild(o);
      });
      return s;
    };

    const fId = texto('cqa-id', v.id, 40, 'ex: primeira-live');
    if (editando) fId.disabled = true;
    const fNome = texto('cqa-nome', v.nome, 60, 'Nome que aparece na tela');
    const fDesc = texto('cqa-desc', v.descricao, 200, 'O que fazer pra ganhar');
    const fIcone = texto('cqa-icone', v.icone, 16, '🏆');
    const fGrupo = escolha('cqa-grupo', CQ_GRUPOS, v.grupo);
    const fOrdem = numero('cqa-ordem', v.ordem, -999, 999);
    const fMedidor = escolha('cqa-medidor', (dados.medidores || []).map((m) => [m.id, m.nome]), v.medidor);
    const fMeta = numero('cqa-meta', v.meta, 1, 1000000);
    const fVagas = numero('cqa-vagas', v.vagas, 0, 50);
    const fSelo = escolha('cqa-selo', [['', 'nenhum']].concat((dados.selos || []).map((s) => [s.slug, s.nome])), v.selo);
    const fPro = numero('cqa-pro', v.pro, 0, 365);
    const fAtiva = h('input', { type: 'checkbox', id: 'cqa-ativa' });
    fAtiva.checked = !!v.ativa;
    const fOculta = h('input', { type: 'checkbox', id: 'cqa-oculta' });
    fOculta.checked = !!v.oculta;

    /* Trocar o medidor de quem já ganhou é recusado no servidor; aqui o
       campo já nasce travado, com o motivo do lado. */
    if (editando && v.quantos > 0) fMedidor.disabled = true;

    const aviso = h('p', { cls: 'cqa-aviso' });
    const confere = () => {
      const avisos = [];
      if (editando && v.quantos > 0) {
        if (+fVagas.value < v.vagas) avisos.push('Menos vagas: quem já tem perde a diferença na próxima visita.');
        if (+fPro.value !== v.pro) avisos.push('Os dias de Pro novos valem só pra quem ganhar daqui pra frente.');
        if (+fMeta.value > v.meta) avisos.push('Meta maior: quem já ganhou continua com ela.');
      }
      aviso.textContent = avisos.join(' ');
    };
    [fVagas, fPro, fMeta].forEach((i) => { i.oninput = confere; });

    const salvar = h('button', { cls: 'bt principal', type: 'button', txt: editando ? 'Salvar' : 'Criar conquista' });
    salvar.onclick = async () => {
      if (editando && v.quantos > 0 && +fVagas.value < v.vagas
          && !confirm('Diminuir as vagas tira espaço de ' + v.quantos + ' pessoas. Continuar?')) return;
      salvar.disabled = true;
      try {
        const r = await manda({
          acao: 'salvar', editando: editando ? 1 : 0,
          id: fId.value.trim().toLowerCase(), nome: fNome.value, descricao: fDesc.value, icone: fIcone.value,
          grupo: fGrupo.value, ordem: +fOrdem.value, medidor: fMedidor.value, meta: +fMeta.value,
          vagas: +fVagas.value, selo: fSelo.value, pro: +fPro.value,
          ativa: fAtiva.checked ? 1 : 0, oculta: fOculta.checked ? 1 : 0,
        });
        fechaPop();
        pinta(r);
        recado(editando ? 'Conquista salva.' : 'Conquista criada. Quem já cumpre ganha na próxima visita.', 'bom');
      } catch (e) {
        recado(e.message, 'ruim');
        salvar.disabled = false;
      }
    };

    caixa.append(
      h('div', { cls: 'pop__topo' }, [x, h('h2', { txt: editando ? 'Editar conquista' : 'Nova conquista' })]),
      h('div', { cls: 'pop__corpo' }, [
        h('div', { cls: 'cqa-form' }, [
          h('div', { cls: 'par' }, [campo('Id (não muda depois)', fId), campo('Ícone', fIcone)]),
          campo('Nome', fNome),
          campo('Como ganhar', fDesc),
          h('div', { cls: 'par' }, [campo('Grupo', fGrupo), campo('Ordem no grupo', fOrdem)]),
          h('div', { cls: 'par' }, [
            campo(editando && v.quantos > 0 ? 'O que mede (travado: já tem quem ganhou)' : 'O que mede', fMedidor),
            campo('Meta', fMeta),
          ]),
          h('div', { cls: 'par' }, [campo('Vagas de overlay', fVagas), campo('Selo', fSelo), campo('Dias de Pro', fPro)]),
          h('label', { cls: 'marca' }, [fAtiva, document.createTextNode('Ativa')]),
          h('label', { cls: 'marca' }, [fOculta, document.createTextNode('Escondida até alguém ganhar')]),
          aviso,
          h('div', { cls: 'campo' }, [salvar]),
        ]),
      ]),
    );
    document.body.appendChild(fundo);
    (editando ? fNome : fId).focus();
  }

  nova.onclick = () => { if (dados) abre(null); };

  manda({ acao: 'admin' }).then(pinta).catch((e) => {
    corpo.innerHTML = '';
    corpo.appendChild(h('p', { cls: 'd', txt: 'Não deu: ' + e.message }));
  });

  return cx;
}

/* ---------------- moderação dos artistas ---------------- */

function blocoArtistas() {
  const cx = h('div', { cls: 'fatia' }, [
    h('h3', { txt: 'Artistas' }),
    h('p', { cls: 'd', txt: 'O selo é a sua palavra: abra o processo, olhe, e só então aprove com selo.' }),
  ]);

  const corpo = h('div');
  cx.appendChild(corpo);

  const carregaAr = () => {
    corpo.innerHTML = '<p class="d">vendo…</p>';

    api('/artistas.php', { method: 'POST', body: JSON.stringify({ acao: 'lista' }) }).then((d) => {
      corpo.innerHTML = '';
      const as = d.artistas || [];
      if (!as.length) return corpo.appendChild(h('p', { cls: 'd', txt: 'Nenhuma inscrição ainda.' }));

      /* Quem tem alguma coisa esperando vem primeiro: inscrição nova ou
         arte nova de quem já está na galeria. */
      const espera = (a) => (a.estado === 'pendente' || (a.obras || []).some((o) => o.aprovada === 0)) ? 1 : 0;
      as.sort((x, y) => espera(y) - espera(x));

      as.forEach((a) => {
        const manda = async (acao, extra) => {
          try {
            await api('/artistas.php', { method: 'POST',
              body: JSON.stringify(Object.assign({ acao, id: a.id }, extra || {})) });
            carregaAr();
          } catch (e) { recado(e.message, 'ruim'); }
        };

        const obras = (a.obras || []).filter((o) => !o.processo);
        const tiras = h('div', { cls: 'arte-tiras', style: 'padding:0 0 8px' });
        obras.forEach((o) => {
          const b = h('button', { type: 'button', style: 'width:64px;height:52px',
            title: o.titulo || 'abrir' }, [h('img', { src: urlObra(o), loading: 'lazy', alt: '' })]);
          b.onclick = () => window.open(urlObra(o), '_blank', 'noopener');
          if (o.aprovada !== 0) { tiras.appendChild(b); return; }

          /* Arte nova de quem já está na galeria: só aparece depois daqui. */
          b.style.borderColor = 'var(--dourado)';
          const sim = h('button', { cls: 'bt mini', type: 'button', txt: '✓', title: 'Aprovar esta arte' });
          const nao = h('button', { cls: 'bt mini perigo', type: 'button', txt: '✕', title: 'Recusar esta arte' });
          sim.onclick = () => manda('obra_aprovar', { id: o.id });
          nao.onclick = () => { if (confirm('Recusar esta arte? Ela é apagada.')) manda('obra_recusar', { id: o.id }); };
          tiras.appendChild(h('div', { style: 'display:flex;flex-direction:column;align-items:center;gap:4px' }, [
            b, h('div', { style: 'display:flex;gap:4px' }, [sim, nao])]));
        });

        const acoes = h('div', { cls: 'campo', style: 'gap:6px;flex-wrap:wrap' });

        const processo = (a.obras || []).filter((o) => o.processo)[0];
        if (processo) {
          const vp = h('button', { cls: 'bt mini', type: 'button', txt: 'Abrir o processo' });
          vp.onclick = () => window.open(urlObra(processo), '_blank', 'noopener');
          acoes.appendChild(vp);
        }

        if (a.estado !== 'aprovado' || !a.sem_ia) {
          const ok1 = h('button', { cls: 'bt mini', type: 'button', txt: 'Aprovar com selo' });
          ok1.onclick = () => manda('aprovar', { sem_ia: 1 });
          acoes.appendChild(ok1);
        }
        if (a.estado !== 'aprovado' || a.sem_ia) {
          const ok2 = h('button', { cls: 'bt mini', type: 'button', txt: 'Aprovar sem selo' });
          ok2.onclick = () => manda('aprovar', { sem_ia: 0 });
          acoes.appendChild(ok2);
        }
        if (a.estado !== 'recusado') {
          const nao = h('button', { cls: 'bt mini', type: 'button', txt: 'Recusar' });
          nao.onclick = () => {
            if (confirm('Recusar ' + a.nome + '? As imagens vão pro lixo, o cadastro fica.')) manda('recusar');
          };
          acoes.appendChild(nao);
        }
        const del = h('button', { cls: 'bt mini perigo', type: 'button', txt: '×' });
        del.onclick = () => { if (confirm('Apagar ' + a.nome + ' de vez?')) manda('apagar'); };
        acoes.appendChild(del);

        const url = linkSeguro(a.link);
        const ficha = h('p', { cls: 'd', txt: a.estado
          + (a.sem_ia ? '  ·  com selo' : '')
          + (a.arroba ? '  ·  @' + a.arroba : '')
          + (a.contato ? '  ·  ' + a.contato : '') });

        const dentro = [h('b', { txt: a.nome }), ficha];
        if (a.bio) dentro.push(h('p', { cls: 'd', txt: a.bio }));
        if (url) dentro.push(h('p', {}, [h('a', { href: url, target: '_blank',
          rel: 'noopener noreferrer nofollow', txt: url })]));
        const eLink = h('input', { type: 'url', placeholder: 'https://… rede social', style: 'flex:1 1 220px' });
        eLink.value = a.link || '';
        const eTw = h('input', { type: 'text', placeholder: 'twitch (opcional)', style: 'flex:0 0 150px' });
        eTw.value = a.twitch || '';
        const eSalva = h('button', { cls: 'bt mini', type: 'button', txt: 'Salvar' });
        eSalva.onclick = () => manda('editar', { link: eLink.value, twitch: eTw.value });
        dentro.push(h('div', { cls: 'campo', style: 'gap:6px;flex-wrap:wrap' }, [eLink, eTw, eSalva]));

        dentro.push(tiras, acoes);

        corpo.appendChild(h('div', { style: 'border-top:1px solid var(--linha);padding-top:12px;margin-top:12px' }, dentro));
      });
    }).catch((e) => {
      corpo.innerHTML = '';
      corpo.appendChild(h('p', { cls: 'd', txt: 'Não deu: ' + e.message }));
    });
  };

  carregaAr();
  return cx;
}
