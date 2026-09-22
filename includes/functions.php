<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) session_start();

function h(mixed $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
function redirect(string $url): never {
    header('Location: ' . $url);
    exit;
}
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}
function csrf_field(): string {
    return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
}
function verify_csrf(): void {
    $token = $_POST['csrf'] ?? '';
    if (!$token || !hash_equals($_SESSION['csrf'] ?? '', (string)$token)) {
        http_response_code(419);
        exit('Pedido inválido (CSRF). Atualiza a página e tenta novamente.');
    }
}
function flash(string $type, string $message): void {
    $_SESSION['flash'] = ['type'=>$type,'message'=>$message];
}
function render_flash(): void {
    if (!empty($_SESSION['flash'])) {
        $f = $_SESSION['flash'];
        unset($_SESSION['flash']);
        echo '<div class="flash ' . h($f['type']) . '">' . h($f['message']) . '</div>';
    }
}
function initials(string $name): string {
    $parts = preg_split('/\s+/u', trim($name)) ?: [];
    $out = '';
    foreach (array_slice($parts,0,2) as $p) $out .= mb_strtoupper(mb_substr($p,0,1));
    return $out ?: 'HM';
}
function table_exists(string $table): bool {
    static $cache=[];
    if (array_key_exists($table,$cache)) return $cache[$table];
    try {
        $st=db()->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
        $st->execute([$table]);
        return $cache[$table]=((int)$st->fetchColumn()>0);
    } catch(Throwable) { return false; }
}
function column_exists(string $table,string $column): bool {
    static $cache=[];
    $k=$table.'.'.$column;
    if (array_key_exists($k,$cache)) return $cache[$k];
    try {
        $st=db()->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
        $st->execute([$table,$column]);
        return $cache[$k]=((int)$st->fetchColumn()>0);
    } catch(Throwable) { return false; }
}
function table_count(string $table): int {
    $allowed=['atletas','equipas','escaloes','inscricoes_atleta','competicoes','jogos','treinos','treinadores','convocatorias','eventos_jogo','presencas_treino','lesoes','utilizadores','fontes_externas','notificacoes'];
    if (!in_array($table,$allowed,true) || !table_exists($table)) return 0;
    return (int)db()->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
}
function db_date(?string $v): string {
    if (!$v || $v==='0000-00-00') return '—';
    try { return (new DateTimeImmutable($v))->format('d/m/Y'); } catch(Throwable) { return (string)$v; }
}
function db_datetime(?string $date, ?string $time=null): string {
    if (!$date) return '—';
    $s = db_date($date);
    if ($time) $s .= ' · ' . substr($time,0,5);
    return $s;
}
function age_from_date(?string $date): ?int {
    if (!$date) return null;
    try { return (new DateTimeImmutable($date))->diff(new DateTimeImmutable('today'))->y; } catch(Throwable) { return null; }
}
function pct(float|int $num,float|int $den,int $dec=1): float {
    return $den>0 ? round(($num/$den)*100,$dec) : 0.0;
}
function current_season(): string {
    return $_SESSION['season'] ?? APP_SEASON;
}
function user_role(): string {
    return (string)($_SESSION['user_role'] ?? '');
}
function can_manage(string $area='sport'): bool {
    $role=user_role();
    if ($role==='Administrador') return true;
    if ($area==='users' || $area==='settings') return false;
    return in_array($role,['Treinador','Dirigente'],true);
}
function status_class(?string $status): string {
    $s=mb_strtolower((string)$status);
    if (str_contains($s,'cancel')||str_contains($s,'inativ')||str_contains($s,'susp')||str_contains($s,'falta')||str_contains($s,'indispon')) return 'red';
    if (str_contains($s,'agend')||str_contains($s,'adiad')||str_contains($s,'recuper')||str_contains($s,'atras')) return 'yellow';
    if (str_contains($s,'ativo')||str_contains($s,'realiz')||str_contains($s,'presente')||str_contains($s,'recuperado')) return '';
    return 'blue';
}
function safe_return_url(string $fallback='index.php'): string {
    $r=(string)($_GET['return'] ?? $_POST['return'] ?? '');
    if ($r!=='' && !preg_match('~^(?:https?:)?//~i',$r) && !str_contains($r,"\n") && !str_contains($r,"\r")) return $r;
    return $fallback;
}
function activity_log(string $acao,string $entidade='',?int $id=null,string $detalhe=''): void {
    if (!table_exists('atividade_log')) return;
    try {
        $st=db()->prepare("INSERT INTO atividade_log (IdUtilizador,Acao,Entidade,IdRegisto,Detalhe,IP) VALUES (?,?,?,?,?,?)");
        $st->execute([$_SESSION['user_id']??null,$acao,$entidade?:null,$id,$detalhe?:null,$_SERVER['REMOTE_ADDR']??null]);
    } catch(Throwable) {}
}
function source_badge(?string $source): string {
    if (!$source) return '—';
    $label=str_contains(mb_strtolower($source),'federa')?'FPA':(str_contains(mb_strtolower($source),'zerozero')?'zerozero':$source);
    return '<span class="source-badge">'.h($label).'</span>';
}
