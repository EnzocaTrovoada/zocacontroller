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

---

## 5. O que falta

### 5.1 Artistas

Seção do site pra divulgar arte humana, sem IA.

Como o Instagram não deixa puxar por @, o conteúdo é cadastrado — o que dá
curadoria de graça: você escolhe qual arte aparece, com autorização explícita.

```sql
CREATE TABLE artistas (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nome VARCHAR(80) NOT NULL,
  arroba VARCHAR(64) NULL,
  link VARCHAR(200) NULL,
  bio VARCHAR(240) NULL,
  estado ENUM('pendente','aprovado','recusado') NOT NULL DEFAULT 'pendente',
  sem_ia TINYINT(1) NOT NULL DEFAULT 0,
  verificado_em DATETIME NULL,
  criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE artista_obras (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  artista_id INT UNSIGNED NOT NULL,
  arquivo VARCHAR(64) NOT NULL,
  titulo VARCHAR(120) NULL,
  ordem INT NOT NULL DEFAULT 0
);
```

Arquivos: `api/artistas.php` (inscrição pública, listagem, moderação) e uma
tela nova. Upload reaproveita as regras do `api/som.php`: nome sorteado por
nós, tipo decidido pelo conteúdo e não pela extensão, teto de tamanho,
servido por PHP com Content-Type fixo.

**Inscrição:** formulário público com nome, @, link e 1 a 5 imagens. Entra
como `pendente`; ninguém aparece sem aprovação.

**Verificação "sem IA":** não existe detector confiável — não prometa detecção
automática. O que dá é declaração mais evidência: o artista marca "feito à
mão" e envia **um arquivo de processo** (PSD, rascunho, timelapse) que só o
admin vê. Aprovado, ganha o selo. O selo é a palavra do Enzo, não a de um
algoritmo, e o texto do site precisa dizer isso.

### 5.2 Estatísticas da transmissão

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

### 5.3 Painéis de mod pelo site

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

### 5.4 Verificação de streamer na Descoberta

Hoje a vitrine sorteia qualquer canal pequeno em português, com filtro de
conteúdo adulto e lista de banidos. Falta um selo de "conferido".

Mais simples do que parece: uma tabela `vitrine_aprovados(login, aprovado_em)`
e um botão no admin. Canal aprovado ganha selo e entra num sorteio separado; o
resto continua aparecendo sem selo. Não precisa de automação.

### 5.5 Menores

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

**Ordem sugerida**, do mais isolado pro mais entrelaçado: artistas →
estatísticas → verificação → painéis de mod → guardião da live.

---

## 7. Antes de cobrar de alguém

1. Rodar as migrações pendentes (023 a 031)
2. Subir todo o `api/`
3. `'ligado' => true`, `'modo' => 'producao'`, credenciais de produção
4. Testar aprovado, recusado e Pix pendente
5. Cloudflare na frente de `api.zocahop.com`

E o que vale mais que a lista toda: **uma live de teste de trinta minutos**.
Nada aqui foi testado ao vivo.
