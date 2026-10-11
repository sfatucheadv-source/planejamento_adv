<?php
declare(strict_types=1);

function posts(): array
{
    static $posts = null;
    if ($posts === null) {
        $posts = json_decode((string) file_get_contents(APP_DIR . '/content/posts.json'), true) ?: [];
    }
    return $posts;
}

function post_by_slug(string $slug): ?array
{
    foreach (posts() as $p) {
        if ($p['slug'] === $slug) {
            return $p;
        }
    }
    return null;
}

function post_by_number(string $n): ?array
{
    foreach (posts() as $p) {
        if ($p['number'] === $n) {
            return $p;
        }
    }
    return null;
}

function post_categories(): array
{
    $cats = [];
    foreach (posts() as $p) {
        $cats[$p['category_slug']] = $p['category'];
    }
    return $cats;
}

function posts_in(array $numbers): array
{
    return array_values(array_filter(array_map('post_by_number', $numbers)));
}

/**
 * Páginas por situação. As rotas preservam os endereços do protótipo
 * (PRD, seção 8) e acrescentam a revisão de financiamento do workflow.
 */
function situations(): array
{
    return [
        'analise-de-financiamento' => [
            'menu' => 'Parcela alta ou juros',
            'kicker' => 'Revisão e negociação do financiamento',
            'title' => 'A parcela do carro pesou no orçamento?',
            'lead' => 'Antes de decidir entre negociar, revisar ou manter o contrato, vale entender o que você está pagando. A gente refaz a conta do seu financiamento e explica, em linguagem simples, o que é regular e o que merece discussão.',
            'priority' => 'reduzir_parcela',
            'notice' => null,
            'pains' => [
                'A parcela começou a disputar espaço com mercado, aluguel e escola.',
                'Você suspeita que os juros estão acima do normal, mas não sabe comparar.',
                'Apareceram tarifas, seguro ou serviços que você não lembra de ter escolhido.',
                'Já está com parcelas atrasadas e tem medo de perder o carro.',
            ],
            'checks' => [
                ['Taxa contratada x média do Banco Central', 'Comparamos juros com juros, na mesma unidade, usando a série oficial do Banco Central para financiamento de veículos. A diferença é um indicador, não uma conclusão automática.'],
                ['Cobranças acessórias', 'Tarifa de avaliação, registro, seguro e serviços de terceiros são conferidos um a um: se foram informados, se o serviço existiu e se você teve escolha.'],
                ['Saldo real e cenários', 'Refazemos o contrato e montamos três cenários na mesma data: seguir como está, negociar e revisar. Você vê o custo total de cada um.'],
                ['Viabilidade e custo', 'Avaliamos prova, prazos e benefício líquido. Se a revisão não compensar, dizemos isso com clareza e mostramos o que fazer.'],
            ],
            'docs' => ['contrato', 'historico', 'seguro_tarifas', 'renda'],
            'honest' => 'Juros acima da média, sozinhos, não garantem revisão. E entrar com ação revisional não suspende a cobrança nem impede a apreensão. Por isso a orientação sobre os pagamentos vem antes de qualquer outra coisa.',
            'posts' => ['07', '05', '09'],
        ],
        'veiculo-apreendido' => [
            'menu' => 'Veículo apreendido',
            'kicker' => 'Busca e apreensão',
            'title' => 'O carro foi apreendido ou chegou uma notificação?',
            'lead' => 'Nessa hora o mais importante é conferir datas e documentos rapidamente. Seu caso vai direto para o advogado, e a coleta dos documentos corre em paralelo para não perder tempo.',
            'priority' => 'apreensao',
            'notice' => null,
            'urgent' => true,
            'pains' => [
                'O oficial de justiça levou o veículo, ou você recebeu um mandado.',
                'Chegou uma carta do banco ou de cartório falando em busca e apreensão.',
                'Você não sabe se ainda dá tempo de pagar, nem qual valor seria exigido.',
                'O carro é o seu instrumento de trabalho.',
            ],
            'checks' => [
                ['Qual procedimento está em curso', 'Judicial ou extrajudicial (cartório)? Cada um tem regras e prazos próprios, que não podem ser misturados.'],
                ['Datas que contam', 'Data da apreensão, da citação ou da notificação. Os prazos são conferidos no processo, com responsável e calendário.'],
                ['Notificação e mora', 'Endereço do contrato, comprovante de envio, notificação por e-mail: o que o banco precisa demonstrar.'],
                ['Defesa e negociação em paralelo', 'Avaliamos defesa e acordo ao mesmo tempo, porque às vezes a melhor saída é uma combinação dos dois.'],
            ],
            'docs' => ['processo', 'notificacoes', 'contrato', 'historico'],
            'honest' => 'Nenhum site consegue calcular o seu prazo com segurança. Se há apreensão ou intimação recente, fale com Sidney agora pelo WhatsApp e envie o documento que você tem em mãos, mesmo que seja só uma foto.',
            'posts' => ['02', '01', '16'],
        ],
        'venda-do-veiculo-e-saldo' => [
            'menu' => 'Veículo vendido e saldo',
            'kicker' => 'Prestação de contas da venda',
            'title' => 'O banco vendeu o carro e ainda cobra um saldo?',
            'lead' => 'Depois da venda, o banco precisa mostrar por quanto vendeu, como aplicou o dinheiro na dívida e nas despesas e qual é o saldo. Pode existir dívida restante, mas também pode existir valor a seu favor.',
            'priority' => 'venda_saldo',
            'notice' => null,
            'pains' => [
                'Você perdeu o carro e mesmo assim continua recebendo cobrança.',
                'Nunca recebeu a conta da venda nem sabe por quanto o carro foi vendido.',
                'Desconfia que o valor da venda ficou muito abaixo do mercado.',
                'Pagou boa parte do financiamento e quer saber se algo deveria voltar.',
            ],
            'checks' => [
                ['Preço e condições da venda', 'Data, valor, estado do veículo e documentos da venda. A tabela FIPE é referência de mercado, não indenização automática.'],
                ['Dívida na data da venda', 'Conferimos a evolução do débito para saber quanto realmente era devido.'],
                ['Despesas abatidas', 'Cada despesa precisa ser discriminada e comprovada.'],
                ['Saldo da operação', 'Se o resultado for favorável a você, o caminho é exigir as contas e a entrega do valor. Se houver dívida restante, avaliamos negociação.'],
            ],
            'docs' => ['contrato', 'historico', 'venda', 'despesas', 'notificacoes'],
            'honest' => 'As parcelas já pagas não voltam automaticamente. O que pode existir é um saldo, que só aparece depois de conferir venda, dívida e despesas. Quando faltar dado, o valor fica como “a apurar”, nunca como zero.',
            'posts' => ['11', '12', '13'],
        ],
        'terceiro-adquirente' => [
            'menu' => 'Comprei de outra pessoa',
            'kicker' => 'Terceiro adquirente',
            'title' => 'Você comprou um carro que ainda estava financiado?',
            'lead' => 'Quem comprou de boa-fé e foi surpreendido por uma busca e apreensão precisa de uma análise própria: datas, registro do gravame e documentos da compra fazem toda a diferença.',
            'priority' => 'apreensao',
            'notice' => null,
            'pains' => [
                'O carro foi apreendido, mas o financiamento era de outra pessoa.',
                'Você pagou ao vendedor e não sabia da dívida com o banco.',
                'O documento do veículo não mostrava restrição na época da compra.',
            ],
            'checks' => [
                ['Compra e pagamento', 'Contrato, recibos, transferências e mensagens com o vendedor.'],
                ['Registro do gravame', 'Se a restrição estava registrada e visível quando você comprou.'],
                ['Ciência e boa-fé', 'O que você sabia, quando soube e o que podia ter verificado.'],
                ['Caminho adequado', 'Defesa no processo, negociação ou cobrança do vendedor, conforme o caso.'],
            ],
            'docs' => ['processo', 'veiculo', 'notificacoes'],
            'honest' => 'Ser terceiro de boa-fé não garante, por si só, a devolução do veículo. A análise é individual e depende das datas e do registro.',
            'posts' => ['15', '03', '01'],
        ],
    ];
}

function faq(): array
{
    return [
        ['Preciso ter o contrato em mãos para começar?', 'Não. Muita gente não tem. Começamos com o que você tiver e, se for preciso, te ajudamos a pedir a cópia ao banco pelos canais oficiais. Você nunca precisa nos passar senha do banco ou do Gov.br.'],
        ['Vocês vão me dizer para parar de pagar?', 'Não. Parar de pagar ou pagar um valor menor por conta própria pode piorar a situação. Qualquer orientação sobre pagamentos vem por escrito, do advogado, depois de olhar o seu caso.'],
        ['Entrar com ação revisional impede a apreensão do carro?', 'Por si só, não. A ação revisional não afasta automaticamente o atraso. Por isso avaliamos defesa, negociação e revisão em conjunto, e explicamos o efeito real de cada caminho.'],
        ['Juros acima da média do Banco Central são ilegais?', 'Não automaticamente. A comparação com a taxa média é um indicador importante, mas o tribunal analisa as circunstâncias do contrato. Mostramos a comparação e o que ela significa no seu caso.'],
        ['Quanto tempo leva?', 'Depende do caminho. A análise do contrato tem meta de até 2 dias úteis depois que os documentos estiverem completos. Prazos do banco e da Justiça não dependem de nós, e não prometemos duração nem resultado. Mas você sempre sabe qual é o próximo passo e quando terá notícia.'],
        ['Quanto custa?', 'O custo é apresentado por escrito, antes de qualquer contratação, com o que está incluído e o que não está. Despesas e riscos (como custas e honorários da outra parte, se houver processo) também são explicados antes.'],
        ['Como vou acompanhar o meu caso?', 'Pelo canal que você preferir, normalmente o WhatsApp. Se quiser, também pode usar a área do cliente aqui no site, que mostra a etapa atual, os documentos e a data do próximo retorno. Mesmo sem novidade, você recebe notícia a cada 15 dias.'],
        ['Quando o caso termina?', 'Concluir o atendimento inicial (análise, conversa e proposta) não significa que a dívida foi resolvida. Na atuação contratada, não basta assinar um acordo ou ganhar uma decisão: só encerramos depois de conferir o efeito na prática: o boleto novo correto, o pagamento reconhecido, a devolução no seu bolso ou a baixa do gravame. E prestamos contas de tudo.'],
        ['Atendem fora do Paraná?', 'Sim. O atendimento é digital, para todo o Brasil, por WhatsApp, ligação e videochamada. O escritório tem base em São José dos Pinhais/PR.'],
    ];
}
