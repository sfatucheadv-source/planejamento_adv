<section class="portal">
  <div class="wrap">
    <div class="login-box">
      <p class="kicker">Área do cliente</p>
      <h1>Nenhum atendimento disponível agora</h1>
      <p class="lead">O atendimento anterior foi concluído e o prazo de acesso a ele terminou. Seus documentos continuam guardados com o escritório.</p>
      <div class="panel">
        <h2>Começar um novo atendimento</h2>
        <p class="muted">Para outro contrato ou assunto, abra uma nova conversa. Seus dados de contato já estão aqui; o assistente pergunta só o que for novo.</p>
        <form method="post" action="<?= e(url('cliente/novo')) ?>">
          <?= csrf_field() ?>
          <button class="btn btn-gold" type="submit">Iniciar novo atendimento</button>
        </form>
      </div>
      <p class="muted">Precisa de um documento seu? Peça pelo <a href="<?= e(whatsapp_link('Olá, Sidney! Preciso de um documento do meu atendimento.')) ?>" target="_blank" rel="noopener">WhatsApp <?= e(office('phone_display')) ?></a>. <a href="<?= e(url('cliente/sair')) ?>">Sair</a></p>
    </div>
  </div>
</section>
