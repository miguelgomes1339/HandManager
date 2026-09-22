<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';

function nav_groups(): array {
 return [
  'Visão geral'=>[
   ['index.php','▦','Dashboard','dashboard'],
   ['calendario.php','◫','Calendário','calendario'],
   ['pesquisa.php','⌕','Pesquisa global','pesquisa'],
  ],
  'Gestão desportiva'=>[
   ['atletas.php','♟','Atletas','atletas'],
   ['equipas.php','◈','Equipas','equipas'],
   ['escaloes.php','◇','Escalões','escaloes'],
   ['inscricoes.php','↔','Inscrições','inscricoes'],
   ['treinadores.php','♜','Equipa técnica','treinadores'],
   ['competicoes.php','★','Competições','competicoes'],
   ['participacoes.php','⊕','Participações','participacoes'],
   ['classificacoes.php','≡','Classificações','classificacoes'],
  ],
  'Jogo & performance'=>[
   ['jogos.php','▤','Jogos','jogos'],
   ['convocatorias.php','▧','Convocatórias','convocatorias'],
   ['eventos.php','ϟ','Eventos de jogo','eventos'],
   ['estatisticas.php','▥','Estatísticas','estatisticas'],
  ],
  'Treino & saúde'=>[
   ['treinos.php','⚒','Treinos','treinos'],
   ['presencas.php','✓','Presenças','presencas'],
   ['lesoes.php','✚','Lesões','lesoes'],
  ],
  'Organização'=>[
   ['relatorios.php','⇩','Relatórios','relatorios'],
   ['fontes.php','◎','Fontes externas','fontes'],
   ['notificacoes.php','●','Notificações','notificacoes'],
  ],
  'Sistema'=>[
   ['utilizadores.php','♙','Utilizadores','utilizadores'],
   ['atividade.php','↻','Atividade','atividade'],
   ['definicoes.php','⚙','Definições','definicoes'],
  ],
 ];
}
function render_header(string $title, string $active=''): void {
 $u=current_user();
 $season=current_season();
 $notif=0;
 if(table_exists('notificacoes')){try{$st=db()->prepare("SELECT COUNT(*) FROM notificacoes WHERE Lida=0 AND (IdUtilizador IS NULL OR IdUtilizador=?)");$st->execute([$u['id']]);$notif=(int)$st->fetchColumn();}catch(Throwable){}}
?><!doctype html><html lang="pt-PT"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="color-scheme" content="dark">
<title><?=h($title)?> · HandManager</title>
<link rel="icon" href="assets/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="assets/css/style.css?v=20260915-1"></head><body>
<div class="app">
<aside class="sidebar" id="sidebar">
<a class="brand" href="index.php"><img src="assets/logo.svg" alt="HandManager"></a>
<nav class="nav">
<?php foreach(nav_groups() as $group=>$items): ?>
<div class="nav-group-label"><?=h($group)?></div>
<?php foreach($items as [$href,$ico,$label,$key]): ?>
<?php if(in_array($key,['utilizadores','atividade'],true) && !can_manage('users')) continue; ?>
<a href="<?=$href?>" class="<?=$active===$key?'active':''?>"><span class="nav-ico"><?=$ico?></span><span><?=h($label)?></span></a>
<?php endforeach; ?>
<?php endforeach; ?>
</nav>
<div class="sidebar-foot"><strong>HandManager</strong><br>Gestão integrada de andebol<br><span>v3.0 · Época <?=h($season)?></span></div>
</aside>
<main class="main">
<header class="topbar">
<button class="btn mobile" id="mobileMenu" type="button">☰</button>
<form class="search" action="pesquisa.php" method="get"><span class="glass">⌕</span><input id="globalSearch" name="q" placeholder="Pesquisar atletas, equipas, jogos..." autocomplete="off"><span class="key">Ctrl + K</span></form>
<div class="top-actions">
<a class="notif-button" href="notificacoes.php" title="Notificações">●<?php if($notif):?><b><?=min($notif,99)?></b><?php endif;?></a>
<form action="season.php" method="post" class="season-form"><?=csrf_field()?>
<select class="season" name="season" onchange="this.form.submit()" aria-label="Época">
<?php foreach(['2026/2027','2025/2026','2024/2025'] as $s):?><option value="<?=h($s)?>" <?=$s===$season?'selected':''?>>Época <?=h($s)?></option><?php endforeach;?>
</select><input type="hidden" name="return" value="<?=h(basename($_SERVER['REQUEST_URI'] ?? 'index.php'))?>"></form>
<div class="profile"><div class="avatar"><?=h(initials((string)$u['name']))?></div><div><strong><?=h($u['name'])?></strong><span><?=h($u['role'])?> · <a href="logout.php">Sair</a></span></div></div>
</div>
</header><section class="content">
<?php render_flash(); ?>
<?php
}
function render_footer(string $extra=''): void {
 echo '</section></main></div>';
 echo $extra;
 echo '<script src="assets/js/app.js?v=20260915-1"></script></body></html>';
}
?>
<script>
document.addEventListener('DOMContentLoaded', function () {

    const sidebar = document.getElementById('sidebar');

    if (!sidebar) return;

    const scrollGuardKey = 'handmanager_sidebar_scroll';

    // Recuperar posição anterior
    const savedScroll = sessionStorage.getItem(scrollGuardKey);

    if (savedScroll !== null) {
        sidebar.scrollTop = parseInt(savedScroll, 10);
    }

    // Guardar posição sempre que fazemos scroll
    sidebar.addEventListener('scroll', function () {
        sessionStorage.setItem(scrollGuardKey, sidebar.scrollTop);
    }, { passive: true });

    // Garantir que a posição fica guardada antes de mudar de página
    document.querySelectorAll('.sidebar a').forEach(function(link) {

        link.addEventListener('click', function() {
            sessionStorage.setItem(
                scrollGuardKey,
                sidebar.scrollTop
            );
        });

    });

});
</script>