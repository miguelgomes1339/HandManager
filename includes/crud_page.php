<?php
declare(strict_types=1);
require_once __DIR__ . '/layout.php';
require_login();

$defs = require __DIR__ . '/entities.php';
if (!isset($entityKey,$defs[$entityKey])) exit('Entidade inválida.');
$cfg=$defs[$entityKey]; $pdo=db(); $table=$cfg['table']; $pk=$cfg['pk']; $fields=$cfg['fields'];
if(!table_exists($table)){
    render_header($cfg['title'],$entityKey);
    echo '<div class="hero"><h1>'.h($cfg['title']).'</h1><p>Esta área ainda não existe na base de dados. Executa <b>upgrade_2026_2027.sql</b> no phpMyAdmin.</p></div>';
    render_footer(); exit;
}
// Só mostra campos que existem realmente na BD, permitindo abrir o site antes/depois do upgrade.
$fields=array_filter($fields,fn($f,$name)=>column_exists($table,(string)$name),ARRAY_FILTER_USE_BOTH);
$cfg['fields']=$fields;

function relation_options(PDO $pdo, array $f): array {
    if(!table_exists((string)$f['table'])) return [];
    $sql="SELECT `{$f['pk']}` AS id, `{$f['display']}` AS label FROM `{$f['table']}` ORDER BY `{$f['display']}`";
    return $pdo->query($sql)->fetchAll();
}
function normalize_value(array $f, mixed $raw): mixed {
    if (is_string($raw)) $raw=trim($raw);
    if (($raw==='' || $raw===null) && !empty($f['nullable'])) return null;
    if ($raw==='') return null;
    if (($f['type'] ?? '')==='datetime-local' && is_string($raw)) return str_replace('T',' ',$raw).(strlen($raw)===16?':00':'');
    return $raw;
}
$manageArea=$entityKey==='utilizadores'?'users':'sport';
$editable=can_manage($manageArea);

if ($_SERVER['REQUEST_METHOD']==='POST') {
    if(!$editable){ http_response_code(403); exit('Sem permissões para alterar esta área.'); }
    verify_csrf();
    $action=$_POST['action'] ?? '';
    try {
        if ($action==='delete') {
            $id=(int)($_POST['id']??0);
            if ($id>0) {
                $st=$pdo->prepare("DELETE FROM `$table` WHERE `$pk`=?"); $st->execute([$id]);
                activity_log('Eliminar',$table,$id);
                flash('success','Registo eliminado.');
            }
            redirect(basename($_SERVER['PHP_SELF']));
        }
        if ($action==='save') {
            $id=(int)($_POST['id']??0); $values=[]; $errors=[];
            foreach($fields as $name=>$f){
                $raw=$_POST[$name] ?? '';
                if (($f['type']??'')==='password') {
                    if ($id>0 && $raw==='') continue;
                    if ($raw==='' && $id===0) { $errors[]='A password é obrigatória.'; continue; }
                    if(strlen((string)$raw)<8) { $errors[]='A password deve ter pelo menos 8 caracteres.'; continue; }
                    $values[$name]=password_hash((string)$raw,PASSWORD_DEFAULT); continue;
                }
                $v=normalize_value($f,$raw);
                if (!empty($f['required']) && ($v===null || $v==='')) $errors[]=$f['label'].' é obrigatório.';
                $values[$name]=$v;
            }
            if ($errors) throw new RuntimeException(implode(' ', $errors));
            if ($id>0) {
                $set=implode(',',array_map(fn($k)=>"`$k`=:$k",array_keys($values))); $values['_id']=$id;
                $st=$pdo->prepare("UPDATE `$table` SET $set WHERE `$pk`=:_id"); $st->execute($values);
                activity_log('Editar',$table,$id); flash('success','Registo atualizado com sucesso.');
            } else {
                $cols=implode(',',array_map(fn($k)=>"`$k`",array_keys($values))); $pars=implode(',',array_map(fn($k)=>":$k",array_keys($values)));
                $st=$pdo->prepare("INSERT INTO `$table` ($cols) VALUES ($pars)"); $st->execute($values);
                $newId=(int)$pdo->lastInsertId(); activity_log('Criar',$table,$newId); flash('success','Registo adicionado com sucesso.');
            }
            redirect(basename($_SERVER['PHP_SELF']));
        }
    } catch(Throwable $e) {
        flash('error',$e->getMessage());
        redirect(basename($_SERVER['PHP_SELF']).(!empty($_POST['id'])?'?edit='.(int)$_POST['id']:'?new=1'));
    }
}

$edit=null;
if ($editable && !empty($_GET['edit'])) {
    $st=$pdo->prepare("SELECT * FROM `$table` WHERE `$pk`=?"); $st->execute([(int)$_GET['edit']]); $edit=$st->fetch() ?: null;
}
$showForm=$editable && (isset($_GET['new']) || $edit);
$relationMaps=[];
foreach($fields as $name=>$f) if(($f['type']??'')==='relation') $relationMaps[$name]=relation_options($pdo,$f);

$q=trim((string)($_GET['q']??'')); $filterFields=$cfg['filters'] ?? []; $activeFilters=[]; $whereParts=[]; $params=[];
$searchable=array_keys(array_filter($fields,fn($f)=>!empty($f['search'])));
if ($q!=='' && $searchable) {
    $parts=[]; foreach($searchable as $i=>$col){$parts[]="`$col` LIKE :q$i";$params["q$i"]="%$q%";} $whereParts[]='('.implode(' OR ',$parts).')';
}
foreach($filterFields as $name){
    if(!isset($fields[$name])) continue; $f=$fields[$name]; $raw=$_GET['filter'][$name] ?? ''; $value=is_string($raw)?trim($raw):''; if($value==='') continue;
    $activeFilters[$name]=$value; $param='f_'.$name;
    if($name==='IdEquipa' && $value==='__none__') $whereParts[]="`$name` IS NULL";
    elseif(($f['type']??'')==='text'){$whereParts[]="`$name` LIKE :$param";$params[$param]='%'.$value.'%';}
    else {$whereParts[]="`$name` = :$param";$params[$param]=$value;}
}
$where=$whereParts?'WHERE '.implode(' AND ',$whereParts):'';
$page=max(1,(int)($_GET['page']??1)); $perPage=50; $offset=($page-1)*$perPage;
$st=$pdo->prepare("SELECT COUNT(*) FROM `$table` $where");$st->execute($params);$total=(int)$st->fetchColumn();$pages=max(1,(int)ceil($total/$perPage));
$sql="SELECT * FROM `$table` $where ORDER BY `$pk` DESC LIMIT $perPage OFFSET $offset";$st=$pdo->prepare($sql);$st->execute($params);$rows=$st->fetchAll();
$hasFilters=($q!=='' || !empty($activeFilters));

$teamTabs=[];$unassignedCount=0;
if($entityKey==='atletas' && isset($fields['IdEquipa'],$relationMaps['IdEquipa'])){
    $countRows=$pdo->query("SELECT IdEquipa, COUNT(*) total FROM atletas GROUP BY IdEquipa")->fetchAll();$counts=[];
    foreach($countRows as $cr){if($cr['IdEquipa']===null)$unassignedCount=(int)$cr['total'];else$counts[(string)$cr['IdEquipa']]=(int)$cr['total'];}
    foreach($relationMaps['IdEquipa'] as $team){$id=(string)$team['id'];$teamTabs[]=['id'=>$id,'label'=>(string)$team['label'],'count'=>$counts[$id]??0];}
}

render_header($cfg['title'],$entityKey);
?>
<div class="page-head">
 <div><h1><?=h($cfg['title'])?></h1><p><?=h($cfg['subtitle'])?></p></div>
 <div class="actions">
  <?php if($entityKey==='jogos'): ?><a class="btn" href="calendario.php">Calendário</a><a class="btn" href="eventos.php">Eventos</a><?php endif; ?>
  <?php if($entityKey==='treinos'): ?><a class="btn" href="presencas.php">Marcar presenças</a><?php endif; ?>
  <?php if($entityKey==='atletas'): ?><a class="btn" href="inscricoes.php">Gerir inscrições</a><?php endif; ?>
  <?php if($editable): ?><a class="btn primary" href="<?=h(basename($_SERVER['PHP_SELF']))?>?new=1#form">+ Adicionar</a><?php endif; ?>
 </div>
</div>

<?php if($entityKey==='atletas' && $teamTabs): $selectedTeam=(string)($activeFilters['IdEquipa']??'');$baseQuery=$_GET;unset($baseQuery['edit'],$baseQuery['new'],$baseQuery['page']);$allQuery=$baseQuery;if(isset($allQuery['filter']['IdEquipa']))unset($allQuery['filter']['IdEquipa']);if(isset($allQuery['filter'])&&!$allQuery['filter'])unset($allQuery['filter']);$allUrl=basename($_SERVER['PHP_SELF']).($allQuery?'?'.http_build_query($allQuery):'');?>
<nav class="team-tabs-shell" aria-label="Equipas"><div class="team-tabs">
<a class="team-tab <?=$selectedTeam===''?'active':''?>" href="<?=h($allUrl)?>"><span class="team-tab-name">Todas as equipas</span><span class="team-tab-count"><?=array_sum(array_column($teamTabs,'count'))+$unassignedCount?></span></a>
<?php foreach($teamTabs as $team):$tabQuery=$baseQuery;$tabQuery['filter']['IdEquipa']=$team['id'];$url=basename($_SERVER['PHP_SELF']).'?'.http_build_query($tabQuery);?><a class="team-tab <?=$selectedTeam===(string)$team['id']?'active':''?>" href="<?=h($url)?>"><span class="team-tab-name"><?=h($team['label'])?></span><span class="team-tab-count"><?=$team['count']?></span></a><?php endforeach;?>
<?php if($unassignedCount):$tabQuery=$baseQuery;$tabQuery['filter']['IdEquipa']='__none__';?><a class="team-tab <?=$selectedTeam==='__none__'?'active':''?>" href="?<?=h(http_build_query($tabQuery))?>"><span class="team-tab-name">Sem equipa</span><span class="team-tab-count"><?=$unassignedCount?></span></a><?php endif;?>
</div></nav><?php endif;?>

<?php if($showForm): ?>
<section class="panel form-card" id="form"><div class="panel-head"><div class="panel-title"><span class="accent">＋</span><?=$edit?'Editar':'Novo'?> <?=h($cfg['singular'] ?? 'registo')?></div></div><div class="panel-body">
<form method="post"><?=csrf_field()?><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?=h($edit[$pk]??'')?>"><div class="form-grid">
<?php foreach($fields as $name=>$f):$val=$edit[$name]??($f['default']??'');$type=$f['type'];$cls=!empty($f['full'])?'field full':'field';?>
<div class="<?=$cls?>"><label for="<?=h($name)?>"><?=h($f['label'])?><?=!empty($f['required'])?' *':''?></label>
<?php if($type==='select'):?><select name="<?=h($name)?>" id="<?=h($name)?>" <?=!empty($f['required'])?'required':''?>><option value="">Selecionar...</option><?php foreach($f['options'] as $op):?><option value="<?=h($op)?>" <?=((string)$val===(string)$op)?'selected':''?>><?=h($op)?></option><?php endforeach;?></select>
<?php elseif($type==='relation'):?><select name="<?=h($name)?>" id="<?=h($name)?>" <?=!empty($f['required'])?'required':''?>><?php if(!empty($f['nullable'])):?><option value="">— Sem associação —</option><?php else:?><option value="">Selecionar...</option><?php endif;?><?php foreach($relationMaps[$name] as $op):?><option value="<?=h($op['id'])?>" <?=((string)$val===(string)$op['id'])?'selected':''?>><?=h($op['label'])?></option><?php endforeach;?></select>
<?php elseif($type==='textarea'):?><textarea name="<?=h($name)?>" id="<?=h($name)?>"><?=h($val)?></textarea>
<?php else:?><input name="<?=h($name)?>" id="<?=h($name)?>" type="<?=h($type)?>" value="<?=$type==='password'?'':h($val)?>" <?=isset($f['step'])?'step="'.h($f['step']).'"':''?> <?=isset($f['min'])?'min="'.h($f['min']).'"':''?> <?=isset($f['max'])?'max="'.h($f['max']).'"':''?> <?=(!empty($f['required'])&&!($type==='password'&&$edit))?'required':''?>><?php if($type==='password'&&$edit):?><div class="helper">Deixa vazio para manter a password atual.</div><?php endif;?>
<?php endif;?></div><?php endforeach;?></div><div class="form-actions"><a class="btn" href="<?=h(basename($_SERVER['PHP_SELF']))?>">Cancelar</a><button class="btn primary">Guardar</button></div></form>
</div></section><?php endif;?>

<section class="panel athlete-list-panel">
<form class="filter-toolbar" method="get"><div class="filter-topbar"><div class="filter-title-wrap"><div class="filter-icon">⌕</div><div><strong>Encontrar <?=h(mb_strtolower($cfg['title']))?></strong><span>Pesquisa e combina filtros para reduzir os resultados.</span></div></div><div class="filter-meta"><?php if($activeFilters):?><span class="filter-count"><?=count($activeFilters)?> filtro(s) ativo(s)</span><?php endif;?><span class="result-pill"><b><?=$total?></b> resultado(s)</span></div></div>
<div class="filter-search-row"><div class="modern-search"><span>⌕</span><input name="q" value="<?=h($q)?>" placeholder="Pesquisar..." autocomplete="off"></div><button class="btn primary filter-submit">Pesquisar</button><?php if($hasFilters):?><a class="btn filter-reset" href="<?=h(basename($_SERVER['PHP_SELF']))?>">Limpar</a><?php endif;?></div>
<?php if($filterFields):?><div class="filter-section"><div class="filter-grid"><?php foreach($filterFields as $name):if(!isset($fields[$name])||($entityKey==='atletas'&&$name==='IdEquipa'))continue;$f=$fields[$name];$value=$activeFilters[$name]??'';$type=$f['type']??'text';?><div class="filter-field <?=$value!==''?'is-active':''?>"><label><?=h($f['label'])?></label><div class="filter-control">
<?php if($type==='select'):?><select name="filter[<?=h($name)?>]"><option value="">Todos</option><?php foreach($f['options'] as $op):?><option value="<?=h($op)?>" <?=((string)$value===(string)$op)?'selected':''?>><?=h($op)?></option><?php endforeach;?></select>
<?php elseif($type==='relation'):?><select name="filter[<?=h($name)?>]"><option value="">Todos</option><?php foreach($relationMaps[$name] as $op):?><option value="<?=h($op['id'])?>" <?=((string)$value===(string)$op['id'])?'selected':''?>><?=h($op['label'])?></option><?php endforeach;?></select>
<?php else:?><input name="filter[<?=h($name)?>]" type="<?=h(in_array($type,['date','number'],true)?$type:'text')?>" value="<?=h($value)?>" placeholder="Qualquer"><?php endif;?></div></div><?php endforeach;?></div><div class="filter-footer"><div class="filter-hint">Página <?=$page?> de <?=$pages?> · <?=$perPage?> registos por página</div><div class="actions"><?php if($hasFilters):?><a class="btn" href="<?=h(basename($_SERVER['PHP_SELF']))?>">Repor tudo</a><?php endif;?><button class="btn primary">Aplicar filtros</button></div></div></div><?php endif;?>
</form>
<div class="panel-body table-wrap">
<?php if(!$rows):?><div class="empty"><strong>Sem registos</strong>Não existem resultados para os critérios selecionados.</div><?php else:?><table><thead><tr><?php foreach($fields as $name=>$f):if(!empty($f['secret'])||!empty($f['full']))continue;?><th><?=h($f['label'])?></th><?php endforeach;?><th>Ações</th></tr></thead><tbody>
<?php foreach($rows as $row):?><tr data-searchable><?php foreach($fields as $name=>$f):if(!empty($f['secret'])||!empty($f['full']))continue;$v=$row[$name]??null;?><td><?php if(($f['type']??'')==='relation'){$label='—';foreach($relationMaps[$name]??[] as $op){if((string)$op['id']===(string)$v){$label=$op['label'];break;}}echo h($label);}elseif(($f['type']??'')==='date')echo h(db_date($v));elseif(in_array($name,['Estado','EstadoJogo','EstadoDoJogador','EstadoEquipa'],true))echo '<span class="status '.h(status_class((string)$v)).'">'.h($v??'—').'</span>';elseif($name==='Fonte')echo source_badge((string)$v);elseif(($f['type']??'')==='url'&&$v)echo '<a class="text-link" href="'.h($v).'" target="_blank" rel="noopener">Abrir ↗</a>';else echo h($v??'—');?></td><?php endforeach;?><td><div class="actions">
<?php if(!empty($cfg['detail'])):$detail=str_replace('{id}',(string)$row[$pk],$cfg['detail']);?><a class="btn small" href="<?=h($detail)?>">Ver</a><?php endif;?>
<?php if($editable):?><a class="btn small" href="?edit=<?=h($row[$pk])?>#form">Editar</a><form method="post" class="confirm-delete"><?=csrf_field()?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=h($row[$pk])?>"><button class="btn small danger">Eliminar</button></form><?php endif;?>
</div></td></tr><?php endforeach;?></tbody></table><?php endif;?>
</div>
<?php if($pages>1):?><div class="pagination"><?php $qs=$_GET;for($p=max(1,$page-2);$p<=min($pages,$page+2);$p++):$qs['page']=$p;?><a class="<?=$p===$page?'active':''?>" href="?<?=h(http_build_query($qs))?>"><?=$p?></a><?php endfor;?></div><?php endif;?>
</section>
<?php render_footer(); ?>
