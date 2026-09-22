<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/layout.php';require_login();$pdo=db();
$gameId=(int)($_GET['jogo']??$_POST['IdJogo']??0);
if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='save'){if(!can_manage()) { http_response_code(403); exit('Sem permissões.'); } verify_csrf();$gameId=(int)$_POST['IdJogo'];
 try{
  $pdo->beginTransaction();$pdo->prepare("DELETE FROM convocatorias WHERE IdJogo=?")->execute([$gameId]);
  foreach(($_POST['selected']??[]) as $athleteId){
   $aid=(int)$athleteId;$estado=$_POST['estado'][$aid]??'Convocado';$titular=!empty($_POST['titular'][$aid])?1:0;
   $st=$pdo->prepare("INSERT INTO convocatorias (IdJogo,IdAtleta,Estado,Titular,Observacoes) VALUES (?,?,?,?,NULL)");
   $st->execute([$gameId,$aid,$estado,$titular]);
  }
  $pdo->commit();activity_log('Guardar convocatória','convocatorias',$gameId);flash('success','Convocatória guardada com sucesso.');
 }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();flash('error',$e->getMessage());}
 redirect('convocatorias.php?jogo='.$gameId);
}
$games=$pdo->query("SELECT IdJogo,DataJogo,Adversario,IdEquipa FROM jogos ORDER BY DataJogo DESC")->fetchAll();
$game=null;$athletes=[];$current=[];
if($gameId){
 $st=$pdo->prepare("SELECT * FROM jogos WHERE IdJogo=?");$st->execute([$gameId]);$game=$st->fetch();
 if($game){
  if(table_exists('inscricoes_atleta')){$st=$pdo->prepare("SELECT DISTINCT a.* FROM atletas a LEFT JOIN inscricoes_atleta ia ON ia.IdAtleta=a.IdAtleta AND ia.IdEquipa=? AND ia.Estado='Ativo' WHERE a.IdEquipa=? OR ia.IdEquipa=? ORDER BY a.NumeroCamisola IS NULL,a.NumeroCamisola,a.Nome");$st->execute([$game['IdEquipa'],$game['IdEquipa'],$game['IdEquipa']]);$athletes=$st->fetchAll();}else{$st=$pdo->prepare("SELECT * FROM atletas WHERE IdEquipa=? ORDER BY NumeroCamisola IS NULL,NumeroCamisola,Nome");$st->execute([$game['IdEquipa']]);$athletes=$st->fetchAll();}
  $st=$pdo->prepare("SELECT * FROM convocatorias WHERE IdJogo=?");$st->execute([$gameId]);foreach($st->fetchAll() as $r)$current[$r['IdAtleta']]=$r;
 }
}
render_header('Convocatórias','convocatorias');
?>
<div class="page-head"><div><h1>Convocatórias</h1><p>Seleciona os atletas, estado de disponibilidade e titulares para cada jogo.</p></div></div>
<section class="panel form-card"><form class="toolbar" method="get"><select name="jogo" required><option value="">Selecionar jogo...</option><?php foreach($games as $g):?><option value="<?=$g['IdJogo']?>" <?=$gameId===$g['IdJogo']?'selected':''?>><?=h(db_date($g['DataJogo']).' · '.$g['Adversario'])?></option><?php endforeach;?></select><button class="btn primary">Abrir convocatória</button></form></section>
<?php if($game):?><form method="post"><input type="hidden" name="action" value="save"><input type="hidden" name="IdJogo" value="<?=$gameId?>"><?=csrf_field()?>
<section class="panel"><div class="panel-head"><div class="panel-title"><span class="accent">♟</span><?=h($game['Adversario'])?></div><?php if(can_manage()):?><button class="btn primary">Guardar convocatória</button><?php endif;?></div><div class="panel-body roster">
<?php if(!$athletes):?><div class="empty"><strong>Sem atletas nesta equipa</strong>Associa atletas à equipa antes de criar a convocatória.</div><?php endif;?>
<?php foreach($athletes as $a):$c=$current[$a['IdAtleta']]??null;?><div class="player-row" data-searchable>
<input type="checkbox" name="selected[]" value="<?=$a['IdAtleta']?>" <?=$c?'checked':''?>>
<div class="person"><span class="mini-avatar"><?=h(initials($a['Nome']))?></span><div><strong><?=h($a['Nome'])?></strong><div style="color:#819bb2">#<?=h($a['NumeroCamisola']?:'—')?> · <?=h($a['Posicao']?:'—')?></div></div></div>
<select name="estado[<?=$a['IdAtleta']?>]"><?php foreach(['Convocado','Nao Convocado','Lesionado','Suspenso','Indisponivel'] as $s):?><option <?=$c&&$c['Estado']===$s?'selected':''?>><?=h($s)?></option><?php endforeach;?></select>
<label class="titular" style="font-size:10px;color:#9eb4c8"><input type="checkbox" name="titular[<?=$a['IdAtleta']?>]" value="1" <?=$c&&!empty($c['Titular'])?'checked':''?>> Titular</label>
</div><?php endforeach;?>
</div></section></form><?php endif;render_footer();?>
