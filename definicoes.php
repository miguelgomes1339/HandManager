<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/layout.php';require_login();
$pdo=db();
$version='v3.0';
$mysql=(string)$pdo->query('SELECT VERSION()')->fetchColumn();
$checks=[
 'Escalões'=>table_exists('escaloes'),
 'Inscrições múltiplas'=>table_exists('inscricoes_atleta'),
 'Equipa técnica'=>table_exists('treinadores'),
 'Fontes externas'=>table_exists('fontes_externas'),
 'Classificações'=>table_exists('classificacao_competicao'),
 'Auditoria'=>table_exists('atividade_log'),
 'CIPA'=>column_exists('atletas','CIPA'),
 'Designação oficial das equipas'=>column_exists('equipas','NomeOficial'),
 'Código FPA das competições'=>column_exists('competicoes','CodigoFPA'),
];
render_header('Definições','definicoes');
?>
<div class="page-head"><div><h1>Definições & diagnóstico</h1><p>Configuração técnica, versão da base e estado dos módulos.</p></div></div>
<div class="grid2">
<section class="panel"><div class="panel-head"><div class="panel-title"><span class="accent">⚙</span>Aplicação</div></div><div class="panel-body"><div class="setting-list"><div class="setting-row"><div><strong>HandManager</strong><span>Versão da aplicação</span></div><b><?=$version?></b></div><div class="setting-row"><div><strong>Época ativa</strong><span>Usada nos novos registos e vistas</span></div><b><?=h(current_season())?></b></div><div class="setting-row"><div><strong>PHP</strong><span>Runtime do servidor</span></div><b><?=h(PHP_VERSION)?></b></div><div class="setting-row"><div><strong>MySQL / MariaDB</strong><span><?=h(DB_HOST.' · '.DB_NAME)?></span></div><b><?=h($mysql)?></b></div></div><div class="helper">A ligação é configurada em <b>config/config.php</b>.</div></div></section>
<section class="panel"><div class="panel-head"><div class="panel-title"><span class="accent">▥</span>Conteúdo atual</div></div><div class="panel-body"><div class="cards" style="grid-template-columns:1fr 1fr"><div class="card"><h3><?=table_count('atletas')?></h3><p>Atletas</p></div><div class="card"><h3><?=table_count('equipas')?></h3><p>Equipas</p></div><div class="card"><h3><?=table_count('jogos')?></h3><p>Jogos</p></div><div class="card"><h3><?=table_count('eventos_jogo')?></h3><p>Eventos</p></div></div></div></section>
</div>
<section class="panel" style="margin-top:14px"><div class="panel-head"><div class="panel-title"><span class="accent">✓</span>Estado do upgrade 2026/2027</div><span class="chip"><?=count(array_filter($checks))?> / <?=count($checks)?> módulos</span></div><div class="panel-body setting-list"><?php foreach($checks as $name=>$ok):?><div class="setting-row"><div><strong><?=h($name)?></strong><span><?=$ok?'Disponível':'Ainda não existe na base de dados'?></span></div><span class="status <?=$ok?'':'red'?>"><?=$ok?'OK':'Falta upgrade'?></span></div><?php endforeach;?></div></section>
<section class="panel info-banner"><div class="panel-body"><strong>Atualizar sem perder dados</strong><p>Se algum módulo aparecer como “Falta upgrade”, importa <b>upgrade_2026_2027.sql</b> no phpMyAdmin. O ficheiro foi preparado para acrescentar a nova estrutura sem apagar os plantéis e jogos existentes. Para uma instalação limpa, usa <b>database.sql</b>.</p></div></section>
<?php render_footer();?>
