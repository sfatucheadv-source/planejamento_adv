<?php
declare(strict_types=1);

/**
 * Seção 21 — mensagens para reduzir o esforço do cliente.
 * Campos {{assim}} são preenchidos com os dados do caso; os que ficarem
 * entre colchetes precisam ser completados pelo gestor, que confere fatos,
 * datas e orientação jurídica antes de enviar.
 */
const MESSAGE_TEMPLATES = [
    // Seção 5.4 do plano de reforma: mensagens do atendimento inicial, em nome de Sidney.
    'inicial_complemento' => [
        'label' => 'Atendimento inicial · complemento',
        'stage' => 'documentos',
        'text'  => "Para continuar, falta apenas: [lista]. Você pode enviar aqui. Se não tiver algum item, me avise.",
    ],
    'inicial_recebimento' => [
        'label' => 'Atendimento inicial · arquivo recebido',
        'stage' => 'documentos',
        'text'  => "Arquivo recebido. Sidney ainda vai conferir se está completo e legível.",
    ],
    'inicial_preferencia' => [
        'label' => 'Atendimento inicial · formato da conversa',
        'stage' => 'decisao',
        'text'  => "A conversa pode ser por vídeo no Google Meet. Se preferir, pode ser por telefone, WhatsApp por texto ou por este chat. Qual formato funciona melhor?",
    ],
    'inicial_reuniao' => [
        'label' => 'Atendimento inicial · reunião confirmada',
        'stage' => 'decisao',
        'text'  => "Sua conversa com Sidney está confirmada para [data/hora, Brasília], por [canal]. [Link ou orientação aplicável].",
    ],
    'inicial_apos_reuniao' => [
        'label' => 'Atendimento inicial · depois da reunião',
        'stage' => 'decisao',
        'text'  => "Este é o resumo do que conversamos e o próximo passo: [resumo/ação/data]. Se alguma informação estiver diferente, você pode corrigir aqui.",
    ],
    'primeiro_contato' => [
        'label' => 'Primeiro contato',
        'stage' => 'acolhimento',
        'text'  => "Olá, {{nome}}. Sou {{gestor}}, do escritório Sidney Fatuche Advocacia, e vou organizar o seu atendimento.\n\nAntes de tudo: você recebeu notificação do banco, cartório ou Justiça, ou o veículo já foi apreendido? Depois dessa verificação, te explico o que precisamos para analisar as opções.",
    ],
    'acolhimento_resumo' => [
        'label' => 'Confirmação do entendimento',
        'stage' => 'acolhimento',
        'text'  => "{{nome}}, deixa eu confirmar se entendi: {{resumo}}\n\nVou organizar seu atendimento e acompanhar os próximos passos. Primeiro conferimos se há algum prazo urgente. Depois reunimos os documentos e o advogado explica as opções para o seu caso. Nosso próximo contato será {{proximo_retorno}}.",
    ],
    'documentos' => [
        'label' => 'Recebimento de documentos',
        'stage' => 'documentos',
        'text'  => "Recebemos {{docs_recebidos}}. Falta apenas {{docs_faltantes}}.\n\nVocê pode conseguir por [caminho]. Se tiver dificuldade, te ajudamos por ligação. Com isso, nosso retorno sobre a análise fica previsto para {{proximo_retorno}}.",
    ],
    'analise' => [
        'label' => 'Apresentação da análise',
        'stage' => 'decisao',
        'text'  => "A análise encontrou [achado confirmado]. Nossa recomendação é [caminho] porque [razão]. A estimativa é [valor/cenário], sujeita a [condições].\n\nVamos explicar o custo, os riscos e as alternativas antes de você decidir.",
    ],
    'oferta' => [
        'label' => 'Oferta de acordo',
        'stage' => 'negociacao',
        'text'  => "O banco ofereceu entrada de {{oferta_entrada}} e {{oferta_parcelas}} parcelas de {{oferta_parcela}}, com total de {{oferta_total}}.\n\nO efeito sobre [saldo/veículo/registros] é [efeito]. A oferta vence em {{oferta_validade}}. Nossa avaliação é [recomendação]. Só confirmaremos com sua autorização.",
    ],
    'sem_movimentacao' => [
        'label' => 'Sem movimentação',
        'stage' => '*',
        'text'  => "Olá, {{nome}}. Conferimos seu caso em {{hoje}} e ainda aguardamos [evento]. Enquanto isso, estamos [ação].\n\nVocê precisa [ação específica, se houver]. Nosso próximo retorno será até {{proximo_retorno}}. Se receber alguma notificação, envie pelo WhatsApp do escritório.",
    ],
    'decisao_desfavoravel' => [
        'label' => 'Decisão desfavorável',
        'stage' => 'decisao_recursos',
        'text'  => "{{nome}}, a decisão rejeitou [pedido] por [motivo em linguagem simples]. Isso significa [efeito concreto].\n\nO advogado avaliou [recurso/alternativa] e recomenda [caminho], com custo e risco de [explicação]. Precisamos conversar até [data] para definir o próximo passo.",
    ],
    'implantacao' => [
        'label' => 'Resultado em implantação',
        'stage' => 'conferencia',
        'text'  => "Conseguimos [acordo/decisão], com [efeito]. Agora vamos conferir se o banco [obrigações]. Seu caso permanece em acompanhamento até essa verificação.\n\nVocê precisa fazer [ação], em [data], conforme a orientação enviada.",
    ],
    'encerramento' => [
        'label' => 'Encerramento com solução comprovada',
        'stage' => 'encerramento',
        'text'  => "Conferimos [resultado efetivo] e os comprovantes seguem anexos. Seu compromisso agora é [obrigação, se houver]. A prestação de contas mostra [resumo dos valores].\n\nCombinamos uma nova checagem em {{retorno_30}}. Se surgir cobrança diferente do que foi definido, fale conosco pelo WhatsApp do escritório.",
    ],
    'cartao' => [
        'label' => 'Cartão de acompanhamento',
        'stage' => '*',
        'text'  => "Seu objetivo: {{objetivo}}.\nEtapa atual: {{fase}}.\nO que concluímos: [entrega].\nPróximo passo do escritório: {{proxima_acao}} ({{responsavel}}).\nSua próxima ação: {{acao_cliente}}.\nPróximo retorno: {{proximo_retorno}}.",
    ],
];

function fill_template(string $key, array $case, array $extra = []): string
{
    $tpl = MESSAGE_TEMPLATES[$key]['text'] ?? '';
    $vars = template_vars($case) + $extra;
    return preg_replace_callback('/\{\{(\w+)\}\}/', function ($m) use ($vars) {
        $v = $vars[$m[1]] ?? null;
        return ($v === null || $v === '') ? '[' . str_replace('_', ' ', $m[1]) . ']' : (string) $v;
    }, $tpl) ?? $tpl;
}

function template_vars(array $c): array
{
    $docsIn = [];
    $docsOut = [];
    foreach (all('SELECT * FROM documents WHERE case_id = ?', [$c['id']]) as $d) {
        if (in_array($d['status'], ['recebido', 'conferido'], true)) {
            $docsIn[] = mb_strtolower($d['label']);
        } elseif (in_array($d['status'], ['falta', 'pendencia', 'ajuda'], true) && $d['essential']) {
            $docsOut[] = mb_strtolower($d['label']);
        }
    }
    $offer = one('SELECT * FROM offers WHERE case_id = ? ORDER BY id DESC LIMIT 1', [$c['id']]);
    $next = dt($c['next_client_update']);
    return [
        'nome'            => first_name($c['client_name']),
        'gestor'          => $c['gestor_id'] ? first_name(user_name((int) $c['gestor_id'])) : null,
        'resumo'          => $c['summary_sentence'],
        'objetivo'        => $c['objective'] ?: ($c['summary_sentence'] ?? ''),
        'fase'            => CLIENT_PHASES[stage($c['stage'])['phase']]['label'],
        'proxima_acao'    => $c['next_action'],
        'responsavel'     => $c['next_action_owner_id'] ? user_name((int) $c['next_action_owner_id']) : null,
        'acao_cliente'    => $c['client_next_action'] ?: 'nenhuma no momento',
        'proximo_retorno' => $next ? fmt_date($c['next_client_update']) : null,
        'hoje'            => now()->format('d/m/Y'),
        'retorno_30'      => now()->modify('+30 days')->format('d/m/Y'),
        'docs_recebidos'  => $docsIn ? natural_join($docsIn) : null,
        'docs_faltantes'  => $docsOut ? natural_join($docsOut) : null,
        'oferta_entrada'  => $offer && $offer['down_payment'] !== null ? money((float) $offer['down_payment']) : null,
        'oferta_parcelas' => $offer['installments'] ?? null,
        'oferta_parcela'  => $offer && $offer['installment_value'] !== null ? money((float) $offer['installment_value']) : null,
        'oferta_total'    => $offer ? money(offer_total($offer)) : null,
        'oferta_validade' => $offer && $offer['valid_until'] ? fmt_date($offer['valid_until']) : null,
    ];
}

function natural_join(array $items): string
{
    $items = array_values($items);
    if (count($items) <= 1) {
        return $items[0] ?? '';
    }
    $last = array_pop($items);
    return implode(', ', $items) . ' e ' . $last;
}
