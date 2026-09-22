<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/functions.php';

function logged_in(): bool { return !empty($_SESSION['user_id']); }
function require_login(): void {
    if (!logged_in()) redirect('login.php');
}
function current_user(): array {
    return [
        'id'=>$_SESSION['user_id'] ?? null,
        'name'=>$_SESSION['user_name'] ?? 'Utilizador',
        'role'=>$_SESSION['user_role'] ?? '',
    ];
}
function require_manage(string $area='sport'): void {
    require_login();
    if (!can_manage($area)) {
        http_response_code(403);
        exit('Não tens permissões para alterar esta área.');
    }
}
