<?php
/**
 * Painel de controles: a situação de cada área num quadro, os botões de ligar
 * e desligar mais usados e o que precisa de atenção. Os detalhes de cada
 * área ficam numa página própria.
 */
$opId = operator_id();
$opName = $opId ? (string) scalar('SELECT name FROM users WHERE id = ?', [$opId]) : '';
$keyOk = llm_key() !== null;
$iaOn = llm_enabled();
$agentOn = setting('agent_enabled', '1') === '1';
$driver = mail_driver();
$lastMail = one('SELECT * FROM mail_log ORDER BY id DESC LIMIT 1');
$tpl = one('SELECT COUNT(*) AS total, SUM(approved_at IS NOT NULL) AS ok FROM doc_templates') ?: ['total' => 0, 'ok' => 0];
$activeUsers = count(array_filter($users, fn($x) => (int) ($x['active'] ?? 1) === 1));
$legacy = (int) scalar("SELECT COUNT(*) FROM cases WHERE workflow_version = 'legacy' AND status = 'ativo'");
$recentErr = $usage['last_error'] && dt($usage['last_error']['created_at']) > now()->modify('-1 day');

// O que precisa de atenção, do mais grave ao mais simples.
$alerts = [];
if (!$opId) {
    $alerts[] = ['err', 'Nenhum operador escolhido: o site continua no formulário antigo.', 'painel/configuracoes/atendimento', 'Escolher operador'];
}
if (setting('llm_enabled') === '1' && !$keyOk) {
    $alerts[] = ['err', 'A IA está marcada como ligada, mas sem chave do fornecedor.', 'painel/configuracoes/ia', 'Cadastrar chave'];
} elseif ($recentErr) {
    $alerts[] = ['warn', 'A IA teve erro nas últimas 24 horas: ' . mb_strimwidth((string) $usage['last_error']['error'], 0, 90, '…'), 'painel/configuracoes/ia', 'Ver a IA'];
}
if ($lastMail && !$lastMail['ok']) {
    $alerts[] = ['err', 'O último e-mail de aviso falhou: ' . mb_strimwidth((string) $lastMail['error'], 0, 90, '…'), 'painel/configuracoes/email', 'Ver e-mails'];
} elseif ($driver === 'mail') {
    $alerts[] = ['warn', 'Os avisos saem pela função de e-mail da hospedagem e podem cair no spam. Configure o SMTP.', 'painel/configuracoes/email', 'Configurar SMTP'];
} elseif ($driver === 'off') {
    $alerts[] = ['warn', 'Os avisos por e-mail estão desligados: o cliente só vê as novidades quando entra.', 'painel/configuracoes/email', 'Ver e-mails'];
}
if ((int) $tpl['total'] > (int) $tpl['ok']) {
    $alerts[] = ['warn', ((int) $tpl['total'] - (int) $tpl['ok']) . ' modelo(s) de documento aguardando a sua aprovação.', 'painel/modelos-documentos', 'Revisar modelos'];
}
if (!google_connected()) {
    $alerts[] = ['info', 'Google não conectado: as reuniões ficam só no sistema (sem Meet) e os atendimentos não vão para o Drive.', 'painel/configuracoes/agenda', 'Conectar'];
}
if (!asaas_enabled()) {
    $alerts[] = ['info', 'Asaas sem chave: as cobranças do contrato ainda não podem ser criadas pelo sistema.', 'painel/configuracoes/asaas', 'Conectar'];
}

$icon = function (string $d): string {
    return '<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" d="' . $d . '"/></svg>';
};
$I = [
    'inbox' => 'M22 12h-6l-2 3h-4l-2-3H2M5.5 5.1 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.5-6.9A2 2 0 0 0 16.7 4H7.3a2 2 0 0 0-1.8 1.1z',
    'spark' => 'M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9zM19 15l.8 2.2L22 18l-2.2.8L19 21l-.8-2.2L16 18l2.2-.8z',
    'chip' => 'M9 2v2M15 2v2M9 20v2M15 20v2M2 9h2M2 15h2M20 9h2M20 15h2M6 4h12a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2zM9 9h6v6H9z',
    'mail' => 'M4 4h16a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2zM22 6l-10 7L2 6',
    'calendar' => 'M3 6a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2zM16 2v4M8 2v4M3 10h18',
    'card' => 'M3 5h18a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2zM1 10h22',
    'braces' => 'M8 3H7a2 2 0 0 0-2 2v5a2 2 0 0 1-2 2 2 2 0 0 1 2 2v5a2 2 0 0 0 2 2h1M16 3h1a2 2 0 0 1 2 2v5a2 2 0 0 0 2 2 2 2 0 0 0-2 2v5a2 2 0 0 1-2 2h-1',
    'doc' => 'M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8zM14 2v6h6M8 13h8M8 17h6',
    'coin' => 'M12 1v22M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6',
    'chat' => 'M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z',
    'team' => 'M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75',
    'move' => 'M5 12h14M13 5l7 7-7 7',
];

// Controles rápidos: [chave, rótulo, ligado?, descrição, confirmação ao desligar]
$switches = [
    ['entrada', 'Atendimento online no site', intake_workflow() === 'simple_v1', 'Novas entradas pelo chat. Desligado: formulário antigo.', 'Desligar o atendimento online? As novas entradas voltam ao formulário antigo.'],
    ['ia', 'Inteligência artificial', $iaOn, $keyOk ? 'Lê documentos e apoia o assistente.' : 'Cadastre a chave para poder ligar.', 'Desligar a IA? O site passa a usar só o roteiro com botões.'],
    ['agente', 'IA conduz a conversa', $agentOn && $iaOn, 'Conversa livre com o cliente, com áudio e anexos.', null],
    ['avisos', 'Avisos por e-mail', $driver !== 'off', 'Avisa o cliente de novidade na conversa.', null],
];

// Quadros: [grupo, ícone, título, estado (ok|warn|err|off|info), resumo do estado, detalhes, link, rótulo do link]
$tiles = [
    ['Atendimento', 'inbox', 'Atendimento e entrada', $opId ? 'ok' : 'err', $opId ? 'Operador: ' . $opName : 'Sem operador',
        [intake_workflow() === 'simple_v1' ? 'Entrada pelo atendimento online' : 'Entrada pelo formulário antigo'], 'painel/configuracoes/atendimento', 'Configurar'],
    ['Atendimento', 'spark', 'Assistente com IA', $agentOn && $iaOn ? 'ok' : 'off', $agentOn && $iaOn ? 'Conduzindo a conversa' : 'Roteiro com botões',
        [count(agent_required_fields()) . ' variáveis e ' . count(agent_required_docs()) . ' documentos mínimos', 'Tom, proibições e competências'], 'painel/assistente', 'Ajustar o assistente'],
    ['Atendimento', 'chat', 'Mensagens prontas', 'info', 'Modelos de mensagem ao cliente', ['Textos usados no WhatsApp e na área do cliente'], 'painel/modelos', 'Abrir mensagens'],
    ['Integrações', 'chip', 'Inteligência artificial', !$keyOk ? 'err' : ($iaOn ? ($recentErr ? 'warn' : 'ok') : 'off'),
        !$keyOk ? 'Sem chave' : ($iaOn ? 'Ligada' : 'Desligada'),
        [LLM_PROVIDERS[llm_provider()]['label'] . ' em uso · ' . llm_model(), $keyOk ? 'Chave ' . llm_key_hint() : 'Chave não configurada', $usage['calls'] ? $usage['calls'] . ' chamada(s) em 30 dias' . ($usage['errors'] ? ', ' . $usage['errors'] . ' com erro' : '') : 'Sem uso nos últimos 30 dias'],
        'painel/configuracoes/ia', 'Configurar'],
    ['Integrações', 'mail', 'E-mails de aviso', $driver === 'off' ? 'off' : ($lastMail && !$lastMail['ok'] ? 'err' : ($driver === 'smtp' ? 'ok' : 'warn')),
        MAIL_DRIVERS[$driver] ?? $driver,
        ['Remetente: ' . mail_from()[0], 'Espera antes de avisar: ' . notify_delay_min() . ' min', $lastMail ? 'Último envio: ' . fmt_datetime($lastMail['created_at']) . ($lastMail['ok'] ? ' (enviado)' : ' (falhou)') : 'Nenhum envio ainda'],
        'painel/configuracoes/email', 'Configurar'],
    ['Integrações', 'card', 'Asaas (cobranças)', asaas_enabled() ? 'ok' : 'info', asaas_enabled() ? 'Conectado · ' . ASAAS_ENVS[asaas_env()]['label'] : 'Não conectado',
        [asaas_enabled() ? 'Chave ' . asaas_key_hint() : 'Chave não configurada', 'Multa ' . str_replace('.', ',', (string) asaas_late_fees()['multa']) . '% · juros ' . str_replace('.', ',', (string) asaas_late_fees()['juros']) . '% ao mês'],
        'painel/configuracoes/asaas', 'Configurar'],
    ['Integrações', 'calendar', 'Google (Agenda e Drive)', google_connected() ? (drive_enabled() ? 'ok' : 'warn') : 'info', google_connected() ? 'Conectado' . (setting('google_account_email') ? ' · ' . setting('google_account_email') : '') : 'Não conectado',
        ['Conversas de ' . agenda_int('agenda_duracao') . ' min · expediente ' . implode(' às ', array_map('agenda_hhmm', agenda_hours())), drive_enabled() ? 'Drive: ' . drive_path() : 'Drive não liberado'], 'painel/configuracoes/agenda', 'Configurar'],
    ['Integrações', 'coin', 'Preços e negociação', 'ok', fmt_pct(pricing()['pct_veiculo']) . ' do veículo · mín. ' . brl(pricing()['minimo']),
        ['Êxito: ' . fmt_pct(pricing()['exito']) . ' da economia', 'Taxas do Asaas: ' . (asaas_fees_custom() ? 'personalizadas' : 'padrão de referência')], 'painel/configuracoes/precos', 'Configurar'],
    ['Documentos', 'braces', 'Dados do escritório', 'info', 'Variáveis dos documentos', ['Endereço, telefone, PIX, foro e campos personalizados'], 'painel/variaveis', 'Editar dados'],
    ['Documentos', 'doc', 'Modelos de documentos', (int) $tpl['total'] > (int) $tpl['ok'] ? 'warn' : 'ok', (int) $tpl['ok'] . ' de ' . (int) $tpl['total'] . ' aprovados',
        ['Procuração, contrato, declaração e demais'], 'painel/modelos-documentos', 'Revisar modelos'],
    ['Documentos', 'coin', 'Propostas', 'info', 'Honorários e parcelamento', ['Simulação e cláusula de pagamento do contrato'], 'painel/propostas', 'Abrir propostas'],
    ['Equipe e sistema', 'team', 'Usuários do painel', 'info', $activeUsers . ' usuário(s) ativo(s)', ['Acessos e funções (somente supervisor)'], 'painel/equipe', 'Gerenciar'],
    ['Equipe e sistema', 'move', 'Migração', $legacy ? 'info' : 'ok', $legacy ? $legacy . ' caso(s) no fluxo anterior' : 'Nada a migrar', ['Atendimentos registrados antes do novo fluxo'], 'painel/migracao', 'Abrir migração'],
];
$groups = [];
foreach ($tiles as $t) {
    $groups[$t[0]][] = $t;
}
$stLabel = ['ok' => 'Funcionando', 'warn' => 'Atenção', 'err' => 'Resolver', 'off' => 'Desligado', 'info' => ''];
?>
<div class="page-head"><div><h1>Painel de controles</h1><p class="sub">A situação de cada área num só lugar. Ligue e desligue o essencial aqui; os detalhes ficam em “Configurar”. Nada aqui apaga atendimentos.</p></div></div>

<section class="cfg-switches" aria-label="Controles rápidos">
  <?php foreach ($switches as [$key, $label, $on, $desc, $confirm]): ?>
    <form method="post" action="<?= e(url('painel/configuracoes')) ?>" class="cfg-switch<?= $on ? ' is-on' : '' ?>">
      <?= csrf_field() ?><input type="hidden" name="op" value="alternar"><input type="hidden" name="controle" value="<?= e($key) ?>"><input type="hidden" name="ligar" value="<?= $on ? '0' : '1' ?>">
      <span class="cfg-switch-txt"><strong><?= e($label) ?></strong><small><?= e($desc) ?></small></span>
      <button type="submit" class="cfg-toggle" role="switch" aria-checked="<?= $on ? 'true' : 'false' ?>" aria-label="<?= e(($on ? 'Desligar: ' : 'Ligar: ') . $label) ?>"<?= $on && $confirm ? ' data-confirm="' . e($confirm) . '"' : '' ?>><span></span></button>
      <span class="cfg-switch-state"><?= $on ? 'Ligado' : 'Desligado' ?></span>
    </form>
  <?php endforeach ?>
</section>

<?php if ($alerts): ?>
<section class="box cfg-alerts" aria-labelledby="h-alertas">
  <h2 id="h-alertas">O que precisa de atenção <span class="cfg-count"><?= count($alerts) ?></span></h2>
  <ul>
    <?php foreach ($alerts as [$tone, $txt, $href, $cta]): ?>
      <li class="cfg-alert cfg-<?= e($tone) ?>"><span class="cfg-dot" aria-hidden="true"></span><span class="cfg-alert-txt"><?= e($txt) ?></span><a class="b" href="<?= e(url($href)) ?>"><?= e($cta) ?></a></li>
    <?php endforeach ?>
  </ul>
</section>
<?php else: ?>
<p class="cfg-allok"><span class="cfg-dot" aria-hidden="true"></span> Tudo configurado e funcionando.</p>
<?php endif ?>

<?php foreach ($groups as $g => $list): ?>
  <h2 class="cfg-group"><?= e($g) ?></h2>
  <div class="cfg-grid">
    <?php foreach ($list as [, $ic, $title, $st, $state, $facts, $href, $cta]): ?>
      <a class="cfg-tile cfg-<?= e($st) ?>" href="<?= e(url($href)) ?>">
        <span class="cfg-tile-head">
          <span class="cfg-ico"><?= $icon($I[$ic]) ?></span>
          <strong><?= e($title) ?></strong>
          <?php if ($stLabel[$st]): ?><span class="cfg-pill"><span class="cfg-dot" aria-hidden="true"></span><?= e($stLabel[$st]) ?></span><?php endif ?>
        </span>
        <span class="cfg-state"><?= e($state) ?></span>
        <span class="cfg-facts"><?php foreach ($facts as $f): ?><span><?= e($f) ?></span><?php endforeach ?></span>
        <span class="cfg-cta"><?= e($cta) ?> ›</span>
      </a>
    <?php endforeach ?>
  </div>
<?php endforeach ?>
