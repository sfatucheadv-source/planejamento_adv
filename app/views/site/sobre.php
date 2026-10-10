<?php
$portrait = photo_url('sidney-retrato');
$office = photo_url('escritorio');
$working = photo_url('sidney-trabalhando');
?>
<section class="page-hero">
  <div class="wrap about-grid">
    <div>
      <p class="kicker">Quem atende</p>
      <h1>Sidney Fatuche</h1>
      <p class="lead">Advogado, OAB/PR 131.033. Atuo em direito do consumidor e financiamento de veículos: a dívida, o contrato, a apreensão, a venda do bem e as contas que o banco precisa prestar.</p>
      <blockquote class="quote">“Minha atuação em financiamento de veículos concentra a análise da dívida, da venda e da prestação de contas. Examino os documentos, respondo às dúvidas e oriento a forma de enfrentar o caso. O atendimento é digital, para clientes de todo o Brasil.”</blockquote>
      <p>Na prática, isso quer dizer que você sabe o que está acontecendo, quanto custa cada caminho e qual é o próximo passo. Quando a revisão não compensa, isso é dito com clareza. E o caso só termina quando o resultado é conferido na sua vida, não apenas no papel.</p>
      <div class="actions">
        <a class="btn btn-gold" href="<?= e(url('comecar', ['origem' => 'sobre'])) ?>">Contar meu caso</a>
        <a class="btn btn-ghost" href="<?= e(office('oab_lookup_url')) ?>" target="_blank" rel="noopener">Conferir a inscrição na OAB</a>
      </div>
    </div>
    <figure class="about-photo">
      <?php if ($portrait): ?>
        <img src="<?= e($portrait) ?>" alt="Sidney Fatuche, advogado" width="520" height="640">
      <?php else: ?>
        <div class="person-card person-card-tall">
          <?php partial('mark', ['size' => 64, 'light' => true]) ?>
          <p class="person-name"><strong>Sidney Fatuche</strong><span>Advogado · OAB/PR 131.033</span></p>
          <p class="person-meta">Atendimento digital para todo o Brasil · base em <?= e(office('city')) ?></p>
        </div>
      <?php endif ?>
    </figure>
  </div>
</section>

<section class="section section-paper">
  <div class="wrap split split-top">
    <div>
      <p class="kicker">Método</p>
      <h2>Como o caso é analisado</h2>
      <ol class="method">
        <li>Identificar contrato, garantia e em que estágio está o veículo.</li>
        <li>Conferir histórico de pagamentos e demonstrativo do débito.</li>
        <li>Examinar taxas, tarifas, seguro e, se houve venda, preço, condições e documentos.</li>
        <li>Conferir despesas e calcular a posição real do financiamento.</li>
        <li>Orientar o caso por escrito e apresentar a proposta de atuação cabível.</li>
      </ol>
    </div>
    <div>
      <?php if ($office || $working): ?>
        <figure class="photo-frame"><img src="<?= e($office ?: $working) ?>" alt="Escritório Sidney Fatuche Advocacia" loading="lazy" width="800" height="560"></figure>
      <?php else: ?>
        <figure class="photo-frame"><img src="<?= e(asset('img/consulta.jpg')) ?>" alt="Mesa de reunião com contrato de financiamento e chave do carro" loading="lazy" width="800" height="450"></figure>
      <?php endif ?>
    </div>
  </div>
</section>

<section class="section">
  <div class="wrap">
    <header class="section-head">
      <p class="kicker">Dados profissionais</p>
      <h2>Transparência sobre quem somos</h2>
    </header>
    <dl class="facts">
      <div><dt>Advogado responsável</dt><dd><?= e(office('lawyer')) ?> · <?= e(office('lawyer_oab')) ?></dd></div>
      <div><dt>Sociedade</dt><dd><?= e(office('society')) ?> · <?= e(office('society_oab')) ?></dd></div>
      <div><dt>CNPJ</dt><dd><?= e(office('cnpj')) ?></dd></div>
      <div><dt>Base</dt><dd><?= e(office('city')) ?> · atendimento digital em todo o Brasil</dd></div>
      <div><dt>Canais oficiais</dt><dd>WhatsApp <?= e(office('phone_display')) ?> · <?= e(office('email')) ?> · @<?= e(office('instagram')) ?></dd></div>
      <div><dt>Horário</dt><dd>Dias úteis, das <?= open_hour() ?>h às <?= close_hour() ?>h (Brasília)</dd></div>
    </dl>
  </div>
</section>

<?php partial('instagram') ?>
<?php partial('cta', ['origin' => 'sobre']) ?>
