<?php
/**
 * Cria (ou atualiza a senha de) um usuário do painel.
 * Uso: php scripts/criar-usuario.php "Nome" email@dominio "gestor,advogado,supervisor" [senha]
 * Sem senha, uma senha forte é gerada e exibida uma única vez.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/app/bootstrap.php';

[$self, $name, $email, $roles, $pass] = array_pad($argv, 5, null);
if (!$name || !$email) {
    fwrite(STDERR, "Uso: php scripts/criar-usuario.php \"Nome\" email \"gestor,advogado,calculos,negociador,financeiro,supervisor\" [senha]\n");
    exit(1);
}
$roles = $roles ?: implode(',', array_keys(ROLES));
$valid = array_intersect(array_map('trim', explode(',', $roles)), array_keys(ROLES));
if (!$valid) {
    fwrite(STDERR, "Funções válidas: " . implode(', ', array_keys(ROLES)) . "\n");
    exit(1);
}
$generated = false;
if (!$pass) {
    $pass = rtrim(strtr(base64_encode(random_bytes(12)), '+/', 'xy'), '=');
    $generated = true;
}
if (strlen($pass) < 10) {
    fwrite(STDERR, "A senha precisa de pelo menos 10 caracteres.\n");
    exit(1);
}
$email = mb_strtolower($email);
$existing = one('SELECT id FROM users WHERE email = ?', [$email]);
if ($existing) {
    update('users', (int) $existing['id'], ['name' => $name, 'roles' => implode(',', $valid), 'pass_hash' => password_hash($pass, PASSWORD_DEFAULT), 'active' => 1]);
    echo "Usuário atualizado: $email\n";
} else {
    insert('users', ['name' => $name, 'email' => $email, 'roles' => implode(',', $valid), 'pass_hash' => password_hash($pass, PASSWORD_DEFAULT), 'active' => 1, 'created_at' => now_str()]);
    echo "Usuário criado: $email\n";
}
echo 'Funções: ' . implode(', ', array_map(fn($r) => ROLES[$r], $valid)) . "\n";
if ($generated) {
    echo "Senha gerada (anote agora, não será exibida de novo): $pass\n";
}
