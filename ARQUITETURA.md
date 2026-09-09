# O que falta, e como fazer

Este arquivo é a teoria. Ele descreve o que ainda não existe no
ZocaController, por que cada coisa é feita do jeito descrito, e o que a
plataforma de fora realmente permite — não o que seria bom se permitisse.

Cada bloco tem a mesma forma: **o problema**, **o limite real da API**,
**as tabelas**, **os arquivos**, **a ordem de execução** e **o que pode
quebrar**. Quem for executar não precisa decidir arquitetura, só escrever.

Ordem sugerida: 0 → 1 → 4 → 2 → 5 → 3 → 6.
O 0 é obrigatório antes de qualquer outro: sem ele, metade do que já existe
está no repositório e não está no ar.

---

## 0. O que já está pronto e ainda não está no ar

Nada aqui é código novo. É o que falta subir e rodar para o que já foi feito
começar a funcionar.

### Arquivos para subir no servidor
Tudo em `api/` e tudo em `docs/`. Em especial os que mudaram por último:
`api/lib/contagem.php`, `api/lib/twitch.php`, `api/lib/lastfm.php`,
`api/lib/youtube.php`, `api/lastfm.php`, `api/youtube.php`, `api/admin.php`,
`api/perfil.php`, `api/config-overlay.php`, `docs/index.html`,
`docs/overlay.html`.

### SQL para rodar, na ordem
| Arquivo | O que faz | Quebra o quê se faltar |
|---|---|---|
| `sql/019-metas-subathon.sql` | alvos de seguidores/subs que empurram o cronômetro | subathon não ganha tempo por meta |
| `sql/020-admin.sql` | `usuarios.admin`, `perfis_max`, `recursos`, `visto_em` | **`perfil.php` devolve erro 500 e o painel inteiro fica sem lista** |
| `sql/021-plataformas.sql` | `contagens.fonte` cresce para 32, `usuarios.yt_canal/yt_handle` | meta de YouTube e de Kick não conta |
| `sql/022-lastfm.sql` | `usuarios.lastfm_user` | Last.fm não liga |

O 020 é o mais perigoso da lista: `recursos_do_usuario()` faz
`SELECT perfis_max, recursos FROM usuarios`, e sem as colunas isso derruba a
listagem de overlays de **todo mundo**, não só a de quem é admin.

### Chaves no `api/config.php`
```php
'youtube' => ['api_key' => '...'],   // Google Cloud > YouTube Data API v3
'lastfm'  => ['api_key' => '...'],   // last.fm/api/account/create
```

### Teste de aceitação (faça na ordem, é rápido)
1. Abrir o painel: a barra de cima mostra `@seulogin` no canto direito.
   Se não mostrar, o `perfil.php` novo não subiu.
2. Criar uma meta de seguidores da Twitch: o número tem que aparecer sozinho
   em até um minuto.
3. `curl -I` num `config-overlay.php?k=...` duas vezes, repetindo o ETag da
   primeira no `If-None-Match` da segunda: a segunda tem que responder **304**.
   Se responder 200, o `config-overlay.php` novo não subiu.
4. Ligar o Last.fm com música tocando: a tela tem que dizer o nome da faixa.

---

## 1. Confiabilidade — o guardião da live

### O problema
Hoje o site não sabe se o canal está no ar. Três consequências, todas já
observadas ou inevitáveis:

- O cronômetro do subathon queima tempo com a live caída. Quem cai por vinte
  minutos volta com vinte minutos a menos de subathon, sem ter recebido nada.
- A contagem automática pergunta viewers para um canal offline a cada minuto,
  gastando chamada para receber zero.
- Ninguém avisa o streamer quando a ponte do OBS morre no meio da live. O
  chat manda `!cena` e não acontece nada, e a descoberta é sempre tarde.

### O limite real da API
`stream.online` e `stream.offline` são EventSub versão 1, condição
`broadcaster_user_id`, e **não exigem escopo nenhum** — é dado público. Isso
significa que dá para assinar para todo usuário já cadastrado sem pedir
autorização nova a ninguém. É o item mais barato desta lista inteira.

O Kick tem `livestream.status.updated` no webhook, mesmo formato dos que já
tratamos em `kick-eventos.php`. O YouTube não tem evento equivalente e fica
de fora — quem escolher YouTube não ganha guardião, e a tela precisa dizer
isso em vez de fingir que ganhou.

### Tabelas
```sql
ALTER TABLE usuarios
  ADD COLUMN ao_vivo     TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN ao_vivo_em  DATETIME   NULL DEFAULT NULL;

/* Por que a coluna nova no subathon:
   pausa de pessoa e pausa de queda de live são coisas diferentes. Sem
   separar, o stream.online despausaria um subathon que o streamer tinha
   pausado de propósito — e ele veria o cronômetro voltar a andar sozinho. */
ALTER TABLE subathon
  ADD COLUMN pausa_auto  TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN guardiao    TINYINT(1) NOT NULL DEFAULT 0;
```

`guardiao = 0` por padrão de propósito: ligar sozinho um comportamento que
para o cronômetro de alguém é o tipo de surpresa que faz a pessoa desconfiar
do site.

### Regra de ouro do pausa/despausa
```
stream.offline + guardiao ligado + modo == 'rodando'
    -> restante = fim - agora;  modo = 'pausado';  pausa_auto = 1

stream.online  + pausa_auto == 1
    -> fim = agora + restante;  modo = 'rodando';  pausa_auto = 0

pessoa pausa na mão
    -> pausa_auto = 0    (e aí o stream.online não mexe)
```
A conversão `fim <-> restante` já existe e está certa em
`api/lib/subathon-somar.php` e no controle `pausa` do painel. Reaproveite,
não reescreva: são duas representações do mesmo tempo e um terceiro lugar
convertendo é um terceiro lugar para errar.

### Arquivos
- `api/eventsub.php` — somar `stream.online` e `stream.offline` à lista de
  tópicos assinados e tratar os dois no recebimento.
- `api/lib/canal.php` (novo, pequeno) — `canal_ao_vivo(int $usuario_id): bool`
  e `canal_marcar(int $usuario_id, bool $ao_vivo): void`. Todo mundo pergunta
  aqui, ninguém lê a coluna direto.
- `api/lib/contagem.php` — quando `canal_ao_vivo()` for falso, `viewers`
  devolve 0 sem chamar a Twitch. Seguidores e subs continuam contando: eles
  mudam com o canal offline.
- `api/kick-eventos.php` — mesmo tratamento para `livestream.status.updated`.
- `docs/index.html` — a chave do guardião dentro da aba do subathon, com a
  frase explicando o que ela faz. E um ponto verde/cinza ao lado do `@login`
  na barra dizendo se o site acha que o canal está no ar.

### A ponte caída
Separado, e mais simples do que parece: a `ponte.html` já fala com o servidor.
Ela passa a mandar um sinal de vida a cada 30 s (`api/estado.php`), que grava
`pontes.visto_em`. Quando o painel vê um sinal com mais de 90 s, mostra
"a ponte não está respondendo" com o link para reabrir. Não precisa de
notificação nem de nada empurrado: quem está com o painel aberto vê, e quem
não está não seria avisado de qualquer jeito.

### O que pode quebrar
- **EventSub duplicado.** Assinar de novo o que já está assinado devolve 409.
  Trate 409 como sucesso, senão o cadastro falha para quem já tinha a
  assinatura.
- **Entrega fora de ordem.** A Twitch entrega "pelo menos uma vez" e sem
  garantia de ordem. Um `offline` atrasado chegando depois de um `online`
  pausaria uma live que está no ar. Guarde o `event_timestamp` da mensagem em
  `ao_vivo_em` e **ignore evento mais velho do que o que já está gravado**.
- **Live que pisca.** Quedas curtas de encoder geram online/offline em
  segundos. Segure o offline por 90 s antes de pausar — na prática, isso é
  um `offline_em` gravado e a decisão tomada no próximo poll, porque em
  hospedagem compartilhada não existe processo esperando.

---

## 2. Steam

Este é o maior bloco, e o único que vale quebrar em partes que entregam
sozinhas. Faça na ordem; cada número já é útil sem o seguinte.

### O limite real da API
- `ISteamUser/GetPlayerSummaries/v2` devolve `gameextrainfo` (nome do jogo) e
  `gameid` **só quando o perfil é público**. Perfil privado devolve o jogo
  vazio, e não existe contorno.
- `IPlayerService/GetOwnedGames/v1` com `include_appinfo=1` devolve a
  biblioteca inteira com horas jogadas. Também depende de perfil público.
- `ISteamUserStats/GetPlayerAchievements/v1` devolve as conquistas de um app,
  com `achieved` 0/1. Não avisa quando muda: **só dá para saber comparando
  duas leituras**.
- Preço: `https://store.steampowered.com/api/appdetails?appids=X&cc=br&l=pt`
  — não é documentado, não tem chave, e é o que todo mundo usa. Trate como
  algo que pode sumir: cache longo e falha silenciosa.
- Não existe login OAuth de Steam. O que existe é **OpenID 2.0**, que devolve
  só o SteamID64 e mais nada. Isso é suficiente para tudo acima e é o caminho.

### Ordem
**2.1 — Overlay "jogando agora".**
Poll de `GetPlayerSummaries` a cada 60 s, guardado igual à música. Mostra capa
(`https://cdn.cloudflare.steamstatic.com/steam/apps/<appid>/header.jpg`), nome
do jogo e horas totais. Reaproveite `docs/musica.js` como forma: é o mesmo
desenho — capa, título, subtítulo — e o mesmo ciclo de aparecer/sumir.

**2.2 — Jogo manual.**
Um campo no painel para escrever o jogo à mão, com um interruptor
"usar o manual em vez do que a Steam diz". Isto não é um extra: Minecraft,
emuladores, jogos de Epic e Game Pass são metade do que se joga em live, e
sem o campo o overlay some justo nessas horas.

**2.3 — `!preco`.**
Comando de chat que responde o preço em BRL do jogo em que a pessoa está.
Cache de 6 h por appid numa tabela `steam_precos`, porque o endpoint não é
oficial e não convém bater nele por mensagem de chat.

**2.4 — Conquistas.**
A cada 5 min, ler as conquistas do appid atual e comparar com a leitura
anterior guardada. O que virou 1 desde a última vez vira evento no `eventos`
e cai no feed e no alerta — que já sabem desenhar evento, sem código novo.
Guarde apenas os IDs conquistados (JSON), não o histórico: o que interessa é
o diff.

**2.5 — Roleta de backlog.**
`GetOwnedGames` filtrando `playtime_forever == 0`, sorteia um. É o comando
mais simples da lista e o de maior chance de virar quadro de live.

**2.6 — A ponte com o "o que streamar".**
O recomendador de jogos já existe como projeto separado dele. Aqui ele entra
como **leitura**, não como fusão: um endpoint no lado de lá devolvendo
`[{jogo, nota, motivo}]`, e um overlay/comando aqui que mostra a sugestão do
dia. Não misture os bancos. São dois produtos, e um deles depende de cron
pesado que esta hospedagem não deve carregar junto.

### Tabelas
```sql
ALTER TABLE usuarios
  ADD COLUMN steam_id     VARCHAR(20) NULL DEFAULT NULL,
  ADD COLUMN steam_manual VARCHAR(80) NULL DEFAULT NULL;

CREATE TABLE steam_cache (
  usuario_id INT UNSIGNED NOT NULL,
  chave      VARCHAR(32)  NOT NULL,   /* 'agora', 'conquistas:<appid>', 'lib' */
  valor      MEDIUMTEXT   NULL,
  visto_em   DATETIME     NOT NULL,
  PRIMARY KEY (usuario_id, chave)
);

CREATE TABLE steam_precos (
  appid    INT UNSIGNED NOT NULL PRIMARY KEY,
  json     TEXT         NULL,
  visto_em DATETIME     NOT NULL
);
```

### Arquivos
`api/lib/steam.php`, `api/steam.php` (ligar/desligar conta, estado),
`api/steam-openid.php` (a volta do OpenID), `docs/steam.js` + `docs/steam.css`
(o desenho), e o tipo `steam` entrando nas **três** listas que o `conferir.js`
vigia: `TIPOS` no `index.html`, `PERFIL_TIPOS` no `perfil.php`, e o roteador
de renderizadores no `overlay.html`.

### O que pode quebrar
- **Perfil privado** é o caso comum, não a exceção. A tela tem que dizer
  "seu perfil da Steam está privado, e por isso não dá para ver o jogo",
  com o caminho do ajuste. Sem isso, vira o mesmo tipo de bilhete inútil que
  o do Spotify era.
- **Chave da Steam é do servidor**, uma só, com limite de 100 mil chamadas por
  dia. Com 60 s de poll por usuário são 1.440 chamadas/dia por pessoa: cabem
  ~60 usuários. Antes disso, o poll precisa ser sob demanda — só busca quando
  algum overlay daquele usuário pediu config nos últimos 2 minutos.
- **`appdetails` sem contrato.** Se sumir, `!preco` para. Ele não pode
  derrubar mais nada junto.

---

## 3. TikTok

### O limite real da API
`GET /v2/user/info/` com escopo `user.info.stats` devolve `follower_count`.
Isso funciona e é tudo o que dá para fazer.

O que **não** existe: qualquer API oficial de eventos ao vivo. Presente,
entrada na live, comentário — nada disso tem endpoint. O que circula por aí
são bibliotecas que fingem ser o app e leem o WebSocket interno. Elas quebram
sozinhas, e usar uma delas num produto pago é escolher a hora em que ele vai
parar. **Não vá por aí.**

Além disso, antes da aprovação do app o TikTok só atende **10 contas de teste**
cadastradas à mão — mesma armadilha do Spotify em modo de desenvolvimento,
com a diferença de que aqui a aprovação existe e é alcançável.

### O que fazer
Só meta de seguidores. Nada de alerta, nada de feed, nada de chat.

E antes do código, o processo, que é a parte demorada: o TikTok exige vídeo
demonstrando cada escopo pedido, política de privacidade e termos de uso
publicados. Os termos já existem em `zocahop.com/termos/` — falta o vídeo e
o preenchimento. **Comece por isso**, porque a análise leva dias e o código
são poucas horas.

### Arquitetura
Idêntica à do Kick, e é por isso que ele vem depois: OAuth com PKCE, tokens
guardados por usuário, `contagem_plataformas` ganhando
`'tiktok' => ['seguidores']`, e o seletor de plataforma do painel ganhando
mais uma opção. Nenhuma estrutura nova.

```sql
ALTER TABLE usuarios
  ADD COLUMN tt_open_id VARCHAR(64) NULL DEFAULT NULL,
  ADD COLUMN tt_token   TEXT        NULL DEFAULT NULL,
  ADD COLUMN tt_refresh TEXT        NULL DEFAULT NULL,
  ADD COLUMN tt_expira  DATETIME    NULL DEFAULT NULL;
```

### O que pode quebrar
O token do TikTok vale 24 h e o refresh vale 365 dias — bem mais curto que o
da Twitch. O renovador tem que rodar no caminho da leitura, igual ao do
Spotify, e não num cron: cron que falha em silêncio deixa a meta parada sem
ninguém saber por quê.

---

## 4. Spotify — sair do modo de desenvolvimento

### O problema, com o diagnóstico já fechado
O app está em Development Mode. Nesse modo o Spotify atende **5 contas**,
escritas à mão no painel deles, e devolve **403 para todas as outras**. Não é
bug, não é token, não é vínculo: é o modo do app. Foi o que aconteceu com os
testadores.

### As duas saídas, e por que a ordem é essa
**Curto prazo — coletar o e-mail para poder cadastrar.**
Hoje o site não sabe o e-mail de quem conectou, então cadastrar alguém na
lista exige perguntar por fora. Somar `user-read-email` ao `SP_ESCOPOS` em
`api/lib/spotify.php`, ler `/v1/me` logo depois da troca do código, e guardar
`sp_email`. O `api/admin.php` ganha uma lista com esses e-mails e um botão de
copiar. Aí cadastrar vira colar.

Isso **não** resolve o limite de 5. Resolve o atrito de usar os 5 que existem,
e é meia hora de trabalho.

**Médio prazo — pedir a extensão de cota.** O formulário de Quota Extension
exige app publicado, política de privacidade e a descrição do uso. Com ele
aprovado, o limite de 5 acaba. É o caminho de verdade; o de cima é ponte.

**Vários apps não é caminho.** Distribuir os usuários entre apps do mesmo
dono para furar o teto é exatamente o que os termos proíbem, e o custo do
tombo é o app principal ser derrubado. Se for para gastar esforço, gaste no
formulário.

### O que já está certo e deve continuar
O Last.fm é o padrão para quem chega. O Spotify fica para quem precisa de
`!pular`, `!fila` e `!like`, que o Last.fm não faz por ser só leitura. Essa
divisão está implementada e é a decisão certa — não a desfaça quando a cota
sair.

---

## 5. Histórico de seguidores

Você já tem um `seguidores.php` que guarda cada seguidor com dia, hora,
segundo e nick. O que ele faz e o ZocaController não faz: **saber quem
deixou de seguir**. A Twitch não expõe unfollow em lugar nenhum, e a única
forma é comparar duas fotos da lista.

O caminho é o mesmo do outro projeto seu: `seguidores-cron.php` tira a foto,
o painel mostra a diferença. Aqui isso vira duas coisas úteis:

- Um overlay "quem seguiu por último" que aguenta o boot — hoje o feed começa
  vazio quando a fonte do OBS carrega, porque ele vive do que chega por
  evento. Com a tabela, ele nasce cheio.
- Um número de seguidores que não depende do EventSub ter funcionado: a
  contagem vira `SELECT COUNT(*)`, e o webhook vira só o que acelera.

Escopo necessário: `moderator:read:followers`, que a `api/lib/twitch.php` já
pede. Nada novo para autorizar.

**Cuidado:** isso é lista de pessoas, com nome e data. Fica atrás da chave do
painel, nunca sai por `config-overlay.php`, e o overlay recebe no máximo os
últimos nomes — nunca a lista inteira. O link do overlay é público por
natureza e vaza com um print da tela.

---

## 6. Cobrança

**Correção do que este arquivo dizia antes:** `api/checkout.php` e
`api/webhook-mercadopago.php` não são código pronto e sem teste — são
ESQUELETO. O checkout tem 39 linhas e a chamada à API deles está comentada;
o webhook tem a moldura certa (assinatura, idempotência, resposta rápida) mas
a consulta que confirma o pagamento é um TODO. Ou seja: hoje ninguém
consegue pagar, e se conseguisse ninguém seria liberado.

O que JÁ está pronto e conferido é a verificação de assinatura
(`mp_webhook_valido` em `api/lib/seguranca.php`) — inclusive um defeito que
reprovaria todo pagamento legítimo: o `ts` do Mercado Pago vem em
MILISSEGUNDOS e a janela de cinco minutos comparava com segundos.

**Estado em 2026-09-09: a estrutura está escrita e desligada.**
`api/lib/mercadopago.php`, `api/checkout.php` e
`api/webhook-mercadopago.php` estão fechados; `sql/023-cobranca.sql` cria as
colunas que amarram cobrança e pagamento. A chave `mercadopago.ligado` no
config nasce **falsa**: o checkout responde 503 e nenhum botão de pagar
existe no painel. Nada pode ser cobrado por acidente.

**Pagamento avulso, não assinatura recorrente.** A recorrência do Mercado
Pago só aceita cartão, e Pix não pode ser recorrente. Para streamer
brasileiro pequeno, tirar o Pix da mesa é tirar metade do público. Cada
pagamento empurra a validade 30 ou 365 dias a partir do que for maior entre
hoje e a validade atual — quem renova adiantado não perde os dias que
faltavam. O custo honesto: ninguém é cobrado sozinho, então quem esquece cai
pro grátis.

O que falta, e é tudo do lado de fora do código:

1. **Criar o app no painel do Mercado Pago** e pegar as credenciais de
   TESTE, mais a "Assinatura secreta" do webhook.
2. **Rodar o `sql/023-cobranca.sql`.**
3. **Testar de ponta a ponta em modo teste** — aprovado, recusado, Pix
   pendente que aprova depois.
4. **Só então** virar `ligado => true` e trocar as credenciais pelas de
   produção. Elas são pares: access_token de teste com segredo de produção dá
   erro de assinatura sem explicação.
4. **Decidir o que acontece com quem não paga.** Hoje `acesso_do_usuario()`
   dá cortesia de alguns dias e depois cai para o plano grátis, que agora tem
   8 overlays. Quem tiver 20 no plano pago e cair para o grátis fica com 20 e
   não pode criar mais — não perde nada. Confirme que é isso mesmo que você
   quer, porque é o comportamento que está no código.

---

## 6.5. Cronômetro de speedrun — FEITO

Existe desde 2026-09-09: tipo de overlay `speedrun`, em `docs/spd.js` e
`docs/spd.css`. Lista de trechos, diferença contra o recorde, trecho recorde
em dourado e soma dos melhores, no formato que o LiveSplit consagrou.

O que ficou de fora, e por quê: **tecla global não existe numa página.** O
LiveSplit é programa de desktop e escuta a tecla com o jogo em primeiro
plano; uma aba de navegador só recebe tecla quando ela mesma está em foco.
Por isso os atalhos valem com o painel na frente, e quem joga em tela cheia
depende do chat.

Duas continuações possíveis, em ordem de utilidade:

1. **`!split` pelo chat.** O comando já tem toda a estrutura pronta em
   `api/comando.php` — falta a ação que mexe no `spcor` do overlay. É a que
   resolve o caso real de quem joga em tela cheia.
2. **Tecla global de verdade, pela ponte.** A `ponte.html` já fala
   obs-websocket. O OBS tem tecla global para ligar e desligar fonte; a ponte
   pode ouvir `SceneItemEnableStateChanged` de uma fonte-isca e traduzir isso
   em split. Dá tecla global de verdade sem instalar nada, ao custo de uma
   configuração a mais.

---

## 7. O teste que nunca foi feito

Nada disso — nem o que já está pronto — foi testado numa live de verdade.
Overlay funcionando na aba de um navegador é evidência fraca: a fonte do OBS
congela `setTimeout` e animação de CSS quando não está sendo pintada, e esse
detalhe já produziu leitura falsa várias vezes durante a construção.

Uma live de teste de trinta minutos, com o painel aberto de um lado e o OBS do
outro, vale mais do que qualquer item desta lista. Sugestão de roteiro:

1. Subir os arquivos e rodar os SQLs (bloco 0).
2. Abrir os overlays no OBS **antes** de começar, e conferir que todos
   desenham dentro de 15 s.
3. Começar a live. Conferir a meta de seguidores mexendo sozinha.
4. Mandar um bit e um sub de teste; ver o alerta e o feed.
5. Pausar e despausar o subathon; conferir que o tempo não andou.
6. Derrubar a live de propósito por dois minutos e voltar. **Hoje o
   cronômetro vai ter queimado esses dois minutos** — é justamente o que o
   bloco 1 conserta, e é bom ver o problema antes de consertar.
7. Fechar o OBS e reabrir; conferir que tudo volta sozinho.
