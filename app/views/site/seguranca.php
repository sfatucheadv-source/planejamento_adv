<section class="page-hero">
  <div class="wrap narrow">
    <p class="kicker">Segurança</p>
    <h1>Como saber que é mesmo o escritório falando com você</h1>
    <p class="lead">Golpes com boletos falsos e falsos advogados são comuns, principalmente com quem está com o carro financiado. Estas regras valem para todo atendimento.</p>
  </div>
</section>
<section class="section">
  <div class="wrap narrow prose">
    <h2>Canais oficiais</h2>
    <ul>
      <li>WhatsApp: <strong><?= e(office('phone_display')) ?></strong></li>
      <li>E-mail: <strong><?= e(office('email')) ?></strong></li>
      <li>Instagram: <strong>@<?= e(office('instagram')) ?></strong></li>
      <li>Site: <strong><?= e(preg_replace('#^https?://#', '', office('base_url'))) ?></strong></li>
    </ul>
    <p>Se receber contato de outro número dizendo ser do escritório, não responda e confirme pelo WhatsApp oficial.</p>

    <h2>O que nunca pedimos</h2>
    <ul>
      <li>Senha do Gov.br, senha do banco ou código recebido por SMS.</li>
      <li>Pagamento de “taxa para liberar o carro” ou “taxa de processo” por Pix para pessoa física.</li>
      <li>Que você pare de pagar o financiamento ou pague parcela menor por conta própria.</li>
    </ul>

    <h2>Boletos e pagamentos ao banco</h2>
    <p>Antes de pagar qualquer boleto de acordo, conferimos com você o beneficiário e a autenticidade pelo canal oficial do banco. Qualquer mudança de destinatário ou de valor exige nova conferência. Se algo parecer estranho, suspenda o pagamento e fale com Sidney.</p>

    <h2>Seus documentos</h2>
    <p>Os arquivos enviados pela área do cliente ficam em área protegida, acessível apenas a Sidney e a você, mediante login. Não usamos documentos nem relatos para divulgação do escritório.</p>

    <h2>Confira a inscrição na OAB</h2>
    <p>Você pode conferir a inscrição de <?= e(office('lawyer')) ?> (<?= e(office('lawyer_oab')) ?>) no <a href="<?= e(office('oab_lookup_url')) ?>" target="_blank" rel="noopener">Cadastro Nacional dos Advogados</a>.</p>
  </div>
</section>
