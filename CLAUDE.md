# O padrão deste projeto

As regras de plataforma (cache, OBS, hospedagem, `?v=`) estão em
`ARQUITETURA.md`, seção 2. Este arquivo é outra coisa: **como se decide** o
que construir e como escrever. Cada item aqui saiu de um defeito real.

Quem lê isto e segue não precisa conhecer o projeto inteiro pra mexer nele
sem quebrar nada.

---

## 1. Falha silenciosa é o inimigo número um

Quase todo defeito deste projeto foi silencioso. Um SQL não rodado, uma
ponte velha em cache, uma coluna truncada, um comando ofuscado por outro,
um HMAC com um pedaço a mais. **Nenhum deu erro em lugar nenhum.**

Então a regra não é "não errar" — é **fazer o erro aparecer**:

- Todo `catch` que engole um problema explica no comentário por que engolir
  é o certo ali. Se não houver motivo, não engula.
- Falha que custa dinheiro ou acesso vai pra tabela `erros` (`erro_anota`),
  e aparece na tela "O que está quebrado".
- Ao registrar recusa de terceiro, guarde o **formato** (que campos vieram),
  nunca o conteúdo. "Assinatura inválida" sozinho não diz onde consertar.
- Coisa que existe em dois lugares ganha guarda no `conferir.js` — e o
  guarda tem que ser **testado disparando**, não só passando. Guarda que
  nunca dispara não é guarda, é enfeite.

## 2. Entrada de terceiro é texto. Sempre.

Nome de viewer, mensagem de chat, payload de webhook, título de stream:
**texto**. Nunca URL, nunca HTML, nunca código, nunca SQL montado.

- `textContent`, nunca `innerHTML`, pra qualquer coisa vinda de fora.
- `prepare` + `execute`, sem exceção. Nunca concatenar valor em SQL.
- Código e banco só mudam por commit daqui ou por upload do dono.
  Nada que chegue pela rede altera qualquer um dos dois.

## 3. Dinheiro precisa de dois caminhos

Webhook se perde. Isso não é hipótese: aconteceu no primeiro pagamento real.

Quem pagou não pode depender de uma entrega só, nem de achar um botão de
socorro. Todo caminho que libera acesso pago tem **uma rede automática**
que pergunta de novo ao provedor — `checkout.php?cron=`. O botão manual
continua existindo, mas como segunda linha.

E o que é "de uma venda" (gastar dias de raid, pagar comissão de cupom)
acontece **uma vez**, nunca a cada renovação.

## 4. A pessoa do outro lado não entende de computação

O público são streamers, não programadores.

- Sem jargão. "A fonte do OBS" e não "o browser source".
- Erro diz **o que fazer**, não o que falhou. "Suba o arquivo de novo"
  ganha de "arquivo desatualizado".
- O que dá pra sortear, sorteia. Não peça pra pessoa inventar id, nome de
  link ou segredo — e muito menos pra repetir o mesmo valor em dois
  lugares, que é erro de cópia esperando acontecer.
- Comando pronto pra copiar, com botão, em vez de modelo pra preencher.
- Tela de diagnóstico dá **veredito**, não tabela. Saber que há zero
  avisos não ajuda; saber que zero avisos com zero recusas significa "o
  provedor não está mandando pra este endereço" diz o que fazer.

## 5. Interface

- Uma tela responde uma pergunta. Se responde três, são três blocos.
- O que é perigoso não fica ao lado do que é comum. Botão de apagar não
  aparece em linha que não pode ser apagada — recusar depois é pior que
  não oferecer.
- Confirmação diz a **consequência**, não "tem certeza?".
- Campo obrigatório só aparece quando é mesmo obrigatório.
- Estado vazio explica por que está vazio e o que fazer.

## 6. Texto

- Frase curta, voz ativa, sem exclamação.
- Verbo no passado em histórico ("seguiu"), no imperativo em ação ("Tirar").
- Nada de "ops", "oops", "eita". O erro já é chato sem piada.
- Número com unidade e sem ambiguidade: "5 dias", não "5".
- Nunca prometa o que o código não faz.

## 7. Código

- **Comentário explica o porquê, nunca o quê.** Armadilha de plataforma,
  "por que não do jeito óbvio", unidade que engana. O resto se lê no código.
- Função com um dono. Lógica igual em dois lugares vira uma função — ou
  vira guarda no `conferir.js`, quando duplicar é inevitável.
- Nome não se sobrecarrega: se `quando()` significa duas coisas em dois
  escopos, uma das duas está com o nome errado.
- Ramo de `isset($_GET[...])` vem **antes** do ramo geral de GET, que
  responde e encerra. O `conferir.js` checa isso.
- Arquivo novo só quando o assunto é novo. Variação de coisa existente vai
  em arquivo isolado com o contrato no topo.

## 8. Antes de commitar

```bash
node conferir.js
```

Ele roda os ensaios de pagamento junto. Se falhar, não commite.

Para mudança em PHP, lint também:

```bash
for f in api/*.php api/lib/*.php; do php -l "$f"; done
```

Commit em português, sem atribuição de ferramenta, explicando **por que** a
mudança existe — não o que ela faz, que se lê no diff.

## 9. Onde cada coisa vai

| O que | Para onde | Como |
|---|---|---|
| `api/**` | Hostinger | upload |
| `sql/*.sql` | Hostinger | rodar no phpMyAdmin |
| `docs/**` | GitHub Pages (`mods.zocahop.com`) | `git push` |

**Ordem importa.** Banco primeiro, depois `api/`, depois o push. O contrário
deixa a tela nova falando com a API velha.

Migração nova que cria tabela ou coluna entra na lista `SEM_BANCO` do
`api/saude.php`, ou a tela de saúde não avisa que falta rodar.

## 10. Pesquisar antes de aplicar

Regra de plataforma se confirma na documentação oficial ou medindo — nunca
de memória. Duas linhas da documentação do Mercado Pago custaram um
pagamento recusado com o dinheiro já na conta.

Quando a fonte é ambígua e a dúvida custa dinheiro, **não aposte**: mantenha
o caminho que já funciona ao lado do novo, e diga qual dúvida ficou.
