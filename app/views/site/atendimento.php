<?php
$steps = [
    ['entendendo', 'A primeira conversa', [
        'Uma pessoa da equipe se apresenta, pergunta como você prefere conversar (mensagem, áudio ou ligação) e se precisa de alguma adaptação.',
        'A primeira pergunta é sempre sobre urgência: notificação, documento da Justiça ou de cartório, carro apreendido. Se houver, o advogado entra imediatamente.',
        'Depois, o mínimo para entender: banco, parcela, atraso, cidade e se você tem o contrato. Valores aproximados bastam.',
        'Fechamos com uma frase: “Sua prioridade é manter o veículo e encontrar uma prestação que consiga pagar. Correto?”. Se não for, você corrige.',
    ]],
    ['analisando', 'Documentos e análise do contrato', [
        'Você recebe uma lista única, dizendo o que já chegou, o que falta e o que nós vamos ajudar a obter. Nada de pedir a mesma coisa duas vezes.',
        'Se você não tem o contrato, orientamos a busca no aplicativo ou no e-mail do banco e, se preciso, pedimos a cópia ao credor pelo canal oficial.',
        'Refazemos o financiamento: taxa, CET, tarifas, seguro, pagamentos feitos e saldo. Comparamos com a taxa média do Banco Central para o mesmo tipo de operação.',
        'O advogado valida tudo e chega a uma conclusão clara: revisão fundamentada, negociação prioritária, prova insuficiente ou revisão não recomendada.',
    ]],
    ['buscando', 'Você decide, com tudo na mesa', [
        'Uma conversa de cerca de 20 minutos, por ligação ou vídeo, com resumo por escrito.',
        'Mostramos três cenários na mesma data: seguir como está, negociar e revisar. Sempre separando “estimativa”, “oferta do banco” e “resultado confirmado”.',
        'Você vê custo do serviço, despesas, riscos e o efeito de alongar a dívida. O advogado recomenda e explica por quê. A escolha é sua.',
        'Contrato de honorários e procuração com assinatura digital acessível. Você recebe cópia de tudo o que assinar.',
    ]],
    ['acontecendo', 'Negociação e, quando houver fundamento, processo', [
        'Propostas ao banco dentro do limite que você autorizou. Cada oferta chega até você com entrada, parcelas, total e consequências.',
        'Nenhum silêncio vale como aceite: só fechamos acordo com a sua confirmação.',
        'Se o acordo não resolver e houver fundamento, preparamos a ação. Negociação pode continuar durante o processo.',
        'Audiências e decisões são explicadas antes e depois, em linguagem simples. Você nunca descobre uma audiência por mensagem automática.',
    ]],
    ['conferindo', 'Conferência e prestação de contas', [
        'Conferimos se o banco cumpriu: boleto novo correto, quitação, devolução, baixa do gravame, regularização de cadastro.',
        'Você recebe um resumo de uma página: problema inicial, caminho seguido, solução e efeito financeiro, com os comprovantes.',
        'Em 30 dias voltamos a falar: “O combinado continua funcionando? Surgiu alguma cobrança diferente?”.',
    ]],
];
?>
<section class="page-hero">
  <div class="wrap narrow">
    <p class="kicker">Como funciona</p>
    <h1>Da primeira conversa ao resultado conferido</h1>
    <p class="lead">O escritório assume a organização do caso. Você conta a sua situação, entrega o que tem, escolhe a estratégia com orientação do advogado e acompanha tudo por um único canal.</p>
  </div>
</section>

<section class="section">
  <div class="wrap">
    <ol class="steps-detail">
      <?php foreach ($steps as $i => [$phase, $title, $items]): ?>
        <li class="step-block">
          <div class="step-side">
            <span class="step-n"><?= $i + 1 ?></span>
            <p class="step-phase"><?= e(CLIENT_PHASES[$phase]['label']) ?></p>
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
      <h2>Um contato só, uma equipe inteira por trás</h2>
      <p>Você conversa sempre com a mesma pessoa, que acompanha o caso e sabe a sua história. Ela lê o histórico antes de cada contato, para você não precisar repetir nada.</p>
      <p>O advogado <strong>Sidney Fatuche</strong> define a estratégia, analisa riscos, revisa cada peça e orienta as decisões. Cálculos, negociação e conferência financeira têm responsáveis próprios, e cada tarefa tem um substituto definido para ausências.</p>
    </div>
    <ul class="role-list">
      <li><strong>Quem acompanha seu caso</strong><span>Contato único, organiza documentos e avisa os retornos.</span></li>
      <li><strong>Advogado responsável</strong><span>Estratégia, riscos, peças e orientação das decisões.</span></li>
      <li><strong>Cálculos</strong><span>Refaz o contrato e demonstra os cenários, com validação jurídica.</span></li>
      <li><strong>Negociação</strong><span>Conversa com o banco dentro do que você autorizou.</span></li>
      <li><strong>Financeiro</strong><span>Despesas, recebimentos e prestação de contas.</span></li>
      <li><strong>Supervisão</strong><span>Cobre ausências, resolve atrasos e revisa encerramentos.</span></li>
    </ul>
  </div>
</section>

<?php if (config('show_service_goals')): ?>
<section class="section">
  <div class="wrap">
    <header class="section-head">
      <p class="kicker">Metas de atendimento</p>
      <h2>Prazos que dependem de nós</h2>
      <p>Horário de atendimento humano: dias úteis, das <?= open_hour() ?>h às <?= close_hour() ?>h, horário de Brasília. Fora dele, a mensagem informa o próximo atendimento. Prazos do banco e da Justiça são informados conforme o caso, sem promessa de duração ou de resultado.</p>
    </header>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>Quando</th><th>Nossa meta</th><th>Se atrasar</th></tr></thead>
        <tbody>
          <?php foreach (SERVICE_GOALS as $g): ?>
            <tr><td><?= e($g['event']) ?></td><td><?= e($g['goal']) ?></td><td><?= e(match ($g['event']) {
                'Primeiro contato' => 'Outra pessoa da equipe assume a conversa.',
                'Notificação ou apreensão' => 'A supervisão é acionada na hora.',
                'Recebimento documental' => 'Você recebe a lista de pendências com nova data.',
                'Análise ordinária completa' => 'Explicamos a complexidade e fixamos nova entrega.',
                'Proposta ou decisão relevante' => 'O advogado assume a comunicação.',
                default => 'O caso entra na lista de prioridade do dia.',
            }) ?></td></tr>
          <?php endforeach ?>
        </tbody>
      </table>
    </div>
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
      <li>Ninguém da equipe vai sugerir que você pare de pagar ou pague uma parcela menor por conta própria.</li>
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
