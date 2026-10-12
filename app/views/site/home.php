<?php
$portrait = photo_url('sidney-retrato');
$meeting = photo_url('sidney-atendimento');
$open = is_open();
?>
<section class="hero">
  <div class="wrap hero-grid">
    <div class="hero-copy">
      <p class="kicker">Financiamento de veículos · atendimento digital em todo o Brasil</p>
      <h1>O financiamento do carro apertou? Você não precisa resolver isso sozinho.</h1>
      <p class="lead">Parcela que não cabe mais, juros que parecem altos, carta do banco ou carro apreendido. O advogado <strong>Sidney Fatuche</strong> analisa o seu contrato, explica os caminhos possíveis e acompanha cada etapa até o resultado ser conferido na prática.</p>
      <div class="hero-ctas">
        <a class="btn btn-gold" href="<?= e(url('comecar', ['origem' => 'home-topo'])) ?>">Contar meu caso pelo chat</a>
        <a class="btn btn-ghost" href="#como-funciona">Como funciona</a>
      </div>
    </div>

    <aside class="hero-person" aria-label="Quem atende">
      <?php if ($portrait): ?>
        <figure class="portrait">
          <img src="<?= e($portrait) ?>" alt="Sidney Fatuche, advogado" width="520" height="640" fetchpriority="high">
          <figcaption><strong>Sidney Fatuche</strong><span>Advogado · OAB/PR 131.033</span></figcaption>
        </figure>
      <?php else: ?>
        <div class="person-card">
          <?php partial('mark', ['size' => 54, 'light' => true]) ?>
          <blockquote>“Examino os documentos, respondo às dúvidas e oriento a forma de enfrentar o caso.”</blockquote>
          <p class="person-line">O assistente virtual organiza suas informações. Sidney analisa o caso e conversa com você sobre as opções.</p>
          <p class="person-name"><strong>Sidney Fatuche</strong><span>Advogado · OAB/PR 131.033</span></p>
          <ul class="person-facts">
            <li>Atendimento digital para todo o Brasil</li>
            <li>Base em <?= e(office('city')) ?></li>
            <li>Dias úteis, das <?= open_hour() ?>h às <?= close_hour() ?>h</li>
          </ul>
        </div>
      <?php endif ?>
    </aside>
    <div class="first-question" aria-labelledby="pergunta-inicial">
      <p id="pergunta-inicial" class="fq-title">Antes de tudo, uma pergunta que muda a ordem das coisas:</p>
      <p class="fq-q">Você recebeu alguma notificação do banco, de cartório ou da Justiça? O carro já foi apreendido?</p>
      <div class="fq-options">
        <a class="fq-option is-urgent" href="<?= e(url('comecar', ['aviso' => 'apreendido', 'origem' => 'home'])) ?>">Sim, o carro foi apreendido</a>
        <a class="fq-option is-urgent" href="<?= e(url('comecar', ['aviso' => 'justica', 'origem' => 'home'])) ?>">Recebi documento da Justiça ou de cartório</a>
        <a class="fq-option" href="<?= e(url('comecar', ['aviso' => 'banco', 'origem' => 'home'])) ?>">Recebi cobrança ou carta do banco</a>
        <a class="fq-option" href="<?= e(url('comecar', ['aviso' => 'nenhum', 'origem' => 'home'])) ?>">Nada disso, mas a parcela está pesada</a>
        <a class="fq-option" href="<?= e(url('comecar', ['aviso' => 'nao_sei', 'origem' => 'home'])) ?>">Não sei dizer</a>
      </div>
      <p class="fq-foot">
        <span class="availability <?= $open ? 'is-open' : '' ?>"><span class="dot" aria-hidden="true"></span><?= $open ? 'Sidney em atendimento agora' : 'Fora do horário · retornamos ' . e(fmt_when(next_opening())) ?></span>
        <a href="<?= e(url('comecar', ['origem' => 'home'])) ?>">Prefere contar do seu jeito? Inicie o atendimento online</a>
      </p>
    </div>
  </div>
</section>

<section class="section trust-strip" aria-label="Como trabalhamos">
  <div class="wrap trust-grid">
    <div><strong>Atendimento com Sidney</strong><span>Depois da coleta, você conversa diretamente com o advogado. Sem repassar seu caso de pessoa em pessoa.</span></div>
    <div><strong>Nada é decidido sem você</strong><span>Toda oferta do banco e toda decisão importante são explicadas e confirmadas por escrito.</span></div>
    <div><strong>Sem promessas fáceis</strong><span>Mostramos o que é regular, o que merece discussão e o que ainda depende de prova.</span></div>
  </div>
</section>

<section class="section" id="situacoes">
  <div class="wrap">
    <header class="section-head">
      <p class="kicker">Qual é a sua situação?</p>
      <h2>Cada momento do financiamento pede uma resposta diferente</h2>
      <p>Escolha o que mais parece com o seu caso. Se nenhuma opção encaixar, é só contar com suas palavras.</p>
    </header>
    <div class="cards situations">
      <?php foreach (situations() as $slug => $s): ?>
        <a class="card situation-card<?= !empty($s['urgent']) ? ' is-urgent' : '' ?>" href="<?= e(url($slug)) ?>">
          <span class="card-kicker"><?= e($s['kicker']) ?></span>
          <h3><?= e($s['title']) ?></h3>
          <p><?= e($s['pains'][0]) ?></p>
          <span class="card-link">Entender o que pode ser feito →</span>
        </a>
      <?php endforeach ?>
    </div>
  </div>
</section>

<section class="section section-paper how" id="como-funciona">
  <div class="wrap">
    <div class="how-intro">
      <figure class="photo-frame how-photo">
        <img src="<?= e($meeting ?: asset('img/consulta.jpg')) ?>" alt="<?= $meeting ? 'Sidney Fatuche em atendimento' : 'Mesa de reunião com contrato de financiamento, chave do carro e anotações' ?>" loading="lazy" width="800" height="450">
        <figcaption>A primeira conversa é para ouvir. Áudio, foto do documento ou mensagem longa: do jeito que for mais fácil para você.</figcaption>
      </figure>
      <div class="how-copy">
        <p class="kicker">Como funciona</p>
        <h2>Primeiro, o atendimento inicial; depois, a solução</h2>
        <ol class="how-phases">
          <li><strong>Atendimento inicial</strong><span>O assistente reúne suas informações e documentos (você pode parar e continuar depois), Sidney analisa o financiamento, vocês conversam por Google Meet, telefone, WhatsApp ou pelo chat do site, e você recebe a proposta.</span></li>
          <li><strong>Se você contratar</strong><span>A atuação segue nas etapas abaixo, sempre com o próximo passo à vista. Concluir o atendimento inicial não significa que a dívida foi resolvida.</span></li>
        </ol>
        <a class="btn btn-gold" href="<?= e(url('comecar', ['origem' => 'home-como-funciona'])) ?>">Começar pelo chat</a>
      </div>
    </div>

    <ol class="journey" aria-label="Etapas depois da contratação">
      <?php
      $yours = [
          'entendendo'  => 'Você conta o que está acontecendo. Áudio também vale.',
          'analisando'  => 'Você envia o que tiver. Dizemos exatamente o que falta e ajudamos a conseguir.',
          'buscando'    => 'Você escolhe o caminho, sem pressão, depois de entender custos e riscos.',
          'acontecendo' => 'Você autoriza cada proposta. Nada é aceito em seu nome sem a sua confirmação.',
          'conferindo'  => 'Você confirma que o boleto, a quitação ou a devolução chegaram certos.',
      ];
      $i = 1;
      foreach (CLIENT_PHASES as $key => $ph): ?>
        <li>
          <span class="journey-n" aria-hidden="true"><?= $i++ ?></span>
          <h3><?= e($ph['label']) ?></h3>
          <p><?= e($ph['text']) ?></p>
          <p class="journey-you"><strong>Sua parte</strong><?= e($yours[$key]) ?></p>
        </li>
      <?php endforeach ?>
    </ol>
    <p class="how-more"><a href="<?= e(url('atendimento')) ?>">Ver o atendimento em detalhes →</a></p>
  </div>
</section>

<?php if (config('show_service_goals')): ?>
<section class="section section-dark">
  <div class="wrap">
    <header class="section-head">
      <p class="kicker kicker-light">Compromissos de atendimento</p>
      <h2>O que você pode esperar</h2>
      <p>O atendimento humano é realizado por Sidney. Informamos o próximo retorno e avisamos se a previsão mudar. Documentos ou prazos urgentes recebem prioridade para avaliação.</p>
    </header>
    <ul class="goals">
      <li>O assistente virtual funciona a qualquer hora, mas não é plantão do advogado.</li>
      <li>Você sabe sempre qual é o próximo passo e a data do próximo retorno.</li>
      <li>Nunca pedimos senha do banco, do Gov.br ou código de autenticação.</li>
      <li>Custos explicados por escrito antes de qualquer despesa.</li>
    </ul>
  </div>
</section>
<?php endif ?>

<section class="section">
  <div class="wrap closing-rule">
    <div>
      <p class="kicker">Nossa regra de encerramento</p>
      <h2>Acordo assinado não é fim de caso</h2>
      <p>Contrato assinado, acordo fechado ou decisão favorável não bastam. O seu caso só é encerrado quando conferimos o efeito na sua vida: o boleto novo com o valor certo, o pagamento reconhecido, o dinheiro devolvido na sua conta ou a baixa do gravame.</p>
      <p>E se o resultado que você queria não for possível, dizemos com clareza, explicamos as alternativas para a dívida e prestamos contas de tudo o que foi feito.</p>
    </div>
    <ul class="evidence-list">
      <li><span>Novo demonstrativo e boletos</span> conferidos com o acordo</li>
      <li><span>Termo de quitação</span> e saldo zero reconhecido</li>
      <li><span>Restituição</span> creditada para a pessoa certa</li>
      <li><span>Baixa do gravame</span> consultada no registro</li>
      <li><span>Checagem em 30 dias</span> para ver se o combinado continua funcionando</li>
    </ul>
  </div>
</section>

<section class="section section-paper">
  <div class="wrap">
    <header class="section-head row-head">
      <div>
        <p class="kicker">Blog</p>
        <h2>Para entender antes de decidir</h2>
      </div>
      <a class="btn btn-ghost" href="<?= e(url('blog')) ?>">Ver todos os estudos</a>
    </header>
    <div class="cards posts">
      <?php foreach ($posts as $p) { partial('post-card', ['p' => $p]); } ?>
    </div>
  </div>
</section>

<?php partial('instagram') ?>

<section class="section">
  <div class="wrap faq-wrap">
    <header class="section-head">
      <p class="kicker">Dúvidas frequentes</p>
      <h2>Perguntas que quase todo mundo faz</h2>
    </header>
    <div class="faq">
      <?php foreach (array_slice(faq(), 0, 5) as [$q, $a]): ?>
        <details><summary><?= e($q) ?></summary><p><?= e($a) ?></p></details>
      <?php endforeach ?>
    </div>
    <p><a href="<?= e(url('duvidas')) ?>">Ver todas as dúvidas →</a></p>
  </div>
</section>

<?php partial('cta') ?>
