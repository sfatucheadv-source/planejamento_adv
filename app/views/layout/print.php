<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($meta_title ?? 'Documento') ?></title>
<style>
:root{--ink:#1d2330;--muted:#5d6475;--line:#d9dce3;--bg:#fff;--mark:#fff3bf}
@media (prefers-color-scheme:dark){:root:not([data-theme="light"]){--ink:#e8eaf0;--muted:#a3a9b8;--line:#3a4050;--bg:#161a22;--mark:#5a4a00}}
body{background:var(--bg);color:var(--ink);font:12pt/1.55 Georgia,"Times New Roman",serif;max-width:17cm;margin:0 auto;padding:24px 16px}
.doc p{margin:0 0 .7em;white-space:normal;overflow-wrap:anywhere}
.doc mark{background:var(--mark);color:inherit}
.bar{font:14px/1.4 system-ui,sans-serif;display:flex;flex-wrap:wrap;gap:8px;align-items:center;justify-content:space-between;border-bottom:1px solid var(--line);padding-bottom:12px;margin-bottom:24px;color:var(--muted)}
.bar a{color:inherit}
.foot{font:12px system-ui,sans-serif;color:var(--muted);border-top:1px solid var(--line);margin-top:24px;padding-top:8px}
@media print{.bar{display:none}body{padding:0}}
</style>
</head>
<body><?= $content ?><script src="<?= e(asset('js/print.js')) ?>"></script></body>
</html>
