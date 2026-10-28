<?php
declare(strict_types=1);
require __DIR__ . '/_app.php';

// A lista de concorrentes e sua, entao a pagina so abre com login.
if (!admin_logado()) {
    header('Location: admin.php');
    exit;
}

$lista = Db::all(
    'SELECT c.*, m.nome AS marca_nome, m.avatar, m.status AS escala, m.ativos_atual, m.ativos_fonte, m.delta7, m.delta14, m.max_dias, m.tipo,
            a.thumbnail, a.imagem, a.formato,
            (SELECT COUNT(*) FROM anuncios x WHERE x.brand_id = c.brand_id AND x.live = 1) AS no_banco,
            (SELECT COUNT(*) FROM anuncios x WHERE x.brand_id = c.brand_id AND x.primeiro_visto >= ?) AS novos7
     FROM concorrentes c
     LEFT JOIN anunciantes m ON m.brand_id = c.brand_id
     LEFT JOIN anuncios a ON a.ad_id = m.anuncio_principal
     ORDER BY c.ativo DESC, COALESCE(m.ativos_atual, 0) DESC, c.id',
    [date('Y-m-d', strtotime('-7 days'))]
);

layout_topo('Concorrentes | Raio-X de Ofertas', 'concorrentes');
?>
<section class="hero">
  <div>
    <p class="eyebrow">Modo Concorrente</p>
    <h1>Seus concorrentes</h1>
    <p class="sub">Os anunciantes que você escolheu acompanhar. Todo dia o robô busca os anúncios ativos de cada um e mede se estão escalando ou caindo.</p>
  </div>
  <div class="hero-meta">
    <div class="kpi"><span><?= count($lista) ?></span>acompanhados</div>
    <div class="kpi kpi-up"><span><?= count(array_filter($lista, fn($c) => $c['escala'] === 'escalando')) ?></span>escalando</div>
    <div class="kpi kpi-down"><span><?= count(array_filter($lista, fn($c) => $c['escala'] === 'caindo')) ?></span>caindo</div>
    <p class="atualizado"><a href="admin.php#concorrentes">Adicionar ou remover concorrentes</a></p>
  </div>
</section>

<?php if (!Concorrente::ativo()): ?><p class="alerta">O Modo Concorrente está desligado (MODO_CONCORRENTE=0 no .env). Nada novo é coletado.</p><?php endif; ?>

<?php if (!$lista): ?>
  <div class="vazio">
    <h2>Nenhum concorrente ainda</h2>
    <p>No <a href="admin.php#concorrentes">painel</a>, digite o domínio de um concorrente. Ou abra uma oferta e clique em "Acompanhar como concorrente".</p>
  </div>
<?php else: ?>
<section class="grade">
  <?php foreach ($lista as $c):
      $temDados = $c['marca_nome'] !== null;
      $st = $c['escala'] ?: 'coletando';
      $thumb = url_segura($c['thumbnail'] ?: $c['imagem'] ?: $c['avatar']);
      $serie = $c['brand_id'] ? Escala::serie((string)$c['brand_id'], 21) : ['pontos' => []];
      $situacao = !(int)$c['ativo'] ? 'Pausado' : match ($c['status']) {
          'buscando' => 'Procurando a marca pelo domínio',
          'nao_encontrado' => 'Nenhum anunciante encontrado para este domínio',
          'erro' => 'Erro na última tentativa',
          default => $temDados ? '' : 'Aguardando a primeira coleta',
      };
      $tag = $temDados ? 'a' : 'div';
  ?>
  <<?= $tag ?> class="card<?= (int)$c['ativo'] ? '' : ' desativado' ?>"<?= $temDados ? ' href="oferta.php?id=' . e(urlencode((string)$c['brand_id'])) . '"' : '' ?>>
    <div class="card-midia">
      <?php if ($thumb): ?><img src="<?= e($thumb) ?>" alt="" loading="lazy" referrerpolicy="no-referrer"><?php endif; ?>
      <?php if ($c['formato'] === 'video'): ?><span class="play" aria-label="Vídeo"></span><?php endif; ?>
      <?= selo($st) ?>
    </div>
    <div class="card-corpo">
      <div class="card-topo">
        <?php if (url_segura($c['avatar'])): ?><img class="avatar" src="<?= e(url_segura($c['avatar'])) ?>" alt="" loading="lazy" referrerpolicy="no-referrer"><?php endif; ?>
        <div>
          <h3><?= e($c['marca_nome'] ?: $c['nome']) ?></h3>
          <p class="mut"><?= e((string)$c['dominio']) ?><?= $c['tipo'] === 'info' ? ' · Infoproduto' : '' ?></p>
        </div>
      </div>
      <?php if ($situacao !== ''): ?>
        <p class="mut small"><?= e($situacao) ?><?= $c['erro'] ? ': ' . e($c['erro']) : '' ?></p>
      <?php else: ?>
      <div class="metricas">
        <div><b><?= $c['ativos_atual'] !== null ? (int)$c['ativos_atual'] : '?' ?></b><span><?= $c['ativos_fonte'] === 'real' ? 'anúncios ativos' : 'anúncios vistos' ?></span></div>
        <div><b class="<?= ($c['delta7'] ?? 0) > 0 ? 'up' : (($c['delta7'] ?? 0) < 0 ? 'down' : '') ?>"><?= e(pct($c['delta7'] !== null ? (float)$c['delta7'] : null)) ?></b><span>em 7 dias</span></div>
        <div><b><?= (int)$c['novos7'] ?></b><span>criativos novos (7 dias)</span></div>
      </div>
      <?= sparkline($serie['pontos'], $st) ?>
      <p class="mut small">Mais antigo no ar: <?= (int)$c['max_dias'] ?> dias · última coleta <?= e(data_br($c['ultima_coleta'], true) ?: 'ainda não') ?></p>
      <?php endif; ?>
    </div>
  </<?= $tag ?>>
  <?php endforeach; ?>
</section>
<?php endif; ?>
<?php layout_rodape();
