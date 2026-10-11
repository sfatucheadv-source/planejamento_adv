<?php
declare(strict_types=1);

/**
 * Workflow de atendimento e resolução de financiamento de veículo.
 * Fonte: "Workflow de atendimento e resolução de financiamento de veículo"
 * (procedimento operacional proposto, 10/10/2026). As seções citadas nos
 * comentários seguem a numeração do documento.
 */

/** Seção 3 — o cliente só vê cinco situações. */
const CLIENT_PHASES = [
    'entendendo'  => ['label' => 'Entendendo seu caso',            'short' => 'Entender',  'text' => 'Ouvimos sua situação, verificamos se há algum prazo urgente e definimos com você o que precisa ser resolvido primeiro.'],
    'analisando'  => ['label' => 'Analisando documentos',          'short' => 'Analisar',  'text' => 'Reunimos o contrato e os comprovantes, refazemos as contas do financiamento e conferimos cada cobrança.'],
    'buscando'    => ['label' => 'Buscando uma solução',           'short' => 'Decidir',   'text' => 'O advogado explica os caminhos possíveis, com custos e riscos. Você escolhe, e nós colocamos o plano em andamento.'],
    'acontecendo' => ['label' => 'Fazendo a solução acontecer',    'short' => 'Resolver',  'text' => 'Negociamos com o banco e, quando houver fundamento, atuamos no processo. Você é avisado de cada oferta e decisão.'],
    'conferindo'  => ['label' => 'Conferindo e encerrando',        'short' => 'Conferir',  'text' => 'Só encerramos depois de conferir o resultado na prática: boleto novo, quitação, devolução ou baixa do gravame.'],
];

/**
 * Seção 20 — etapas internas. Cada etapa tem responsável, entrada, meta,
 * passos, condição para avançar (gates) e caminhos possíveis.
 * Gates com 'block' => true impedem o avanço (seção 20: "bloquear avanço sem
 * cálculo validado, decisão documentada ou evidência final").
 */
function stages(): array
{
    return [
        'acolhimento' => [
            'n' => 1, 'phase' => 'entendendo',
            'label' => 'Acolhimento',
            'title' => 'Acolhimento e definição do problema',
            'owner' => ['gestor'],
            'entry' => 'Contato espontâneo, indicação ou pedido de avaliação.',
            'goal'  => 'Primeiro atendimento humano em até 1 hora útil.',
            'steps' => [
                'apresentacao' => 'Apresentar nome, função e canal do escritório. Perguntar como o cliente prefere conversar e registrar necessidades de acessibilidade.',
                'pergunta_urgencia' => 'Perguntar primeiro: “Você recebeu alguma notificação, documento de cartório ou da Justiça? O veículo já foi apreendido?”.',
                'minimo' => 'Reunir o mínimo: banco, contrato ativo ou quitado, valor aproximado da parcela, atraso, cidade/UF e disponibilidade do contrato. Identificar outro processo ou advogado atuando.',
                'prioridade' => 'Perguntar “O que você precisa resolver primeiro?” e “Qual pagamento caberia no seu orçamento sem comprometer despesas essenciais?” (valores são estimativas).',
                'resumo' => 'Repetir o entendimento em uma frase e confirmar com o cliente.',
                'registro' => 'Abrir registro único, anotar objetivo, atribuir gestor e advogado e verificar conflito de interesses antes de receber documentação extensa.',
            ],
            'gates' => [
                'cliente_compreende' => ['label' => 'Cliente compreende o próximo passo', 'block' => true],
                'urgencias_encaminhadas' => ['label' => 'Urgências foram encaminhadas ao advogado', 'block' => true],
                'conflito_tratado' => ['label' => 'Conflito de interesses verificado e tratado', 'block' => true],
            ],
            'needs' => ['first_contact', 'team', 'objective'],
            'deliverable' => 'Resumo do caso, prioridade, responsável e próximo contato.',
            'message' => 'Vou organizar seu atendimento e acompanhar os próximos passos. Primeiro precisamos entender se há algum prazo urgente. Depois reunimos os documentos e o advogado explica as opções para o seu caso.',
            'next' => ['urgencia', 'documentos', 'encerramento'],
        ],
        'urgencia' => [
            'n' => 2, 'phase' => 'entendendo',
            'label' => 'Urgência em avaliação',
            'title' => 'Triagem de urgência e proteção imediata',
            'owner' => ['advogado', 'gestor'],
            'entry' => 'Notificação, documento judicial ou extrajudicial, apreensão ou dúvida sobre prazo.',
            'goal'  => 'Encaminhamento imediato ao advogado; avaliação inicial em até 2 horas úteis.',
            'steps' => [
                'classificar' => 'Classificar com base em documentos e fatos, não apenas em dias de atraso.',
                'vias' => 'Verificar vias judiciais e, quando pertinente, extrajudiciais de recuperação do bem, os efeitos de cada notificação e as exigências do Decreto-Lei 911/1969.',
                'contagem' => 'Definir a contagem aplicável. A meta de duas horas não é prazo legal.',
                'orientacao' => 'Entregar orientação escrita sobre pagamentos e novas notificações. A ação revisional, sozinha, não afasta a mora; contestar tarifas ou seguros não protege automaticamente contra apreensão.',
                'nao_parar' => 'O atendimento administrativo não recomenda parar de pagar nem pagar parcela reduzida por conta própria.',
            ],
            'gates' => [
                'classificado' => ['label' => 'Risco classificado pelo advogado', 'block' => true],
                'orientacao_escrita' => ['label' => 'Orientação escrita entregue ao cliente', 'block' => true],
                'calendario' => ['label' => 'Calendário urgente aberto (prazo, medida e responsável)', 'block' => false],
            ],
            'needs' => ['urgency_class'],
            'deliverable' => 'Risco identificado, calendário urgente aberto, cliente orientado e medida decidida pelo advogado. A coleta de documentos segue em paralelo.',
            'next' => ['documentos', 'auditoria', 'preparacao_judicial', 'negociacao'],
        ],
        'documentos' => [
            'n' => 3, 'phase' => 'analisando',
            'label' => 'Documentos em obtenção',
            'title' => 'Coleta assistida e obtenção do contrato',
            'owner' => ['gestor'],
            'entry' => 'Caso aberto com prioridade definida.',
            'goal'  => 'Conferir cada envio em até 1 dia útil.',
            'steps' => [
                'lista_unica' => 'Enviar uma solicitação única e organizada, distinguindo “já recebido”, “falta” e “vamos ajudar a obter”.',
                'sem_contrato' => 'Se o cliente não tem o contrato: orientar busca no aplicativo, internet banking ou e-mail; pedir cópia integral ao credor por canal oficial; registrar protocolo e cobrar dois dias úteis após o prazo do banco.',
                'scr' => 'Usar o SCR (Registrato) como apoio quando útil. O cliente acessa a própria conta e entrega o relatório, sem compartilhar senha.',
                'conferencia' => 'Indexar por tipo e data; conferir titular, banco, contrato, legibilidade e sequência. Responder: “Recebemos X e Y; falta apenas Z”.',
                'lembretes' => 'Sem resposta: até dois lembretes respeitosos, em dois e cinco dias úteis, salvo urgência. Nunca pausar medida com prazo em curso por rotina automática.',
            ],
            'gates' => [
                'dossie_conferido' => ['label' => 'Dossiê conferido e pendências listadas', 'block' => true],
                'suficiencia' => ['label' => 'Advogado avaliou expressamente a suficiência dos documentos', 'block' => true],
            ],
            'needs' => [],
            'deliverable' => 'Dossiê conferido, lista residual de pendências e avaliação de suficiência pelo advogado. Enquanto faltar prova essencial, o caso continua em “documentação em obtenção”, nunca descartado como inviável.',
            'next' => ['auditoria'],
        ],
        'auditoria' => [
            'n' => 4, 'phase' => 'analisando',
            'label' => 'Auditoria',
            'title' => 'Auditoria financeira e jurídica',
            'owner' => ['calculos', 'advogado'],
            'entry' => 'Documentação suficiente.',
            'goal'  => 'Parecer ordinário em até 2 dias úteis; casos complexos recebem nova previsão justificada.',
            'steps' => [
                'operacao' => 'Identificar a operação original e alterações: data, principal, entrada, parcelas, taxas mensal e anual, CET, tarifas, seguro, garantia, atrasos e renegociações.',
                'reproduzir' => 'Reconstruir o cenário contratado e conciliar os pagamentos. O cálculo precisa reproduzir a operação antes de propor alterações.',
                'serie' => 'Consultar a série oficial do Banco Central (aquisição de veículos PF, série 25471), guardando fonte, competência e data da consulta.',
                'mesma_unidade' => 'Comparar juros com juros na mesma unidade. O CET não se compara com a taxa média de juros.',
                'circunstancias' => 'Examinar as circunstâncias da contratação. A razão entre taxa contratada e média é um indicador; 1,5 vez a média não é critério automático.',
                'cobrancas' => 'Conferir cobrança por cobrança (tarifas de avaliação e registro, seguros, serviços de terceiros), sem presumir nulidade de toda tarifa ou seguro.',
                'capitalizacao' => 'Verificar capitalização, encargos de atraso e pactuação. Tabela Price e juros compostos não são, por si, prova de ilegalidade.',
                'viabilidade' => 'Avaliar prescrição, legitimidade, ações prévias, competência, prova, jurisprudência e benefício líquido provável.',
            ],
            'gates' => [
                'memoria' => ['label' => 'Memória de cálculo reproduzível (entradas, fontes, fórmulas, saldos, hipóteses)', 'block' => true],
                'sintese' => ['label' => 'Síntese de uma página em linguagem simples para o cliente', 'block' => false],
            ],
            'needs' => ['audit_validated'],
            'deliverable' => 'Parecer aprovado com uma conclusão: revisão fundamentada, negociação prioritária, prova ainda insuficiente ou revisão não recomendada.',
            'next' => ['decisao', 'documentos'],
        ],
        'decisao' => [
            'n' => 5, 'phase' => 'buscando',
            'label' => 'Decisão do cliente',
            'title' => 'Apresentação das opções e escolha informada',
            'owner' => ['advogado', 'gestor'],
            'entry' => 'Parecer validado.',
            'goal'  => 'Ligação ou vídeo de cerca de 20 minutos, com resumo escrito.',
            'steps' => [
                'objetivo' => 'Começar pelo objetivo do cliente e pelo resultado da análise.',
                'cobrancas' => 'Explicar quais cobranças merecem discussão, quais estão regulares e o que ainda depende de prova.',
                'mesma_data' => 'Usar valores na mesma data e distinguir “estimativa”, “oferta do banco” e “resultado já confirmado”.',
                'custos' => 'Mostrar custo do serviço, despesas previsíveis, risco de sucumbência e possíveis recursos. Explicar que alongar a dívida pode reduzir a prestação e aumentar o custo total.',
                'recomendacao' => 'O advogado apresenta a recomendação e a razão. O cliente decide sem pressão.',
                'confirmar' => 'Confirmar com duas perguntas: “Qual caminho você prefere e por quê?” e “O que ficou combinado sobre seus pagamentos enquanto buscamos a solução?”.',
            ],
            'gates' => [
                'objetivo_mensuravel' => ['label' => 'Estratégia e objetivo mensurável registrados', 'block' => true],
                'limites' => ['label' => 'Limites da negociação e decisões pendentes definidos', 'block' => false],
            ],
            'needs' => ['path_decision'],
            'deliverable' => 'Estratégia e objetivo registrados. Se o cliente quiser refletir, combinar data de retorno. Sem viabilidade revisional, oferecer negociação ou orientação com limites explícitos.',
            'next' => ['contratacao', 'documentos', 'encerramento'],
        ],
        'contratacao' => [
            'n' => 6, 'phase' => 'buscando',
            'label' => 'Contratação',
            'title' => 'Contratação e plano de trabalho',
            'owner' => ['advogado', 'gestor', 'financeiro'],
            'entry' => 'Caminho escolhido pelo cliente.',
            'goal'  => 'Concluir a documentação de contratação em até 1 dia útil após a escolha, quando possível.',
            'steps' => [
                'documentos' => 'Enviar contrato de honorários, procuração com poderes adequados e documentos adicionais, com assinatura acessível e verificação de identidade.',
                'escopo' => 'Especificar o que o serviço cobre: diagnóstico, negociação, ação, defesa em busca e apreensão, recursos, cálculos e cumprimento.',
                'honorarios' => 'Definir honorário inicial, eventual êxito, base e momento de cobrança, despesas externas, tratamento de acordo, desistência e prestação de contas, conforme a tabela da seccional.',
                'exemplo' => 'Dar exemplo numérico simples da base de êxito, sem somar o mesmo benefício em duas bases.',
                'equipe' => 'Confirmar gestor, advogado, substituto, canal, frequência de atualização, urgências e primeiro prazo de entrega. Enviar cópia de tudo que o cliente assinou.',
                'transferencia' => 'Transferência interna com resumo, documentos, cálculo, riscos, autorização e tarefas. O novo executor confirma que recebeu o caso.',
            ],
            'gates' => [
                'assinado' => ['label' => 'Contrato de honorários e procuração assinados', 'block' => true],
                'escopo_compreendido' => ['label' => 'Escopo e custos compreendidos pelo cliente', 'block' => true],
                'transferencia' => ['label' => 'Executor confirmou recebimento do caso', 'block' => false],
            ],
            'needs' => ['team'],
            'deliverable' => 'Representação regular, escopo e custos compreendidos e plano de trabalho aberto.',
            'next' => ['negociacao', 'preparacao_judicial'],
        ],
        'negociacao' => [
            'n' => 7, 'phase' => 'acontecendo',
            'label' => 'Negociação',
            'title' => 'Negociação orientada ao resultado',
            'owner' => ['negociador', 'advogado'],
            'entry' => 'Contratação ou autorização adequada.',
            'goal'  => 'Primeira proposta em até 2 dias úteis após autorização e dados suficientes.',
            'steps' => [
                'teto' => 'Definir teto de entrada, prestação máxima, data possível de pagamento e objetivo prioritário. Não ultrapassar o limite autorizado sem nova decisão do cliente.',
                'credor' => 'Identificar o credor atual e poderes de empresas de cobrança ou cessionários. Obter saldo discriminado e condições de quitação.',
                'proposta' => 'Enviar proposta fundamentada, com divergências apuradas e pedido de solução completa, solicitando prazo de validade e resposta.',
                'comparar' => 'Registrar para cada oferta: saldo de referência, desconto, entrada, parcelas, encargos, total, garantias, vencimentos e consequências do atraso.',
                'controle' => 'Cobrar no prazo do credor ou em 5 dias úteis. Após duas rodadas ou 10 dias úteis sem avanço, o advogado revisa a estratégia.',
                'silencio' => 'Manter o cliente informado de cada oferta. Nenhum silêncio do cliente vale como aceite.',
            ],
            'gates' => [
                'decisao_real' => ['label' => 'Decisão do cliente registrada e plano para o próximo caminho', 'block' => true],
            ],
            'needs' => [],
            'deliverable' => 'Histórico de ofertas, comparação do custo total, recomendação e aceite ou recusa documentados.',
            'next' => ['acordo', 'preparacao_judicial', 'encerramento'],
        ],
        'acordo' => [
            'n' => 8, 'phase' => 'acontecendo',
            'label' => 'Acordo em cumprimento',
            'title' => 'Formalização e cumprimento do acordo',
            'owner' => ['advogado', 'gestor', 'financeiro'],
            'entry' => 'Proposta aceita pelo cliente.',
            'goal'  => 'Revisar o instrumento em até 1 dia útil após recebê-lo, observada a validade da oferta.',
            'steps' => [
                'instrumento' => 'Conferir partes, contrato, veículo, saldo reconhecido, descontos, valores, datas, encargos e consequência de cada obrigação.',
                'pontos' => 'Tratar quitação, permanência do financiamento, boletos, restrições, gravame, processos, destino do veículo e valores a devolver.',
                'poderes' => 'Avaliar poderes para transigir, homologação e efeitos sobre ações e recursos. Entrega do bem não significa quitação automática.',
                'boleto' => 'Confirmar com o cliente o texto final, o dinheiro disponível e o calendário. Verificar beneficiário e autenticidade do boleto em canal oficial.',
                'acompanhar' => 'Registrar assinaturas, avisar pagamento 3 dias antes do vencimento, confirmar comprovantes e conferir se o banco implantou o que assinou.',
                'descumprimento' => 'Se alguém descumprir: reunir prova, cobrar formalmente e reabrir tarefa com data e responsável.',
            ],
            'gates' => [
                'formal' => ['label' => 'Acordo formal assinado e calendário de obrigações registrado', 'block' => true],
            ],
            'needs' => [],
            'deliverable' => 'Acordo formal e obrigações cumpridas ou monitoradas com calendário. Registrar “acordo em cumprimento”, nunca quitação antecipada.',
            'next' => ['conferencia', 'negociacao'],
        ],
        'preparacao_judicial' => [
            'n' => 9, 'phase' => 'acontecendo',
            'label' => 'Preparação judicial',
            'title' => 'Preparação e início da atuação judicial',
            'owner' => ['advogado'],
            'entry' => 'Fundamento e prova suficientes, estratégia autorizada e representação regular.',
            'goal'  => 'A urgência pode exigir antecipar medidas cabíveis.',
            'steps' => [
                'partes' => 'Confirmar partes, relação de consumo, competência, ação ou defesa adequada, processo existente e limites temporais.',
                'fatos' => 'Narrar fatos específicos e vincular cada pedido à prova correspondente.',
                'calculos' => 'Validar cálculos e o valor da causa conforme os pedidos, sem usar automaticamente o valor total financiado.',
                'incontroverso' => 'Discriminar obrigações questionadas e quantificar o valor incontroverso; orientar a continuidade dos pagamentos.',
                'tutela' => 'Avaliar tutela provisória somente com requisitos e prova pertinentes.',
                'precedentes' => 'Conferir precedentes atuais e eventuais suspensões (ex.: Tema 1378 do STJ).',
                'defesa' => 'Havendo busca e apreensão, cumprir o rito e os prazos próprios; a revisional não substitui a defesa.',
            ],
            'gates' => [
                'protocolo' => ['label' => 'Protocolo registrado (número, juízo, data e pedidos)', 'block' => true],
                'orientacao' => ['label' => 'Resumo de uma página e orientação de pagamentos enviados ao cliente', 'block' => true],
            ],
            'needs' => [],
            'deliverable' => 'Atuação protocolada e revisada, calendário aberto, orientação escrita e responsável pela medida urgente.',
            'next' => ['processo', 'acordo'],
        ],
        'processo' => [
            'n' => 10, 'phase' => 'acontecendo',
            'label' => 'Processo em curso',
            'title' => 'Acompanhamento de provas e atos processuais',
            'owner' => ['advogado', 'gestor'],
            'entry' => 'Processo ativo.',
            'goal'  => 'Comunicação quinzenal mesmo sem movimentação.',
            'steps' => [
                'intimacoes' => 'Receber e conferir comunicações oficiais diariamente, registrando prazo, executor, substituto e revisão. Prazo jurídico e data de atualização ao cliente são controles distintos.',
                'contestacao' => 'Após contestação, verificar contratos, demonstrativos e divergências em relação ao cálculo; decidir sobre exibição, prova técnica ou perícia.',
                'audiencia' => 'Para audiência: enviar data, formato e instruções; preparar o cliente com antecedência. Nunca deixar o cliente descobrir uma audiência por mensagem automática.',
                'reavaliar' => 'Reavaliar negociação após prova relevante, decisão urgente, perícia ou mudança na capacidade de pagamento.',
            ],
            'gates' => [
                'pronto' => ['label' => 'Provas e atos tratados; processo pronto para decisão ou acordo', 'block' => false],
            ],
            'needs' => [],
            'deliverable' => 'Cliente preparado e processo pronto para decisão ou acordo. Toda novidade relevante gera tarefa e mensagem com efeito concreto.',
            'next' => ['decisao_recursos', 'acordo'],
        ],
        'decisao_recursos' => [
            'n' => 11, 'phase' => 'acontecendo',
            'label' => 'Decisão e recursos',
            'title' => 'Decisão e avaliação de recursos',
            'owner' => ['advogado'],
            'entry' => 'Decisão proferida.',
            'goal'  => 'Explicar a decisão em até 1 dia útil após conferência, sem prejudicar prazo recursal menor.',
            'steps' => [
                'separar' => 'Separar pedidos acolhidos, rejeitados e ainda não definidos; informar efeitos sobre saldo, pagamentos, restituição, posse do veículo, custas e honorários.',
                'natureza' => 'Distinguir decisão provisória, sentença sujeita a recurso e decisão definitiva.',
                'recomendacao' => 'Apresentar recomendação recursal por escrito, com fundamento, custo, risco e utilidade. Registrar a decisão do cliente antes do prazo.',
                'transito' => 'Acompanhar recursos e suspensões; confirmar o trânsito em julgado e avaliar obrigações exigíveis.',
            ],
            'gates' => [
                'compreendida' => ['label' => 'Decisão explicada e compreendida pelo cliente', 'block' => true],
                'recurso' => ['label' => 'Recurso ou ausência de recurso fundamentados', 'block' => true],
            ],
            'needs' => [],
            'deliverable' => 'Vitória segue para apuração e cumprimento. Derrota definitiva segue para orientação e alternativas, sem falsear o resultado.',
            'next' => ['apuracao', 'processo', 'acordo', 'encerramento'],
        ],
        'apuracao' => [
            'n' => 12, 'phase' => 'acontecendo',
            'label' => 'Apuração e cumprimento',
            'title' => 'Apuração e cumprimento da decisão',
            'owner' => ['advogado', 'calculos', 'financeiro'],
            'entry' => 'Título ou decisão exigível.',
            'goal'  => 'Converter o reconhecimento do direito em entrega efetiva.',
            'steps' => [
                'dispositivo' => 'Ler o dispositivo e os critérios (taxa, cobranças excluídas, período, correção, juros, compensação, forma de restituição).',
                'demonstrativo' => 'Produzir demonstrativo separado: novo saldo, parcelas restantes, valores a devolver, montante compensado, atualização, despesas e honorários.',
                'exigir' => 'Pedir pagamento e obrigações de fazer com precisão: recalcular, emitir boletos, restituir, dar quitação ou regularizar registro.',
                'prazos' => 'Registrar cada prazo, o evento que o iniciou e a comprovação; verificar no primeiro dia útil após o término.',
                'recebimento' => 'Confirmar valor, data e beneficiário de depósitos; entregar demonstrativo de valor bruto, deduções autorizadas e líquido do cliente.',
            ],
            'gates' => [
                'apurado' => ['label' => 'Valores apurados e obrigações cumpridas, ou pendência objetiva sob acompanhamento', 'block' => true],
            ],
            'needs' => [],
            'deliverable' => 'Valores exigíveis apurados e obrigações cumpridas, ou pendência objetiva acompanhada. Dinheiro não recebido não é benefício entregue.',
            'next' => ['conferencia'],
        ],
        'conferencia' => [
            'n' => 13, 'phase' => 'conferindo',
            'label' => 'Conferência',
            'title' => 'Conferência da solução na vida do cliente',
            'owner' => ['gestor', 'advogado', 'financeiro'],
            'entry' => 'Acordo ou decisão em cumprimento.',
            'goal'  => 'Conferir cada entrega em até 2 dias úteis após evidência suficiente.',
            'steps' => [
                'objetivo' => 'Retomar o objetivo registrado no início e comparar o resultado contratado com o resultado entregue.',
                'scr' => 'Conferir o SCR separadamente dos cadastros privados; não prometer apagar histórico verdadeiro.',
                'teste' => 'Teste prático: “Você recebeu o boleto correto?”, “O pagamento foi reconhecido?”, “O dinheiro da devolução entrou?”, “Ainda recebeu cobrança diferente do combinado?”.',
                'divergencia' => 'Qualquer divergência reabre a obrigação na negociação ou no cumprimento. Não transferir ao cliente a tarefa de cobrar sozinho.',
            ],
            'gates' => [
                'checklist' => ['label' => 'Checklist de resultado validado e comprovação guardada', 'block' => true],
            ],
            'needs' => ['solution_evidence'],
            'deliverable' => 'Checklist de resultado validado e comprovação guardada. Obrigação futura essencial continua monitorada.',
            'next' => ['encerramento', 'acordo', 'apuracao', 'negociacao'],
        ],
        'encerramento' => [
            'n' => 14, 'phase' => 'conferindo',
            'label' => 'Encerramento',
            'title' => 'Prestação de contas e encerramento',
            'owner' => ['gestor', 'financeiro', 'supervisor', 'advogado'],
            'entry' => 'Resultados conferidos ou desfecho adverso devidamente tratado.',
            'goal'  => 'Resumo de uma página, pacote final e acompanhamento em 30 dias.',
            'steps' => [
                'pacote' => 'Enviar resumo de uma página com problema inicial, caminho seguido, solução e efeito financeiro, com acordo ou decisão, quitação, demonstrativos, comprovantes, honorários e despesas.',
                'responsabilidades' => 'Explicar o que continua com o cliente: vencimentos futuros, guarda de documentos, atualização cadastral, compromissos do acordo.',
                'posterior' => 'Combinar contato em 30 dias: “O combinado continua funcionando?”, “Surgiu alguma cobrança diferente?”, “Você sabe o que precisa fazer daqui em diante?”.',
                'avaliacao' => 'Pedir avaliação: “De 1 a 5, quanto foi fácil resolver as etapas conosco?” e “O que poderíamos simplificar?”.',
            ],
            'gates' => [],
            'needs' => ['closing_list', 'outcome'],
            'deliverable' => 'Cliente com solução comprovada ou limites do desfecho compreendidos, contas prestadas e pendências encaminhadas.',
            'next' => [],
        ],
    ];
}

function stage(string $key): array
{
    return stages()[$key] ?? stages()['acolhimento'];
}

function stage_label(?string $key): string
{
    return $key && isset(stages()[$key]) ? stages()[$key]['label'] : '—';
}

/** Etapa 2 — tabela de classificação de urgência. */
const URGENCY_CLASSES = [
    'apreensao_prazo' => [
        'label' => 'Veículo apreendido, mandado, citação ou intimação com prazo',
        'action' => 'Obter documento completo, data do recebimento e acesso aos autos; identificar rito e prazos.',
        'result' => 'Plano urgente com medida, prazo e responsável.',
        'urgent' => true,
    ],
    'notificacao' => [
        'label' => 'Notificação do banco ou cartório, ameaça documentada ou busca já ajuizada',
        'action' => 'Verificar origem, validade, fase e prazo; avaliar defesa e negociação em paralelo.',
        'result' => 'Risco explicado e tarefas prioritárias abertas.',
        'urgent' => true,
    ],
    'atraso' => [
        'label' => 'Atraso sem documento urgente',
        'action' => 'Conferir vencimentos, cobranças e capacidade de pagamento.',
        'result' => 'Plano de regularização e análise ordinária.',
        'urgent' => false,
    ],
    'em_dia' => [
        'label' => 'Contrato em dia ou quitado',
        'action' => 'Analisar revisão ou restituição, inclusive limites temporais.',
        'result' => 'Encaminhamento à auditoria.',
        'urgent' => false,
    ],
];

/** Seção 4 — papéis. */
const ROLE_DUTIES = [
    'gestor'     => ['Ser o contato único, organizar documentos, cobrar tarefas e comunicar retornos.', 'Agenda e execução das tarefas de atendimento.'],
    'advogado'   => ['Definir estratégia, analisar riscos, revisar peças e orientar decisões.', 'Viabilidade, urgência, pedidos e recursos.'],
    'calculos'   => ['Reconstruir o contrato e demonstrar os cenários.', 'Memória de cálculo, com validação jurídica.'],
    'negociador' => ['Conversar com o credor e documentar propostas.', 'Apresentar ofertas dentro da autorização do cliente.'],
    'financeiro' => ['Controlar despesas, recebimentos e prestação de contas.', 'Conferência dos valores e documentos financeiros.'],
    'supervisor' => ['Cobrir ausências, resolver atrasos e revisar encerramentos.', 'Escalonamento de falhas e aprovação do fechamento.'],
];

/** Seção 4 — metas internas propostas. */
const SERVICE_GOALS = [
    ['event' => 'Primeiro contato', 'goal' => 'Resposta humana em até 1 hora útil', 'miss' => 'Responder assim que possível e informar nova previsão ao cliente', 'public' => 'Sidney responde assim que possível no horário de atendimento.'],
    ['event' => 'Notificação ou apreensão', 'goal' => 'Encaminhamento ao advogado imediato; avaliação inicial em até 2 horas úteis', 'miss' => 'Prioridade imediata: avaliar antes das demais tarefas', 'public' => 'Notificação ou apreensão vai direto ao advogado, com avaliação inicial em até 2 horas úteis.'],
    ['event' => 'Recebimento documental', 'goal' => 'Conferência em até 1 dia útil', 'miss' => 'Aviso de pendências com nova data', 'public' => 'Cada documento enviado é conferido em até 1 dia útil.'],
    ['event' => 'Análise ordinária completa', 'goal' => 'Até 2 dias úteis após documentos suficientes', 'miss' => 'Justificar complexidade e fixar nova entrega', 'public' => 'A análise do contrato fica pronta em até 2 dias úteis depois que os documentos estiverem completos.'],
    ['event' => 'Proposta ou decisão relevante', 'goal' => 'Comunicar em até 1 dia útil, antes se houver prazo curto', 'miss' => 'Comunicar ao cliente ainda hoje', 'public' => 'Toda proposta do banco ou decisão relevante chega até você em até 1 dia útil.'],
    ['event' => 'Caso sem novidade', 'goal' => 'Atualizar a cada 15 dias corridos', 'miss' => 'Dar notícia ao cliente com a próxima data', 'public' => 'Mesmo sem novidade, você recebe notícia do seu caso a cada 15 dias.'],
];

/** Etapa 3 — catálogo de documentos. */
const DOC_CATALOG = [
    'contrato' => [
        'label' => 'Contrato completo, anexos e renegociações',
        'why'   => 'Mostra a taxa, os custos, as garantias e as condições que você assinou.',
        'how'   => 'Pode ser PDF ou fotos em sequência. Procure no aplicativo do banco, no internet banking, no e-mail da contratação ou peça na concessionária. Se não encontrar, nós ajudamos a pedir ao banco.',
    ],
    'historico' => [
        'label' => 'Histórico de pagamentos e extrato da operação',
        'why'   => 'Permite refazer o saldo e identificar o que já foi pago e cobrado.',
        'how'   => 'Peça ao banco o extrato específico do financiamento. Comprovantes soltos também ajudam.',
    ],
    'titular' => [
        'label' => 'Documento de identidade e comprovante de endereço do titular',
        'why'   => 'Identificação e representação.',
        'how'   => 'Foto legível do documento com CPF e de uma conta recente. Se já nos enviou, não precisa repetir.',
    ],
    'identidade' => [
        'label' => 'Documento de identificação pessoal',
        'why'   => 'Identifica você nos documentos da contratação, como a procuração.',
        'how'   => 'Foto legível do RG ou da CNH (frente e verso). Não precisa mandar os dois.',
    ],
    'residencia' => [
        'label' => 'Comprovante de residência',
        'why'   => 'Confirma o endereço que vai nos documentos da contratação.',
        'how'   => 'Conta de luz, água, telefone ou internet recente, em seu nome ou de quem mora com você.',
    ],
    'veiculo' => [
        'label' => 'Documento do veículo (CRLV)',
        'why'   => 'Identifica o bem e o gravame do financiamento.',
        'how'   => 'O CRLV digital pode ser baixado no aplicativo Carteira Digital de Trânsito, acessado por você mesmo.',
    ],
    'notificacoes' => [
        'label' => 'Notificações, cobranças e prova de negativação',
        'why'   => 'Mostra o risco atual e comprova os fatos.',
        'how'   => 'Envie a mensagem ou carta completa, com a origem e a data. Print de tela serve, desde que mostre de onde veio.',
    ],
    'processo' => [
        'label' => 'Mandado, citação, intimação ou auto de apreensão',
        'why'   => 'Define o procedimento e os prazos que precisam ser conferidos com urgência.',
        'how'   => 'Fotografe todas as páginas, inclusive o verso. Se recebeu por oficial de justiça, anote a data e a hora.',
    ],
    'seguro_tarifas' => [
        'label' => 'Proposta de seguro e documentos de tarifas',
        'why'   => 'Permite verificar cobranças acessórias.',
        'how'   => 'Só pedimos quando o contrato mostra essas cobranças.',
    ],
    'venda' => [
        'label' => 'Comunicação ou comprovante da venda do veículo',
        'why'   => 'Mostra por quanto o veículo foi vendido e quando.',
        'how'   => 'Carta, e-mail ou mensagem do banco sobre o leilão ou venda. Se não recebeu nada, conte para nós.',
    ],
    'despesas' => [
        'label' => 'Demonstrativo do débito e das despesas abatidas',
        'why'   => 'Permite conferir se o valor da venda foi aplicado corretamente e se existe saldo.',
        'how'   => 'Normalmente vem junto com a cobrança do saldo. Se o banco não enviou, a ausência também é um dado importante.',
    ],
    'renda' => [
        'label' => 'Comprovante de renda',
        'why'   => 'Permite a Sidney avaliar o pedido de justiça gratuita que você pediu para considerar.',
        'how'   => 'Holerite, extrato de benefício, declaração de imposto de renda ou extrato bancário recente.',
    ],
];

const DOC_STATUS = [
    'falta'      => ['label' => 'Falta',                 'client' => 'Falta',                         'tone' => 'warn'],
    'ajuda'      => ['label' => 'Vamos ajudar a obter',  'client' => 'Vamos ajudar a obter',          'tone' => 'info'],
    'recebido'   => ['label' => 'Recebido · conferir',   'client' => 'Recebido, em conferência',      'tone' => 'info'],
    'conferido'  => ['label' => 'Conferido',             'client' => 'Já recebido',                   'tone' => 'ok'],
    'pendencia'  => ['label' => 'Reenviar',              'client' => 'Precisamos de nova cópia',      'tone' => 'warn'],
    'nao_possui' => ['label' => 'Cliente não possui',    'client' => 'Você informou que não possui',  'tone' => 'muted'],
    'dispensado' => ['label' => 'Dispensado',            'client' => 'Não é necessário',              'tone' => 'muted'],
];

/** Etapa 5 — caminhos. */
const PATHS = [
    'negociar'  => ['label' => 'Negociar com o credor', 'when' => 'Busca de pagamento sustentável ou quitação.', 'explain' => 'Total a pagar, prazo, entrada e efeitos da renegociação.'],
    'revisao'   => ['label' => 'Revisão judicial', 'when' => 'Fundamento e prova suficientes, com utilidade concreta.', 'explain' => 'Custos, risco de derrota, demora e necessidade de cumprimento posterior.'],
    'prova'     => ['label' => 'Obter prova antes de decidir', 'when' => 'Contrato ou histórico indispensável ausente.', 'explain' => 'Documento faltante, medida para obtê-lo e data de nova análise.'],
    'orientar'  => ['label' => 'Manter contrato e orientar', 'when' => 'Falta de fundamento ou benefício líquido insuficiente.', 'explain' => 'Motivo, alternativas possíveis e sinais para reavaliar.'],
];

/** Etapa 4 — conclusões possíveis do parecer. */
const AUDIT_CONCLUSIONS = [
    'revisao'      => 'Revisão fundamentada',
    'negociacao'   => 'Negociação prioritária',
    'insuficiente' => 'Prova ainda insuficiente',
    'nao_recomendada' => 'Revisão não recomendada',
];

/** Etapa 11 — resultado da decisão. */
const DECISION_RESULTS = [
    'favoravel'   => ['label' => 'Favorável', 'next' => 'Verificar recurso do banco e possibilidade de cumprimento.', 'client' => 'Medidas, custos e eventual cumprimento provisório.'],
    'parcial'     => ['label' => 'Parcial', 'next' => 'Quantificar o que foi obtido e o que faltou.', 'client' => 'Aceitar, negociar ou recorrer com orientação.'],
    'desfavoravel' => ['label' => 'Desfavorável', 'next' => 'Analisar fundamentos, correções cabíveis e risco financeiro.', 'client' => 'Recurso útil ou estratégia de negociação.'],
    'extincao'    => ['label' => 'Extinção sem exame do pedido', 'next' => 'Verificar causa, prazo e possibilidade de correção ou recurso.', 'client' => 'Caminho adequado para prosseguir.'],
];

/** Etapa 13 — conferência da solução. */
const SOLUTION_ITEMS = [
    'revisado'     => ['label' => 'Financiamento revisado', 'evidence' => 'Novo demonstrativo, saldo e boletos', 'done' => 'Valores e calendário correspondem ao acordo ou decisão'],
    'renegociada'  => ['label' => 'Prestação renegociada', 'evidence' => 'Instrumento, custo total e primeiro boleto correto', 'done' => 'Cliente entende o compromisso e a implantação está conferida'],
    'quitacao'     => ['label' => 'Quitação integral', 'evidence' => 'Comprovante de pagamento e termo de quitação', 'done' => 'Credor reconhece saldo zero e trata obrigações de liberação'],
    'restituicao'  => ['label' => 'Restituição', 'evidence' => 'Comprovante de crédito ou levantamento efetivo', 'done' => 'Dinheiro disponível ao destinatário correto e contas prestadas'],
    'gravame'      => ['label' => 'Baixa do gravame após quitação', 'evidence' => 'Providência do credor e consulta ao registro competente', 'done' => 'Restrição vinculada ao financiamento efetivamente baixada'],
    'negativacao'  => ['label' => 'Regularização de negativação', 'evidence' => 'Consulta e comprovante pertinentes à obrigação', 'done' => 'Registro afetado tratado conforme acordo ou decisão'],
    'veiculo'      => ['label' => 'Proteção ou devolução do veículo', 'evidence' => 'Ordem ou acordo e prova de cumprimento', 'done' => 'Situação real do bem corresponde à solução exigível'],
    'entrega'      => ['label' => 'Acordo com entrega do veículo', 'evidence' => 'Documento de entrega e apuração final', 'done' => 'Destino do bem e existência de saldo residual esclarecidos'],
];

/** Etapa 14 — lista obrigatória para autorizar o fechamento. */
const CLOSING_LIST = [
    'objetivo'     => 'Objetivo inicial confrontado com resultado efetivo e documentos de prova.',
    'saldo'        => 'Saldo, parcelas, dinheiro e situação do veículo conferidos, quando aplicáveis.',
    'baixas'       => 'Obrigações de baixa ou correção tratadas, com comprovação ou pendência explícita.',
    'processos'    => 'Processos, recursos, depósitos e prazos pertinentes revisados pelo advogado.',
    'contas'       => 'Honorários e despesas conciliados; contas e valores do cliente entregues.',
    'proximas'     => 'Próximas obrigações explicadas e cópias acessíveis ao cliente.',
    'sem_pendente' => 'Nenhuma tarefa essencial sem responsável, prazo e destino.',
];

/** Etapa 14 — categorias de desfecho. */
const OUTCOMES = [
    'resolvido'        => ['label' => 'Resolvido', 'text' => 'Objetivo alcançado e obrigações essenciais cumpridas.'],
    'parcial'          => ['label' => 'Resolvido parcialmente', 'text' => 'Parte do objetivo foi entregue; explicar qual parte e o tratamento do restante.'],
    'monitoramento'    => ['label' => 'Em cumprimento ou monitoramento', 'text' => 'A solução depende de obrigações futuras.'],
    'sem_alcance'      => ['label' => 'Encerrado sem alcance do objetivo', 'text' => 'Resultado adverso ou ausência de solução viável, após orientação e medidas cabíveis.'],
    'decisao_cliente'  => ['label' => 'Encerrado por decisão do cliente', 'text' => 'Desistência ou mudança de representação, com transição regular.'],
];

/** Seção 19 — exceções que precisam ter próximo passo. */
const EXCEPTIONS = [
    'contrato_ausente'   => ['label' => 'Contrato ausente', 'who' => 'Gestor e advogado', 'action' => 'Solicitar ao banco e avaliar exibição; manter prazo de revisão.'],
    'calculo_inconclusivo' => ['label' => 'Cálculo inconclusivo', 'who' => 'Analista e advogado', 'action' => 'Identificar dado faltante; não apresentar economia como certeza.'],
    'apreensao_durante'  => ['label' => 'Busca ou apreensão durante negociação', 'who' => 'Advogado', 'action' => 'Retomar triagem urgente; rever medidas e prazos imediatamente.', 'urgent' => true],
    'oferta_expira'      => ['label' => 'Oferta expira antes da decisão', 'who' => 'Negociador', 'action' => 'Confirmar renovação formal; não executar oferta vencida.'],
    'sem_resposta'       => ['label' => 'Cliente deixa de responder', 'who' => 'Gestor e advogado', 'action' => 'Tentar canais autorizados; avaliar prazos e transição regular. Silêncio não autoriza arquivamento com prazo ativo.'],
    'banco_descumpre'    => ['label' => 'Banco descumpre acordo ou decisão', 'who' => 'Advogado', 'action' => 'Documentar e exigir cumprimento; manter item aberto.'],
    'boleto_antigo'      => ['label' => 'Novo boleto volta ao valor antigo', 'who' => 'Gestor e cálculos', 'action' => 'Conferir, cobrar correção e atualizar orientação ao cliente.'],
    'fora_orcamento'     => ['label' => 'Acordo não cabe mais no orçamento', 'who' => 'Advogado e negociador', 'action' => 'Reavaliar antes do atraso e buscar condições possíveis.'],
    'fraude'             => ['label' => 'Suspeita de fraude ou cobrança falsa', 'who' => 'Advogado e financeiro', 'action' => 'Suspender pagamento suspeito e confirmar pelo canal oficial.', 'urgent' => true],
    'mudanca_advogado'   => ['label' => 'Mudança de advogado ou desistência', 'who' => 'Advogado', 'action' => 'Informar consequências, entregar documentos e organizar transição.'],
    'adverso_definitivo' => ['label' => 'Resultado judicial adverso definitivo', 'who' => 'Advogado', 'action' => 'Tratar custos e alternativas; registrar ausência de alcance do objetivo.'],
    'obrigacao_futura'   => ['label' => 'Obrigação futura de acordo parcelado', 'who' => 'Gestor', 'action' => 'Monitorar até entrega final no escopo contratado; não antecipar quitação.'],
];

/** Respostas da triagem (site). */
const NOTICE_OPTIONS = [
    'apreendido' => 'Sim, o veículo já foi apreendido',
    'justica'    => 'Recebi documento da Justiça (citação, intimação ou mandado)',
    'cartorio'   => 'Recebi notificação de cartório',
    'banco'      => 'Recebi carta, e-mail ou mensagem do banco',
    'nenhum'     => 'Não recebi nada disso',
    'nao_sei'    => 'Não sei dizer',
];

const PRIORITY_OPTIONS = [
    'manter_veiculo' => ['label' => 'Manter o veículo', 'sentence' => 'manter o veículo'],
    'reduzir_parcela' => ['label' => 'Ter uma parcela que eu consiga pagar', 'sentence' => 'encontrar uma prestação que você consiga pagar'],
    'quitar'         => ['label' => 'Negociar ou quitar a dívida', 'sentence' => 'negociar ou quitar a dívida'],
    'restituicao'    => ['label' => 'Recuperar valores pagos a mais', 'sentence' => 'verificar se há valores pagos indevidamente para recuperar'],
    'apreensao'      => ['label' => 'Resolver uma apreensão ou ameaça de apreensão', 'sentence' => 'tratar a apreensão ou a ameaça de perder o veículo'],
    'venda_saldo'    => ['label' => 'Entender a venda do veículo e o saldo cobrado', 'sentence' => 'entender a venda do veículo e conferir o saldo cobrado'],
    'outro'          => ['label' => 'Outra coisa', 'sentence' => 'resolver a questão que você descreveu'],
];

const CONTACT_PREFS = [
    'whatsapp' => 'WhatsApp (mensagem)',
    'audio'    => 'WhatsApp (áudio)',
    'ligacao'  => 'Ligação',
    'email'    => 'E-mail',
];

const UFS = ['AC', 'AL', 'AP', 'AM', 'BA', 'CE', 'DF', 'ES', 'GO', 'MA', 'MT', 'MS', 'MG', 'PA', 'PB', 'PR', 'PE', 'PI', 'RJ', 'RN', 'RS', 'RO', 'RR', 'SC', 'SP', 'SE', 'TO'];

const TASK_DEPENDS = [
    'equipe'     => 'Equipe',
    'cliente'    => 'Cliente',
    'banco'      => 'Banco / credor',
    'judiciario' => 'Judiciário',
    'terceiro'   => 'Terceiro',
];
