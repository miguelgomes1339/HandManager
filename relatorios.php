<?php
declare(strict_types=1);
require_once __DIR__.'/includes/layout.php';require_login();$pdo=db();
$type=(string)($_GET['export']??'');
if($type!==''){
 $maps=[
  'atletas'=>["SELECT a.CIPA,a.Nome,a.Sexo,a.DataNasc,a.Altura,a.Peso,a.MaoDominante,a.Escalao,a.Posicao,a.NumeroCamisola,a.EstadoDoJogador,e.NomeEquipa FROM atletas a LEFT JOIN equipas e ON e.IdEquipa=a.IdEquipa ORDER BY e.NomeEquipa,a.Nome",'atletas'],
  'equipas'=>["SELECT NomeEquipa,Escalao,Genero,Epoca,Treinador,EstadoEquipa FROM equipas ORDER BY NomeEquipa",'equipas'],
  'jogos'=>["SELECT j.DataJogo,j.HoraJogo,e.NomeEquipa,c.NomeCompeticao,j.Adversario,j.Local,j.Jornada,j.GolosFavor,j.GolosContra,j.EstadoJogo,j.CasaFora FROM jogos j LEFT JOIN equipas e ON e.IdEquipa=j.IdEquipa LEFT JOIN competicoes c ON c.IdCompeticao=j.IdCompeticao ORDER BY j.DataJogo",'jogos'],
  'lesoes'=>["SELECT a.Nome,l.DataInicio,l.TipoLesao,l.Gravidade,l.DataPrevistaRegresso,l.DataRegresso,l.Estado FROM lesoes l JOIN atletas a ON a.IdAtleta=l.IdAtleta ORDER BY l.DataInicio DESC",'lesoes'],
  'presencas'=>["SELECT t.DataTreino,e.NomeEquipa,a.Nome,p.Estado,p.Observacoes FROM presencas_treino p JOIN treinos t ON t.IdTreino=p.IdTreino JOIN equipas e ON e.IdEquipa=t.IdEquipa JOIN atletas a ON a.IdAtleta=p.IdAtleta ORDER BY t.DataTreino DESC,a.Nome",'presencas'],
 ];
 if(isset($maps[$type])){$rows=$pdo->query($maps[$type][0])->fetchAll();$name='handmanager_'.$maps[$type][1].'_'.date('Ymd_His').'.csv';header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="'.$name.'"');echo "\xEF\xBB\xBF";$out=fopen('php://output','w');if($rows){fputcsv($out,array_keys($rows[0]),';');foreach($rows as $r)fputcsv($out,$r,';');}fclose($out);exit;}
}
$summary=['Atletas'=>table_count('atletas'),'Equipas'=>table_count('equipas'),'Jogos'=>table_count('jogos'),'Treinos'=>table_count('treinos'),'Lesões'=>table_count('lesoes')];
render_header('Relatórios','relatorios');?>
<div class="page-head"><div><h1>Relatórios & exportação</h1><p>Extrai informação da base de dados para análise, arquivo ou apresentação.</p></div></div>
<div class="stat-strip"><?php foreach($summary as $k=>$v):?><div class="stat-mini"><span><?=h($k)?></span><strong><?=$v?></strong></div><?php endforeach;?></div>
<div class="cards report-cards"><a class="card" href="?export=atletas"><h3>Plantel completo</h3><p>CIPA, dados físicos, equipa, posição e estado.</p><div class="card-meta"><span class="chip">CSV</span><span class="chip">Exportar ↓</span></div></a><a class="card" href="?export=equipas"><h3>Equipas</h3><p>Escalão, género, época, treinador e estado.</p><div class="card-meta"><span class="chip">CSV</span><span class="chip">Exportar ↓</span></div></a><a class="card" href="?export=jogos"><h3>Jogos & resultados</h3><p>Calendário, competição, resultados e estado.</p><div class="card-meta"><span class="chip">CSV</span><span class="chip">Exportar ↓</span></div></a><a class="card" href="?export=presencas"><h3>Assiduidade</h3><p>Presenças de treino por atleta e equipa.</p><div class="card-meta"><span class="chip">CSV</span><span class="chip">Exportar ↓</span></div></a><a class="card" href="?export=lesoes"><h3>Relatório clínico</h3><p>Lesões, gravidade, previsão e regresso.</p><div class="card-meta"><span class="chip">CSV</span><span class="chip">Exportar ↓</span></div></a></div>
<section class="panel info-banner"><div class="panel-body"><strong>Relatórios seguros</strong><p>Os ficheiros são gerados no momento a partir da base de dados local. O relatório não altera nenhum registo.</p></div></section><?php render_footer();?>
