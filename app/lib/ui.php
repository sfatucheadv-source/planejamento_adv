<?php
declare(strict_types=1);

/** Abre um formulário de ação do caso no painel. */
function case_form(array $c, string $op, array $hidden = [], string $class = '', bool $multipart = false): string
{
    $h = '<form method="post" action="' . e(url('painel/caso/' . $c['id'])) . '" class="' . e($class) . '"' . ($multipart ? ' enctype="multipart/form-data"' : '') . '>';
    $h .= csrf_field() . '<input type="hidden" name="op" value="' . e($op) . '">';
    // No modo guiado, toda ação volta para o passo em execução.
    if (!empty($GLOBALS['GUIA_VOLTA']) && !isset($hidden['volta'])) {
        $hidden['volta'] = $GLOBALS['GUIA_VOLTA'];
    }
    foreach ($hidden as $k => $v) {
        $h .= '<input type="hidden" name="' . e($k) . '" value="' . e((string) $v) . '">';
    }
    return $h;
}

/** Item de checklist com alternância (passos e condições de avanço). */
function check_item(array $c, string $stageKey, string $item, string $label, ?array $row, string $back, bool $block = false): string
{
    $done = !empty($row['done']);
    $meta = $done && !empty($row['done_at']) ? '<small>' . e(first_name(user_name($row['done_by'] ? (int) $row['done_by'] : null))) . ' · ' . e(fmt_datetime($row['done_at'])) . '</small>' : '';
    return case_form($c, 'check', ['stage' => $stageKey, 'item' => $item, 'done' => $done ? '0' : '1', 'back' => $back], 'ck' . ($done ? ' ck-on' : '') . ($block ? ' ck-block' : ''))
        . '<button type="submit" aria-pressed="' . ($done ? 'true' : 'false') . '"><span class="ck-box" aria-hidden="true"></span><span class="ck-text">' . e($label) . ($block ? ' <em>obrigatório</em>' : '') . '</span></button>' . $meta . '</form>';
}

function user_select(string $name, ?int $selected, array $users, string $empty = '—'): string
{
    $h = '<select name="' . e($name) . '"><option value="">' . e($empty) . '</option>';
    foreach ($users as $u) {
        $h .= '<option value="' . (int) $u['id'] . '"' . selected($selected, (int) $u['id']) . '>' . e($u['name']) . '</option>';
    }
    return $h . '</select>';
}

function dt_local(?string $v): string
{
    $d = dt($v);
    return $d ? $d->format('Y-m-d\TH:i') : '';
}

function status_tag(string $status, array $map): string
{
    $s = $map[$status] ?? ['label' => $status, 'tone' => 'muted'];
    $tone = ['ok' => 'green', 'warn' => 'amber', 'info' => 'blue', 'muted' => 'gray', 'danger' => 'red'][$s['tone']] ?? 'gray';
    return '<span class="tag tag-' . $tone . '">' . e($s['label']) . '</span>';
}

function num(?float $v, int $dec = 2): string
{
    return $v === null ? '' : number_format($v, $dec, ',', '.');
}
