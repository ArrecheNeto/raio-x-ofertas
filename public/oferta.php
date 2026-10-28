<?php
declare(strict_types=1);
require __DIR__ . '/_app.php';

$id = (string)($_GET['id'] ?? '');
$m = Db::one('SELECT m.*, n.nome AS nicho_nome FROM anunciantes m LEFT JOIN nichos n ON n.id = m.nicho_id WHERE m.brand_id = ?', [$id]);
if (!$m) {
    http_response_code(404);
    layout_topo('Oferta não encontrada');
    echo '<div class="vazio"><h2>Oferta não encontrada</h2><p><a href="index.php">Voltar para as ofertas</a></p></div>';
    layout_rodape();
    exit;
}

$anuncios = Db::all('SELECT * FROM anuncios WHERE brand_id = ? ORDER BY live DESC, dias_no_ar DESC LIMIT 30', [$id]);
$selId = (string)($_GET['ad'] ?? $m['anuncio_principal'] ?? ($anuncios[0]['ad_id'] ?? ''));
$ad = null;
foreach ($anuncios as $a) {
    if ($a['ad_id'] === $selId) {
        $ad = $a;
    }
}
$ad ??= $anuncios[0] ?? null;
$rx = $ad ? Db::one('SELECT * FROM raiox WHERE ad_id = ?', [$ad['ad_id']]) : null;
$raio = $rx ? json_decode($rx['json'], true) : null;
$serie = Escala::serie($id, 30);
$st = $m['status'] ?: 'coletando';
$link = $ad ? url_segura($ad['link_url']) : '';
$historico = array_reverse($serie['pontos'], true);

layout_topo($m['nome'] . ' | Raio-X de Ofertas');
?>
<p class="voltar"><a href="index.php">Voltar para as ofertas</a></p>

<section class="oferta-topo">
  <div class="oferta-id">
    <?php if (url_segura($m['avatar'])): ?><img class="avatar avatar-g" src="<?= e(url_segura($m['avatar'])) ?>" alt="" referrerpolicy="no-referrer"><?php endif; ?>
    <div>
      <p class="eyebrow"><?= e(mb_convert_case((string)$m['nicho_nome'], MB_CASE_TITLE)) ?><?= $m['tipo'] === 'info' ? ' · Infoproduto' : '' ?></p>
      <h1><?= e($m['nome']) ?></h1>
      <div class="linha-selos"><?= selo($st) ?><span class="mut">Visto pela primeira vez em <?= e(data_br($m['primeiro_visto'])) ?></span></div>
    </div>
  </div>
  <div class="oferta-kpis">
    <div class="kpi"><span><?= (int)$m['max_dias'] ?></span>dias no ar</div>
    <div class="kpi"><span><?= $m['ativos_atual'] !== null ? (int)$m['ativos_atual'] : '?' ?></span><?= $m['ativos_fonte'] === 'real' ? 'anúncios ativos' : 'anúncios vistos' ?></div>
    <div class="kpi"><span><?= e(pct($m['delta7'] !== null ? (float)$m['delta7'] : null)) ?></span>em 7 dias</div>
    <div class="kpi"><span><?= e(pct($m['delta14'] !== null ? (float)$m['delta14'] : null)) ?></span>em 14 dias</div>
  </div>
</section>

<div class="oferta-grid">
  <div class="col-criativo">
    <?php if ($ad): ?>
    <div class="painel criativo">
      <?php if (url_segura($ad['video'])): ?>
        <video controls preload="none" playsinline poster="<?= e(url_segura($ad['thumbnail'])) ?>" src="<?= e(url_segura($ad['video'])) ?>"></video>
      <?php elseif (url_segura($ad['imagem'] ?: $ad['thumbnail'])): ?>
        <img src="<?= e(url_segura($ad['imagem'] ?: $ad['thumbnail'])) ?>" alt="Criativo do anúncio" referrerpolicy="no-referrer">
      <?php endif; ?>
      <div class="criativo-info">
        <p><b><?= (int)$ad['dias_no_ar'] ?> dias no ar</b> · desde <?= e(data_br($ad['iniciou_em'])) ?> · <?= e(strtoupper((string)$ad['formato'])) ?></p>
        <?php if ($link): ?>
          <a class="btn btn-pri" href="<?= e($link) ?>" target="_blank" rel="noopener noreferrer nofollow">Abrir página de vendas</a>
          <p class="mut small"><?= e($ad['dominio']) ?></p>
        <?php endif; ?>
      </div>
    </div>

    <?php if (count($anuncios) > 1): ?>
    <div class="painel">
      <h2>Outros anúncios deste anunciante <small><?= count($anuncios) ?></small></h2>
      <div class="mini-lista">
        <?php foreach ($anuncios as $a): $t = url_segura($a['thumbnail'] ?: $a['imagem']); ?>
          <a class="mini <?= $a['ad_id'] === $ad['ad_id'] ? 'on' : '' ?>" href="oferta.php?id=<?= e(urlencode($id)) ?>&amp;ad=<?= e(urlencode($a['ad_id'])) ?>">
            <?php if ($t): ?><img src="<?= e($t) ?>" alt="" loading="lazy" referrerpolicy="no-referrer"><?php endif; ?>
            <span><?= (int)$a['dias_no_ar'] ?>d</span>
          </a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <div class="painel">
      <h2>Curva de escala</h2>
      <div class="spark-g"><?= sparkline($serie['pontos'], $st, 600, 120) ?></div>
      <p class="mut small"><?= $serie['fonte'] === 'real' ? 'Anúncios ativos por dia (contagem da Biblioteca de Anúncios).' : 'Anúncios deste anunciante encontrados em cada coleta diária.' ?></p>
      <?php if ($historico): ?>
      <table class="tabela">
        <thead><tr><th>Data</th><th><?= $serie['fonte'] === 'real' ? 'Ativos' : 'Vistos' ?></th></tr></thead>
        <tbody>
          <?php foreach (array_slice($historico, 0, 14, true) as $d => $v): ?>
            <tr><td><?= e(data_br((string)$d)) ?></td><td><?= (int)$v ?></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </div>

  <div class="col-raiox">
    <div class="painel raiox">
      <div class="raiox-topo">
        <h2>Raio-X do criativo</h2>
        <?php if ($rx): ?><span class="mut small">IA · <?= e(data_br($rx['criado_em'])) ?></span><?php endif; ?>
      </div>
      <?php if (!$raio): ?>
        <p class="mut">O Raio-X deste anúncio ainda não foi gerado. Ele é feito na próxima rodada do robô para as ofertas novas ou que mudaram.</p>
      <?php else: ?>
        <?php if (!empty($raio['resumo'])): ?><p class="resumo"><?= e($raio['resumo']) ?></p><?php endif; ?>
        <dl class="raiox-campos">
          <?php foreach (RaioX::CAMPOS as $k => $rot): ?>
            <div><dt><?= e($rot) ?></dt><dd><?= e($raio[$k] ?? '') ?></dd></div>
          <?php endforeach; ?>
        </dl>
        <h3>Como modelar (crie a sua versão, não copie)</h3>
        <div class="angulos">
          <?php foreach ($raio['como_modelar']['angulos'] ?? [] as $i => $a): ?>
            <div class="angulo"><span class="num"><?= $i + 1 ?></span><div><b><?= e($a['titulo']) ?></b><p><?= e($a['descricao']) ?></p></div></div>
          <?php endforeach; ?>
        </div>
        <h3>5 ideias de gancho</h3>
        <ol class="ganchos">
          <?php foreach ($raio['como_modelar']['ganchos'] ?? [] as $g): ?><li><?= e($g) ?></li><?php endforeach; ?>
        </ol>
      <?php endif; ?>
    </div>

    <?php if ($ad): ?>
    <div class="painel">
      <h2>Texto do anúncio</h2>
      <?php if ($ad['headline']): ?><p><b><?= e($ad['headline']) ?></b></p><?php endif; ?>
      <div class="texto"><?= nl2br(e($ad['descricao'])) ?></div>
    </div>
    <div class="painel">
      <h2>Transcrição</h2>
      <?php if (trim((string)$ad['transcricao']) !== ''): ?>
        <div class="texto texto-rolagem"><?= nl2br(e($ad['transcricao'])) ?></div>
      <?php else: ?>
        <p class="mut">Sem transcrição (anúncio de imagem ou vídeo sem fala).</p>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php layout_rodape();
