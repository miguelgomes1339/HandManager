<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/layout.php';
require_login();

$pdo = db();

/*
|--------------------------------------------------------------------------
| EVENTOS QUE PODEM SER REGISTADOS
|--------------------------------------------------------------------------
| Regra importante para a eficácia:
| Uma finalização deve ser registada UMA ÚNICA VEZ pelo seu resultado.
| Exemplo: se foi golo, regista "Golo". Não registes "Remate" + "Golo".
|--------------------------------------------------------------------------
*/
$eventosPermitidos = [
    'Golo',
    'Remate Falhado',
    'Remate Defendido',
    'Remate ao Poste',
    'Golo 7m',
    '7m Falhado',
    '7m Defendido',
    'Assistencia',
    'Recuperacao',
    'Perda de Bola',
    'Falta',
    'Exclusao 2 Min',
    'Cartao Amarelo',
    'Cartao Vermelho',
    'Defesa',
    'Defesa 7m',
    'Golo Sofrido'
];

$jogoId = (int)($_GET['jogo'] ?? $_POST['IdJogo'] ?? 0);

/*
|--------------------------------------------------------------------------
| GUARDAR / ELIMINAR EVENTOS
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!can_manage()) { http_response_code(403); exit('Sem permissões.'); }
    verify_csrf();

    $acao = $_POST['action'] ?? '';

    try {
        if ($acao === 'add_event') {
            $jogoId = (int)($_POST['IdJogo'] ?? 0);
            $atletaId = (int)($_POST['IdAtleta'] ?? 0);
            $tipoEvento = trim((string)($_POST['TipoEvento'] ?? ''));
            $minuto = ($_POST['Minuto'] ?? '') === '' ? null : (int)$_POST['Minuto'];
            $segundo = ($_POST['Segundo'] ?? '') === '' ? null : (int)$_POST['Segundo'];
            $observacao = trim((string)($_POST['Observacao'] ?? ''));

            if ($jogoId <= 0) {
                throw new RuntimeException('Seleciona um jogo.');
            }

            if ($atletaId <= 0) {
                throw new RuntimeException('Seleciona um atleta.');
            }

            if (!in_array($tipoEvento, $eventosPermitidos, true)) {
                throw new RuntimeException('Tipo de evento inválido.');
            }

            if ($minuto !== null && ($minuto < 0 || $minuto > 120)) {
                throw new RuntimeException('O minuto deve estar entre 0 e 120.');
            }

            if ($segundo !== null && ($segundo < 0 || $segundo > 59)) {
                throw new RuntimeException('O segundo deve estar entre 0 e 59.');
            }

            // Confirmar que o jogo existe.
            $stmt = $pdo->prepare("SELECT IdJogo FROM jogos WHERE IdJogo = ?");
            $stmt->execute([$jogoId]);
            if (!$stmt->fetchColumn()) {
                throw new RuntimeException('O jogo selecionado não existe.');
            }

            // Confirmar que o atleta existe.
            $stmt = $pdo->prepare("SELECT IdAtleta FROM atletas WHERE IdAtleta = ?");
            $stmt->execute([$atletaId]);
            if (!$stmt->fetchColumn()) {
                throw new RuntimeException('O atleta selecionado não existe.');
            }

            $stmt = $pdo->prepare("
                INSERT INTO eventos_jogo
                    (IdJogo, IdAtleta, TipoEvento, Minuto, Segundo, Observacao)
                VALUES
                    (?, ?, ?, ?, ?, ?)
            ");

            $stmt->execute([
                $jogoId,
                $atletaId,
                $tipoEvento,
                $minuto,
                $segundo,
                $observacao !== '' ? $observacao : null
            ]);

            activity_log('Registar evento','eventos_jogo',(int)$pdo->lastInsertId(),$tipoEvento);
            flash('success', 'Evento registado: ' . $tipoEvento . '.');
            redirect('eventos.php?jogo=' . $jogoId);
        }

        if ($acao === 'delete_event') {
            $eventoId = (int)($_POST['IdEvento'] ?? 0);

            $stmt = $pdo->prepare("
                SELECT IdJogo
                FROM eventos_jogo
                WHERE IdEvento = ?
            ");
            $stmt->execute([$eventoId]);
            $jogoDoEvento = (int)$stmt->fetchColumn();

            if ($jogoDoEvento <= 0) {
                throw new RuntimeException('Evento não encontrado.');
            }

            $stmt = $pdo->prepare("
                DELETE FROM eventos_jogo
                WHERE IdEvento = ?
            ");
            $stmt->execute([$eventoId]);

            activity_log('Eliminar evento','eventos_jogo',$eventoId);
            flash('success', 'Evento eliminado.');
            redirect('eventos.php?jogo=' . $jogoDoEvento);
        }
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
        redirect('eventos.php' . ($jogoId > 0 ? '?jogo=' . $jogoId : ''));
    }
}

/*
|--------------------------------------------------------------------------
| JOGOS
|--------------------------------------------------------------------------
*/
$jogos = $pdo->query("
    SELECT
        j.IdJogo,
        j.DataJogo,
        j.HoraJogo,
        j.Adversario,
        j.EstadoJogo,
        j.IdEquipa,
        e.NomeEquipa
    FROM jogos j
    LEFT JOIN equipas e
        ON e.IdEquipa = j.IdEquipa
    ORDER BY j.DataJogo DESC, j.HoraJogo DESC, j.IdJogo DESC
")->fetchAll();

$jogo = null;
$atletas = [];
$eventos = [];
$estatisticasJogo = [];
$totais = [
    'golos' => 0,
    'remates' => 0,
    'assistencias' => 0,
    'defesas' => 0
];

if ($jogoId > 0) {
    /*
    |--------------------------------------------------------------------------
    | JOGO SELECIONADO
    |--------------------------------------------------------------------------
    */
    $stmt = $pdo->prepare("
        SELECT
            j.*,
            e.NomeEquipa,
            c.NomeCompeticao
        FROM jogos j
        LEFT JOIN equipas e
            ON e.IdEquipa = j.IdEquipa
        LEFT JOIN competicoes c
            ON c.IdCompeticao = j.IdCompeticao
        WHERE j.IdJogo = ?
        LIMIT 1
    ");
    $stmt->execute([$jogoId]);
    $jogo = $stmt->fetch();

    if ($jogo) {
        /*
        |--------------------------------------------------------------------------
        | ATLETAS DA EQUIPA
        |--------------------------------------------------------------------------
        | Aceita tanto:
        | - atletas.IdEquipa
        | - inscricoes_atleta
        |--------------------------------------------------------------------------
        */
        if (table_exists('inscricoes_atleta')) {
            $stmt = $pdo->prepare("
                SELECT DISTINCT
                    a.IdAtleta, a.Nome, a.Posicao, a.EstadoDoJogador,
                    COALESCE(ia.NumeroCamisola, a.NumeroCamisola) AS NumeroCamisola
                FROM atletas a
                LEFT JOIN inscricoes_atleta ia
                    ON ia.IdAtleta = a.IdAtleta
                   AND ia.IdEquipa = ?
                   AND ia.Estado = 'Ativo'
                WHERE a.IdEquipa = ? OR ia.IdEquipa = ?
                ORDER BY a.NumeroCamisola IS NULL, a.NumeroCamisola, a.Nome
            ");
            $stmt->execute([$jogo['IdEquipa'],$jogo['IdEquipa'],$jogo['IdEquipa']]);
        } else {
            $stmt = $pdo->prepare("SELECT IdAtleta,Nome,Posicao,EstadoDoJogador,NumeroCamisola FROM atletas WHERE IdEquipa=? ORDER BY NumeroCamisola IS NULL,NumeroCamisola,Nome");
            $stmt->execute([$jogo['IdEquipa']]);
        }
        $atletas = $stmt->fetchAll();

        /*
        |--------------------------------------------------------------------------
        | EVENTOS DO JOGO
        |--------------------------------------------------------------------------
        */
        $stmt = $pdo->prepare("
            SELECT
                ev.*,
                a.Nome,
                a.NumeroCamisola,
                a.Posicao
            FROM eventos_jogo ev
            LEFT JOIN atletas a
                ON a.IdAtleta = ev.IdAtleta
            WHERE ev.IdJogo = ?
            ORDER BY
                COALESCE(ev.Minuto, 0) DESC,
                COALESCE(ev.Segundo, 0) DESC,
                ev.IdEvento DESC
        ");
        $stmt->execute([$jogoId]);
        $eventos = $stmt->fetchAll();

        /*
        |--------------------------------------------------------------------------
        | ESTATÍSTICAS DO JOGO
        |--------------------------------------------------------------------------
        | Vêm da VIEW criada no SQL de correção.
        |--------------------------------------------------------------------------
        */
        $stmt = $pdo->prepare("
            SELECT *
            FROM vw_estatisticas_atleta_jogo
            WHERE IdJogo = ?
            ORDER BY
                EficaciaRemate DESC,
                Golos DESC,
                Nome ASC
        ");
        $stmt->execute([$jogoId]);
        $estatisticasJogo = $stmt->fetchAll();

        /*
        |--------------------------------------------------------------------------
        | TOTAIS DA EQUIPA NO JOGO
        |--------------------------------------------------------------------------
        */
        $stmt = $pdo->prepare("
            SELECT
                SUM(CASE
                    WHEN TipoEvento IN ('Golo','Golo 7m')
                    THEN 1 ELSE 0
                END) AS golos,

                SUM(CASE
                    WHEN TipoEvento IN (
                        'Golo',
                        'Remate Falhado',
                        'Remate Defendido',
                        'Remate ao Poste',
                        'Golo 7m',
                        '7m Falhado',
                        '7m Defendido'
                    )
                    THEN 1 ELSE 0
                END) AS remates,

                SUM(CASE
                    WHEN TipoEvento = 'Assistencia'
                    THEN 1 ELSE 0
                END) AS assistencias,

                SUM(CASE
                    WHEN TipoEvento IN ('Defesa','Defesa 7m')
                    THEN 1 ELSE 0
                END) AS defesas

            FROM eventos_jogo
            WHERE IdJogo = ?
        ");
        $stmt->execute([$jogoId]);
        $r = $stmt->fetch();

        if ($r) {
            $totais = [
                'golos' => (int)$r['golos'],
                'remates' => (int)$r['remates'],
                'assistencias' => (int)$r['assistencias'],
                'defesas' => (int)$r['defesas']
            ];
        }
    }
}

render_header('Eventos de Jogo', 'eventos');
?>

<style>
.live-head{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:15px;
    flex-wrap:wrap;
    margin-bottom:16px;
}
.live-game{
    padding:18px;
    border-radius:16px;
    border:1px solid var(--line);
    background:linear-gradient(135deg,#0b2945,#091b2d);
}
.live-game h2{margin:0 0 5px;font-size:20px}
.live-game p{margin:0;color:var(--muted);font-size:11px}
.quick-grid{
    display:grid;
    grid-template-columns:repeat(4,minmax(0,1fr));
    gap:9px;
}
.quick-btn{
    min-height:62px;
    border:1px solid var(--line);
    border-radius:13px;
    color:white;
    font-weight:850;
    font-size:11px;
    background:#0b2137;
    transition:.15s;
}
.quick-btn:hover{transform:translateY(-1px);border-color:#4d8fdd}
.quick-btn.goal{background:linear-gradient(135deg,#0c8754,#12b76f)}
.quick-btn.miss{background:linear-gradient(135deg,#8b2f38,#bd3845)}
.quick-btn.save{background:linear-gradient(135deg,#1358a7,#267be0)}
.quick-btn.post{background:linear-gradient(135deg,#9b6b12,#c89421)}
.quick-btn.penalty{background:linear-gradient(135deg,#5d3fc2,#7c58e7)}
.quick-btn.other{background:linear-gradient(135deg,#153451,#0d253e)}
.stats-number{font-weight:900;font-size:14px}
.eff-high{color:#5ce0a0;font-weight:900}
.eff-mid{color:#ffd56b;font-weight:900}
.eff-low{color:#ff9298;font-weight:900}
.rule-box{
    margin-top:12px;
    padding:11px 12px;
    border-radius:11px;
    border:1px solid rgba(245,184,46,.23);
    background:rgba(245,184,46,.08);
    color:#ffe4a0;
    font-size:10px;
    line-height:1.5;
}
@media(max-width:1050px){
    .quick-grid{grid-template-columns:repeat(3,minmax(0,1fr))}
}
@media(max-width:700px){
    .quick-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
}
</style>

<div class="page-head">
    <div>
        <h1>Eventos de Jogo</h1>
        <p>Regista cada ação e deixa o HandManager calcular automaticamente a eficácia.</p>
    </div>
    <div class="actions">
        <a class="btn" href="estatisticas.php">Ver estatísticas gerais</a>
        <a class="btn" href="jogos.php">Gerir jogos</a>
    </div>
</div>

<section class="panel form-card">
    <form class="toolbar" method="get">
        <select name="jogo" required style="min-width:300px">
            <option value="">Selecionar jogo...</option>

            <?php foreach ($jogos as $j): ?>
                <option
                    value="<?= h($j['IdJogo']) ?>"
                    <?= $jogoId === (int)$j['IdJogo'] ? 'selected' : '' ?>
                >
                    <?= h(
                        db_date($j['DataJogo'])
                        . ' · '
                        . ($j['NomeEquipa'] ?: 'Equipa')
                        . ' vs '
                        . $j['Adversario']
                    ) ?>
                </option>
            <?php endforeach; ?>
        </select>

        <button class="btn primary">Abrir jogo</button>
    </form>
</section>

<?php if ($jogo): ?>

<div class="live-head">
    <div class="live-game">
        <h2>
            <?= h($jogo['NomeEquipa'] ?: 'HandManager') ?>
            vs
            <?= h($jogo['Adversario']) ?>
        </h2>

        <p>
            <?= h(db_datetime($jogo['DataJogo'], $jogo['HoraJogo'])) ?>
            · <?= h($jogo['NomeCompeticao'] ?: 'Sem competição') ?>
            · <?= h($jogo['CasaFora']) ?>
        </p>
    </div>

    <div class="actions">
        <span class="status blue"><?= h($jogo['EstadoJogo']) ?></span>
    </div>
</div>

<div class="stat-strip">
    <div class="stat-mini">
        <span>Golos</span>
        <strong><?= $totais['golos'] ?></strong>
    </div>

    <div class="stat-mini">
        <span>Remates</span>
        <strong><?= $totais['remates'] ?></strong>
    </div>

    <div class="stat-mini">
        <span>Eficácia coletiva</span>
        <strong>
            <?= $totais['remates'] > 0
                ? number_format(($totais['golos'] / $totais['remates']) * 100, 1, ',', '.')
                : '0,0' ?>%
        </strong>
    </div>

    <div class="stat-mini">
        <span>Assistências</span>
        <strong><?= $totais['assistencias'] ?></strong>
    </div>
</div>

<section class="panel form-card">
    <div class="panel-head">
        <div class="panel-title">
            <span class="accent">●</span>
            Registar ação
        </div>
    </div>

    <div class="panel-body">
        <?php if (!$atletas): ?>

            <div class="empty">
                <strong>Não existem atletas associados a esta equipa.</strong>
                Primeiro associa os atletas à equipa em Atletas / Inscrições.
            </div>

        <?php else: ?>

        <form method="post" id="eventForm">
            <?= csrf_field() ?>

            <input type="hidden" name="action" value="add_event">
            <input type="hidden" name="IdJogo" value="<?= h($jogoId) ?>">
            <input type="hidden" name="TipoEvento" id="TipoEvento" value="">

            <div class="form-grid" style="margin-bottom:14px">

                <div class="field">
                    <label>Atleta *</label>

                    <select name="IdAtleta" required>
                        <option value="">Selecionar atleta...</option>

                        <?php foreach ($atletas as $a): ?>
                            <option value="<?= h($a['IdAtleta']) ?>">
                                <?= $a['NumeroCamisola']
                                    ? '#' . h($a['NumeroCamisola']) . ' · '
                                    : '' ?>
                                <?= h($a['Nome']) ?>
                                <?= $a['Posicao']
                                    ? ' (' . h($a['Posicao']) . ')'
                                    : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="field">
                    <label>Minuto</label>
                    <input
                        type="number"
                        name="Minuto"
                        min="0"
                        max="120"
                        placeholder="Ex.: 24"
                    >
                </div>

                <div class="field">
                    <label>Segundo</label>
                    <input
                        type="number"
                        name="Segundo"
                        min="0"
                        max="59"
                        placeholder="Ex.: 32"
                    >
                </div>

                <div class="field full">
                    <label>Observação</label>
                    <input
                        type="text"
                        name="Observacao"
                        maxlength="255"
                        placeholder="Opcional"
                    >
                </div>

            </div>

            <div class="quick-grid">

                <button
                    class="quick-btn goal"
                    type="submit"
                    data-event="Golo"
                >
                    ⚽ GOLO
                </button>

                <button
                    class="quick-btn miss"
                    type="submit"
                    data-event="Remate Falhado"
                >
                    ✕ REMATE FALHADO
                </button>

                <button
                    class="quick-btn save"
                    type="submit"
                    data-event="Remate Defendido"
                >
                    ◇ REMATE DEFENDIDO
                </button>

                <button
                    class="quick-btn post"
                    type="submit"
                    data-event="Remate ao Poste"
                >
                    ▌ REMATE AO POSTE
                </button>

                <button
                    class="quick-btn penalty"
                    type="submit"
                    data-event="Golo 7m"
                >
                    7M · GOLO
                </button>

                <button
                    class="quick-btn penalty"
                    type="submit"
                    data-event="7m Falhado"
                >
                    7M · FALHADO
                </button>

                <button
                    class="quick-btn penalty"
                    type="submit"
                    data-event="7m Defendido"
                >
                    7M · DEFENDIDO
                </button>

                <button
                    class="quick-btn other"
                    type="submit"
                    data-event="Assistencia"
                >
                    ↗ ASSISTÊNCIA
                </button>

                <button
                    class="quick-btn other"
                    type="submit"
                    data-event="Recuperacao"
                >
                    + RECUPERAÇÃO
                </button>

                <button
                    class="quick-btn other"
                    type="submit"
                    data-event="Perda de Bola"
                >
                    − PERDA DE BOLA
                </button>

                <button
                    class="quick-btn other"
                    type="submit"
                    data-event="Exclusao 2 Min"
                >
                    2' EXCLUSÃO
                </button>

                <button
                    class="quick-btn save"
                    type="submit"
                    data-event="Defesa"
                >
                    🛡 DEFESA GR
                </button>

                <button
                    class="quick-btn save"
                    type="submit"
                    data-event="Defesa 7m"
                >
                    🛡 DEFESA 7M
                </button>

                <button
                    class="quick-btn other"
                    type="submit"
                    data-event="Golo Sofrido"
                >
                    GOLO SOFRIDO GR
                </button>

                <button
                    class="quick-btn other"
                    type="submit"
                    data-event="Falta"
                >
                    FALTA
                </button>

                <button
                    class="quick-btn other"
                    type="submit"
                    data-event="Cartao Vermelho"
                >
                    CARTÃO VERMELHO
                </button>

            </div>

            <div class="rule-box">
                <strong>Regra para a eficácia:</strong>
                numa finalização escolhe apenas o resultado da jogada.
                Por exemplo, se o jogador rematou e marcou, regista apenas
                <strong>GOLO</strong>. Não registes “Remate” e depois “Golo”,
                porque o mesmo remate seria contado duas vezes.
            </div>
        </form>

        <?php endif; ?>
    </div>
</section>

<section class="panel form-card">
    <div class="panel-head">
        <div class="panel-title">
            <span class="accent">▥</span>
            Eficácia neste jogo
        </div>

        <span class="panel-link">
            Atualização automática
        </span>
    </div>

    <div class="panel-body table-wrap">

        <?php if (!$estatisticasJogo): ?>

            <div class="empty">
                <strong>Ainda não existem estatísticas.</strong>
                Regista eventos para começar o cálculo.
            </div>

        <?php else: ?>

        <table>
            <thead>
                <tr>
                    <th>Atleta</th>
                    <th>Golos</th>
                    <th>Remates</th>
                    <th>Eficácia</th>
                    <th>7m</th>
                    <th>Ef. 7m</th>
                    <th>Assist.</th>
                    <th>Recup.</th>
                    <th>Perdas</th>
                    <th>Defesas</th>
                    <th>Ef. GR</th>
                </tr>
            </thead>

            <tbody>

            <?php foreach ($estatisticasJogo as $s): ?>

                <?php
                $ef = (float)$s['EficaciaRemate'];

                if ($ef >= 70) {
                    $effClass = 'eff-high';
                } elseif ($ef >= 50) {
                    $effClass = 'eff-mid';
                } else {
                    $effClass = 'eff-low';
                }
                ?>

                <tr data-searchable>
                    <td>
                        <div class="person">
                            <span class="mini-avatar">
                                <?= h(initials($s['Nome'])) ?>
                            </span>

                            <div>
                                <strong><?= h($s['Nome']) ?></strong>
                                <div style="color:#819bb2">
                                    #<?= h($s['NumeroCamisola'] ?: '—') ?>
                                    · <?= h($s['Posicao'] ?: '—') ?>
                                </div>
                            </div>
                        </div>
                    </td>

                    <td class="stats-number">
                        <?= h($s['Golos']) ?>
                    </td>

                    <td>
                        <?= h($s['Remates']) ?>
                    </td>

                    <td class="<?= $effClass ?>">
                        <?= number_format(
                            (float)$s['EficaciaRemate'],
                            2,
                            ',',
                            '.'
                        ) ?>%
                    </td>

                    <td>
                        <?= h($s['Golos7m']) ?>
                        /
                        <?= h($s['Remates7m']) ?>
                    </td>

                    <td>
                        <?= number_format(
                            (float)$s['Eficacia7m'],
                            2,
                            ',',
                            '.'
                        ) ?>%
                    </td>

                    <td><?= h($s['Assistencias']) ?></td>
                    <td><?= h($s['Recuperacoes']) ?></td>
                    <td><?= h($s['PerdasBola']) ?></td>
                    <td><?= h($s['Defesas']) ?></td>

                    <td>
                        <?= number_format(
                            (float)$s['EficaciaGuardaRedes'],
                            2,
                            ',',
                            '.'
                        ) ?>%
                    </td>
                </tr>

            <?php endforeach; ?>

            </tbody>
        </table>

        <?php endif; ?>

    </div>
</section>

<section class="panel">
    <div class="panel-head">
        <div class="panel-title">
            <span class="accent">ϟ</span>
            Histórico do jogo
        </div>

        <span class="panel-link">
            <?= count($eventos) ?> evento(s)
        </span>
    </div>

    <div class="panel-body table-wrap">

        <?php if (!$eventos): ?>

            <div class="empty">
                <strong>Nenhum evento registado.</strong>
                Os eventos aparecerão aqui por ordem cronológica.
            </div>

        <?php else: ?>

        <table>
            <thead>
                <tr>
                    <th>Tempo</th>
                    <th>Atleta</th>
                    <th>Evento</th>
                    <th>Observação</th>
                    <th></th>
                </tr>
            </thead>

            <tbody>

            <?php foreach ($eventos as $e): ?>

                <tr data-searchable>
                    <td>
                        <?= h(
                            ($e['Minuto'] ?? 0)
                            . ':'
                            . str_pad(
                                (string)($e['Segundo'] ?? 0),
                                2,
                                '0',
                                STR_PAD_LEFT
                            )
                        ) ?>
                    </td>

                    <td>
                        <?= h($e['Nome'] ?: '—') ?>
                    </td>

                    <td>
                        <span class="status blue">
                            <?= h($e['TipoEvento']) ?>
                        </span>
                    </td>

                    <td>
                        <?= h($e['Observacao'] ?: '—') ?>
                    </td>

                    <td>
                        <form
                            method="post"
                            class="confirm-delete"
                        >
                            <?= csrf_field() ?>

                            <input
                                type="hidden"
                                name="action"
                                value="delete_event"
                            >

                            <input
                                type="hidden"
                                name="IdEvento"
                                value="<?= h($e['IdEvento']) ?>"
                            >

                            <button
                                class="btn small danger"
                                type="submit"
                            >
                                Eliminar
                            </button>
                        </form>
                    </td>
                </tr>

            <?php endforeach; ?>

            </tbody>
        </table>

        <?php endif; ?>

    </div>
</section>

<script>
(function () {
    const form = document.getElementById('eventForm');
    const tipo = document.getElementById('TipoEvento');

    if (!form || !tipo) return;

    form.querySelectorAll('[data-event]').forEach(function (button) {
        button.addEventListener('click', function () {
            tipo.value = button.dataset.event || '';
        });
    });

    form.addEventListener('submit', function (event) {
        if (!tipo.value) {
            event.preventDefault();
            alert('Seleciona uma ação.');
        }
    });
})();
</script>

<?php endif; ?>

<?php
render_footer();
?>
