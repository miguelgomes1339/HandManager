<?php
declare(strict_types=1);
require_once __DIR__.'/includes/layout.php';require_manage('users');$pdo=db();
if(!table_exists('atividade_log')){render_header('Atividade','atividade');echo '<div class="hero"><h1>Atividade</h1><p>Executa upgrade_2026_2027.sql para ativar a auditoria.</p></div>';render_footer();exit;}
$rows=$pdo->query("SELECT l.*,u.Nome Utilizador FROM atividade_log l LEFT JOIN utilizadores u ON u.IdUtilizador=l.IdUtilizador ORDER BY l.CriadaEm DESC LIMIT 250")->fetchAll();
render_header('Atividade','atividade');?>
<div class="page-head"><div><h1>Registo de atividade</h1><p>Histórico das alterações efetuadas pelos utilizadores da aplicação.</p></div><div class="actions"><span class="chip"><?=count($rows)?> registos recentes</span></div></div>
<section class="panel"><div class="panel-body table-wrap"><?php if(!$rows):?><div class="empty"><strong>Sem atividade registada</strong>As próximas operações de criação, edição e eliminação serão registadas.</div><?php else:?><table><thead><tr><th>Data</th><th>Utilizador</th><th>Ação</th><th>Entidade</th><th>ID</th><th>Detalhe</th><th>IP</th></tr></thead><tbody><?php foreach($rows as $r):?><tr data-searchable><td><?=h($r['CriadaEm'])?></td><td><?=h($r['Utilizador']??'Sistema')?></td><td><span class="chip"><?=h($r['Acao'])?></span></td><td><?=h($r['Entidade']??'—')?></td><td><?=h($r['IdRegisto']??'—')?></td><td><?=h($r['Detalhe']??'—')?></td><td><?=h($r['IP']??'—')?></td></tr><?php endforeach;?></tbody></table><?php endif;?></div></section>
<?php render_footer();?>
