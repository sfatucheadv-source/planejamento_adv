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
2. **Código:** em *Avançado → Git*, crie um repositório com:
   - Repositório: `https://github.com/sfatucheadv-source/planejamento_adv.git`
   - Branch: `claude/epic-ride-hbdg7z`
   - Diretório: deixe vazio para instalar em `public_html` (a pasta precisa estar vazia; mova ou apague o site antigo antes).

   Clique em **Implantar**. Para atualizar no futuro, basta clicar em *Implantar* de novo (ou ativar a implantação automática).
   O `.htaccess` da raiz serve o site a partir de `public/` e bloqueia `app/`, `storage/`, `scripts/` e `tests/`.
   *Alternativa sem Git:* baixe o ZIP do GitHub (*Code → Download ZIP*), envie pelo *Gerenciador de Arquivos* para `public_html` e extraia, deixando os arquivos direto em `public_html` (não dentro de uma subpasta).
3. **SSL:** em *Segurança → SSL*, ative o certificado do domínio.
4. **Primeiro acesso:** abra `https://seu-dominio/painel/instalar`. A página pede um código que fica no arquivo `storage/codigo-instalacao.txt` (abra pelo *Gerenciador de Arquivos*). Informe o código, seu e-mail e uma senha. O arquivo é apagado e a página de instalação se desativa sozinha.
   *Com terminal (SSH):* `php scripts/criar-usuario.php "Sidney Fatuche" sidney@fatuche.adv.br` também cria o acesso.
5. **Equipe:** no painel, em *Equipe*, cadastre os colegas e as funções de cada um.
6. **Backup:** guarde periodicamente a pasta `storage/` (banco `fatuche.sqlite` e documentos em `storage/uploads/`). Ela não vai para o Git e não é apagada pelas atualizações.

Ajustes sem editar o código: copie `app/config.local.example.php` para `app/config.local.php`.

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
