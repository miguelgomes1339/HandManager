<?php
declare(strict_types=1);
require_once __DIR__.'/includes/layout.php';require_login();$pdo=db();$q=trim((string)($_GET['q']??''));$results=[];
if($q!==''){
 $like='%'.$q.'%';
 $st=$pdo->prepare("SELECT IdAtleta id,Nome titulo,CONCAT(COALESCE(Posicao,'—'),' · ',COALESCE(Escalao,'—')) subtitulo,'atleta' tipo FROM atletas WHERE Nome LIKE ? OR CAST(CIPA AS CHAR) LIKE ? LIMIT 12");$st->execute([$like,$like]);$results=array_merge($results,$st->fetchAll());
 $st=$pdo->prepare("SELECT IdEquipa id,NomeEquipa titulo,CONCAT(Genero,' · ',Escalao,' · ',Epoca) subtitulo,'equipa' tipo FROM equipas WHERE NomeEquipa LIKE ? OR COALESCE(Treinador,'') LIKE ? LIMIT 12");$st->execute([$like,$like]);$results=array_merge($results,$st->fetchAll());
 $st=$pdo->prepare("SELECT IdJogo id,Adversario titulo,CONCAT(DATE_FORMAT(DataJogo,'%d/%m/%Y'),' · ',CasaFora,' · ',EstadoJogo) subtitulo,'jogo' tipo FROM jogos WHERE Adversario LIKE ? OR COALESCE(Local,'') LIKE ? LIMIT 12");$st->execute([$like,$like]);$results=array_merge($results,$st->fetchAll());
 $st=$pdo->prepare("SELECT IdCompeticao id,NomeCompeticao titulo,CONCAT(Epoca,' · ',COALESCE(Organizador,'—')) subtitulo,'competicao' tipo FROM competicoes WHERE NomeCompeticao LIKE ? OR COALESCE(Organizador,'') LIKE ? LIMIT 12");$st->execute([$like,$like]);$results=array_merge($results,$st->fetchAll());
}
render_header('Pesquisa global','pesquisa');
?>
<div class="page-head"><div><h1>Pesquisa global</h1><p>Encontra rapidamente atletas, equipas, jogos e competições.</p></div></div>
<section class="panel"><form class="toolbar" method="get"><input name="q" value="<?=h($q)?>" placeholder="Nome, CIPA, equipa, adversário, pavilhão..." autofocus><button class="btn primary">Pesquisar</button></form><div class="panel-body search-results">
<?php if($q==='' ):?><div class="empty"><strong>Pesquisa em todo o HandManager</strong>Escreve um termo para começar.</div><?php elseif(!$results):?><div class="empty"><strong>Sem resultados</strong>Não encontrei registos para “<?=h($q)?>”.</div><?php else:?>
<?php foreach($results as $r):$url=match($r['tipo']){'atleta'=>'atleta.php?id='.$r['id'],'equipa'=>'equipa.php?id='.$r['id'],'jogo'=>'jogo.php?id='.$r['id'],'competicao'=>'competicao.php?id='.$r['id'],default=>'index.php'};?><a class="search-result" href="<?=h($url)?>" data-searchable><span class="search-type"><?=h(mb_strtoupper($r['tipo']))?></span><div><strong><?=h($r['titulo'])?></strong><small><?=h($r['subtitulo'])?></small></div><b>→</b></a><?php endforeach;?>
<?php endif;?></div></section><?php render_footer();?>
