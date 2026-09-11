# ZocaController — estado e o que falta

Ponto de partida de qualquer sessão nova. Leia antes de escrever a primeira
linha.

---

## 1. Como o projeto é montado

| Onde | O quê | Como sobe |
|---|---|---|
| `docs/` | painel e overlays (HTML/JS puro) | commit no `main` → GitHub Pages → `mods.zocahop.com` |
| `api/` | PHP 8.3 sem framework | **upload manual** na Hostinger → `api.zocahop.com` |
| `sql/` | migrações numeradas | **rodadas à mão** no phpMyAdmin, em ordem |

Não existe build, bundler nem `npm install`. O `docs/index.html` tem ~7 mil
linhas e é o painel inteiro.

---

## 2. Regras que não podem ser quebradas

Cada uma custou um defeito em produção.

**Rode `node conferir.js` antes de commitar.** Ele guarda três listas
duplicadas em arquivos diferentes: tipos de overlay, ações de chat e cargos.
Adicionar um tipo de overlay exige mexer em `TIPOS` (index.html),
`PERFIL_TIPOS` (perfil.php) e no roteador de `overlay.html`. Esquecer um dá
"tipo desconhecido" sem dizer onde.

**Mexeu em `.js` ou `.css` de `docs/`? Suba o `?v=` em todos os HTML.** O
LiteSpeed guarda esses arquivos por 7 dias. Sem o número novo, o HTML atualiza
e o script continua o velho — a página parece nova e se comporta como velha.
HTML não é cacheado; só JS e CSS.

**Nada de ES2020 em arquivo que roda no OBS** (`overlay.html`, `ponte.html`,
`clock.js`, `chat.js`, `musica.js`, `alerta.js`, `spd.js`). O navegador
embutido do OBS fica versões atrás: sem `?.`, sem `??`, sem `catch {}` vazio.

**Tempo em overlay se calcula a partir de um instante, nunca somando.** O OBS
congela `setTimeout` e animação de CSS quando a fonte não está sendo
desenhada. Guardar "quando acaba" e subtrair é o que mantém o número certo
depois de horas escondido. Ver `modo`/`fim`/`restante` no subathon.

**A hospedagem é compartilhada.** Sem Node, sem WebSocket, sem processo longo.
Trabalho pesado vai depois da resposta com `responder_e_continuar()`
(`litespeed_finish_request`, não `fastcgi_`). Cron existe no hPanel.

**Comentário curto.** Só o que não dá pra deduzir lendo o código: armadilha de
plataforma, "por que não do jeito óbvio", unidade que engana. O raciocínio
longo vem pra este arquivo.

**Texto que o admin editou no painel é guardado pelo texto original do
código.** Reescrever em `docs/index.html` uma frase que já foi editada no
modo de edição deixa a edição sem par, e ela some do site — continua no
banco, mas não acha mais onde ficar. O `node conferir.js` lê as edições
que estão no ar e falha quando isso acontece. A correção é pôr o texto
novo apontando pro antigo em `TEXTOS_ANTIGOS`, no próprio `index.html`.

---

## 3. O que já está pronto

**Overlays (10 tipos):** meta, subathon, relógio, contador, placar, chat,
feed, música, alerta, speedrun. Cada um é um link fixo colado no OBS.

**Ponte com o OBS:** `docs/ponte.html` roda como fonte no OBS e fala
obs-websocket v5 em `localhost`. Chat troca cena, silencia mic, dispara
gatilhos. Moderadores têm links próprios revogáveis.

**Integrações:** Twitch (Helix + EventSub), YouTube (Data API), Kick (OAuth
2.1 + PKCE + webhook RSA), Spotify, Last.fm, LivePix.

**Cobrança (Mercado Pago):** checkout, webhook com assinatura, estorno,
reconciliação, cupons, comissão de parceiro. Ligada por
`cfg()['mercadopago']['ligado']`.

**Planos:** grátis (8 overlays, travados além disso), Pro mensal R$ 13,99,
anual R$ 150, vitalício R$ 330. Sem marca d'água em plano nenhum.

**Painel:** vitrine na home (carrossel de canais + em alta + atualizações),
busca, caminho de navegação, modo de edição de textos, CSS extra, admin com
usuários, cupons, parceiros e comissões.

**Selos:** tabela `selos` + `usuario_selos`, com desenho opcional por selo.
Dados à mão na administração, quantos couberem por conta. Nada automático —
selo dado por regra é selo que a regra contorna. Sem desenho, o selo aparece
como etiqueta escrita na cor dele.

**Feed:** posts com texto e uma imagem na coluna do meio da inicial, com
curtida, comentário e o perfil levando ao canal na Twitch. Nome e
foto vêm da Twitch no login e ficam guardados em `usuarios` — pedir o perfil
de cada autor a cada visita bate no limite deles. Dois selos, ligados à mão
na administração: `selo_streamer` e `selo_artista`.

**Luzes:** o chat muda a cor das lâmpadas com `!luz`. Ver a seção 4.1.

**Artistas:** `#/artistas` é a única tela que abre sem chave — um artista que
chega por link de divulgação não tem conta e não precisa ter. Inscrição
pública com até 5 imagens mais um arquivo de processo, tudo pendente até
alguém aprovar. O selo "sem IA" é conferência humana, não detector: quem
aprova abre o processo e olha. As imagens ficam em `arte/`, fora da pasta
servida, e saem por `artistas.php?a=obra&id=`; as pendentes só com link
assinado de 15 minutos, porque `<img>` não manda cabeçalho.

---

## 4. Limites reais das plataformas

Verificados na documentação. Não re-descubra.

- **Spotify:** modo de desenvolvimento atende **5 contas**. Extensão de cota
  exige 250 mil usuários/mês — inalcançável. Last.fm é a fonte pública.
  Biblioteca virou `PUT /me/library?uris=` (query, não corpo); os antigos
  devolvem 403.
- **Kick:** não expõe contagem de seguidores em endpoint nenhum.
- **YouTube:** arredonda inscritos em 3 algarismos significativos, até pro dono.
- **TikTok:** só `follower_count`, e depois de análise do app. Não existe API
  oficial de eventos ao vivo.
- **Mercado Pago:** o sandbox foi desligado — sempre `init_point`. O `ts` do
  webhook vem em milissegundos. Testar exige janela anônima com usuário de
  teste.
- **Instagram:** a Basic Display API morreu em dez/2024. Não há caminho
  oficial pra puxar posts de alguém pelo @.

### 4.1 Luzes: por que a arquitetura é essa

Três fatos, e eles decidem tudo:

1. A hospedagem **não alcança a rede de casa de ninguém**. Qualquer lâmpada
   que só tenha API local (Nanoleaf, WLED, Hue local) está fora do alcance
   do PHP.
2. Uma página em **HTTPS não pode falar com `http://192.168.x.x`** — o
   Chrome corta como conteúdo misto. `localhost` é exceção (é por isso que a
   ponte fala com o obs-websocket), mas um IP da rede não é.
3. Logo: **nuvem agora, local depois**, e o "depois" é a ponte executando o
   mesmo contrato de driver na máquina de quem transmite.

**Somar uma marca não exige conhecer o projeto.** É um arquivo em
`api/luzes/`, achado sozinho pelo núcleo, e o contrato inteiro está escrito
em `api/luzes/_MODELO.php`. A tela do painel se desenha a partir dos campos
que o driver declara — nem `docs/index.html` precisa mudar. Para uma sessão
nova: *"leia `api/luzes/_MODELO.php` e escreva `api/luzes/tapo.php`"*, e
mais nada.

Prontos: `lifx.php` (token, 120 chamadas/min, o efeito `pulse` volta sozinho
ao estado anterior) e `govee.php` (chave de API; a v2 exige `requestId` +
`payload{sku,device,capability}`, um aparelho e uma capacidade por chamada).

O que cabe e ainda não existe:

| Marca | Caminho | Custo de entrada |
|---|---|---|
| Tuya / Smart Life | nuvem oficial | conta de desenvolvedor Tuya; teste grátis de 1 mês, renovável à mão |
| Hue remoto | nuvem oficial | app aprovado pela Philips |
| Tapo (TP-Link) | não oficial | exige guardar usuário e senha da conta — desaconselhado |
| Nanoleaf, WLED, Hue local | rede de casa | depende da ponte executar drivers |
| Alexa | não existe | a API dela é pra quem fabrica aparelho, não pra quem controla |

---

## 5. O que falta

### 5.1 Estatísticas da transmissão

Já existe `contagens` (seguidores, subs, viewers por usuário). Falta o
histórico: uma linha por dia.

```sql
CREATE TABLE metricas_dia (
  usuario_id INT UNSIGNED NOT NULL,
  dia DATE NOT NULL,
  seguidores INT UNSIGNED NULL,
  subs INT UNSIGNED NULL,
  pico_viewers INT UNSIGNED NULL,
  minutos_ao_vivo INT UNSIGNED NULL,
  PRIMARY KEY (usuario_id, dia)
);
```

Alimentada pelo mesmo caminho da `contagens`, mais o cron da vitrine.

**Regra de exibição, decidida e fechada:** cresceu → mostra o crescimento
("+18% desde que você começou a usar"). Caiu ou empatou → mostra **só o número
atual**, sem porcentagem e sem elogio inventado, com uma linha de incentivo
("continue transmitindo", "constância é o que move esse número"). Elogio de
verdade só quando existe fato: recorde de espectadores, sequência de dias,
primeiro sub.

### 5.2 Painéis de mod pelo site

O que começou o projeto. Hoje o moderador recebe um link solto
(`docs/mods.html`). Falta: quem é mod de vários canais entrar no site e ver
todos num lugar, com a live embutida ao lado dos controles.

Já existe `convites_mod` com `usuario_id`, `token_hash` e permissões. Falta
amarrar o convite à conta Twitch do moderador em vez de só ao token, pra que
ele veja a lista ao entrar:

```sql
ALTER TABLE convites_mod ADD COLUMN mod_usuario_id INT UNSIGNED NULL;
```

Tela: lista dos canais onde ele é mod → escolhe um → player da Twitch embutido
ao lado dos controles que a permissão dele permite. Reaproveita o embed do
carrossel da vitrine e o `docs/painel.html`, que já tem os controles.

### 5.3 Verificação de streamer na Descoberta

Hoje a vitrine sorteia qualquer canal pequeno em português, com filtro de
conteúdo adulto e lista de banidos. Falta um selo de "conferido".

Mais simples do que parece: uma tabela `vitrine_aprovados(login, aprovado_em)`
e um botão no admin. Canal aprovado ganha selo e entra num sorteio separado; o
resto continua aparecendo sem selo. Não precisa de automação.

### 5.4 Menores

- `!split` pelo chat (o comando já tem estrutura; falta a ação que mexe no
  `spcor`)
- Guardião da live: `stream.online`/`offline` não pedem escopo nenhum
- Link assinado pro recomendador de jogos (`link_assinar` já existe)
- App local de música (SMTC do Windows) — resolve o Spotify sem cota
- Steam, TikTok, histórico de seguidores
- Suporte: formulário no site marcando quem é Pro

---

## 6. Trabalhando em várias sessões

O maior risco não é o código: é duas sessões mexendo no mesmo arquivo, ou uma
sessão nova refazendo uma descoberta que já custou caro.

**Uma sessão por área, não por tarefa.**

| Área | Arquivos | Dá pra paralelizar? |
|---|---|---|
| Painel (`docs/index.html`) | um arquivo gigante | **não** — uma por vez |
| Overlays (`docs/*.js`, `*.css`) | separados por tipo | sim, um tipo por sessão |
| Backend novo (artistas, métricas) | arquivos novos em `api/` | sim |
| Driver de luz | um arquivo em `api/luzes/` | sim — é a área mais isolada que existe aqui |
| Cobrança | `mercadopago.php`, `checkout.php`, webhook | uma por vez |

`docs/index.html` é o gargalo: quase toda funcionalidade encosta nele. Duas
sessões ali ao mesmo tempo dão conflito de merge num arquivo de 7 mil linhas,
que é o pior lugar possível pra resolver conflito.

**Como abrir uma sessão nova:**

1. `git pull`
2. Peça pra ela ler `ARQUITETURA.md` e rodar `node conferir.js`
3. Diga a ÁREA, não a lista de tarefas: "trabalhe só na parte de artistas,
   arquivos novos em `api/` e uma tela nova em `docs/`"
4. No fim: `node conferir.js`, `php -l` em cada PHP, commit e push

**O que dizer sempre:** que `api/` sobe à mão, que SQL é rodado à mão, e que
mexer em `.js`/`.css` exige subir o `?v=`.

**O que não precisa dizer:** os limites das plataformas — estão na seção 4.

**Ordem sugerida**, do mais isolado pro mais entrelaçado: estatísticas →
verificação → painéis de mod → guardião da live.

---

## 7. Antes de cobrar de alguém

1. Rodar as migrações pendentes (023 a 036)
2. Subir todo o `api/`
3. `'ligado' => true`, `'modo' => 'producao'`, credenciais de produção
4. Testar aprovado, recusado e Pix pendente
5. Cloudflare na frente de `api.zocahop.com`

E o que vale mais que a lista toda: **uma live de teste de trinta minutos**.
Nada aqui foi testado ao vivo.
