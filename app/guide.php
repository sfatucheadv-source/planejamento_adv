<?php
declare(strict_types=1);

/**
 * Modo guiado (execução passo a passo).
 *
 * Cada etapa do workflow vira uma sequência de passos com: quem faz, o que
 * fazer, o que dizer ao cliente, os campos daquele passo e a condição que o
 * dá por concluído. A fila de cada pessoa mostra os casos em que ela tem um
 * passo disponível agora; os que dependem do cliente, do banco ou da Justiça
 * ficam em "aguardando".
 *
 * Chaves de um passo:
 *   title    frase curta no imperativo
 *   roles    funções que executam o passo
 *   how      instruções (lista de frases)
 *   say      modelo de mensagem (MESSAGE_TEMPLATES) ou texto para falar ao cliente
 *   form     bloco de campos (app/views/panel/guia/blocos.php)
 *   choices  botões de conclusão; cada um marca itens do checklist e pode ter efeito
 *   done     fn(array $c): bool
 *   when     fn(array $c): bool — o passo só existe quando verdadeiro
 *   after    passos que precisam estar concluídos antes
 *   wait     fn(array $c): ?string — motivo de espera externa (cliente, banco, Justiça)
 *   optional não impede o avanço da etapa
 */

function ck(array $c, string $stage, string $item): bool
{
    // Cada ação termina em redirecionamento, então o cache vale só para uma leitura.
    static $cache = [];
    $key = $c['id'] . '|' . $stage;
    $cache[$key] ??= case_checks((int) $c['id'], $stage);
    return !empty($cache[$key][$item]['done']);
}

function open_decision(array $c, string $kind): ?array
{
    return one('SELECT * FROM decisions WHERE case_id = ? AND kind = ? ORDER BY requested_at DESC LIMIT 1', [$c['id'], $kind]);
}

function guide(string $stage): array
{
    $steps = [
        /* 1 · Acolhimento */
        'acolhimento' => [
            'contato' => [
                'title' => 'Fazer o primeiro contato com o cliente',
                'roles' => ['gestor'],
                'how' => [
                    'Apresente-se pelo nome, diga que vai acompanhar o caso e pergunte como a pessoa prefere conversar.',
                    'A primeira pergunta é sempre sobre urgência: notificação, documento de cartório ou da Justiça, ou veículo apreendido.',
                    'Meta: até 1 hora útil depois que o pedido chegou.',
                ],
                'say' => 'primeiro_contato',
                'form' => 'primeiro_contato',
                'done' => fn($c) => (bool) $c['first_human_contact_at'],
            ],
            'entender' => [
                'title' => 'Conferir o que o cliente contou',
                'roles' => ['gestor'],
                'how' => [
                    'Confira com o cliente os dados abaixo: banco, situação do contrato, parcela, atraso, cidade e se ele tem o contrato em mãos.',
                    'Pergunte se existe outro processo ou outro advogado atuando.',
                    'Pergunte: “O que você precisa resolver primeiro?” e “Qual pagamento caberia no seu orçamento sem faltar para o essencial?”. Valores são estimativas.',
                    'Se algum dado estiver errado, corrija no quadro “Corrigir dados”.',
                ],
                'form' => 'fatos',
                'choices' => [
                    ['key' => 'ok', 'label' => 'Conferi os dados com o cliente', 'checks' => [['acolhimento', 'pergunta_urgencia'], ['acolhimento', 'minimo'], ['acolhimento', 'prioridade']]],
                ],
                'after' => ['contato'],
                'done' => fn($c) => ck($c, 'acolhimento', 'minimo'),
            ],
            'objetivo' => [
                'title' => 'Registrar o objetivo e confirmar o entendimento',
                'roles' => ['gestor'],
                'how' => [
                    'Escreva o objetivo concreto do cliente, como ele diria. Ex.: “manter o carro pagando até R$ 900 por mês”.',
                    'Diga como o resultado será comprovado no final. Ex.: “novo boleto no valor combinado”.',
                    'Repita o entendimento em uma frase e peça a confirmação do cliente.',
                ],
                'say' => 'acolhimento_resumo',
                'form' => 'objetivo',
                'after' => ['contato'],
                'done' => fn($c) => trim((string) $c['objective']) !== '' && (bool) $c['summary_confirmed'],
            ],
            'responsaveis' => [
                'title' => 'Definir os responsáveis pelo caso',
                'roles' => ['gestor'],
                'how' => [
                    'Todo caso precisa de um gestor (contato do cliente), um advogado responsável e um substituto para ausências.',
                ],
                'form' => 'equipe',
                'done' => fn($c) => $c['gestor_id'] && $c['advogado_id'],
            ],
            'conflito' => [
                'title' => 'Verificar conflito de interesses',
                'roles' => ['gestor'],
                'how' => [
                    'Antes de receber muitos documentos, confira se o escritório já atende a outra parte ou tem algum impedimento.',
                    'Se houver conflito, explique ao cliente o limite da atuação e indique outro caminho.',
                ],
                'form' => 'conflito',
                'done' => fn($c) => (bool) case_data($c, 'conflict'),
            ],
            'urgencia' => [
                'title' => 'Encaminhar urgências ao advogado',
                'roles' => ['gestor'],
                'how' => [
                    'Há urgência quando o veículo foi apreendido, chegou documento da Justiça ou de cartório, ou o banco notificou. Se o cliente não sabe dizer, trate como urgente.',
                    'Urgência vai para o advogado imediatamente. Avaliação inicial em até 2 horas úteis.',
                    'Nunca oriente o cliente a parar de pagar ou pagar menos por conta própria.',
                ],
                'form' => 'urgencia_info',
                'choices' => [
                    ['key' => 'urgente', 'label' => 'Há urgência: encaminhar ao advogado agora', 'checks' => [['acolhimento', 'urgencias_encaminhadas']], 'effect' => 'urgente', 'danger' => true],
                    ['key' => 'sem', 'label' => 'Não há notificação, prazo ou apreensão', 'checks' => [['acolhimento', 'urgencias_encaminhadas']], 'effect' => 'sem_urgencia'],
                ],
                'after' => ['entender'],
                'done' => fn($c) => ck($c, 'acolhimento', 'urgencias_encaminhadas'),
            ],
            'proximo' => [
                'title' => 'Explicar o próximo passo ao cliente',
                'roles' => ['gestor'],
                'how' => [
                    'Explique o que acontece agora: se há urgência, o advogado avalia primeiro; depois reunimos os documentos e o advogado apresenta as opções.',
                    'Combine a data do próximo retorno e pergunte se ficou claro.',
                ],
                'say' => stage('acolhimento')['message'],
                'choices' => [
                    ['key' => 'ok', 'label' => 'O cliente entendeu o próximo passo', 'checks' => [['acolhimento', 'cliente_compreende'], ['acolhimento', 'resumo']]],
                ],
                'after' => ['objetivo'],
                'done' => fn($c) => ck($c, 'acolhimento', 'cliente_compreende'),
            ],
        ],

        /* 2 · Urgência */
        'urgencia' => [
            'classificar' => [
                'title' => 'Classificar o risco',
                'roles' => ['advogado'],
                'how' => [
                    'Classifique com base em documentos e fatos, não só em dias de atraso.',
                    'Verifique as vias judiciais e extrajudiciais de recuperação do bem, o efeito de cada notificação e as exigências do Decreto-Lei 911/1969.',
                    'A meta de 2 horas úteis é interna; não é prazo legal.',
                ],
                'form' => 'urgencia',
                'done' => fn($c) => (bool) $c['urgency_assessed_at'],
            ],
            'prazos' => [
                'title' => 'Abrir o calendário de prazos',
                'roles' => ['advogado'],
                'how' => [
                    'Registre cada prazo com o evento que o iniciou, o vencimento, quem executa e o substituto.',
                    'Se não houver prazo em curso, registre isso.',
                ],
                'form' => 'prazos',
                'choices' => [
                    ['key' => 'sem', 'label' => 'Não há prazo jurídico em curso', 'checks' => [['urgencia', 'calendario'], ['urgencia', 'contagem']]],
                    ['key' => 'ok', 'label' => 'Prazos registrados', 'checks' => [['urgencia', 'calendario'], ['urgencia', 'contagem']], 'primary' => true],
                ],
                'after' => ['classificar'],
                'done' => fn($c) => ck($c, 'urgencia', 'calendario'),
            ],
            'orientar' => [
                'title' => 'Enviar orientação escrita ao cliente',
                'roles' => ['advogado'],
                'how' => [
                    'Escreva o que o cliente deve fazer com os pagamentos e com novas notificações enquanto o caso anda.',
                    'A ação revisional, sozinha, não afasta a mora. Contestar tarifas ou seguros não protege contra apreensão.',
                    'O texto aparece na área do cliente; mande também pelo WhatsApp.',
                ],
                'form' => 'orientacao',
                'after' => ['classificar'],
                'done' => fn($c) => ck($c, 'urgencia', 'orientacao_escrita'),
            ],
        ],

        /* 3 · Documentos */
        'documentos' => [
            'pedir' => [
                'title' => 'Enviar a lista de documentos ao cliente',
                'roles' => ['gestor'],
                'how' => [
                    'Mande uma única mensagem com tudo o que falta, separando o que já recebemos, o que falta e o que vamos ajudar a obter.',
                    'O cliente pode enviar fotos pelo WhatsApp ou pela área do cliente.',
                ],
                'say' => 'documentos',
                'form' => 'docs_resumo',
                'choices' => [
                    ['key' => 'ok', 'label' => 'Lista enviada ao cliente', 'checks' => [['documentos', 'lista_unica']]],
                ],
                'done' => fn($c) => ck($c, 'documentos', 'lista_unica'),
            ],
            'contrato' => [
                'title' => 'Pedir o contrato ao banco',
                'roles' => ['gestor'],
                'when' => fn($c) => (bool) scalar("SELECT 1 FROM documents WHERE case_id = ? AND kind = 'contrato' AND (status IN ('ajuda','nao_possui') OR (status IN ('falta','pendencia') AND ? <> 'sim'))", [$c['id'], (string) $c['has_contract']]),
                'how' => [
                    'Primeiro oriente o cliente a procurar no aplicativo do banco, no internet banking, no e-mail da contratação ou na concessionária.',
                    'Não encontrou? Peça ao banco, por canal oficial, a cópia integral do contrato, o demonstrativo do saldo, o histórico de pagamentos, o CET e os documentos de seguro e tarifas.',
                    'Registre o protocolo e o prazo informado. O sistema cria a cobrança ao banco 2 dias úteis depois.',
                    'Nunca peça a senha do banco ou do Gov.br ao cliente.',
                ],
                'form' => 'pedido_banco',
                'choices' => [
                    ['key' => 'cliente', 'label' => 'O cliente vai conseguir sozinho', 'checks' => [['documentos', 'sem_contrato']]],
                ],
                'after' => ['pedir'],
                'optional' => true,
                'done' => fn($c) => ck($c, 'documentos', 'sem_contrato') || case_data($c, 'bank_requests'),
            ],
            'conferir' => [
                'title' => 'Conferir os documentos recebidos',
                'roles' => ['gestor'],
                'how' => [
                    'Abra cada arquivo e confira: titular, banco, número do contrato, legibilidade e se não falta página.',
                    'Marque “Conferido” ou “Reenviar” com o motivo. Arquivos recebidos pelo WhatsApp podem ser anexados aqui.',
                    'Responda ao cliente: “Recebemos X e Y; falta apenas Z”.',
                    'Sem resposta do cliente: até dois lembretes, em 2 e 5 dias úteis.',
                ],
                'form' => 'docs_conferir',
                'choices' => [
                    ['key' => 'ok', 'label' => 'Dossiê conferido; pendências listadas', 'checks' => [['documentos', 'conferencia'], ['documentos', 'dossie_conferido']], 'effect' => 'pedir_suficiencia'],
                ],
                'after' => ['pedir'],
                'wait' => fn($c) => scalar("SELECT 1 FROM documents WHERE case_id = ? AND essential = 1 AND status IN ('falta','pendencia')", [$c['id']])
                    && !scalar("SELECT 1 FROM documents WHERE case_id = ? AND status = 'recebido'", [$c['id']])
                    ? 'Aguardando documentos do cliente' : null,
                'done' => fn($c) => ck($c, 'documentos', 'dossie_conferido'),
            ],
            'suficiencia' => [
                'title' => 'Avaliar se os documentos bastam para a análise',
                'roles' => ['advogado'],
                'how' => [
                    'Diga se o que temos permite analisar o contrato. Não exija todos os acessórios: marque como indispensável só o que muda a decisão.',
                    'Faltando prova essencial, o caso continua em “documentos em obtenção”; nunca é descartado como inviável por isso.',
                ],
                'form' => 'docs_resumo',
                'choices' => [
                    ['key' => 'ok', 'label' => 'Suficientes: pode começar a análise', 'checks' => [['documentos', 'suficiencia']], 'effect' => 'suficiencia'],
                    ['key' => 'falta', 'label' => 'Ainda falta prova essencial', 'effect' => 'falta_prova', 'note' => 'O que falta e por quê?'],
                ],
                'after' => ['conferir'],
                'done' => fn($c) => ck($c, 'documentos', 'suficiencia'),
            ],
        ],

        /* 4 · Auditoria */
        'auditoria' => [
            'calculo' => [
                'title' => 'Refazer o cálculo do contrato',
                'roles' => ['calculos'],
                'how' => [
                    'Preencha a operação original com os números do contrato. A calculadora ao lado confere se os dados reproduzem a parcela.',
                    'Consulte a série 25471 do Banco Central (veículos, pessoa física) da competência da contratação e guarde a data da consulta.',
                    'Compare juros com juros, na mesma unidade. O CET não se compara com a taxa média.',
                    'Confira cada cobrança (tarifas, seguro, serviços) sem presumir que toda tarifa é indevida.',
                    'Monte os três cenários na mesma data e escreva a memória de cálculo.',
                ],
                'form' => 'auditoria',
                'choices' => [
                    ['key' => 'ok', 'label' => 'Cálculo pronto: enviar ao advogado', 'effect' => 'calculo_pronto'],
                ],
                'done' => fn($c) => !empty(case_data($c, 'audit')['ready_at']) || !empty(case_data($c, 'audit')['validated_at']),
            ],
            'validar' => [
                'title' => 'Validar o cálculo e o parecer',
                'roles' => ['advogado'],
                'how' => [
                    'Revise a memória de cálculo e os cenários. A razão entre taxa contratada e média é um indicador, não uma conclusão automática.',
                    'Avalie prescrição, legitimidade, ações anteriores, prova e benefício líquido provável.',
                    'Escolha a conclusão e escreva a síntese em linguagem simples para o cliente.',
                ],
                'form' => 'validar',
                'after' => ['calculo'],
                'done' => fn($c) => !empty(case_data($c, 'audit')['validated_at']),
            ],
        ],

        /* 5 · Decisão */
        'decisao' => [
            'recomendar' => [
                'title' => 'Preparar a recomendação',
                'roles' => ['advogado'],
                'how' => [
                    'Escolha o caminho que você recomenda e diga a razão em uma frase.',
                    'Liste custos (serviço, despesas, risco de sucumbência, recursos) e os limites da negociação.',
                    'Diga o que o cliente deve fazer com os pagamentos enquanto buscamos a solução.',
                ],
                'form' => 'recomendacao',
                'done' => fn($c) => !empty(case_data($c, 'recommendation')['path']),
            ],
            'conversa' => [
                'title' => 'Conversar com o cliente e registrar a escolha',
                'roles' => ['advogado', 'gestor'],
                'how' => [
                    'Faça uma ligação ou vídeo de uns 20 minutos. Comece pelo objetivo do cliente e pelo resultado da análise.',
                    'Mostre valores na mesma data, separando “estimativa”, “oferta do banco” e “resultado confirmado”. Alongar a dívida reduz a parcela e aumenta o custo total.',
                    'Dê a recomendação e a razão. O cliente decide sem pressão.',
                    'Confirme com duas perguntas: “Qual caminho você prefere e por quê?” e “O que ficou combinado sobre seus pagamentos?”.',
                    'Se ele quiser pensar, envie a pergunta para a área do cliente e combine a data de retorno.',
                ],
                'form' => 'escolha_caminho',
                'after' => ['recomendar'],
                'wait' => fn($c) => ($d = open_decision($c, 'caminho')) && !$d['answer'] ? 'Aguardando o cliente escolher o caminho' : null,
                'done' => fn($c) => (bool) scalar("SELECT 1 FROM decisions WHERE case_id = ? AND kind = 'caminho' AND answer IS NOT NULL", [$c['id']]),
            ],
            'estrategia' => [
                'title' => 'Confirmar estratégia e objetivo',
                'roles' => ['advogado'],
                'how' => [
                    'Confira se o objetivo mensurável e a estratégia estão registrados e se os limites da negociação estão claros.',
                ],
                'form' => 'objetivo_resumo',
                'choices' => [
                    ['key' => 'ok', 'label' => 'Estratégia e objetivo registrados', 'checks' => [['decisao', 'objetivo_mensuravel'], ['decisao', 'confirmar']]],
                ],
                'after' => ['conversa'],
                'done' => fn($c) => ck($c, 'decisao', 'objetivo_mensuravel'),
            ],
        ],

        /* 6 · Contratação */
        'contratacao' => [
            'termos' => [
                'title' => 'Preparar contrato de honorários e procuração',
                'roles' => ['gestor', 'financeiro', 'advogado'],
                'how' => [
                    'Descreva o que o serviço cobre e o que não cobre.',
                    'Defina honorários (inicial, êxito, base e momento) com um exemplo numérico simples, conforme a tabela da seccional.',
                    'Envie contrato e procuração para assinatura e informe a data quando estiverem assinados.',
                ],
                'form' => 'contratacao',
                'wait' => fn($c) => !empty(case_data($c, 'engagement')['scope']) && empty(case_data($c, 'engagement')['signed_at']) ? 'Aguardando assinatura do cliente' : null,
                'done' => fn($c) => ck($c, 'contratacao', 'assinado'),
            ],
            'explicar' => [
                'title' => 'Confirmar que o cliente entendeu escopo e custos',
                'roles' => ['gestor'],
                'how' => [
                    'Repasse com o cliente o que está incluído, o que não está e quanto custa. Envie cópia de tudo o que ele assinou.',
                    'Confirme gestor, advogado, canal, frequência de atualização e o primeiro prazo de entrega.',
                ],
                'choices' => [
                    ['key' => 'ok', 'label' => 'Cliente entendeu escopo e custos', 'checks' => [['contratacao', 'escopo_compreendido'], ['contratacao', 'escopo'], ['contratacao', 'honorarios'], ['contratacao', 'equipe']]],
                ],
                'after' => ['termos'],
                'done' => fn($c) => ck($c, 'contratacao', 'escopo_compreendido'),
            ],
            'receber' => [
                'title' => 'Receber o caso para execução',
                'roles' => ['advogado', 'negociador'],
                'how' => [
                    'Quem vai executar (negociação ou ação) confirma que recebeu resumo, documentos, cálculo, riscos, autorização e tarefas.',
                ],
                'choices' => [
                    ['key' => 'ok', 'label' => 'Recebi o caso e confirmo o plano', 'checks' => [['contratacao', 'transferencia']]],
                ],
                'after' => ['termos'],
                'optional' => true,
                'done' => fn($c) => ck($c, 'contratacao', 'transferencia'),
            ],
        ],

        /* 7 · Negociação */
        'negociacao' => [
            'limites' => [
                'title' => 'Registrar o que o cliente autorizou',
                'roles' => ['negociador', 'advogado'],
                'how' => [
                    'Anote teto de entrada, parcela máxima, data possível de pagamento e o objetivo prioritário.',
                    'Nenhuma proposta pode passar desses limites sem nova decisão do cliente.',
                    'Identifique o credor atual (banco, cessionário ou empresa de cobrança) e o canal oficial.',
                ],
                'form' => 'limites',
                'done' => fn($c) => ck($c, 'negociacao', 'teto'),
            ],
            'propostas' => [
                'title' => 'Negociar e registrar cada oferta',
                'roles' => ['negociador'],
                'how' => [
                    'Peça ao credor o saldo discriminado e as condições de quitação. Envie proposta fundamentada, com pedido de prazo de resposta.',
                    'Registre cada oferta: saldo de referência, entrada, parcelas, total, encargos, validade e efeitos.',
                    'Toda oferta do banco vai ao cliente em até 1 dia útil. Silêncio do cliente nunca vale como aceite.',
                    'Depois de duas rodadas ou 10 dias úteis sem avanço, o advogado revisa a estratégia.',
                ],
                'form' => 'ofertas',
                'choices' => [
                    ['key' => 'sem_acordo', 'label' => 'Sem acordo: o cliente decidiu outro caminho', 'checks' => [['negociacao', 'decisao_real']], 'note' => 'Qual foi a decisão do cliente e qual o próximo caminho?'],
                ],
                'after' => ['limites'],
                'wait' => fn($c) => guide_negotiation_wait($c),
                'done' => fn($c) => ck($c, 'negociacao', 'decisao_real'),
            ],
        ],

        /* 8 · Acordo */
        'acordo' => [
            'instrumento' => [
                'title' => 'Revisar e formalizar o acordo',
                'roles' => ['advogado'],
                'how' => [
                    'Confira partes, contrato, veículo, saldo reconhecido, descontos, valores, datas, encargos e o que acontece se alguém descumprir.',
                    'Trate quitação, boletos, restrições, gravame, processos e destino do veículo. Entregar o bem não é quitação automática.',
                    'Confirme com o cliente o texto final e o dinheiro disponível. Confira beneficiário e autenticidade do boleto em canal oficial.',
                ],
                'form' => 'acordo',
                'wait' => fn($c) => empty(case_data($c, 'agreement')['signed_at']) && !empty(case_data($c, 'agreement')['instrument']) ? 'Aguardando assinatura do acordo' : null,
                'done' => fn($c) => !empty(case_data($c, 'agreement')['signed_at']),
            ],
            'calendario' => [
                'title' => 'Montar o calendário de pagamentos',
                'roles' => ['financeiro', 'gestor'],
                'how' => [
                    'Lance cada vencimento do acordo. O cliente é avisado 3 dias antes de cada um.',
                    'Se for pagamento único já feito, marque “acordo sem parcelas futuras”.',
                ],
                'form' => 'calendario',
                'choices' => [
                    ['key' => 'unico', 'label' => 'Pagamento único, sem parcelas futuras', 'checks' => [['acordo', 'formal']]],
                ],
                'after' => ['instrumento'],
                'done' => fn($c) => ck($c, 'acordo', 'formal'),
            ],
            'cumprimento' => [
                'title' => 'Acompanhar os pagamentos e o banco',
                'roles' => ['financeiro', 'gestor'],
                'how' => [
                    'Confirme cada comprovante e confira se o banco implantou o que assinou (boleto novo, baixa, saldo).',
                    'Se alguém descumprir: reúna a prova, cobre formalmente e registre a exceção.',
                ],
                'form' => 'parcelas',
                'choices' => [
                    ['key' => 'ok', 'label' => 'Obrigações cumpridas: seguir para a conferência', 'checks' => [['acordo', 'acompanhar'], ['acordo', 'boleto']]],
                ],
                'after' => ['calendario'],
                'wait' => fn($c) => guide_schedule_wait($c),
                'done' => fn($c) => ck($c, 'acordo', 'acompanhar'),
            ],
        ],

        /* 9 · Preparação judicial */
        'preparacao_judicial' => [
            'preparar' => [
                'title' => 'Preparar a ação ou a defesa',
                'roles' => ['advogado'],
                'how' => array_values(stage('preparacao_judicial')['steps']),
                'choices' => [
                    ['key' => 'ok', 'label' => 'Peça revisada e pronta para protocolo', 'checks' => array_map(fn($k) => ['preparacao_judicial', $k], array_keys(stage('preparacao_judicial')['steps']))],
                ],
                'done' => fn($c) => ck($c, 'preparacao_judicial', 'calculos'),
            ],
            'protocolo' => [
                'title' => 'Registrar o protocolo',
                'roles' => ['advogado'],
                'how' => [
                    'Informe número do processo, juízo, data, valor da causa e os pedidos.',
                    'Se pediu medida urgente, registre a situação e, quando sair a decisão, o alcance da ordem.',
                ],
                'form' => 'protocolo',
                'after' => ['preparar'],
                'done' => fn($c) => ck($c, 'preparacao_judicial', 'protocolo'),
            ],
            'orientar' => [
                'title' => 'Enviar resumo e orientação de pagamentos ao cliente',
                'roles' => ['advogado'],
                'how' => [
                    'Em uma página: o que foi pedido, o que esperar, quanto tempo pode levar e o que fazer com os pagamentos.',
                ],
                'form' => 'orientacao',
                'after' => ['protocolo'],
                'done' => fn($c) => ck($c, 'preparacao_judicial', 'orientacao'),
            ],
        ],

        /* 10 · Processo */
        'processo' => [
            'acompanhar' => [
                'title' => 'Acompanhar o processo',
                'roles' => ['advogado'],
                'how' => [
                    'Confira as intimações todos os dias e registre cada prazo com executor e substituto.',
                    'Depois da contestação, compare os documentos do banco com o cálculo e decida sobre perícia ou exibição.',
                    'Audiência: informe data, formato e instruções ao cliente com antecedência.',
                    'Reavalie a negociação após prova relevante, decisão urgente ou mudança na capacidade de pagamento.',
                    'O cliente recebe notícia a cada 15 dias, mesmo sem movimentação.',
                ],
                'form' => 'processo',
                'wait' => fn($c) => 'Aguardando andamento do processo',
                'done' => fn($c) => !empty(case_data($c, 'judicial')['decision_result']),
            ],
        ],

        /* 11 · Decisão e recursos */
        'decisao_recursos' => [
            'explicar' => [
                'title' => 'Explicar a decisão ao cliente',
                'roles' => ['advogado'],
                'how' => [
                    'Separe o que foi acolhido, rejeitado e o que ainda não foi definido.',
                    'Explique os efeitos sobre saldo, pagamentos, restituição, posse do veículo, custas e honorários.',
                    'Diga se a decisão é provisória, sujeita a recurso ou definitiva. Meta: 1 dia útil, sem perder prazo recursal menor.',
                ],
                'say' => fn($c) => ($r = DECISION_RESULTS[case_data($c, 'judicial')['decision_result'] ?? ''] ?? null) && in_array(case_data($c, 'judicial')['decision_result'] ?? '', ['desfavoravel', 'extincao'], true) ? 'decisao_desfavoravel' : null,
                'form' => 'decisao_judicial',
                'choices' => [
                    ['key' => 'ok', 'label' => 'O cliente entendeu a decisão', 'checks' => [['decisao_recursos', 'compreendida'], ['decisao_recursos', 'separar'], ['decisao_recursos', 'natureza']]],
                ],
                'done' => fn($c) => ck($c, 'decisao_recursos', 'compreendida'),
            ],
            'recurso' => [
                'title' => 'Decidir sobre recurso',
                'roles' => ['advogado'],
                'how' => [
                    'Apresente a recomendação por escrito: fundamento, custo, risco e utilidade.',
                    'Registre a decisão do cliente antes do prazo.',
                ],
                'form' => 'recurso',
                'after' => ['explicar'],
                'done' => fn($c) => ck($c, 'decisao_recursos', 'recurso'),
            ],
        ],

        /* 12 · Apuração */
        'apuracao' => [
            'demonstrativo' => [
                'title' => 'Apurar os valores da decisão',
                'roles' => ['calculos', 'advogado'],
                'how' => [
                    'Leia o dispositivo e os critérios: taxa, cobranças excluídas, período, correção, juros, compensação.',
                    'Produza o demonstrativo: novo saldo, parcelas restantes, valores a devolver, compensação, despesas e honorários.',
                ],
                'form' => 'apuracao',
                'choices' => [
                    ['key' => 'ok', 'label' => 'Demonstrativo pronto', 'checks' => [['apuracao', 'dispositivo'], ['apuracao', 'demonstrativo']]],
                ],
                'done' => fn($c) => ck($c, 'apuracao', 'demonstrativo'),
            ],
            'cumprimento' => [
                'title' => 'Exigir e conferir o cumprimento',
                'roles' => ['advogado', 'financeiro'],
                'how' => [
                    'Peça o pagamento e as obrigações de fazer com precisão: recalcular, emitir boletos, restituir, dar quitação, regularizar registro.',
                    'Registre cada prazo e confira no primeiro dia útil depois do término.',
                    'Depósito nos autos não é dinheiro disponível. Confirme valor, data e beneficiário e entregue ao cliente o demonstrativo do líquido.',
                ],
                'form' => 'cumprimento',
                'choices' => [
                    ['key' => 'ok', 'label' => 'Valores apurados e obrigações cumpridas', 'checks' => [['apuracao', 'apurado'], ['apuracao', 'exigir'], ['apuracao', 'prazos'], ['apuracao', 'recebimento']]],
                ],
                'after' => ['demonstrativo'],
                'wait' => fn($c) => (case_data($c, 'judicial')['compliance_status'] ?? '') !== 'cumprido' && (case_data($c, 'judicial')['compliance_status'] ?? '') !== '' ? 'Aguardando cumprimento pelo banco' : null,
                'done' => fn($c) => ck($c, 'apuracao', 'apurado'),
            ],
        ],

        /* 13 · Conferência */
        'conferencia' => [
            'conferir' => [
                'title' => 'Conferir a solução na vida do cliente',
                'roles' => ['gestor', 'advogado', 'financeiro'],
                'how' => [
                    'Compare o objetivo registrado no início com o que foi entregue.',
                    'Marque as soluções que se aplicam a este caso e, para cada uma, a evidência guardada.',
                    'Faça o teste prático com o cliente: recebeu o boleto correto? O pagamento foi reconhecido? O dinheiro entrou? Chegou cobrança diferente?',
                    'Qualquer divergência reabre a obrigação; não deixe o cliente cobrar sozinho.',
                ],
                'form' => 'solucao',
                'done' => fn($c) => solution_ready($c),
            ],
        ],

        /* 14 · Encerramento */
        'encerramento' => [
            'lista' => [
                'title' => 'Completar a lista de encerramento',
                'roles' => ['gestor', 'financeiro', 'advogado'],
                'how' => ['Marque cada item só quando estiver comprovado.'],
                'form' => 'lista_encerramento',
                'done' => fn($c) => closing_ready($c),
            ],
            'pacote' => [
                'title' => 'Prestar contas ao cliente',
                'roles' => ['gestor'],
                'how' => array_values(stage('encerramento')['steps']),
                'say' => 'encerramento',
                'choices' => [
                    ['key' => 'ok', 'label' => 'Pacote final enviado e acompanhamento combinado', 'checks' => array_map(fn($k) => ['encerramento', $k], array_keys(stage('encerramento')['steps']))],
                ],
                'after' => ['lista'],
                'done' => fn($c) => ck($c, 'encerramento', 'pacote'),
            ],
            'encerrar' => [
                'title' => 'Encerrar o caso',
                'roles' => ['supervisor', 'advogado'],
                'how' => [
                    'Escolha o desfecho e escreva o resumo de uma página: problema inicial, caminho seguido, solução e efeito financeiro.',
                    'O contato de 30 dias é agendado automaticamente.',
                ],
                'form' => 'encerrar',
                'after' => ['lista', 'pacote'],
                'done' => fn($c) => $c['status'] !== 'ativo',
            ],
        ],
    ];
    return $steps[$stage] ?? [];
}

function guide_negotiation_wait(array $c): ?string
{
    if (scalar("SELECT 1 FROM decisions WHERE case_id = ? AND kind = 'oferta' AND answer IS NULL", [$c['id']])) {
        return 'Aguardando o cliente responder à proposta';
    }
    if (scalar("SELECT 1 FROM offers WHERE case_id = ? AND origin = 'credor' AND communicated_at IS NULL AND status = 'recebida'", [$c['id']])) {
        return null; // há oferta para comunicar: é trabalho da equipe
    }
    if (scalar("SELECT 1 FROM decisions WHERE case_id = ? AND kind = 'oferta' AND answer LIKE 'Autorizo%'", [$c['id']])) {
        return null; // autorizado: registrar o aceite
    }
    if (scalar("SELECT 1 FROM offers WHERE case_id = ? AND origin = 'escritorio' AND status = 'enviada'", [$c['id']])) {
        return 'Aguardando resposta do credor';
    }
    return null;
}

function guide_schedule_wait(array $c): ?string
{
    $late = scalar("SELECT 1 FROM schedule WHERE case_id = ? AND status = 'pendente' AND due_date <= ?", [$c['id'], now()->modify('+3 days')->format('Y-m-d')]);
    if ($late) {
        return null; // vencimento próximo ou vencido: conferir agora
    }
    $next = scalar("SELECT MIN(due_date) FROM schedule WHERE case_id = ? AND status = 'pendente'", [$c['id']]);
    return $next ? 'Próximo vencimento do acordo em ' . fmt_date((string) $next) : null;
}

/* ---------- Estado do roteiro ---------- */

/** Passos aplicáveis da etapa atual, com estado calculado. */
function guide_state(array $c, ?array $u = null): array
{
    $u ??= current_user();
    $steps = [];
    foreach (guide($c['stage']) as $key => $st) {
        if (isset($st['when']) && !($st['when'])($c)) {
            continue;
        }
        $st['key'] = $key;
        $st['done'] = (bool) ($st['done'])($c);
        $steps[$key] = $st;
    }
    foreach ($steps as $key => &$st) {
        $st['blocked_by'] = array_values(array_filter($st['after'] ?? [], fn($a) => isset($steps[$a]) && !$steps[$a]['done']));
        $st['waiting'] = !$st['done'] && isset($st['wait']) ? ($st['wait'])($c) : null;
        $st['mine'] = $u ? guide_is_mine($c, $st['roles'], $u) : false;
        // O supervisor cobre ausências: pode executar qualquer passo.
        $st['can'] = $u ? (bool) array_intersect(array_merge($st['roles'], ['supervisor']), user_roles($u)) : false;
        $st['available'] = !$st['done'] && !$st['blocked_by'];
    }
    unset($st);
    return $steps;
}

function guide_ready(array $steps): bool
{
    foreach ($steps as $st) {
        if (!$st['done'] && empty($st['optional'])) {
            return false;
        }
    }
    return true;
}

/** O passo é desta pessoa? Gestor e advogado seguem a atribuição do caso; demais funções, quem tiver a função. */
function guide_is_mine(array $c, array $roles, array $u): bool
{
    $mine = user_roles($u);
    foreach ($roles as $r) {
        if (!in_array($r, $mine, true)) {
            continue;
        }
        if ($r === 'gestor' && $c['gestor_id'] && (int) $c['gestor_id'] !== (int) $u['id']) {
            continue;
        }
        if ($r === 'advogado' && $c['advogado_id'] && (int) $c['advogado_id'] !== (int) $u['id']) {
            continue;
        }
        return true;
    }
    return false;
}

/** Quem responde pelo avanço da etapa (primeira função da etapa). */
function guide_lead_role(string $stage): string
{
    return stage($stage)['owner'][0];
}

function guide_role_person(array $c, string $role): string
{
    if ($role === 'gestor' && $c['gestor_id']) {
        return first_name(user_name((int) $c['gestor_id']));
    }
    if ($role === 'advogado' && $c['advogado_id']) {
        return first_name(user_name((int) $c['advogado_id']));
    }
    $people = users_with_role($role);
    return count($people) === 1 ? first_name($people[0]['name']) : ROLES[$role];
}

/** Próxima etapa sugerida a partir do que foi registrado no caso. */
function guide_recommended_next(array $c): ?string
{
    $next = stage($c['stage'])['next'];
    if (!$next) {
        return null;
    }
    $pick = match ($c['stage']) {
        'acolhimento' => $c['urgent'] ? 'urgencia' : 'documentos',
        'urgencia' => 'documentos',
        'decisao' => (function () use ($c) {
            $d = one("SELECT answer FROM decisions WHERE case_id = ? AND kind = 'caminho' AND answer IS NOT NULL ORDER BY answered_at DESC LIMIT 1", [$c['id']]);
            $a = mb_strtolower((string) ($d['answer'] ?? ''));
            if (str_contains($a, 'prova')) {
                return 'documentos';
            }
            if (str_contains($a, 'manter') || str_contains($a, 'orientar')) {
                return 'encerramento';
            }
            return 'contratacao';
        })(),
        'contratacao' => (($rec = case_data($c, 'recommendation')['path'] ?? '') === 'revisao') ? 'preparacao_judicial' : 'negociacao',
        'negociacao' => scalar("SELECT 1 FROM offers WHERE case_id = ? AND status = 'aceita'", [$c['id']]) ? 'acordo' : 'preparacao_judicial',
        'decisao_recursos' => match (case_data($c, 'judicial')['appeal'] ?? '') {
            'sim', 'banco' => 'processo',
            default => in_array(case_data($c, 'judicial')['decision_result'] ?? '', ['favoravel', 'parcial'], true) ? 'apuracao' : 'encerramento',
        },
        default => $next[0],
    };
    return in_array($pick, $next, true) ? $pick : $next[0];
}

/** Itens da fila de uma pessoa: o que fazer agora e o que está aguardando. */
function guide_queue(array $u): array
{
    $todo = [];
    $waiting = [];
    $taskRows = all("SELECT * FROM tasks WHERE status = 'aberta' AND case_id IS NOT NULL AND (owner_id = ? OR owner_id IS NULL) ORDER BY due_at IS NULL, due_at", [$u['id']]);
    $tasksByCase = [];
    foreach ($taskRows as $t) {
        $tasksByCase[(int) $t['case_id']][] = $t;
    }
    $cases = all("SELECT * FROM cases WHERE status = 'ativo' OR id IN (SELECT case_id FROM tasks WHERE status = 'aberta' AND (owner_id = ? OR owner_id IS NULL))", [$u['id']]);
    foreach ($cases as $c) {
        $tasks = $tasksByCase[(int) $c['id']] ?? [];
        $item = ['case' => $c, 'tasks' => $tasks, 'step' => null, 'reason' => null];
        if ($c['status'] !== 'ativo') {
            if ($tasks) {
                $item['reason'] = $tasks[0]['title'];
                $item['due'] = $tasks[0]['due_at'];
                $dueTask = $tasks[0]['due_at'] && dt($tasks[0]['due_at']) <= now()->modify('+1 day');
                $dueTask ? $todo[] = $item : $waiting[] = $item;
            }
            continue;
        }
        $steps = guide_state($c, $u);
        $mineAvailable = array_filter($steps, fn($s) => $s['available'] && $s['mine'] && !$s['waiting'] && empty($s['optional']));
        $mineWaiting = array_filter($steps, fn($s) => $s['available'] && $s['mine'] && $s['waiting']);
        if ($mineAvailable) {
            $item['step'] = reset($mineAvailable);
            $item['due'] = $c['next_action_due'];
            $todo[] = $item;
        } elseif (guide_ready($steps) && stage($c['stage'])['next'] && guide_is_mine($c, [guide_lead_role($c['stage'])], $u)) {
            $item['step'] = ['key' => '_avancar', 'title' => 'Passar o caso para a próxima etapa'];
            $todo[] = $item;
        } elseif ($tasks) {
            $item['reason'] = $tasks[0]['title'];
            $item['due'] = $tasks[0]['due_at'];
            $todo[] = $item;
        } elseif ($mineWaiting) {
            $s = reset($mineWaiting);
            $item['step'] = $s;
            $item['reason'] = $s['waiting'];
            $waiting[] = $item;
        }
    }
    $rank = fn($i) => [
        $i['case']['urgent'] ? 0 : 1,
        $i['due'] ?? $i['case']['next_action_due'] ?? '9999',
        $i['case']['stage_since'],
    ];
    usort($todo, fn($a, $b) => $rank($a) <=> $rank($b));
    usort($waiting, fn($a, $b) => ($a['case']['next_client_update'] ?? '9999') <=> ($b['case']['next_client_update'] ?? '9999'));
    return [$todo, $waiting];
}
