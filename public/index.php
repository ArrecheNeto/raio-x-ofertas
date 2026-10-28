<?php
declare(strict_types=1);
require __DIR__ . '/_app.php';
exigir_acesso();

// Busca na hora: so logado, porque gasta credito da AIsa.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['acao'] ?? '') === 'buscar' && admin_logado()) {
    if (!csrf_ok()) {
        $_SESSION['flash'] = ['erro', 'Sessão expirada. Recarregue a página e pesquise de novo.'];
        header('Location: index.php');
        exit;
    }
    $r = Robo::buscarAgora((string)($_POST['termo'] ?? ''), !empty($_POST['acompanhar']));
    if ($r['ok']) {
        $txt = sprintf('Pesquisa pronta: %d anúncios de %d anunciantes, %d deles de infoproduto, e %d Raio-X feitos agora. Custo: %s.', $r['unicos'], $r['marcas'], $r['info'], $r['raiox'], Custos::usd($r['micros'], 4));
        if ($r['info'] < 3 && $r['marcas'] > $r['info']) {
            $txt .= ' Veio pouco infoproduto: tente um termo como "curso de ' . mb_strtolower(trim((string)$_POST['termo'])) . '" ou marque "Incluir produtos físicos e serviços".';
        }
        $_SESSION['flash'] = ['ok', $txt];
    } else {
        $_SESSION['flash'] = ['erro', $r['erro']];
    }
    header('Location: index.php' . (!empty($r['nicho']) ? '?nicho=' . $r['nicho'] : ''));
    exit;
}
$flash = null;
if (admin_logado() && isset($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
}

$nichos = Db::all('SELECT id, nome FROM nichos ORDER BY nome');
$nicho = (int)($_GET['nicho'] ?? 0);
$status = (string)($_GET['status'] ?? '');
$ordem = (string)($_GET['ordem'] ?? 'ativos');
$todos = !empty($_GET['todos']) || Config::int('SO_INFOPRODUTO', 1) === 0;

$where = ['m.ultimo_visto >= ?'];
$p = [date('Y-m-d', strtotime('-7 days'))];
if ($nicho) {
    $where[] = 'm.nicho_id = ?';
    $p[] = $nicho;
}
if (isset(Escala::ROTULOS[$status])) {
    $where[] = 'm.status = ?';
    $p[] = $status;
}
if (!$todos) {
    $where[] = "m.tipo = 'info'";
}
$ordens = [
    'ativos' => 'COALESCE(m.ativos_atual,0) DESC, m.max_dias DESC',
    'dias' => 'm.max_dias DESC',
    'crescimento' => 'COALESCE(m.delta7,-9) DESC, m.max_dias DESC',
];
$sql = 'SELECT m.*, n.nome AS nicho_nome, a.thumbnail, a.imagem, a.formato, a.dominio
        FROM anunciantes m
        LEFT JOIN nichos n ON n.id = m.nicho_id
        LEFT JOIN anuncios a ON a.ad_id = m.anuncio_principal
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY ' . ($ordens[$ordem] ?? $ordens['ativos']) . ' LIMIT 120';
$ofertas = Db::all($sql, $p);

$contagem = [];
foreach (Db::all("SELECT status, COUNT(*) AS n FROM anunciantes WHERE ultimo_visto >= ?" . ($todos ? '' : " AND tipo = 'info'") . ' GROUP BY status', [date('Y-m-d', strtotime('-7 days'))]) as $c) {
    $contagem[$c['status'] ?: 'coletando'] = (int)$c['n'];
}
$ultima = Db::getConfig('ultima_atualizacao') ?? Db::val('SELECT MAX(fim) FROM execucoes WHERE jobs > 0');

function link_filtro(array $mudar): string
{
    $q = array_merge($_GET, $mudar);
    $q = array_filter($q, fn($v) => $v !== '' && $v !== null && $v !== 0 && $v !== '0');
    return 'index.php' . ($q ? '?' . http_build_query($q) : '');
}

layout_topo('Raio-X de Ofertas: ofertas escaladas da semana');
?>
<section class="hero">
  <div>
    <p class="eyebrow">Mineração diária no Facebook e Instagram</p>
    <h1>Ofertas escaladas da semana</h1>
    <p class="sub">Anúncios em português rodando há mais tempo, com a curva de escala de cada anunciante e o Raio-X do criativo feito por IA.</p>
  </div>
  <div class="hero-meta">
    <div class="kpi"><span><?= array_sum($contagem) ?></span>ofertas na semana</div>
    <div class="kpi kpi-up"><span><?= $contagem['escalando'] ?? 0 ?></span>escalando</div>
    <div class="kpi kpi-down"><span><?= $contagem['caindo'] ?? 0 ?></span>caindo</div>
    <p class="atualizado">Última atualização: <b><?= $ultima ? e(data_br($ultima, true)) : 'ainda não coletado' ?></b></p>
  </div>
</section>

<?php if ($flash): ?><p class="<?= $flash[0] === 'ok' ? 'aviso' : 'alerta' ?>"><?= e($flash[1]) ?></p><?php endif; ?>
<?php if (admin_logado()): ?>
<form class="busca" method="post" action="index.php">
  <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
  <input type="hidden" name="acao" value="buscar">
  <label class="busca-campo"><span class="sr">Nicho ou produto</span>
    <input type="search" name="termo" required minlength="3" maxlength="60" placeholder="Digite um nicho ou produto. Ex.: confeitaria, inglês, tráfego pago" autocomplete="off">
  </label>
  <button class="btn btn-pri" name="acao" value="buscar">Pesquisar agora</button>
  <label class="check"><input type="checkbox" name="acompanhar" value="1" checked> Acompanhar todo dia</label>
  <p class="mut small">Busca os anúncios em português rodando há mais tempo para esse termo e faz o Raio-X dos 3 principais. Leva até 1 minuto.</p>
</form>
<?php endif; ?>

<form class="filtros" method="get" action="index.php">
  <div class="chips" role="group" aria-label="Status">
    <?php foreach (['' => 'Todos', 'escalando' => 'Escalando', 'estavel' => 'Estável', 'caindo' => 'Caindo', 'coletando' => 'Coletando'] as $k => $rot): ?>
      <a class="chip <?= $status === $k ? 'on' : '' ?> chip-<?= e($k ?: 'todos') ?>" href="<?= e(link_filtro(['status' => $k])) ?>"><?= e($rot) ?><?php if ($k !== ''): ?> <small><?= $contagem[$k] ?? 0 ?></small><?php endif; ?></a>
    <?php endforeach; ?>
  </div>
  <div class="selects">
    <input type="hidden" name="status" value="<?= e($status) ?>">
    <label>Nicho
      <select name="nicho" onchange="this.form.submit()">
        <option value="0">Todos os nichos</option>
        <?php foreach ($nichos as $n): ?>
          <option value="<?= (int)$n['id'] ?>" <?= $nicho === (int)$n['id'] ? 'selected' : '' ?>><?= e(mb_convert_case($n['nome'], MB_CASE_TITLE)) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Ordenar
      <select name="ordem" onchange="this.form.submit()">
        <option value="ativos" <?= $ordem === 'ativos' ? 'selected' : '' ?>>Mais anúncios ativos</option>
        <option value="dias" <?= $ordem === 'dias' ? 'selected' : '' ?>>Mais dias no ar</option>
        <option value="crescimento" <?= $ordem === 'crescimento' ? 'selected' : '' ?>>Maior crescimento</option>
      </select>
    </label>
    <?php if (Config::int('SO_INFOPRODUTO', 1) === 1): ?>
      <label class="check"><input type="checkbox" name="todos" value="1" <?= $todos ? 'checked' : '' ?> onchange="this.form.submit()"> Incluir produtos físicos e serviços</label>
    <?php endif; ?>
    <noscript><button class="btn">Filtrar</button></noscript>
  </div>
</form>

<?php if (!$ofertas): ?>
  <div class="vazio">
    <h2>Nenhuma oferta por aqui ainda</h2>
    <p>Assim que o robô rodar a coleta do dia, as ofertas aparecem nesta página. Tente outro filtro ou marque "Incluir produtos físicos e serviços".</p>
  </div>
<?php else: ?>
<section class="grade">
  <?php foreach ($ofertas as $o):
      $serie = Escala::serie($o['brand_id'], 21);
      $thumb = url_segura($o['thumbnail'] ?: $o['imagem'] ?: $o['avatar']);
      $st = $o['status'] ?: 'coletando';
  ?>
  <a class="card" href="oferta.php?id=<?= e(urlencode($o['brand_id'])) ?>">
    <div class="card-midia">
      <?php if ($thumb): ?><img src="<?= e($thumb) ?>" alt="" loading="lazy" referrerpolicy="no-referrer"><?php endif; ?>
      <?php if ($o['formato'] === 'video'): ?><span class="play" aria-label="Vídeo"></span><?php endif; ?>
      <?= selo($st) ?>
    </div>
    <div class="card-corpo">
      <div class="card-topo">
        <?php if (url_segura($o['avatar'])): ?><img class="avatar" src="<?= e(url_segura($o['avatar'])) ?>" alt="" loading="lazy" referrerpolicy="no-referrer"><?php endif; ?>
        <div>
          <h3><?= e($o['nome']) ?></h3>
          <p class="mut"><?= e(mb_convert_case((string)$o['nicho_nome'], MB_CASE_TITLE)) ?><?= $o['dominio'] ? ' · ' . e($o['dominio']) : '' ?></p>
        </div>
      </div>
      <div class="metricas">
        <div><b><?= (int)$o['max_dias'] ?></b><span>dias no ar</span></div>
        <div><b><?= $o['ativos_atual'] !== null ? (int)$o['ativos_atual'] : '?' ?></b><span><?= $o['ativos_fonte'] === 'real' ? 'anúncios ativos' : 'anúncios vistos' ?></span></div>
        <div><b class="<?= ($o['delta7'] ?? 0) > 0 ? 'up' : (($o['delta7'] ?? 0) < 0 ? 'down' : '') ?>"><?= e(pct($o['delta7'] !== null ? (float)$o['delta7'] : null)) ?></b><span>em 7 dias</span></div>
      </div>
      <?= sparkline($serie['pontos'], $st) ?>
    </div>
  </a>
  <?php endforeach; ?>
</section>
<?php endif; ?>
<?php layout_rodape();
