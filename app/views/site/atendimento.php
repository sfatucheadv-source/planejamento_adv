<?php
$steps = [
    ['Atendimento inicial · Em coleta', 'O assistente organiza; você continua quando puder', [
        'Você conta seu caso pelo assistente virtual do site. Ele se identifica como assistente e organiza as informações para Sidney.',
        'A primeira pergunta é sempre sobre urgência: carro apreendido, documento da Justiça ou de cartório, cobrança do banco. Se houver, Sidney recebe o aviso na hora.',
        'Depois, o necessário para a análise: contrato, documento pessoal, comprovante de residência e pagamentos. Comprovante de renda só se você pedir avaliação de justiça gratuita.',
        '“Não sei” também é resposta: Sidney confere no contrato. Você pode parar e continuar depois, de onde parou.',
    ]],
    ['Atendimento inicial · Em análise', 'Sidney confere e analisa o financiamento', [
        'Sidney confere se cada documento está completo e legível e pede só o que realmente falta, em uma lista única.',
        'Refaz o financiamento: taxa, CET, tarifas, seguro, pagamentos e saldo, com a taxa média do Banco Central para o mesmo tipo de operação como referência.',
        'Chega a uma conclusão clara e prepara a proposta antes da conversa, para você decidir com tudo na mesa.',
    ]],
    ['Atendimento inicial · Reunião e proposta', 'Você conversa direto com Sidney', [
        'A conversa pode ser por vídeo no Google Meet. Se preferir, por telefone, WhatsApp por texto ou pelo chat deste site. Sidney confirma o dia e o horário.',
        'Ele explica o que a análise mostrou, os caminhos possíveis, custos e riscos. Você recebe o resumo e a proposta por escrito e decide sem pressão.',
        'Contrato de honorários e procuração só depois da sua decisão. Você recebe cópia de tudo o que assinar.',
        'Concluir o atendimento inicial não significa que a dívida foi resolvida: se você contratar, a atuação continua nas etapas abaixo.',
    ]],
    ['Se você contratar · Fazendo a solução acontecer', 'Negociação e, quando houver fundamento, processo', [
        'Propostas ao banco dentro do limite que você autorizou. Cada oferta chega até você com entrada, parcelas, total e consequências.',
        'Nenhum silêncio vale como aceite: só fechamos acordo com a sua confirmação.',
        'Se o acordo não resolver e houver fundamento, preparamos a ação. Negociação pode continuar durante o processo.',
        'Audiências e decisões são explicadas antes e depois, em linguagem simples. Você nunca descobre uma audiência por mensagem automática.',
    ]],
    ['Se você contratar · Conferindo e encerrando', 'Conferência e prestação de contas', [
        'Na atuação contratada, só encerramos depois de conferir o resultado na prática. Conferimos se o banco cumpriu: boleto novo correto, quitação, devolução, baixa do gravame, regularização de cadastro.',
        'Você recebe um resumo de uma página: problema inicial, caminho seguido, solução e efeito financeiro, com os comprovantes.',
        'Em 30 dias voltamos a falar: “O combinado continua funcionando? Surgiu alguma cobrança diferente?”.',
    ]],
];
?>
<section class="page-hero">
  <div class="wrap narrow">
    <p class="kicker">Como funciona</p>
    <h1>Da primeira conversa ao resultado conferido</h1>
    <p class="lead">Sidney acompanha seu atendimento. O site ajuda a organizar dados, documentos, reunião e proposta, e você acompanha tudo em um só lugar.</p>
  </div>
</section>

<section class="section">
  <div class="wrap">
    <ol class="steps-detail">
      <?php foreach ($steps as $i => [$phase, $title, $items]): ?>
        <li class="step-block">
          <div class="step-side">
            <span class="step-n"><?= $i + 1 ?></span>
            <p class="step-phase"><?= e($phase) ?></p>
          </div>
          <div class="step-main">
            <h2><?= e($title) ?></h2>
            <ul><?php foreach ($items as $it): ?><li><?= e($it) ?></li><?php endforeach ?></ul>
          </div>
        </li>
      <?php endforeach ?>
    </ol>
  </div>
</section>

<section class="section section-paper">
  <div class="wrap split split-top">
    <div>
      <p class="kicker">Quem cuida de você</p>
      <h2>Sidney acompanha seu atendimento</h2>
      <p>O advogado <strong>Sidney Fatuche</strong> confere seus documentos, faz a análise, conversa com você, prepara a proposta e conduz a atuação contratada. O site ajuda a organizar dados, documentos, reunião e proposta.</p>
    </div>
    <ul class="role-list">
      <li><strong>Assistente virtual</strong><span>Organiza suas informações e documentos a qualquer hora. Não dá orientação jurídica e não é plantão do advogado.</span></li>
      <li><strong>Sidney Fatuche</strong><span>Análise, conversa, proposta, estratégia e acompanhamento do seu caso.</span></li>
    </ul>
  </div>
</section>

<?php if (config('show_service_goals')): ?>
<section class="section">
  <div class="wrap narrow">
    <header class="section-head">
      <p class="kicker">Retornos</p>
      <h2>Você sempre sabe quando terá notícia</h2>
      <p>O atendimento humano é realizado por Sidney. Informamos o próximo retorno e avisamos se a previsão mudar. Documentos ou prazos urgentes recebem prioridade para avaliação.</p>
      <p>Horário de atendimento humano: dias úteis, das <?= open_hour() ?>h às <?= close_hour() ?>h, horário de Brasília. O assistente do site funciona fora desse horário, mas não é plantão do advogado. Prazos do banco e da Justiça são informados conforme o caso, sem promessa de duração ou de resultado.</p>
    </header>
  </div>
</section>
<?php endif ?>

<section class="section section-dark">
  <div class="wrap split split-top">
    <div>
      <p class="kicker kicker-light">Combinados</p>
      <h2>Regras que valem para todo caso</h2>
    </div>
    <ul class="rules">
      <li>Uma pergunta ou um pequeno conjunto de ações por mensagem. Aceitamos áudio, fotos legíveis, ligação e ajuda para assinar.</li>
      <li>Nunca pedimos senha do Gov.br, senha bancária ou código de autenticação. Quando for preciso baixar algo, ajudamos você mesmo a acessar.</li>
      <li>Custos explicados antes de qualquer despesa. Decisões sobre acordo, ação, recurso e mudança de estratégia são confirmadas por escrito.</li>
      <li>Ninguém do escritório vai sugerir que você pare de pagar ou pague uma parcela menor por conta própria.</li>
      <li>Sempre uma previsão de retorno. Se atrasar, avisamos antes do vencimento, com nova data e responsável.</li>
      <li>Seus documentos e o seu relato não são usados para divulgação do escritório.</li>
    </ul>
  </div>
</section>

<section class="section">
  <div class="wrap narrow">
    <p class="kicker">Canais</p>
    <h2>Onde acompanhar</h2>
    <p>O canal principal é o WhatsApp institucional <a href="<?= e(whatsapp_link()) ?>" target="_blank" rel="noopener"><?= e(office('phone_display')) ?></a>. Se preferir, a <a href="<?= e(url('cliente')) ?>">área do cliente</a> mostra a etapa atual, os documentos recebidos e pendentes, as decisões que dependem de você e a data do próximo retorno. Ela é uma opção, nunca uma barreira: tudo também pode ser feito por mensagem.</p>
  </div>
</section>

<?php partial('cta', ['origin' => 'atendimento']) ?>
