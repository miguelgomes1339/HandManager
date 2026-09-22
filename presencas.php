<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/layout.php';require_login();$pdo=db();
$trainingId=(int)($_GET['treino']??$_POST['IdTreino']??0);
if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='save'){if(!can_manage()) { http_response_code(403); exit('Sem permissões.'); } verify_csrf();$trainingId=(int)$_POST['IdTreino'];
 try{
  $pdo->beginTransaction();$pdo->prepare("DELETE FROM presencas_treino WHERE IdTreino=?")->execute([$trainingId]);
  foreach(($_POST['estado']??[]) as $aid=>$estado){
   if($estado==='')continue;
   $st=$pdo->prepare("INSERT INTO presencas_treino (IdTreino,IdAtleta,Estado,Observacoes) VALUES (?,?,?,?)");
   $st->execute([$trainingId,(int)$aid,$estado,trim((string)($_POST['obs'][$aid]??''))?:null]);
  }
  $pdo->commit();activity_log('Guardar presenças','presencas_treino',$trainingId);flash('success','Presenças guardadas.');
 }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();flash('error',$e->getMessage());}
 redirect('presencas.php?treino='.$trainingId);
}
$trainings=$pdo->query("SELECT IdTreino,DataTreino,TipoTreino,IdEquipa FROM treinos ORDER BY DataTreino DESC,IdTreino DESC")->fetchAll();
$training=null;$athletes=[];$current=[];
if($trainingId){
 $st=$pdo->prepare("SELECT * FROM treinos WHERE IdTreino=?");$st->execute([$trainingId]);$training=$st->fetch();
 if($training){
  if(table_exists('inscricoes_atleta')){$st=$pdo->prepare("SELECT DISTINCT a.* FROM atletas a LEFT JOIN inscricoes_atleta ia ON ia.IdAtleta=a.IdAtleta AND ia.IdEquipa=? AND ia.Estado='Ativo' WHERE a.IdEquipa=? OR ia.IdEquipa=? ORDER BY a.NumeroCamisola IS NULL,a.NumeroCamisola,a.Nome");$st->execute([$training['IdEquipa'],$training['IdEquipa'],$training['IdEquipa']]);$athletes=$st->fetchAll();}else{$st=$pdo->prepare("SELECT * FROM atletas WHERE IdEquipa=? ORDER BY NumeroCamisola IS NULL,NumeroCamisola,Nome");$st->execute([$training['IdEquipa']]);$athletes=$st->fetchAll();}
  $st=$pdo->prepare("SELECT * FROM presencas_treino WHERE IdTreino=?");$st->execute([$trainingId]);foreach($st->fetchAll() as $r)$current[$r['IdAtleta']]=$r;
 }
}
render_header('Presenças','presencas');
?>
<div class="page-head"><div><h1>Presenças nos Treinos</h1><p>Regista assiduidade e observações para cada sessão.</p></div></div>
<section class="panel form-card"><form class="toolbar" method="get"><select name="treino" required><option value="">Selecionar treino...</option><?php foreach($trainings as $t):?><option value="<?=$t['IdTreino']?>" <?=$trainingId===$t['IdTreino']?'selected':''?>><?=h(db_date($t['DataTreino']).' · '.$t['TipoTreino'])?></option><?php endforeach;?></select><button class="btn primary">Abrir treino</button></form></section>
<?php if($training):?><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="save"><input type="hidden" name="IdTreino" value="<?=$trainingId?>">
<section class="panel"><div class="panel-head"><div class="panel-title"><span class="accent">✓</span><?=h(db_date($training['DataTreino']).' · '.$training['TipoTreino'])?></div><?php if(can_manage()):?><button class="btn primary">Guardar presenças</button><?php endif;?></div><div class="panel-body table-wrap">
<?php if(!$athletes):?><div class="empty"><strong>Sem atletas nesta equipa</strong>Associa atletas à equipa primeiro.</div><?php else:?><table><thead><tr><th>Atleta</th><th>Estado</th><th>Observações</th></tr></thead><tbody>
<?php foreach($athletes as $a):$c=$current[$a['IdAtleta']]??null;?><tr data-searchable><td><div class="person"><span class="mini-avatar"><?=h(initials($a['Nome']))?></span><?=h($a['Nome'])?></div></td><td><select name="estado[<?=$a['IdAtleta']?>]" style="height:34px;background:#071827;color:white;border:1px solid rgba(126,167,202,.17);border-radius:8px"><option value="">—</option><?php foreach(['Presente','Falta','Falta Justificada','Atraso','Lesionado'] as $s):?><option <?=$c&&$c['Estado']===$s?'selected':''?>><?=h($s)?></option><?php endforeach;?></select></td><td><input name="obs[<?=$a['IdAtleta']?>]" value="<?=h($c['Observacoes']??'')?>" style="width:100%;min-width:180px;height:34px;background:#071827;color:white;border:1px solid rgba(126,167,202,.17);border-radius:8px;padding:0 8px"></td></tr><?php endforeach;?>
</tbody></table><?php endif;?></div></section></form><?php endif;render_footer();?>
