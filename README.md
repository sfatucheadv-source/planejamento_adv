# Sidney Fatuche Advocacia · site e sistema de atendimento

Site de captação e sistema de processamento do **workflow de atendimento e resolução de financiamento de veículo**, da primeira conversa à comprovação do resultado para o cliente.

São três partes integradas, sobre o mesmo banco de dados:

| Parte | Endereço | Para quem |
|---|---|---|
| Site público e blog | `/`, `/blog`, `/atendimento`, `/sobre`, páginas por situação | Quem procura ajuda |
| Triagem conversacional | `/comecar` | Primeiro contato (etapa 1 do workflow) |
| Área do cliente | `/cliente` | Cliente acompanha o caso (opcional, nunca barreira) |
| Painel da equipe | `/painel` | Gestor, advogado, cálculos, negociação, financeiro e supervisão |

Tecnologia: **PHP 8.1+ e SQLite**, sem dependências externas. Funciona na hospedagem compartilhada da Hostinger: basta enviar os arquivos.

---

## Como o workflow virou sistema

| Seção do documento | Onde está no sistema |
|---|---|
| 1 · Objetivo e regra de encerramento | O caso só fecha com lista obrigatória, evidência de cada solução e categoria de desfecho (aba *Conferência e encerramento*) |
| 2 · Jornada completa | 14 etapas internas com caminhos permitidos, inclusive retorno e negociação durante o processo (`app/workflow.php`) |
| 3 · Cinco situações para o cliente | Barra de progresso e cartão de acompanhamento na área do cliente |
| 4 · Responsáveis e metas internas | Funções por usuário, gestor/advogado/substituto por caso, alertas automáticos de cada meta no painel *Hoje* |
| 5 · Etapa 1 Acolhimento | Triagem do site (pergunta de urgência primeiro, resumo em uma frase confirmado pelo cliente), roteiro em *Novo atendimento*, registro de primeiro contato, objetivo e conflito de interesses |
| 6 · Etapa 2 Urgência | Classificação pelo advogado (tabela do documento), orientação escrita publicada ao cliente, prazos jurídicos com executor e substituto |
| 7 · Etapa 3 Documentos | Lista única (recebido / falta / vamos ajudar / não possui), upload pelo cliente, conferência, pedido ao banco com cobrança automática, lembretes |
| 8 · Etapa 4 Auditoria | Formulário da operação, série 25471 do BCB, calculadora (taxa anual equivalente, taxa implícita, reprodução da parcela, razão contratada ÷ média, saldos), três cenários, validação do advogado |
| 9 e 10 · Etapas 5 e 6 | Tabela de caminhos, recomendação, decisão do cliente por escrito (portal ou registro de ligação), contratação e escopo |
| 11 e 12 · Etapas 7 e 8 | Limites autorizados, ofertas e rodadas, comunicação ao cliente com pedido de autorização, bloqueio de aceite sem autorização, calendário de parcelas |
| 13 a 16 · Etapas 9 a 12 | Processo, tutela, audiência, resultado da decisão com próxima ação, recurso, apuração e cumprimento |
| 17 e 18 · Etapas 13 e 14 | Itens de solução com evidência, SCR, teste prático, lista de fechamento, desfecho, contato de 30 dias, avaliação de 1 a 5 |
| 19 · Exceções | Registro com “quem assume” e providência; exceções urgentes marcam o caso |
| 20 · Registro e automações | Registro único por caso, histórico, auditoria, tarefas com responsável/prazo/dependência, bloqueio de avanço sem cálculo validado, decisão documentada ou evidência final |
| 21 · Mensagens | Modelos preenchidos com os dados do caso, conferência de fatos obrigatória, abertura no WhatsApp do cliente e publicação na área do cliente |
| 22 · Indicadores | Página *Indicadores* com todos os indicadores da tabela |

### Modo guiado (fila de trabalho)

Quem entra no painel cai em **Minha fila** (`/painel/fila`). Ali aparecem só os casos em que a pessoa tem algo a fazer agora, conforme as funções dela e a atribuição do caso (gestor e advogado seguem quem está no caso; cálculos, negociação e financeiro, quem tiver a função). Casos que dependem do cliente, do banco ou da Justiça ficam em “Aguardando” e voltam sozinhos quando a resposta é registrada.

Ao clicar, abre **Executar** (`/painel/executar/{id}`): a etapa vira uma sequência de passos com *o que fazer*, *o que dizer ao cliente* (mensagem pronta para o WhatsApp) e os campos daquele passo. Ao salvar, o fluxo segue para o próximo passo. No fim da etapa, a tela **Passar o caso adiante** sugere a próxima etapa e mostra o que ainda impede o avanço. Quando a parte da pessoa termina, ela volta para a fila e o caso aparece na fila de quem continua. O supervisor pode executar qualquer passo para cobrir ausências.

Os passos estão em `app/guide.php`; a ficha completa por abas continua disponível em *Ficha completa*.

As metas internas aparecem no site como metas, nunca como garantia, e podem ser ocultadas em `app/config.php` (`show_service_goals`).

---

## Rodar no computador

```bash
php scripts/demo.php                      # cria storage/demo.sqlite com casos FICTÍCIOS
FATUCHE_DB=storage/demo.sqlite php -S 127.0.0.1:8080 -t public public/index.php
```

Abra `http://127.0.0.1:8080`. Painel: `sidney@demo.local` / `demonstracao2026`. O script mostra o protocolo e a senha de um cliente de demonstração.

Teste de ponta a ponta (percorre as 14 etapas pela interface, do formulário ao encerramento):

```bash
php tests/fluxo_completo.php
```

## Publicar na Hostinger

Sem terminal, só pelo hPanel. O repositório é público, então não precisa de chave de acesso.

1. **PHP:** em *Avançado → Configuração do PHP*, escolha **PHP 8.1 ou mais recente**. As extensões `pdo_sqlite`, `mbstring` e `fileinfo` costumam vir ativas; se faltar alguma, o próprio site avisa qual ativar.
2. **Pasta dos dados (antes de instalar):** no *Gerenciador de Arquivos*, crie uma pasta chamada `fatuche-storage` **ao lado** de `public_html` (no mesmo nível, não dentro dele). O site detecta essa pasta sozinho e guarda nela o banco, as sessões e os documentos enviados. Assim nenhuma atualização pelo Git encosta nos dados. Sem essa pasta, o site usa `storage/` dentro do projeto, e a página de instalação avisa.
3. **Código:** em *Avançado → Git*:
   - Se aparecer **Conectar com GitHub**: entre na conta dona de `sfatucheadv-source/planejamento_adv`, autorize o app da Hostinger e escolha o repositório, a branch `claude/epic-ride-hbdg7z` e a pasta `public_html`. Clique em **Implantar**. Cada atualização no GitHub passa a ser publicada automaticamente.
   - Se aparecer o formulário antigo: Repositório `https://github.com/sfatucheadv-source/planejamento_adv.git`, Branch `claude/epic-ride-hbdg7z`, Diretório vazio (= `public_html`). Clique em **Implantar**; para atualizar, clique de novo (ou cadastre a URL de *Implantação automática* em GitHub → Settings → Webhooks).

   Em ambos os casos `public_html` precisa estar vazia na primeira implantação: mova ou apague o site antigo antes (isso tira do ar o que estiver hoje no domínio).
   O `.htaccess` da raiz serve o site a partir de `public/`, bloqueia `app/`, `storage/`, `scripts/` e `tests/`, deixa passar `/.well-known/` (validação do SSL) e não interfere em subdomínios que tenham pasta dentro de `public_html`.
   *Alternativa sem Git:* baixe o ZIP do GitHub (*Code → Download ZIP*), envie pelo *Gerenciador de Arquivos* para `public_html` e extraia, deixando os arquivos direto em `public_html` (não dentro de uma subpasta).
4. **SSL:** em *Segurança → SSL*, ative o certificado do domínio.
5. **Primeiro acesso:** abra `https://seu-dominio/painel/instalar`. A página pede um código que fica no arquivo `codigo-instalacao.txt`, dentro de `fatuche-storage` (abra pelo *Gerenciador de Arquivos*). Informe o código, seu e-mail e uma senha. O arquivo é apagado e a página de instalação se desativa sozinha.
   *Com terminal (SSH):* `php scripts/criar-usuario.php "Sidney Fatuche" sidney@fatuche.adv.br` também cria o acesso.
6. **Equipe:** no painel, em *Equipe*, cadastre os colegas e as funções de cada um.
7. **Backup:** baixe periodicamente a pasta `fatuche-storage` (banco `fatuche.sqlite` e documentos em `uploads/`). Ela não vai para o Git.

Ajustes sem editar o código: copie `app/config.local.example.php` para `app/config.local.php` (esse arquivo não vai para o Git; guarde uma cópia).

---

## Fotos reais

O site já está preparado para as fotos do escritório. Basta colocar os arquivos em `public/assets/fotos/` com estes nomes (JPG, WEBP ou PNG); enquanto não existirem, o site usa um layout alternativo sem imagem quebrada:

| Arquivo | Onde aparece | Formato sugerido |
|---|---|---|
| `sidney-retrato.jpg` | Início, página *Quem atende*, autor do blog | vertical 4:5, ~1000×1250 |
| `sidney-atendimento.jpg` | Seção *Como funciona* do início | horizontal 16:10 |
| `escritorio.jpg` ou `sidney-trabalhando.jpg` | Página *Quem atende* | horizontal |
| `instagram-1.jpg` … `instagram-6.jpg` | Faixa do Instagram (início e *Quem atende*) | quadradas |

## Blog

Os 16 estudos estão em `app/content/posts.json` (título, texto, fontes oficiais, artigos relacionados, texto de compartilhamento). As datas mostradas são de verificação das fontes; revise cada estudo antes de divulgar, como prevê a série editorial.

## Estrutura

```
app/            código (não público): workflow, regras, rotas, telas
  workflow.php  etapas, condições de avanço, metas, documentos, exceções, desfechos
  messages.php  modelos de mensagem da seção 21
  routes/       site, área do cliente e painel
  views/        páginas
public/         raiz web: index.php, CSS, JS, imagens, fotos
scripts/        criar-usuario.php, demo.php
storage/        banco SQLite, sessões e documentos enviados (não versionado)
tests/          teste de ponta a ponta
```

## Ainda não incluído

- Envio automático de WhatsApp ou e-mail. O painel prepara a mensagem, abre o WhatsApp com o texto e registra o envio; a confirmação de envio continua humana.
- Agenda e cobrança online (Asaas, Google Agenda). O fluxo de contratação está no painel; integrações de pagamento precisam de credenciais e teste próprio.
- Leitura automática de documentos por IA (prevista como etapa futura no PRD).
