<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/layout.php';
require_login();

$pdo = db();
$id = (int)($_GET['id'] ?? $_POST['IdAtleta'] ?? 0);

/*
|--------------------------------------------------------------------------
| GUARDAR ESTATÍSTICAS DO ATLETA NUM JOGO
|--------------------------------------------------------------------------
| A ficha do atleta passa a permitir introduzir um resumo estatístico.
| Os eventos desse atleta nesse jogo são substituídos pelos valores do
| formulário, para evitar duplicações. A eficácia é sempre calculada pelo
| sistema e nunca é escrita manualmente.
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_match_stats') {
    if (!can_manage()) {
        http_response_code(403);
        exit('Sem permissões.');
    }

    verify_csrf();

    $jogoId = (int)($_POST['IdJogo'] ?? 0);

    $campos = [
        'Golos' => 'Golo',
        'RematesFalhados' => 'Remate Falhado',
        'RematesDefendidos' => 'Remate Defendido',
        'RematesPoste' => 'Remate ao Poste',
        'Golos7m' => 'Golo 7m',
        'Falhados7m' => '7m Falhado',
        'Defendidos7m' => '7m Defendido',
        'Assistencias' => 'Assistencia',
        'Recuperacoes' => 'Recuperacao',
        'FalhasTecnicas' => 'Perda de Bola',
        'Faltas' => 'Falta',
        'Exclusoes2Min' => 'Exclusao 2 Min',
        'CartoesAmarelos' => 'Cartao Amarelo',
        'CartoesVermelhos' => 'Cartao Vermelho',
        'Defesas' => 'Defesa',
        'Defesas7m' => 'Defesa 7m',
        'GolosSofridos' => 'Golo Sofrido',
    ];

    try {
        if ($id <= 0 || $jogoId <= 0) {
            throw new RuntimeException('Seleciona um atleta e um jogo válidos.');
        }

        $stmt = $pdo->prepare("SELECT IdAtleta, IdEquipa FROM atletas WHERE IdAtleta = ?");
        $stmt->execute([$id]);
        $atletaCheck = $stmt->fetch();
        if (!$atletaCheck) {
            throw new RuntimeException('Atleta não encontrado.');
        }

        $stmt = $pdo->prepare("SELECT IdJogo, IdEquipa FROM jogos WHERE IdJogo = ?");
        $stmt->execute([$jogoId]);
        $jogoCheck = $stmt->fetch();
        if (!$jogoCheck) {
            throw new RuntimeException('Jogo não encontrado.');
        }

        // Impede gravar estatísticas de um atleta num jogo de outra equipa.
        if (!empty($atletaCheck['IdEquipa']) && !empty($jogoCheck['IdEquipa']) &&
            (int)$atletaCheck['IdEquipa'] !== (int)$jogoCheck['IdEquipa']) {
            throw new RuntimeException('Este jogo não pertence à equipa atual do atleta.');
        }

        $valores = [];
        foreach ($campos as $campo => $tipoEvento) {
            $valor = (int)($_POST[$campo] ?? 0);
            if ($valor < 0 || $valor > 200) {
                throw new RuntimeException('Os valores devem estar entre 0 e 200.');
            }
            $valores[$campo] = $valor;
        }

        $pdo->beginTransaction();

        // Estes são exatamente os eventos controlados pelo formulário de resumo.
        $tipos = array_values($campos);
        $tipos[] = 'Remate'; // compatibilidade com registos antigos
        $placeholders = implode(',', array_fill(0, count($tipos), '?'));
        $params = array_merge([$jogoId, $id], $tipos);

        $stmt = $pdo->prepare(
            "DELETE FROM eventos_jogo
             WHERE IdJogo = ? AND IdAtleta = ?
             AND TipoEvento IN ($placeholders)"
        );
        $stmt->execute($params);

        $insert = $pdo->prepare(
            "INSERT INTO eventos_jogo
                (IdJogo, IdAtleta, TipoEvento, Minuto, Segundo, Observacao)
             VALUES (?, ?, ?, NULL, NULL, ?)"
        );

        foreach ($campos as $campo => $tipoEvento) {
            for ($i = 0; $i < $valores[$campo]; $i++) {
                $insert->execute([$jogoId, $id, $tipoEvento, 'Resumo estatístico da ficha do atleta']);
            }
        }

        $pdo->commit();

        $remates = $valores['Golos'] + $valores['RematesFalhados'] +
                   $valores['RematesDefendidos'] + $valores['RematesPoste'] +
                   $valores['Golos7m'] + $valores['Falhados7m'] + $valores['Defendidos7m'];
        $golos = $valores['Golos'] + $valores['Golos7m'];
        $eficacia = pct($golos, $remates, 1);

        activity_log(
            'Atualizar estatísticas do atleta',
            'eventos_jogo',
            $id,
            'Jogo ' . $jogoId . ' · ' . $golos . '/' . $remates . ' · ' . $eficacia . '%'
        );

        flash('success', 'Estatísticas guardadas. Eficácia de remate: ' . number_format($eficacia, 1, ',', '.') . '%.');
        redirect('atleta.php?id=' . $id . '&stats_game=' . $jogoId . '#registar-estatisticas');

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('error', $e->getMessage());
        redirect('atleta.php?id=' . $id . ($jogoId > 0 ? '&stats_game=' . $jogoId : '') . '#registar-estatisticas');
    }
}

/* Ficha do atleta */
$stmt = $pdo->prepare(
    "SELECT a.*, e.NomeEquipa, e.Genero, e.Epoca
     FROM atletas a
     LEFT JOIN equipas e ON e.IdEquipa = a.IdEquipa
     WHERE a.IdAtleta = ?"
);
$stmt->execute([$id]);
$a = $stmt->fetch();
if (!$a) {
    http_response_code(404);
    exit('Atleta não encontrado.');
}

/* Estatísticas globais */
$stats = [
    'JogosComRegisto' => 0,
    'Golos' => 0,
    'Remates' => 0,
    'RematesFalhados' => 0,
    'RematesDefendidos' => 0,
    'RematesPoste' => 0,
    'EficaciaRemate' => 0,
    'Assistencias' => 0,
    'Recuperacoes' => 0,
    'PerdasBola' => 0,
    'Faltas' => 0,
    'Exclusoes2Min' => 0,
    'Defesas' => 0,
    'GolosSofridos' => 0,
    'EficaciaGuardaRedes' => 0,
];

if (table_exists('eventos_jogo')) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM vw_eficiencia_atletas WHERE IdAtleta = ?");
        $stmt->execute([$id]);
        $stats = array_merge($stats, $stmt->fetch() ?: []);
    } catch (Throwable) {
    }
}

/* Lesões */
$inj = [];
if (table_exists('lesoes')) {
    $stmt = $pdo->prepare("SELECT * FROM lesoes WHERE IdAtleta = ? ORDER BY DataInicio DESC LIMIT 8");
    $stmt->execute([$id]);
    $inj = $stmt->fetchAll();
}

/* Inscrições */
$ins = [];
if (table_exists('inscricoes_atleta')) {
    $stmt = $pdo->prepare(
        "SELECT ia.*, e.NomeEquipa, es.Nome EscalaoNome
         FROM inscricoes_atleta ia
         JOIN equipas e ON e.IdEquipa = ia.IdEquipa
         JOIN escaloes es ON es.IdEscalao = ia.IdEscalao
         WHERE ia.IdAtleta = ?
         ORDER BY ia.Epoca DESC, ia.IdInscricao DESC"
    );
    $stmt->execute([$id]);
    $ins = $stmt->fetchAll();
}

/* Assiduidade */
$att = ['total' => 0, 'presente' => 0, 'faltas' => 0];
if (table_exists('presencas_treino')) {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) total,
                SUM(Estado='Presente') presente,
                SUM(Estado IN ('Falta','Falta Justificada')) faltas
         FROM presencas_treino
         WHERE IdAtleta = ?"
    );
    $stmt->execute([$id]);
    $att = array_merge($att, $stmt->fetch() ?: []);
}

/* Jogos com eventos do atleta */
$games = [];
if (table_exists('eventos_jogo')) {
    $stmt = $pdo->prepare(
        "SELECT j.IdJogo, j.DataJogo, j.Adversario, j.GolosFavor, j.GolosContra, j.EstadoJogo,
                COUNT(ev.IdEvento) eventos,
                SUM(ev.TipoEvento IN ('Golo','Golo 7m')) golos,
                SUM(ev.TipoEvento IN ('Golo','Remate','Remate Falhado','Remate Defendido','Remate ao Poste','Golo 7m','7m Falhado','7m Defendido')) remates,
                SUM(ev.TipoEvento='Perda de Bola') falhas_tecnicas,
                ROUND(
                    SUM(ev.TipoEvento IN ('Golo','Golo 7m')) /
                    NULLIF(SUM(ev.TipoEvento IN ('Golo','Remate','Remate Falhado','Remate Defendido','Remate ao Poste','Golo 7m','7m Falhado','7m Defendido')),0) * 100,
                    1
                ) eficacia
         FROM eventos_jogo ev
         JOIN jogos j ON j.IdJogo = ev.IdJogo
         WHERE ev.IdAtleta = ?
         GROUP BY j.IdJogo
         ORDER BY j.DataJogo DESC
         LIMIT 8"
    );
    $stmt->execute([$id]);
    $games = $stmt->fetchAll();
}

/* Jogos disponíveis para introduzir/editar estatísticas */
$availableGames = [];
if (!empty($a['IdEquipa'])) {
    $stmt = $pdo->prepare(
        "SELECT IdJogo, DataJogo, Adversario, EstadoJogo, GolosFavor, GolosContra
         FROM jogos
         WHERE IdEquipa = ?
         ORDER BY DataJogo DESC, IdJogo DESC
         LIMIT 40"
    );
    $stmt->execute([(int)$a['IdEquipa']]);
    $availableGames = $stmt->fetchAll();
}

$selectedGameId = (int)($_GET['stats_game'] ?? 0);
if ($selectedGameId <= 0 && $availableGames) {
    $selectedGameId = (int)$availableGames[0]['IdJogo'];
}

$selectedGame = null;
foreach ($availableGames as $g) {
    if ((int)$g['IdJogo'] === $selectedGameId) {
        $selectedGame = $g;
        break;
    }
}

/* Valores atuais do jogo selecionado */
$gameStats = [
    'Golos' => 0,
    'RematesFalhados' => 0,
    'RematesDefendidos' => 0,
    'RematesPoste' => 0,
    'Golos7m' => 0,
    'Falhados7m' => 0,
    'Defendidos7m' => 0,
    'Assistencias' => 0,
    'Recuperacoes' => 0,
    'FalhasTecnicas' => 0,
    'Faltas' => 0,
    'Exclusoes2Min' => 0,
    'CartoesAmarelos' => 0,
    'CartoesVermelhos' => 0,
    'Defesas' => 0,
    'Defesas7m' => 0,
    'GolosSofridos' => 0,
];

if ($selectedGame) {
    $stmt = $pdo->prepare(
        "SELECT
            SUM(TipoEvento='Golo') Golos,
            SUM(TipoEvento='Remate Falhado') RematesFalhados,
            SUM(TipoEvento='Remate Defendido') RematesDefendidos,
            SUM(TipoEvento='Remate ao Poste') RematesPoste,
            SUM(TipoEvento='Golo 7m') Golos7m,
            SUM(TipoEvento='7m Falhado') Falhados7m,
            SUM(TipoEvento='7m Defendido') Defendidos7m,
            SUM(TipoEvento='Assistencia') Assistencias,
            SUM(TipoEvento='Recuperacao') Recuperacoes,
            SUM(TipoEvento='Perda de Bola') FalhasTecnicas,
            SUM(TipoEvento='Falta') Faltas,
            SUM(TipoEvento='Exclusao 2 Min') Exclusoes2Min,
            SUM(TipoEvento='Cartao Amarelo') CartoesAmarelos,
            SUM(TipoEvento='Cartao Vermelho') CartoesVermelhos,
            SUM(TipoEvento='Defesa') Defesas,
            SUM(TipoEvento='Defesa 7m') Defesas7m,
            SUM(TipoEvento='Golo Sofrido') GolosSofridos
         FROM eventos_jogo
         WHERE IdJogo = ? AND IdAtleta = ?"
    );
    $stmt->execute([$selectedGameId, $id]);
    $loaded = $stmt->fetch();
    if ($loaded) {
        foreach ($gameStats as $key => $zero) {
            $gameStats[$key] = (int)($loaded[$key] ?? 0);
        }
    }
}

$currentGameShots = $gameStats['Golos'] + $gameStats['RematesFalhados'] +
                    $gameStats['RematesDefendidos'] + $gameStats['RematesPoste'] +
                    $gameStats['Golos7m'] + $gameStats['Falhados7m'] + $gameStats['Defendidos7m'];
$currentGameGoals = $gameStats['Golos'] + $gameStats['Golos7m'];
$currentGameEfficiency = pct($currentGameGoals, $currentGameShots, 1);

/* Origem dos dados e identificação de informação de demonstração */
$demoStatsCount = 0;
$demoAttendanceCount = 0;

if (table_exists('eventos_jogo')) {
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM eventos_jogo WHERE IdAtleta = ? AND Observacao LIKE '[PAP_DEMO_V2]%'");
        $stmt->execute([$id]);
        $demoStatsCount = (int)$stmt->fetchColumn();
    } catch (Throwable) {
    }
}

if (table_exists('presencas_treino') && table_exists('treinos')) {
    try {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*)
             FROM presencas_treino p
             JOIN treinos t ON t.IdTreino = p.IdTreino
             WHERE p.IdAtleta = ? AND t.Descricao LIKE '[PAP_DEMO_V2]%'"
        );
        $stmt->execute([$id]);
        $demoAttendanceCount = (int)$stmt->fetchColumn();
    } catch (Throwable) {
    }
}

$positionLabels = [
    'GR' => 'Guarda-redes',
    'P' => 'Ponta',
    'PE' => 'Ponta esquerda',
    'PD' => 'Ponta direita',
    'L' => 'Lateral',
    'LE' => 'Lateral esquerdo',
    'LD' => 'Lateral direito',
    'C'  => 'Central',
    'PV' => 'Pivot',
];

$positionFull = $positionLabels[$a['Posicao'] ?? ''] ?? 'Não definida';
$origin = $a['OrigemPerfil'] ?? 'Simulado';
$originClass = $origin === 'Verificado' ? 'data-verified' : ($origin === 'Misto' ? 'data-mixed' : 'data-simulated');
$internalId = 'HM-' . str_pad((string)$id, 4, '0', STR_PAD_LEFT);
$displayCipa = !empty($a['CIPA']) ? (string)$a['CIPA'] : $internalId;
$cipaSub = !empty($a['CIPA']) ? 'Identificador CIPA' : 'ID interno do HandManager';
$age = age_from_date($a['DataNasc'] ?? null);
$attendance = pct((int)$att['presente'], (int)$att['total']);
$gamesCount = max(1, (int)($stats['JogosComRegisto'] ?? 0));
$avgGoals = ((int)($stats['JogosComRegisto'] ?? 0) > 0) ? round((float)$stats['Golos'] / (int)$stats['JogosComRegisto'], 1) : 0;
$avgAssists = ((int)($stats['JogosComRegisto'] ?? 0) > 0) ? round((float)$stats['Assistencias'] / (int)$stats['JogosComRegisto'], 1) : 0;

render_header($a['Nome'], 'atletas');
?>

<style>
.stats-editor-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}
.stats-editor-grid .field{min-width:0}
.stats-summary{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin:14px 0}
.stats-summary>div{padding:13px;border:1px solid var(--line);border-radius:12px;background:#0b2137}
.stats-summary span{display:block;color:var(--muted);font-size:10px;margin-bottom:4px}
.stats-summary strong{font-size:22px}
.stats-help{margin-top:12px;padding:11px 12px;border-radius:11px;border:1px solid rgba(77,143,221,.25);background:rgba(77,143,221,.08);color:#bcd8f5;font-size:10px;line-height:1.55}
.stats-section-gap{margin-top:18px}
.data-origin{display:inline-flex;align-items:center;gap:6px;padding:5px 9px;border-radius:999px;font-size:9px;font-weight:800;letter-spacing:.03em;border:1px solid var(--line)}
.data-verified{background:rgba(55,190,120,.12);border-color:rgba(55,190,120,.28);color:#9be2bb}
.data-mixed{background:rgba(245,182,66,.10);border-color:rgba(245,182,66,.28);color:#f5d28a}
.data-simulated{background:rgba(77,143,221,.10);border-color:rgba(77,143,221,.28);color:#a9cdf4}
.profile-facts{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}
.profile-fact{padding:12px;border:1px solid var(--line);border-radius:12px;background:#0b2137;min-width:0}
.profile-fact span{display:block;color:var(--muted);font-size:9px;margin-bottom:5px}
.profile-fact strong{display:block;font-size:13px;white-space:normal;overflow-wrap:anywhere}
.data-note{margin-top:12px;padding:11px 12px;border:1px solid rgba(245,182,66,.22);background:rgba(245,182,66,.06);border-radius:11px;color:#d7e4f3;font-size:10px;line-height:1.55}
.demo-note{font-size:9px;color:#f5d28a}
@media(max-width:1000px){.stats-editor-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.profile-facts{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:620px){.stats-editor-grid,.stats-summary,.profile-facts{grid-template-columns:1fr}}
</style>

<div class="profile-hero">
    <div class="profile-photo">
        <?php if (!empty($a['Foto'])): ?>
            <img src="<?= h($a['Foto']) ?>" alt="<?= h($a['Nome']) ?>">
        <?php else: ?>
            <?= h(initials($a['Nome'])) ?>
        <?php endif; ?>
    </div>

    <div class="profile-main">
        <div class="eyebrow">Ficha de atleta</div>
        <h1><?= h($a['Nome']) ?></h1>
        <div class="profile-tags">
            <span class="status <?= h(status_class($a['EstadoDoJogador'])) ?>"><?= h($a['EstadoDoJogador']) ?></span>
            <?php if ($a['Posicao']): ?><span class="chip"><?= h($a['Posicao']) ?></span><?php endif; ?>
            <?php if ($a['NumeroCamisola'] !== null): ?><span class="chip">#<?= h($a['NumeroCamisola']) ?></span><?php endif; ?>
            <?php if ($a['NomeEquipa']): ?><a class="chip" href="equipa.php?id=<?= h($a['IdEquipa']) ?>"><?= h($a['NomeEquipa']) ?></a><?php endif; ?>
            <span class="data-origin <?= h($originClass) ?>">Dados: <?= h($origin) ?></span>
        </div>
    </div>

    <div class="actions">
        <a class="btn" href="atletas.php">← Atletas</a>
        <?php if (can_manage()): ?>
            <a class="btn" href="#registar-estatisticas">Registar estatísticas</a>
            <a class="btn primary" href="atletas.php?edit=<?= $id ?>#form">Editar ficha</a>
        <?php endif; ?>
    </div>
</div>

<div class="kpis profile-kpis">
    <div class="kpi"><div><div class="kpi-label">Idade</div><div class="kpi-value"><?= h($age ?? '—') ?></div><div class="kpi-sub"><?= h(db_date($a['DataNasc'] ?? null)) ?></div></div></div>
    <div class="kpi"><div><div class="kpi-label">CIPA / ID</div><div class="kpi-value small-value"><?= h($displayCipa) ?></div><div class="kpi-sub"><?= h($cipaSub) ?></div></div></div>
    <div class="kpi"><div><div class="kpi-label">Dados físicos</div><div class="kpi-value small-value"><?= h($a['Altura'] ?? '—') ?> m</div><div class="kpi-sub"><?= h($a['Peso'] ?? '—') ?> kg · <?= h($a['MaoDominante'] ?? '—') ?></div></div></div>
    <div class="kpi"><div><div class="kpi-label">Assiduidade</div><div class="kpi-value"><?= $attendance ?>%</div><div class="kpi-sub"><?= (int)$att['presente'] ?> / <?= (int)$att['total'] ?> presenças registadas<?= $demoAttendanceCount > 0 ? ' · demo' : '' ?></div></div></div>
</div>

<section class="panel stats-section-gap">
    <div class="panel-head">
        <div class="panel-title"><span class="accent">◈</span>Perfil técnico</div>
        <span class="data-origin <?= h($originClass) ?>"><?= h($origin) ?></span>
    </div>
    <div class="panel-body">
        <div class="profile-facts">
            <div class="profile-fact"><span>Nacionalidade</span><strong><?= h($a['Nacionalidade'] ?? '—') ?></strong></div>
            <div class="profile-fact"><span>Posição</span><strong><?= h($positionFull) ?><?= !empty($a['Posicao']) ? ' (' . h($a['Posicao']) . ')' : '' ?></strong></div>
            <div class="profile-fact"><span>Mão dominante</span><strong><?= h($a['MaoDominante'] ?? '—') ?></strong></div>
            <div class="profile-fact"><span>N.º de camisola</span><strong>#<?= h($a['NumeroCamisola'] ?? '—') ?></strong></div>
            <div class="profile-fact"><span>Data de nascimento</span><strong><?= h(db_date($a['DataNasc'] ?? null)) ?></strong></div>
            <div class="profile-fact"><span>Altura</span><strong><?= h($a['Altura'] ?? '—') ?> m</strong></div>
            <div class="profile-fact"><span>Peso</span><strong><?= h($a['Peso'] ?? '—') ?> kg</strong></div>
            <div class="profile-fact"><span>Atualização</span><strong><?= h(db_date($a['PerfilAtualizadoEm'] ?? null)) ?></strong></div>
        </div>
        <?php if ($origin !== 'Verificado'): ?>
            <div class="data-note">
                <strong>Origem dos dados:</strong> <?= h($a['FontePerfil'] ?? 'Dados de demonstração.') ?>
                Os campos identificados como simulados servem para demonstração da PAP e não devem ser apresentados como informação oficial do atleta.
            </div>
        <?php elseif (!empty($a['FontePerfil'])): ?>
            <div class="stats-help"><strong>Fonte:</strong> <?= h($a['FontePerfil']) ?></div>
        <?php endif; ?>
    </div>
</section>

<div class="grid2 profile-grid stats-section-gap">
    <section class="panel">
        <div class="panel-head">
            <div class="panel-title"><span class="accent">▥</span>Performance <?php if ($demoStatsCount > 0): ?><span class="demo-note">· inclui demonstração</span><?php endif; ?></div>
            <a class="panel-link" href="estatisticas.php">Ver rankings →</a>
        </div>
        <div class="panel-body">
            <div class="stat-strip compact">
                <div class="stat-mini"><span>Jogos</span><strong><?= h($stats['JogosComRegisto']) ?></strong></div>
                <div class="stat-mini"><span>Golos</span><strong><?= h($stats['Golos']) ?></strong></div>
                <div class="stat-mini"><span>Assistências</span><strong><?= h($stats['Assistencias']) ?></strong></div>
                <div class="stat-mini"><span>Eficácia</span><strong><?= h($stats['EficaciaRemate'] ?? 0) ?>%</strong></div>
            </div>
            <div class="metric-list">
                <div><span>Remates</span><b><?= h($stats['Remates']) ?></b></div>
                <div><span>Recuperações</span><b><?= h($stats['Recuperacoes']) ?></b></div>
                <div><span>Falhas técnicas</span><b><?= h($stats['PerdasBola']) ?></b></div>
                <div><span>Exclusões 2 min</span><b><?= h($stats['Exclusoes2Min']) ?></b></div>
                <div><span>Média golos / jogo</span><b><?= h(number_format($avgGoals, 1, ',', '.')) ?></b></div>
                <div><span>Média assistências / jogo</span><b><?= h(number_format($avgAssists, 1, ',', '.')) ?></b></div>
                <?php if (($a['Posicao'] ?? '') === 'GR'): ?>
                    <div><span>Defesas</span><b><?= h($stats['Defesas']) ?></b></div>
                    <div><span>Eficácia GR</span><b><?= h($stats['EficaciaGuardaRedes']) ?>%</b></div>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <section class="panel">
        <div class="panel-head">
            <div class="panel-title"><span class="accent">↔</span>Inscrições</div>
            <a class="panel-link" href="inscricoes.php">Gerir →</a>
        </div>
        <div class="panel-body">
            <?php if (!$ins): ?>
                <div class="empty"><strong>Sem inscrições</strong>Associa o atleta a uma equipa e escalão.</div>
            <?php else: ?>
                <div class="timeline">
                    <?php foreach ($ins as $r): ?>
                        <div class="timeline-item">
                            <i></i>
                            <div><strong><?= h($r['NomeEquipa']) ?></strong><span><?= h($r['EscalaoNome']) ?> · <?= h($r['Epoca']) ?> · #<?= h($r['NumeroCamisola'] ?? '—') ?></span></div>
                            <span class="status <?= h(status_class($r['Estado'])) ?>"><?= h($r['Estado']) ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>
</div>

<?php if (can_manage()): ?>
<section class="panel form-card stats-section-gap" id="registar-estatisticas">
    <div class="panel-head">
        <div class="panel-title"><span class="accent">＋</span>Registar estatísticas do atleta</div>
        <span class="panel-link">A eficácia é calculada automaticamente</span>
    </div>

    <div class="panel-body">
        <?php if (!$availableGames): ?>
            <div class="empty">
                <strong>Não existem jogos disponíveis para esta equipa.</strong>
                Cria primeiro um jogo para poderes registar a performance do atleta.
            </div>
        <?php else: ?>

            <form method="get" class="toolbar" style="margin-bottom:14px">
                <input type="hidden" name="id" value="<?= $id ?>">
                <select name="stats_game" onchange="this.form.submit()" style="min-width:300px">
                    <?php foreach ($availableGames as $g): ?>
                        <option value="<?= (int)$g['IdJogo'] ?>" <?= $selectedGameId === (int)$g['IdJogo'] ? 'selected' : '' ?>>
                            <?= h(db_date($g['DataJogo']) . ' · vs ' . $g['Adversario'] . ' · ' . $g['EstadoJogo']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <noscript><button class="btn">Abrir jogo</button></noscript>
            </form>

            <?php if ($selectedGame): ?>
                <div class="stats-summary">
                    <div><span>Total de remates</span><strong id="liveShots"><?= $currentGameShots ?></strong></div>
                    <div><span>Total de golos</span><strong id="liveGoals"><?= $currentGameGoals ?></strong></div>
                    <div><span>Eficácia de remate</span><strong id="liveEfficiency"><?= number_format($currentGameEfficiency, 1, ',', '.') ?>%</strong></div>
                </div>

                <form method="post" id="athleteStatsForm">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="save_match_stats">
                    <input type="hidden" name="IdAtleta" value="<?= $id ?>">
                    <input type="hidden" name="IdJogo" value="<?= $selectedGameId ?>">

                    <div class="stats-editor-grid">
                        <div class="field"><label>Golos (sem 7 m)</label><input class="stat-input shot-goal" type="number" name="Golos" min="0" max="200" value="<?= $gameStats['Golos'] ?>"></div>
                        <div class="field"><label>Remates falhados</label><input class="stat-input shot" type="number" name="RematesFalhados" min="0" max="200" value="<?= $gameStats['RematesFalhados'] ?>"></div>
                        <div class="field"><label>Remates defendidos</label><input class="stat-input shot" type="number" name="RematesDefendidos" min="0" max="200" value="<?= $gameStats['RematesDefendidos'] ?>"></div>
                        <div class="field"><label>Remates ao poste</label><input class="stat-input shot" type="number" name="RematesPoste" min="0" max="200" value="<?= $gameStats['RematesPoste'] ?>"></div>

                        <div class="field"><label>Golos de 7 m</label><input class="stat-input shot-goal" type="number" name="Golos7m" min="0" max="200" value="<?= $gameStats['Golos7m'] ?>"></div>
                        <div class="field"><label>7 m falhados</label><input class="stat-input shot" type="number" name="Falhados7m" min="0" max="200" value="<?= $gameStats['Falhados7m'] ?>"></div>
                        <div class="field"><label>7 m defendidos</label><input class="stat-input shot" type="number" name="Defendidos7m" min="0" max="200" value="<?= $gameStats['Defendidos7m'] ?>"></div>
                        <div class="field"><label>Assistências</label><input class="stat-input" type="number" name="Assistencias" min="0" max="200" value="<?= $gameStats['Assistencias'] ?>"></div>

                        <div class="field"><label>Recuperações de bola</label><input class="stat-input" type="number" name="Recuperacoes" min="0" max="200" value="<?= $gameStats['Recuperacoes'] ?>"></div>
                        <div class="field"><label>Falhas técnicas / perdas</label><input class="stat-input" type="number" name="FalhasTecnicas" min="0" max="200" value="<?= $gameStats['FalhasTecnicas'] ?>"></div>
                        <div class="field"><label>Faltas</label><input class="stat-input" type="number" name="Faltas" min="0" max="200" value="<?= $gameStats['Faltas'] ?>"></div>
                        <div class="field"><label>Exclusões de 2 min</label><input class="stat-input" type="number" name="Exclusoes2Min" min="0" max="200" value="<?= $gameStats['Exclusoes2Min'] ?>"></div>

                        <div class="field"><label>Cartões amarelos</label><input class="stat-input" type="number" name="CartoesAmarelos" min="0" max="200" value="<?= $gameStats['CartoesAmarelos'] ?>"></div>
                        <div class="field"><label>Cartões vermelhos</label><input class="stat-input" type="number" name="CartoesVermelhos" min="0" max="200" value="<?= $gameStats['CartoesVermelhos'] ?>"></div>
                        <div class="field"><label>Defesas (GR)</label><input class="stat-input" type="number" name="Defesas" min="0" max="200" value="<?= $gameStats['Defesas'] ?>"></div>
                        <div class="field"><label>Defesas 7 m (GR)</label><input class="stat-input" type="number" name="Defesas7m" min="0" max="200" value="<?= $gameStats['Defesas7m'] ?>"></div>
                        <div class="field"><label>Golos sofridos (GR)</label><input class="stat-input" type="number" name="GolosSofridos" min="0" max="200" value="<?= $gameStats['GolosSofridos'] ?>"></div>
                    </div>

                    <div class="stats-help">
                        <strong>Como funciona:</strong> introduz os números finais do atleta nesse jogo. O total de remates e a eficácia são calculados automaticamente. Ao guardar, estes valores substituem as estatísticas anteriores deste atleta nesse jogo, evitando duplicações.
                    </div>

                    <div class="actions" style="margin-top:14px">
                        <button class="btn primary" type="submit">Guardar estatísticas</button>
                        <a class="btn" href="jogo.php?id=<?= $selectedGameId ?>">Abrir ficha do jogo</a>
                    </div>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</section>
<?php endif; ?>

<div class="grid2 profile-grid stats-section-gap">
    <section class="panel">
        <div class="panel-head"><div class="panel-title"><span class="accent">▤</span>Últimos jogos com registo</div></div>
        <div class="panel-body table-wrap">
            <?php if (!$games): ?>
                <div class="empty"><strong>Sem eventos de jogo</strong>A performance aparecerá quando forem registadas estatísticas.</div>
            <?php else: ?>
                <table>
                    <thead><tr><th>Data</th><th>Adversário</th><th>Resultado</th><th>Golos</th><th>Remates</th><th>Eficácia</th><th>Falhas</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($games as $g): ?>
                        <tr>
                            <td><?= h(db_date($g['DataJogo'])) ?></td>
                            <td><a class="text-link" href="jogo.php?id=<?= $g['IdJogo'] ?>"><?= h($g['Adversario']) ?></a></td>
                            <td><?= h(($g['GolosFavor'] ?? '—') . ' - ' . ($g['GolosContra'] ?? '—')) ?></td>
                            <td><?= h($g['golos']) ?></td>
                            <td><?= h($g['remates']) ?></td>
                            <td><?= h($g['eficacia'] ?? 0) ?>%</td>
                            <td><?= h($g['falhas_tecnicas']) ?></td>
                            <td><a class="text-link" href="atleta.php?id=<?= $id ?>&stats_game=<?= (int)$g['IdJogo'] ?>#registar-estatisticas">Editar stats</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </section>

    <section class="panel">
        <div class="panel-head"><div class="panel-title"><span class="accent">✚</span>Histórico clínico</div><a class="panel-link" href="lesoes.php">Lesões →</a></div>
        <div class="panel-body">
            <?php if (!$inj): ?>
                <div class="empty"><strong>Sem lesões registadas</strong>Não existem ocorrências clínicas nesta ficha.</div>
            <?php else: ?>
                <div class="timeline">
                    <?php foreach ($inj as $l): ?>
                        <div class="timeline-item"><i></i><div><strong><?= h($l['TipoLesao']) ?></strong><span><?= h(db_date($l['DataInicio'])) ?> · <?= h($l['Gravidade'] ?? '—') ?> · regresso <?= h(db_date($l['DataPrevistaRegresso'] ?? null)) ?></span></div><span class="status <?= h(status_class($l['Estado'])) ?>"><?= h($l['Estado']) ?></span></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </section>
</div>

<?php
$extra = <<<'HTML'
<script>
(function(){
    const form = document.getElementById('athleteStatsForm');
    if (!form) return;

    const shotsOut = document.getElementById('liveShots');
    const goalsOut = document.getElementById('liveGoals');
    const efficiencyOut = document.getElementById('liveEfficiency');

    function value(name){
        const el = form.elements[name];
        const n = parseInt(el ? el.value : '0', 10);
        return Number.isFinite(n) && n > 0 ? n : 0;
    }

    function update(){
        const goals = value('Golos') + value('Golos7m');
        const shots = goals +
            value('RematesFalhados') +
            value('RematesDefendidos') +
            value('RematesPoste') +
            value('Falhados7m') +
            value('Defendidos7m');
        const efficiency = shots > 0 ? (goals / shots) * 100 : 0;

        if (shotsOut) shotsOut.textContent = String(shots);
        if (goalsOut) goalsOut.textContent = String(goals);
        if (efficiencyOut) efficiencyOut.textContent = efficiency.toFixed(1).replace('.', ',') + '%';
    }

    form.querySelectorAll('.stat-input').forEach(function(input){
        input.addEventListener('input', update);
    });

    update();
})();
</script>
HTML;
render_footer($extra);
?>
