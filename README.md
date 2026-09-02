# ZocaController

Kit de transmissão em que o **chat controla o OBS** — e o streamer não instala nada.

A instalação inteira é copiar uma URL e colar num browser source. Sem programa, sem antivírus reclamando, sem atualizar na mão.

> **Estado:** em construção. O código roda e foi testado no navegador, mas ainda não foi validado contra um OBS real. Veja [O que falta provar](#o-que-falta-provar).

## Como funciona

O OBS já tem um navegador completo rodando dentro dele. Um browser source, então, é um agente na máquina do streamer: ele lê o chat da Twitch por IRC anônimo e conversa com o `obs-websocket` em `ws://localhost:4455`.

```
Twitch (chat)  ──wss──▶  ponte.html  ──ws://localhost:4455──▶  OBS
                        (browser source)
```

Nenhum servidor participa. É por isso que não precisa instalar nada — e é a diferença para ferramentas como o Streamer.bot, que são mais poderosas mas exigem um programa no PC e documentação em inglês.

## O que já faz

| Comando | Quem pode | O que acontece |
|---|---|---|
| `!panico` | mod | Muta microfone e áudio do sistema, corta para a cena de espera |
| `!voltar` | só o streamer | Sai do pânico e restaura a cena anterior |
| `!mute` | mod | Alterna o microfone |
| `!cena NOME` | mod | Troca para uma cena da lista permitida |
| `!replay` | mod | Salva o replay buffer do OBS |
| `!camera` | mod | Liga/desliga a troca automática por ausência |

**Aviso de microfone mudo.** A ponte lê os medidores de volume do OBS. Se entra som no microfone enquanto a fonte está muda, um aviso estoura na tela.

**Troca de cena quando você some da câmera.** A cada 1,5 s a ponte pede ao OBS um print pequeno da fonte de vídeo e procura um rosto nele com o BlazeFace. Sumiu por 12 s, troca para a cena de espera; voltou, volta. Não abre a câmera — funciona com qualquer câmera, inclusive as OBSBOT.

## Decisões que valem explicar

**`!mute` alterna, mas o pânico é de mão única.** No dia a dia o mod muta e desmuta à vontade — o caso mais comum não é vazar áudio, é a live inteira acontecer muda. Mas quando o `!panico` dispara, `!mute` para de desmutar: aquele mudo foi alguém dizendo *"não pode sair som daí agora"*, e só o streamer desfaz.

**Detector quebrado nunca troca de cena.** Se o modelo não carregar ou o print falhar, o módulo se desliga e avisa. Cortar a live de alguém no meio de uma frase por causa de um erro nosso é pior do que não ter o recurso.

**A permissão vem da Twitch, não do texto.** As tags do IRC (`mod=1`, `badges=broadcaster`, `user-id`) são emitidas pelo servidor da Twitch e não se forjam digitando. Ação sem permissão declarada não existe: o registro nega por padrão.

## Como usar

1. No OBS: **Ferramentas → Configurações do Servidor WebSocket** → ativar e copiar a senha.
2. Adicione um browser source de 1920×1080 apontando para:

```
https://mods.zocahop.com/ponte.html?canal=SEUCANAL&senha=SUASENHA
```

3. **Desmarque "Desligar a fonte quando não estiver visível"** — marcado, a ponte morre a cada troca de cena.

### Parâmetros

| Parâmetro | Padrão | Para quê |
|---|---|---|
| `canal` | — | Seu canal na Twitch (obrigatório) |
| `senha` | — | Senha do obs-websocket |
| `porta` | `4455` | Porta do obs-websocket |
| `mic` | detecta sozinho | Nome da fonte de microfone |
| `cenaPanico` | detecta sozinho | Cena para onde o `!panico` corta |
| `cenas` | todas | Lista de cenas que o `!cena` pode usar |
| `ausencia` | `0` | `1` liga a troca por ausência na câmera |
| `camera` | — | Nome da fonte de vídeo |
| `cenaAusente` | — | Cena de espera quando você some |
| `cenasComCamera` | — | Cenas em que a regra vale |
| `segundosAusente` | `12` | Segundos sem rosto antes de trocar |
| `hud` | `1` | `0` esconde o painel de status |
| `aviso` | `1` | `0` desliga o aviso de microfone mudo |

## O que falta provar

Duas perguntas que só um OBS de verdade responde:

1. **O CEF do OBS deixa uma página HTTPS abrir `ws://localhost:4455`?** Abra `spike-obs.html` como browser source: ele testa em cinco passos e diz. Todo o resto depende disso.
2. **O OBS manda medidor de volume de uma fonte que está muda?** Se não mandar, o aviso de microfone mudo não tem o que detectar. Mute o microfone, fale, e olhe a barrinha do HUD.

## Estrutura

```
docs/     overlays — é o que o GitHub Pages publica em mods.zocahop.com
api/      PHP do painel e da assinatura — roda na Hostinger, não aqui
sql/      schema do banco
```

Os segredos vivem em `api/config.php`, que não está versionado. Copie de `api/config.example.php`.

---

Feito por [Enzo](https://zocahop.com) para o canal [enzocatrovoada](https://twitch.tv/enzocatrovoada).
