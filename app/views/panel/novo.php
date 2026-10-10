<?php
$v = fn(string $k) => e(is_string($old[$k] ?? null) ? $old[$k] : '');
$err = fn(string $k) => isset($errors[$k]) ? '<p class="err">' . e($errors[$k]) . '</p>' : '';
$s1 = stage('acolhimento');
?>
<div class="page-head">
  <div>
    <h1>Novo atendimento</h1>
    <p class="sub">Para contatos que chegaram por WhatsApp, telefone ou indicação. Abre o registro único do caso.</p>
  </div>
</div>

<div class="grid-main-side">
  <form class="box form" method="post" action="<?= e(url('painel/casos/novo')) ?>">
    <?= csrf_field() ?>
    <h2>1. Primeiro, a urgência</h2>
    <p class="hint">“Você recebeu alguma notificação, documento de cartório ou da Justiça? O veículo já foi apreendido?”</p>
    <div class="radios">
      <?php foreach (NOTICE_OPTIONS as $k => $l): ?><label><input type="radio" name="notice" value="<?= e($k) ?>"<?= checked(($old['notice'] ?? '') === $k) ?>> <?= e($l) ?></label><?php endforeach ?>
    </div>
    <?= $err('notice') ?>
    <label class="fld fld-s"><span>Data do documento ou da apreensão</span><input type="date" name="notice_date" value="<?= $v('notice_date') ?>"></label>

    <h2>2. O mínimo para entender o caso</h2>
    <div class="row3">
      <label class="fld"><span>Banco</span><input name="bank" value="<?= $v('bank') ?>"></label>
      <label class="fld"><span>Contrato</span><select name="contract_status"><option value="">—</option><option value="ativo"<?= selected($old['contract_status'] ?? '', 'ativo') ?>>Ativo</option><option value="quitado"<?= selected($old['contract_status'] ?? '', 'quitado') ?>>Quitado</option><option value="nao_sei"<?= selected($old['contract_status'] ?? '', 'nao_sei') ?>>Não sabe</option></select></label>
      <label class="fld"><span>Parcela aproximada</span><input name="installment_value" value="<?= $v('installment_value') ?>" placeholder="R$"></label>
      <label class="fld"><span>Atraso</span><select name="overdue"><option value="">—</option><option value="sim"<?= selected($old['overdue'] ?? '', 'sim') ?>>Sim</option><option value="nao"<?= selected($old['overdue'] ?? '', 'nao') ?>>Não</option><option value="nao_sei"<?= selected($old['overdue'] ?? '', 'nao_sei') ?>>Não sabe</option></select></label>
      <label class="fld"><span>Quantas parcelas</span><input name="overdue_count" value="<?= $v('overdue_count') ?>"></label>
      <label class="fld"><span>Tem o contrato?</span><select name="has_contract"><option value="">—</option><option value="sim"<?= selected($old['has_contract'] ?? '', 'sim') ?>>Sim</option><option value="parcial"<?= selected($old['has_contract'] ?? '', 'parcial') ?>>Parcial</option><option value="nao"<?= selected($old['has_contract'] ?? '', 'nao') ?>>Não</option></select></label>
      <label class="fld"><span>Cidade</span><input name="city" value="<?= $v('city') ?>"></label>
      <label class="fld"><span>UF</span><select name="uf"><option value="">—</option><?php foreach (UFS as $uf): ?><option<?= selected($old['uf'] ?? '', $uf) ?>><?= $uf ?></option><?php endforeach ?></select></label>
      <label class="fld"><span>Outro advogado/processo?</span><select name="other_lawyer"><option value="">—</option><option value="sim"<?= selected($old['other_lawyer'] ?? '', 'sim') ?>>Sim</option><option value="nao"<?= selected($old['other_lawyer'] ?? '', 'nao') ?>>Não</option><option value="nao_sei"<?= selected($old['other_lawyer'] ?? '', 'nao_sei') ?>>Não sabe</option></select></label>
    </div>

    <h2>3. Prioridade</h2>
    <p class="hint">“O que você precisa resolver primeiro?” · “Qual pagamento caberia no seu orçamento sem comprometer despesas essenciais?”</p>
    <div class="radios">
      <?php foreach (PRIORITY_OPTIONS as $k => $p): ?><label><input type="radio" name="priority" value="<?= e($k) ?>"<?= checked(($old['priority'] ?? '') === $k) ?>> <?= e($p['label']) ?></label><?php endforeach ?>
    </div>
    <?= $err('priority') ?>
    <div class="row3">
      <label class="fld"><span>Parcela que cabe no orçamento</span><input name="budget_installment" value="<?= $v('budget_installment') ?>" placeholder="R$ (estimativa)"></label>
    </div>
    <label class="fld"><span>Relato do cliente (resumo que acompanha todas as transferências)</span><textarea name="description" rows="4"><?= $v('description') ?></textarea></label>

    <h2>4. Contato</h2>
    <div class="row3">
      <label class="fld"><span>Nome</span><input name="name" value="<?= $v('name') ?>" required><?= $err('name') ?></label>
      <label class="fld"><span>Telefone/WhatsApp</span><input name="phone" value="<?= $v('phone') ?>" required><?= $err('phone') ?></label>
      <label class="fld"><span>E-mail</span><input type="email" name="email" value="<?= $v('email') ?>"></label>
      <label class="fld"><span>Prefere conversar por</span><select name="contact_pref"><option value="">—</option><?php foreach (CONTACT_PREFS as $k => $l): ?><option value="<?= $k ?>"<?= selected($old['contact_pref'] ?? '', $k) ?>><?= e($l) ?></option><?php endforeach ?></select></label>
      <label class="fld"><span>Melhor horário</span><input name="contact_time" value="<?= $v('contact_time') ?>"></label>
      <label class="fld"><span>Acessibilidade</span><input name="accessibility" value="<?= $v('accessibility') ?>"></label>
      <label class="fld"><span>Origem</span><select name="source"><?php foreach (['whatsapp' => 'WhatsApp', 'telefone' => 'Telefone', 'indicacao' => 'Indicação', 'instagram' => 'Instagram', 'email' => 'E-mail'] as $k => $l): ?><option value="<?= $k ?>"<?= selected($old['source'] ?? '', $k) ?>><?= $l ?></option><?php endforeach ?></select></label>
    </div>
    <label class="chk"><input type="checkbox" name="summary_ok" value="1"<?= checked(($old['summary_ok'] ?? '') === '1') ?>> Repeti o entendimento em uma frase e o cliente confirmou</label>
    <label class="chk"><input type="checkbox" name="first_contact" value="1" checked> Este registro é o primeiro contato humano (já conversei com o cliente)</label>
    <div class="actions"><button class="b b-primary" type="submit">Abrir caso</button></div>
  </form>

  <aside class="box guide">
    <h2>Roteiro do acolhimento</h2>
    <ol><?php foreach ($s1['steps'] as $t): ?><li><?= e($t) ?></li><?php endforeach ?></ol>
    <p class="hint"><strong>Mensagem sugerida:</strong> “<?= e($s1['message']) ?>”</p>
    <p class="hint">Valores nesta conversa são estimativas, não cálculos da dívida. Nunca peça senha do Gov.br, senha bancária ou código de autenticação.</p>
  </aside>
</div>
