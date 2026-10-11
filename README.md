# Sidney Fatuche Advocacia · site e sistema de atendimento

Site de captação e sistema de processamento do **workflow de atendimento e resolução de financiamento de veículo**, da primeira conversa à comprovação do resultado para o cliente.

São três partes integradas, sobre o mesmo banco de dados:

| Parte | Endereço | Para quem |
|---|---|---|
| Site público e blog | `/`, `/blog`, `/atendimento`, `/sobre`, páginas por situação | Quem procura ajuda |
| Assistente virtual (atendimento inicial) | `/comecar` | Primeiro contato: coleta retomável por chatbot |
| Área do cliente | `/cliente` | Início, Conversa, Documentos, Reunião e proposta |
| Painel | `/painel` | Sidney (operador único) e, na execução, as ferramentas das 14 etapas |

Tecnologia: **PHP 8.1+ e SQLite**, sem dependências externas. Funciona na hospedagem compartilhada da Hostinger: basta enviar os arquivos.

---

## Atendimento inicial (reforma v1.0 — operador único)

Implementa o *Plano de reforma do site* (10/10/2026). Todo novo atendimento pertence ao operador configurado (Sidney). O assistente coleta; Sidney confere, analisa, prepara a proposta e as minutas, faz a reunião, revisa o kit e registra a decisão.

- **Cinco situações** (`initial_status`): `collecting` Em coleta · `analysis` Em análise · `meeting_scheduled` Reunião agendada · `awaiting_decision` Aguardando decisão · `completed` Concluído (atendimento inicial, não a dívida).
- **Assistente** (`app/inicial/bot.php`): roteiro determinístico B02–B10, sem IA. Cada resposta grava campos estruturados (`case_fields`, com origem e estado “não sei/depois/não se aplica”) e a conversa (`chat_messages`). A pergunta da vez é calculada pelo que falta: fechar a página e voltar retoma do ponto certo. “Corrigir resposta” volta a um item sem reiniciar. Sidney pode **assumir a conversa** e **retomar a coleta automática**.
- **Ficha única** (`/painel/atendimento/{id}`): cabeçalho com situação, urgência, próxima ação e retorno; blocos 1 Dados e contexto · 2 Documentos · 3 Análise e proposta · 4 Reunião · 5 Kit e decisão; conversa ao lado. Salvar mantém a posição.
- **Travas no servidor** (`app/inicial/fluxo.php`): liberar análise só com suficiência e sem pendência essencial (ou atuação urgente delimitada); confirmar reunião só com preparação validada e dados do canal (URL do Meet validada; telefone; WhatsApp; chat do site), com checagem de conflito; registrar reunião exige resumo e proposta apresentada ou motivo do adiamento; finalizar exige decisão, kit pronto e contratação formalizada quando houver aceite. Aceite sem assinatura nunca vira “contratado”.
- **Kit** (`app/inicial/kit.php`): proposta, honorários, procuração, hipossuficiência (só com pedido de gratuidade), parecer de cálculos e ficha para a inicial. Modelos editáveis em *Modelos de documentos*, com versão e aprovação de Sidney; variável ausente ou `[[COMPLETAR: …]]` bloqueia revisão/publicação. Cada minuta guarda a fotografia dos dados; mudança posterior mostra “há alterações não revisadas”. Versões publicadas e assinadas nunca são reescritas.
- **Acesso por atendimento**: conta do cliente separada do caso (`client_accounts`). Contratados continuam acessíveis; encerrado sem contratação fica disponível por 72 horas a partir da comunicação registrada, negado pela data em cada requisição (portal, chat e download). Retorno: o cliente pede, Sidney abre novo atendimento vinculado, sem revelar o histórico expirado.
- **Execução**: ao formalizar, o caso segue para Negociação ou Preparação judicial nas ferramentas existentes (`Execução`, `Casos contratados`). O operador executa todas as funções; não há matriz de papéis.

### Variáveis dos documentos

Em *Variáveis dos documentos* (`/painel/variaveis`, código em `app/inicial/variaveis.php`) Sidney:

- preenche os dados do escritório usados nos modelos: endereço, telefone, e-mail, prazo de resposta, orientação para urgências, tipo e chave PIX (a chave é conferida contra o tipo);
- define padrões para `{{documento.foro}}` e `{{proposta.prazo_repasse}}`, que cada atendimento pode trocar na ficha (o foro é independente de `{{data.cidade}}`);
- cria variáveis personalizadas no formato `grupo.nome`, do escritório (um valor) ou do atendimento (preenchida na ficha, bloco 3, com valor padrão opcional). O nome não muda depois de criado, e uma variável usada em algum modelo não pode ser excluída;
- consulta o catálogo de todas as variáveis, de onde vem cada uma e em que modelos é usada.

Campos novos da ficha: veículo (marca, modelo, ano, placa e Renavam com dígito verificador, lidos também pela IA no contrato e no CRLV), situação do financiamento (adimplente, inadimplente ou quitado; sem escolha de Sidney, vem do relato do cliente), processos e notificações, foro e duas testemunhas opcionais (use `{{testemunha1.nome|____}}` no modelo). Teste: `php tests/variaveis.php`.

### Documentos no padrão do escritório (timbrado)

Procuração, declaração de hipossuficiência, contrato de honorários, proposta e parecer saem no timbrado Fatuche, com a tipografia do formatador de peças (Lora no texto, Poppins nos títulos, A4, margens 32/28/30/30 mm, recuo de 12,5 mm e 5,1 mm entre parágrafos). Código em `app/inicial/diagramacao.php`; modelos em `app/inicial/modelos/*.txt`; timbrado, CSS e motor de paginação do formatador em `public/assets/formatador/`.

- **Ver no timbrado / PDF**: folhas A4 paginadas no navegador, com o timbrado em todas as folhas; imprimir com margens “Nenhuma” e gráficos de fundo ligados.
- **Baixar .docx**: gerado no servidor, com o logotipo e o rodapé nas coordenadas do papel, fólio e a mesma tipografia.
- Os modelos usam uma marcação leve (`# título`, `## I. seção`, `### cláusula`, `@qualificacao`, `@dados`, `@assinatura`, `@quebra`, `@compacto`, `**negrito**`, `^^parte^^`) e `{{flex:inscrito/inscrita}}` para a concordância com o gênero do cliente. A ajuda está em *Modelos de documentos*, com prévia no timbrado.
- Padrões do escritório que valem quando a ficha está em branco (poderes e finalidade da procuração, percentual de honorários, foro, prazo de repasse) ficam em *Variáveis dos documentos* e podem citar outras variáveis.
- Teste: `php tests/documentos.php [pasta]` (preenchimento, .docx, paginação no navegador e conversão no LibreOffice; grava os arquivos e imagens de cada folha na pasta).

### Propostas (condições negociadas)

Menu *Propostas* (logo abaixo de *Modelos de documentos*): escolha o atendimento e registre honorários fixos (valor fechado), honorários por atividade, êxito em percentual com a base de cálculo, valores cobrados à parte (taxas de serviço, emolumentos, custas, perícia, impostos), repasse de deslocamento (por comprovante ou por km) e de despesas, formas de pagamento (PIX, boleto, cartões, transferência, depósito, link, dinheiro, cheque), pagamento à vista ou parcelado — próprio, com juros simples de 1% a.m. por mês até cada vencimento e INPC na data do pagamento, ou no cartão, com a taxa da operadora por conta do cliente. Código em `app/inicial/propostas.php`.

A proposta prevalece sobre os campos de honorários, êxito, pagamento e despesas da ficha. Cada escolha liga ou desliga cláusulas: os modelos usam `@se chave` / `@senao` / `@fimse`, e a numeração (`### Cláusula # — … {#chave}`, `**Parágrafo #.**`, `{{clausula:chave}}`) é refeita a cada geração, com “Parágrafo único” quando sobra um só. Teste: `php tests/propostas.php`.

### Assistente com IA (OpenRouter ou Google Gemini)

Opcional. Em **Configurações › Inteligência artificial**: ligue o uso de IA e escolha o **fornecedor**:

- **OpenRouter**: chave `sk-or-…` e modelo no formato `fornecedor/modelo` (padrão `google/gemini-2.5-flash`).
- **Google Gemini (API direta)**: chave do Google AI Studio (o botão “Copiar chave”) e modelo `gemini-2.5-flash`, `gemini-2.5-flash-lite` ou `gemini-2.5-pro`. Usa a API `generateContent` com PDF e foto enviados como `inline_data`. Para documentos de clientes, use um projeto com faturamento ativo (no plano gratuito o Google pode usar o conteúdo para melhorar os produtos).

Cada fornecedor guarda sua própria chave e seu modelo; trocar o fornecedor não apaga a chave do outro. As chaves ficam só no servidor (tabela `settings`) e a tela mostra apenas o final delas; “Testar a conexão” faz uma chamada mínima ao fornecedor escolhido. “Carregar a lista completa” busca os modelos disponíveis de cada fornecedor.

Com a IA ligada:
- o assistente pede os documentos logo depois do objetivo (contrato, identidade, comprovante de residência, extrato) e a IA lê os dados; o que estiver nos documentos não é perguntado ao cliente;
- os valores lidos entram na ficha com a marca “IA · conferir” (Sidney confirma por grupo ou corrige); nada sobrescreve o que o cliente, Sidney ou outro documento já informou: a diferença vira aviso no histórico;
- mensagens livres do cliente recebem resposta curta da IA, sem orientação jurídica, e os dados ditos ali vão para a ficha (só campos do cliente, validados);
- toda chamada fica em `llm_log` (modelo, erro, tokens e custo); o resumo dos 30 dias aparece em Configurações.

Sem chave, com a IA desligada ou se a chamada falhar, o roteiro determinístico continua funcionando. O código está em `app/inicial/ia.php`; o teste `php tests/assistente_ia.php` usa um simulador local das duas APIs (`tests/mock_openrouter.php`).

### Configuração e implantação

1. Faça backup do banco (com WAL ativo, copie `fatuche.sqlite`, `-wal` e `-shm` juntos, com o site parado, ou use `sqlite3 fatuche.sqlite ".backup copia.sqlite"`).
2. Publique o código: a migração 2 é aditiva e roda sozinha no primeiro acesso (nenhuma coluna antiga é removida; senhas do portal viram contas ligadas ao próprio caso).
3. Em **Configurações**: escolha o operador, informe o endereço do escritório e mantenha a entrada pelo assistente. Sem operador, o site continua com o formulário antigo.
4. Em **Modelos de documentos**: revise e aprove cada modelo (cláusulas, poderes e texto da declaração são de Sidney).
5. Em **Configurações › Migração**: confira e migre os pré-contratuais. Contratados, em processo e encerrados permanecem na execução. `php scripts/relatorio-migracao.php` gera o mesmo relatório pelo terminal.
6. **Reversão**: em Configurações, “Formulário antigo” desliga novas entradas pelo assistente e conserva tudo o que já foi registrado.

Homologação automatizada: `php tests/atendimento_inicial.php` (cenários T01–T26 e S01–S13 da seção 16).

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
php tests/fluxo_completo.php        # execução e modo de equipe (14 etapas)
php tests/atendimento_inicial.php   # atendimento inicial (seção 16 do plano de reforma)
php tests/assistente_ia.php         # assistente com IA (simulador local do OpenRouter e do Gemini)
php tests/variaveis.php             # variáveis dos documentos e campos do contrato
php tests/documentos.php            # documentos no timbrado (.docx e HTML paginado)
php tests/propostas.php             # propostas, parcelamento e cláusulas condicionais
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
  inicial/      atendimento inicial: campos, assistente, fluxo e travas, kit, migração
  routes/       site, área do cliente e painel (inicial.php e inicial_painel.php para o fluxo novo)
  views/        páginas
public/         raiz web: index.php, CSS, JS, imagens, fotos
scripts/        criar-usuario.php, demo.php, relatorio-migracao.php
storage/        banco SQLite, sessões e documentos enviados (não versionado)
tests/          teste de ponta a ponta
```

## Ainda não incluído

- Envio automático de WhatsApp ou e-mail. O painel prepara a mensagem, abre o WhatsApp com o texto e registra o envio; a confirmação de envio continua humana.
- Agenda e cobrança online (Asaas, Google Agenda). O fluxo de contratação está no painel; integrações de pagamento precisam de credenciais e teste próprio.
- Leitura automática de documentos por IA (prevista como etapa futura no PRD).
- Integrações opcionais do plano de reforma: criação automática de link do Meet ou agenda, envio automático por WhatsApp, assinatura eletrônica integrada, e geração de DOCX. Hoje o link do Meet é criado por Sidney, o instrumento assinado é anexado e os documentos saem em texto selecionável para imprimir/PDF ou baixar em HTML (abre no Word).
