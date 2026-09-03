# Prompt para pensar funcionalidades novas

Cole o bloco abaixo em outra conversa, e depois escreva a sua ideia embaixo.
O que voltar de lá você me traz, e eu implemento.

Serve para a parte criativa: pensar o que a funcionalidade deve fazer, como
ela se comporta, o que aparece na tela, o que dá errado. Não é para escrever
código — código é a minha parte, e código escrito sem ver o repositório
costuma vir errado.

---

## O bloco (copie daqui até o fim)

Você vai me ajudar a desenhar uma funcionalidade nova para o **ZocaController**,
um kit de transmissão brasileiro. Não escreva código: escreva a especificação.
Quem implementa é outra pessoa, que tem o repositório na mão.

### O que é o projeto

Um kit onde o chat, os moderadores e o próprio streamer controlam o OBS. A
graça dele é não precisar instalar nada: a instalação inteira é copiar uma URL
e colar no OBS.

### Como ele é montado (isso é limite, não sugestão)

- **ponte.html** — roda como *browser source* dentro do OBS. Lê o chat da
  Twitch por IRC anônimo (sem token, sem bot) e comanda o `obs-websocket` em
  `ws://localhost:4455`. É a única peça que alcança o OBS.
- **painel.html** — uma doca dentro da janela do OBS. Fica na tela do streamer
  e nunca aparece na transmissão.
- **mods.html** — página que os moderadores abrem no celular. **Não alcança o
  OBS**: ela só lê e escreve num relé.
- **convites.html**, **criar.html**, **editor de overlay** — páginas de
  configuração.
- **api/*.php** — o relé, em hospedagem **compartilhada** (PHP 8.3, MySQL,
  LiteSpeed). Guarda o token da Twitch do streamer e fala com a API deles.

Regras duras do ambiente:

- Páginas estáticas ficam no GitHub Pages (`mods.zocahop.com`); o PHP fica na
  Hostinger (`api.zocahop.com`). São domínios diferentes.
- **Não existe** Node, servidor WebSocket, processo em segundo plano, nem root.
  O servidor nunca inicia conversa: a ponte é quem pergunta.
- O browser source **não** alcança a rede local (só `localhost`). Falar com
  lâmpada, celular ou aparelho na mesma casa está fora sem instalar programa.
- O OBS roda uma versão antiga do Chromium. Não conte com recurso novo de
  navegador.

### Regras de produto (valem como critério, não como enfeite)

1. **O público é leigo em computação.** Nada de jargão na tela. Se o sistema
   pode adivinhar, ele adivinha em vez de perguntar. Passo que exige entender
   URL, porta ou arquivo oculto é passo que derruba gente.
2. **Nunca quebrar a live por erro nosso.** Se uma peça falhar, ela se desliga
   e avisa — nunca corta o áudio nem troca a cena por engano.
3. **O lado seguro é dos mods, o irreversível é do dono.** Exemplos que já
   existem: mod entra no pânico, só o streamer sai; mod abre e cancela palpite,
   só o dono paga.
4. **Assuma que todo link vaza.** Ele vai aparecer em print, em VOD e em
   tutorial. Nada que seja segredo pode viajar num link que aparece na tela.
5. **Erro tem que dizer o que fazer em seguida**, em português, sem código.

### O que já existe (não reinvente)

- Comandos de chat com permissão por cargo, tempo de espera e negar por padrão
- Pânico: muta microfone e sistema, corta para a cena de espera
- Mixer de áudio, olhinho das fontes, troca de cena
- Troca de cena automática quando o streamer some da câmera
- Aviso de microfone mudo
- Palpites com pontos de canal, título e categoria da live
- Marcadores de momento (guardam o tempo decorrido da live, que bate com o VOD)
- Convites de moderador, um link por pessoa, revogável
- Um motor de overlay com aparência configurável (fonte, cores, borda,
  contorno) e link fixo que atualiza sozinho quando a config muda no site

### O que eu quero de você

Escreva a especificação da funcionalidade que eu descrever, cobrindo:

1. **Para que serve, em uma frase.** Se não couber em uma frase, a ideia ainda
   não está pronta.
2. **A cena real.** Quem está fazendo o quê, em que momento da live, e por que
   hoje isso incomoda.
3. **O que aparece na tela** — na transmissão, no painel do streamer e no
   celular do mod. Diga o que aparece em cada um, ou diga que não aparece.
4. **Quem pode acionar** — chat comum, mod, ou só o dono. Justifique pela
   regra 3.
5. **O caminho feliz**, passo a passo, do gatilho até o resultado.
6. **O que dá errado**, e o que o sistema faz em cada caso. Inclua: OBS
   fechado, internet caindo, comando repetido, duas pessoas ao mesmo tempo,
   valor absurdo, e a pessoa fazendo na ordem errada.
7. **As palavras da interface** — os textos de botão, de estado e de erro,
   escritos como devem aparecer. Em português, sem jargão.
8. **O que fica de fora da primeira versão**, e por quê.

### Como responder

- Português do Brasil, direto, sem enrolação.
- **Nada de código, nada de nome de função, nada de estrutura de banco.**
- Se a ideia esbarrar num limite do ambiente lá em cima, **diga isso** em vez
  de contornar com algo que não funciona. Uma ideia recusada com motivo vale
  mais que uma ideia aceita que não roda.
- Se faltar informação para decidir, faça a pergunta em vez de inventar.
- Se a ideia parecer ruim, diga que parece ruim e proponha a versão que presta.

**A funcionalidade que eu quero é:**

<!-- escreva sua ideia aqui -->
