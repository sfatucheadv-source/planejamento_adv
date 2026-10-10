<?php
/** Símbolo da marca (hexágono aberto com barra central), adaptado do manual da campanha. */
$size = $size ?? 40;
$light = $light ?? false;
?>
<svg class="mark" width="<?= (int) $size ?>" height="<?= (int) round($size * 1.1) ?>" viewBox="0 0 100 110" aria-hidden="true" focusable="false">
  <path d="M43 12 L11 31 L11 79 L43 98" fill="none" stroke="<?= $light ? '#3FA9A2' : '#1E7C78' ?>" stroke-width="10" stroke-linejoin="miter"/>
  <path d="M57 12 L89 31 L89 79 L57 98" fill="none" stroke="<?= $light ? '#F7F3EA' : '#0F3B43' ?>" stroke-width="10" stroke-linejoin="miter"/>
  <rect x="45" y="30" width="10" height="50" fill="#C4A062"/>
</svg>
