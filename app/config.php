<?php
/**
 * Configuração padrão. Para ajustar em produção sem alterar este arquivo,
 * crie app/config.local.php retornando apenas as chaves que mudam.
 */
return [
    'office' => [
        'brand'          => 'Sidney Fatuche Advocacia',
        'lawyer'         => 'Sidney Fatuche',
        'lawyer_oab'     => 'OAB/PR 131.033',
        'society'        => 'Sidney Fatuche Sociedade Individual de Advocacia',
        'society_oab'    => 'OAB/PR 21.802',
        'cnpj'           => '63.357.926/0001-59',
        'whatsapp'       => '5541995846520',
        'phone_display'  => '(41) 99584-6520',
        'email'          => 'sidney@fatuche.adv.br',
        'instagram'      => 'fatuche.adv',
        'city'           => 'São José dos Pinhais/PR',
        'base_url'       => 'https://fatuche.adv.br',
        'oab_lookup_url' => 'https://cna.oab.org.br/',
    ],

    // Horário de atendimento humano (Brasília). Fora dele, o site informa o próximo atendimento.
    'hours' => ['start' => 9, 'end' => 18],

    // Pontos facultativos tratados como dia sem expediente nas metas internas.
    'closed_on_optional_holidays' => true,

    // Metas internas (workflow, seção 4). Exibidas no site como metas, nunca como garantia.
    'show_service_goals' => true,

    'storage_dir'   => dirname(__DIR__) . '/storage',
    'upload_max_mb' => 15,

    // Número de dias corridos sem atualização ao cliente antes de gerar alerta (workflow: 15).
    'update_cycle_days' => 15,
];
