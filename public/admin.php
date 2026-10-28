<?php
declare(strict_types=1);
require __DIR__ . '/_app.php';

iniciar_sessao();
$msg = null;
$msgErro = false;
$erroLogin = null;
$resultado = null;

// Primeira configuração: só existe enquanto nenhuma senha de painel foi definida.
if (precisa_instalar()) {
    $erroInst = null;
    if (($_POST['acao'] ?? '') === 'instalar') {
        $chave = trim((string)($_POST['chave'] ?? ''));
        $senha = (string)($_POST['senha'] ?? '');
        $teto = str_replace(',', '.', trim((string)($_POST['teto'] ?? '2.00')));
        if (!csrf_ok()) {
            $erroInst = 'Sessão expirada. Tente de novo.';
        } elseif (mb_strlen($senha) < 8) {
            $erroInst = 'Use uma senha de pelo menos 8 caracteres.';
        } elseif ($senha !== (string)($_POST['senha2'] ?? '')) {
            $erroInst = 'As duas senhas não são iguais.';
        } elseif (!is_numeric($teto) || (float)$teto <= 0 || (float)$teto > 100) {
            $erroInst = 'Teto diário inválido. Use um valor como 2.50.';
        } else {
            $erroInst = testar_chave($chave)
                ?? Config::salvar(APP_DIR . '/.env', ['AISA_API_KEY' => $chave, 'ADMIN_PASSWORD' => $senha, 'DAILY_BUDGET_USD' => number_format((float)$teto, 2, '.', '')]);
        }
        if ($erroInst === null) {
            session_regenerate_id(true);
            $_SESSION['admin'] = true;
            header('Location: admin.php?bemvindo=1');
            exit;
        }
    }
    layout_topo('Primeira configuração', 'admin');
    ?>
    <section class="login painel">
      <h1>Primeira configuração</h1>
      <p class="mut">Cole sua chave da AIsa e crie a senha do painel. A chave é testada antes de salvar e fica guardada no servidor, fora da pasta pública. Ela nunca aparece de novo na tela.</p>
      <?php if ($erroInst): ?><p class="alerta"><?= e($erroInst) ?></p><?php endif; ?>
      <form method="post" class="form-pilha">
        <input type="hidden" name="acao" value="instalar">
        <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
        <label>Chave da AIsa<input type="password" name="chave" required autocomplete="off" placeholder="Console da AIsa > API Keys"></label>
        <label>Senha do painel<input type="password" name="senha" required minlength="8" autocomplete="new-password"></label>
        <label>Repita a senha<input type="password" name="senha2" required minlength="8" autocomplete="new-password"></label>
        <label>Teto de gasto por dia (US$)<input name="teto" value="2.50" inputmode="decimal" required></label>
        <button class="btn btn-pri">Salvar e entrar</button>
      </form>
      <p class="mut small">Faça isso logo depois de subir os arquivos: enquanto não houver senha, qualquer pessoa que abrir esta página pode configurar.</p>
    </section>
    <?php
    layout_rodape();
    exit;
}

if (($_POST['acao'] ?? '') === 'login') {
    $erroLogin = tentar_login();
    if ($erroLogin === null) {
        header('Location: admin.php');
        exit;
    }
}

if (!admin_logado()) {
    layout_topo('Entrar no painel', 'admin');
    ?>
    <section class="login painel">
      <h1>Painel do Raio-X</h1>
      <p class="mut">Digite a senha definida em ADMIN_PASSWORD no arquivo .env.</p>
      <?php if ($erroLogin): ?><p class="alerta"><?= e($erroLogin) ?></p><?php endif; ?>
      <form method="post">
        <input type="hidden" name="acao" value="login">
        <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
        <input type="password" name="senha" placeholder="Senha" required autofocus autocomplete="current-password">
        <button class="btn btn-pri">Entrar</button>
      </form>
    </section>
    <?php
    layout_rodape();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_ok()) {
        $msg = 'Sessão expirada. Recarregue a página.';
    } else {
        switch ($_POST['acao'] ?? '') {
            case 'sair':
                $_SESSION = [];
                session_destroy();
                header('Location: index.php');
                exit;
            case 'rodar':
                $resultado = Robo::rodar('painel', 25, max(1, (int)($_POST['coletas'] ?? 1)));
                break;
            case 'nicho_novo':
                $nome = trim((string)($_POST['nome'] ?? ''));
                $termo = trim((string)($_POST['termo'] ?? '')) ?: $nome;
                if ($nome !== '') {
                    Db::q('INSERT INTO nichos (nome, termo, ativo, criado_em) VALUES (?,?,1,?)', [mb_strtolower($nome), $termo, date('Y-m-d H:i:s')]);
                    $msg = 'Nicho adicionado. Ele entra na coleta de amanhã (ou rode agora, se a coleta de hoje ainda não começou).';
                }
                break;
            case 'nicho_salvar':
                Db::q('UPDATE nichos SET nome = ?, termo = ?, ativo = ? WHERE id = ?', [
                    mb_strtolower(trim((string)$_POST['nome'])), trim((string)$_POST['termo']), !empty($_POST['ativo']) ? 1 : 0, (int)$_POST['id'],
                ]);
                $msg = 'Nicho salvo.';
                break;
            case 'nicho_apagar':
                Db::q('DELETE FROM nichos WHERE id = ?', [(int)$_POST['id']]);
                $msg = 'Nicho removido. Os anúncios já coletados continuam no banco.';
                break;
            case 'reabrir':
                Fila::reabrir((int)$_POST['id']);
                Db::q('DELETE FROM config WHERE chave = ?', ['teto_' . date('Y-m-d')]);
                $msg = 'Tarefa voltou para a fila. Ela roda na próxima execução (ou clique em Rodar agora).';
                break;
            case 'conc_dominio':
                $msg = Concorrente::adicionarPorDominio((string)($_POST['dominio'] ?? ''), (string)($_POST['nome'] ?? ''))
                    ?? 'Concorrente adicionado. A marca é procurada na próxima execução (clique em Rodar agora para fazer já).';
                break;
            case 'conc_marca':
                $msg = Concorrente::adicionarPorMarca((string)($_POST['brand_id'] ?? ''))
                    ?? 'Anunciante adicionado aos concorrentes. Os anúncios dele entram na próxima execução.';
                break;
            case 'conc_trocar':
                Concorrente::escolherCandidato((int)$_POST['id'], (string)($_POST['brand_id'] ?? ''));
                $msg = 'Marca trocada. A coleta dela entra na próxima execução.';
                break;
            case 'conc_pausar':
                Db::q('UPDATE concorrentes SET ativo = 1 - ativo WHERE id = ?', [(int)$_POST['id']]);
                $msg = 'Concorrente atualizado.';
                break;
            case 'conc_buscar':
                Db::q("UPDATE concorrentes SET status = 'buscando', erro = NULL WHERE id = ?", [(int)$_POST['id']]);
                Fila::adicionar('concorrente_busca', (string)(int)$_POST['id']);
                $msg = 'Nova busca da marca agendada para a próxima execução.';
                break;
            case 'conc_apagar':
                Db::q('DELETE FROM concorrentes WHERE id = ?', [(int)$_POST['id']]);
                $msg = 'Concorrente removido. Os anúncios já coletados continuam no banco.';
                break;
            case 'config_salvar':
                $novos = [];
                $erroCfg = null;
                $chave = trim((string)($_POST['chave'] ?? ''));
                if ($chave !== '') {
                    $erroCfg = testar_chave($chave);
                    $novos['AISA_API_KEY'] = $chave;
                }
                $numeros = ['DAILY_BUDGET_USD' => [0.01, 100], 'ADS_PER_NICHE' => [1, 250], 'ANALYTICS_TOP' => [0, 100], 'RAIOX_POR_DIA' => [0, 500]];
                foreach ($numeros as $k => [$min, $max]) {
                    $v = str_replace(',', '.', trim((string)($_POST[$k] ?? '')));
                    if (!is_numeric($v) || (float)$v < $min || (float)$v > $max) {
                        $erroCfg ??= "Valor inválido em $k (de $min a $max).";
                    } else {
                        $novos[$k] = $k === 'DAILY_BUDGET_USD' ? number_format((float)$v, 2, '.', '') : (string)(int)$v;
                    }
                }
                foreach (['AGENDAMENTO_ATIVO', 'SITE_PRIVADO', 'ESCONDER_ENTRAR'] as $k) {
                    $novos[$k] = !empty($_POST[$k]) ? '1' : '0';
                }
                $novaSenha = (string)($_POST['senha_nova'] ?? '');
                if ($novaSenha !== '') {
                    if (!hash_equals(Config::str('ADMIN_PASSWORD'), (string)($_POST['senha_atual'] ?? ''))) {
                        $erroCfg ??= 'A senha atual não confere. Nada foi salvo.';
                    } elseif (mb_strlen($novaSenha) < 8) {
                        $erroCfg ??= 'A senha nova precisa de pelo menos 8 caracteres.';
                    } else {
                        $novos['ADMIN_PASSWORD'] = $novaSenha;
                    }
                }
                $erroCfg ??= Config::salvar(APP_DIR . '/.env', $novos);
                $msg = $erroCfg ?? 'Configurações salvas.';
                $msgErro = $erroCfg !== null;
                break;
            case 'limpar_erros':
                Db::q('DELETE FROM erros');
                $msg = 'Erros apagados.';
                break;
        }
    }
}

$hoje = date('Y-m-d');
$gDia = Custos::gastoDia();
$gMes = Custos::gastoMes();
$teto = Custos::tetoMicros();
$porTipo = Db::all('SELECT tipo, COUNT(*) AS n, SUM(micros) AS micros, SUM(itens) AS itens FROM custos WHERE data = ? GROUP BY tipo', [$hoje]);
$porDia = Db::all('SELECT data, SUM(micros) AS micros, COUNT(*) AS n FROM custos WHERE data >= ? GROUP BY data ORDER BY data DESC', [date('Y-m-d', strtotime('-30 days'))]);
$fila = Db::all('SELECT * FROM fila WHERE data = ? ORDER BY ' . Fila::ORDEM_SQL . ', id', [$hoje]);
$filaResumo = [];
foreach ($fila as $f) {
    $filaResumo[$f['tipo']][$f['status']] = ($filaResumo[$f['tipo']][$f['status']] ?? 0) + 1;
}
$erros = Db::all('SELECT * FROM erros ORDER BY id DESC LIMIT 20');
$execs = Db::all('SELECT * FROM execucoes ORDER BY id DESC LIMIT 10');
$nichos = Db::all('SELECT n.*, (SELECT COUNT(*) FROM anuncios a WHERE a.nicho_id = n.id) AS total FROM nichos n ORDER BY n.id');
$concorrentes = Db::all('SELECT c.*, m.status AS escala, m.ativos_atual, m.ativos_fonte FROM concorrentes c LEFT JOIN anunciantes m ON m.brand_id = c.brand_id ORDER BY c.id');
$totais = Db::one("SELECT (SELECT COUNT(*) FROM anuncios) AS anuncios, (SELECT COUNT(*) FROM anunciantes) AS anunciantes, (SELECT COUNT(*) FROM anunciantes WHERE tipo='info') AS info, (SELECT COUNT(*) FROM raiox) AS raiox");

layout_topo('Painel | Raio-X de Ofertas', 'admin');
?>
<div class="adm-topo">
  <h1>Painel</h1>
  <form method="post"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><button class="btn" name="acao" value="sair">Sair</button></form>
</div>
<?php if (!Aisa::temChave()): ?><p class="alerta">Nenhuma chave da AIsa salva. Cole a sua em <a href="#config">Configurações</a>, no fim da página.</p><?php endif; ?>
<?php if (!empty($_GET['bemvindo'])): ?><p class="aviso">Tudo salvo. Agora crie a Tarefa Cron (veja a página Saúde) ou clique em Rodar agora para a primeira coleta.</p><?php endif; ?>
<?php if ($msg): ?><p class="<?= $msgErro ? 'alerta' : 'aviso' ?>"><?= e($msg) ?></p><?php endif; ?>
<?php if (Db::getConfig('teto_' . $hoje)): ?><p class="alerta">O teto diário foi atingido hoje às <?= e(Db::getConfig('teto_' . $hoje)) ?>. O robô parou e volta amanhã.</p><?php endif; ?>

<section class="kpis-adm">
  <div class="painel kpi-c"><span class="mut">Gasto hoje</span><b><?= e(Custos::usd($gDia)) ?></b>
    <div class="barra"><i style="width:<?= min(100, $teto ? round($gDia * 100 / $teto) : 0) ?>%"></i></div>
    <span class="mut small">Teto diário: <?= e(Custos::usd($teto, 2)) ?></span></div>
  <div class="painel kpi-c"><span class="mut">Gasto no mês</span><b><?= e(Custos::usd($gMes)) ?></b><span class="mut small">Soma do custo real devolvido pela AIsa</span></div>
  <div class="painel kpi-c"><span class="mut">Banco</span><b><?= (int)$totais['anuncios'] ?> anúncios</b><span class="mut small"><?= (int)$totais['anunciantes'] ?> anunciantes, <?= (int)$totais['info'] ?> infoprodutos, <?= (int)$totais['raiox'] ?> Raio-X</span></div>
  <div class="painel kpi-c"><span class="mut">Última atualização</span><b><?= e(data_br(Db::getConfig('ultima_atualizacao'), true) ?: 'nunca') ?></b><span class="mut small">Banco: <?= e(Db::driver()) ?></span></div>
</section>

<section class="painel">
  <h2>Rodar a coleta agora</h2>
  <p class="small">Agendamento automático: <?= Config::int('AGENDAMENTO_ATIVO', 1) === 1 ? '<span class="st st-ok">ligado</span>' : '<span class="st st-pulado">desligado</span>' ?> <a class="small" href="#config">mudar</a></p>
  <p class="mut">Cada clique trabalha por até 25 segundos e continua a fila do dia de onde parou: primeiro a coleta dos nichos e dos concorrentes, depois a contagem de anúncios ativos, depois o Raio-X com IA. Clique de novo até a fila acabar.</p>
  <form method="post" class="linha-form">
    <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
    <label>Coletas por clique <input type="number" name="coletas" value="1" min="1" max="10"></label>
    <button class="btn btn-pri" name="acao" value="rodar">Rodar agora</button>
  </form>
  <?php if ($resultado): ?>
    <pre class="log"><?= e(($resultado['msg'] ?? ('Tarefas: ' . $resultado['jobs'] . ' em ' . $resultado['segundos'] . ' s')) . "\n" . implode("\n", $resultado['log'] ?? [])) ?></pre>
  <?php endif; ?>
</section>

<div class="adm-grid">
<section class="painel">
  <h2>Fila de hoje</h2>
  <?php if (!$fila): ?><p class="mut">A fila de hoje ainda não foi criada. Ela nasce na primeira execução do dia.</p><?php else: ?>
  <p class="mut small">
    <?php foreach ($filaResumo as $tipo => $sts): ?>
      <b><?= e($tipo) ?></b>: <?= e(implode(', ', array_map(fn($k, $v) => "$v $k", array_keys($sts), $sts))) ?>.
    <?php endforeach; ?>
  </p>
  <div class="tabela-rolagem"><table class="tabela">
    <thead><tr><th>Tarefa</th><th>Ref</th><th>Status</th><th>Detalhe</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($fila as $f): ?>
      <tr>
        <td><?= e($f['tipo']) ?></td>
        <td class="mono"><?= e(match ($f['tipo']) {
            'coleta' => (string)Db::val('SELECT nome FROM nichos WHERE id = ?', [(int)$f['ref']]),
            'concorrente', 'concorrente_busca' => (string)Db::val('SELECT nome FROM concorrentes WHERE id = ?', [(int)$f['ref']]),
            default => $f['ref'],
        }) ?></td>
        <td><span class="st st-<?= e($f['status']) ?>"><?= e($f['status']) ?></span></td>
        <td class="small"><?= e((string)$f['erro']) ?></td>
        <td><?php if (in_array($f['status'], ['erro', 'pulado'], true)): ?>
          <form method="post"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="id" value="<?= (int)$f['id'] ?>"><button class="btn btn-mini" name="acao" value="reabrir">Tentar de novo</button></form>
        <?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</section>

<section class="painel">
  <h2>Custo de hoje por tipo</h2>
  <?php if (!$porTipo): ?><p class="mut">Nenhuma chamada paga hoje.</p><?php else: ?>
  <table class="tabela">
    <thead><tr><th>Tipo</th><th>Chamadas</th><th>Itens</th><th>Custo</th></tr></thead>
    <tbody><?php foreach ($porTipo as $t): ?>
      <tr><td><?= e($t['tipo']) ?></td><td><?= (int)$t['n'] ?></td><td><?= (int)$t['itens'] ?></td><td><?= e(Custos::usd((int)$t['micros'], 4)) ?></td></tr>
    <?php endforeach; ?></tbody>
  </table>
  <?php endif; ?>
  <h2>Últimos 30 dias</h2>
  <table class="tabela">
    <thead><tr><th>Dia</th><th>Chamadas</th><th>Custo</th></tr></thead>
    <tbody><?php foreach ($porDia as $d): ?>
      <tr><td><?= e(data_br($d['data'])) ?></td><td><?= (int)$d['n'] ?></td><td><?= e(Custos::usd((int)$d['micros'], 3)) ?></td></tr>
    <?php endforeach; ?></tbody>
  </table>
</section>
</div>

<section class="painel">
  <h2>Nichos</h2>
  <p class="mut">O "termo de busca" é o que vai para a busca de anúncios. Ex.: nicho "renda extra", termo "renda extra". Desmarque "ativo" para pausar sem apagar.</p>
  <?php foreach ($nichos as $n): ?>
    <form method="post" id="fn<?= (int)$n['id'] ?>"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="id" value="<?= (int)$n['id'] ?>"></form>
  <?php endforeach; ?>
  <form method="post" id="fnovo"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"></form>
  <div class="tabela-rolagem"><table class="tabela nichos">
    <thead><tr><th>Nome</th><th>Termo de busca</th><th>Ativo</th><th>Anúncios</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($nichos as $n): $f = 'fn' . (int)$n['id']; ?>
      <tr>
        <td><input form="<?= $f ?>" name="nome" value="<?= e($n['nome']) ?>" required></td>
        <td><input form="<?= $f ?>" name="termo" value="<?= e($n['termo']) ?>" required></td>
        <td><input form="<?= $f ?>" type="checkbox" name="ativo" value="1" <?= $n['ativo'] ? 'checked' : '' ?>></td>
        <td><?= (int)$n['total'] ?></td>
        <td class="acoes"><button form="<?= $f ?>" class="btn btn-mini" name="acao" value="nicho_salvar">Salvar</button>
          <button form="<?= $f ?>" class="btn btn-mini btn-perigo" name="acao" value="nicho_apagar" onclick="return confirm('Remover este nicho?')">Remover</button></td>
      </tr>
    <?php endforeach; ?>
      <tr>
        <td><input form="fnovo" name="nome" placeholder="Novo nicho" required></td>
        <td><input form="fnovo" name="termo" placeholder="Termo (opcional)"></td>
        <td></td><td></td>
        <td><button form="fnovo" class="btn btn-mini btn-pri" name="acao" value="nicho_novo">Adicionar</button></td>
      </tr>
    </tbody>
  </table></div>
  <p class="mut small">Cada nicho ativo custa cerca de <?= e(Custos::usd((int)round(Config::int('ADS_PER_NICHE', 20) * Config::float('PRECO_ITEM_USD', 0.0175) * 1_000_000), 3)) ?> por dia (<?= Config::int('ADS_PER_NICHE', 20) ?> anúncios).</p>
</section>

<div class="adm-grid">
<section class="painel">
  <div class="adm-topo"><h2>Erros recentes</h2>
    <?php if ($erros): ?><form method="post"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><button class="btn btn-mini" name="acao" value="limpar_erros">Limpar</button></form><?php endif; ?></div>
  <?php if (!$erros): ?><p class="mut">Nenhum erro registrado.</p><?php else: ?>
  <ul class="erros"><?php foreach ($erros as $er): ?>
    <li><span class="mono small"><?= e(data_br($er['criado_em'], true)) ?> · <?= e($er['contexto']) ?></span><br><?= e($er['mensagem']) ?></li>
  <?php endforeach; ?></ul>
  <?php endif; ?>
</section>
<section class="painel">
  <h2>Execuções</h2>
  <table class="tabela">
    <thead><tr><th>Início</th><th>Origem</th><th>Tarefas</th><th>Tempo</th></tr></thead>
    <tbody><?php foreach ($execs as $x): ?>
      <tr title="<?= e($x['resumo']) ?>"><td><?= e(data_br($x['inicio'], true)) ?></td><td><?= e($x['origem']) ?></td><td><?= (int)$x['jobs'] ?></td><td><?= e(number_format((float)$x['segundos'], 1, ',', '.')) ?> s</td></tr>
    <?php endforeach; ?></tbody>
  </table>
</section>
</div>

<section class="painel" id="concorrentes">
  <div class="adm-topo"><h2>Concorrentes</h2><?php if ($concorrentes): ?><a class="btn btn-mini" href="concorrentes.php">Ver a página dos concorrentes</a><?php endif; ?></div>
  <?php if (!Concorrente::ativo()): ?><p class="alerta">O Modo Concorrente está desligado (MODO_CONCORRENTE=0 no .env).</p><?php endif; ?>
  <p class="mut">Anunciantes que você quer acompanhar todo dia, mesmo fora dos nichos. Digite o domínio do site deles, ou use o botão "Acompanhar como concorrente" na página de qualquer oferta.</p>
  <?php foreach ($concorrentes as $c): ?>
    <form method="post" id="fc<?= (int)$c['id'] ?>"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>"></form>
  <?php endforeach; ?>
  <?php if ($concorrentes): ?>
  <div class="tabela-rolagem"><table class="tabela">
    <thead><tr><th>Concorrente</th><th>Domínio</th><th>Situação</th><th>Última coleta</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($concorrentes as $c): $f = 'fc' . (int)$c['id']; $cands = json_decode((string)$c['candidatos'], true) ?: []; ?>
      <tr>
        <td><?php if ($c['status'] === 'ok' && $c['escala'] !== null): ?><a href="oferta.php?id=<?= e(urlencode((string)$c['brand_id'])) ?>"><?= e($c['nome']) ?></a><?php else: ?><?= e($c['nome']) ?><?php endif; ?>
          <?php if (count($cands) > 1): ?>
            <br><select form="<?= $f ?>" name="brand_id" class="small" aria-label="Marca encontrada">
              <?php foreach ($cands as $cd): ?><option value="<?= e($cd['brand_id']) ?>" <?= $cd['brand_id'] === $c['brand_id'] ? 'selected' : '' ?>><?= e($cd['nome'] ?: $cd['brand_id']) ?></option><?php endforeach; ?>
            </select> <button form="<?= $f ?>" class="btn btn-mini" name="acao" value="conc_trocar">Usar esta</button>
          <?php endif; ?></td>
        <td class="mono small"><?= e((string)$c['dominio']) ?></td>
        <td><?php if (!(int)$c['ativo']): ?><span class="st st-pulado">pausado</span>
          <?php elseif ($c['status'] === 'ok'): ?><span class="st st-ok">acompanhando</span>
          <?php elseif ($c['status'] === 'buscando'): ?><span class="st st-rodando">procurando a marca</span>
          <?php elseif ($c['status'] === 'nao_encontrado'): ?><span class="st st-pulado">não encontrado</span>
          <?php else: ?><span class="st st-erro">erro</span><?php endif; ?>
          <?php if ($c['erro']): ?><br><span class="small mut"><?= e($c['erro']) ?></span><?php endif; ?></td>
        <td class="small"><?= e(data_br($c['ultima_coleta'], true) ?: 'ainda não') ?></td>
        <td class="acoes">
          <?php if (in_array($c['status'], ['nao_encontrado', 'erro'], true) && !$c['brand_id']): ?><button form="<?= $f ?>" class="btn btn-mini" name="acao" value="conc_buscar">Buscar de novo</button><?php endif; ?>
          <button form="<?= $f ?>" class="btn btn-mini" name="acao" value="conc_pausar"><?= (int)$c['ativo'] ? 'Pausar' : 'Retomar' ?></button>
          <button form="<?= $f ?>" class="btn btn-mini btn-perigo" name="acao" value="conc_apagar" onclick="return confirm('Remover este concorrente?')">Remover</button>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
  <form method="post" class="linha-form">
    <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
    <input name="dominio" placeholder="dominio.com.br" required>
    <input name="nome" placeholder="Nome (opcional)">
    <button class="btn btn-pri" name="acao" value="conc_dominio">Adicionar concorrente</button>
  </form>
  <p class="mut small">Cada concorrente custa cerca de <?= e(Custos::usd((int)round(Config::int('CONCORRENTE_ADS', 20) * Config::float('PRECO_ITEM_USD', 0.0175) * 1_000_000), 3)) ?> por dia (<?= Config::int('CONCORRENTE_ADS', 20) ?> anúncios), mais 1 dia de contagem de ativos. Achar a marca pelo domínio custa até <?= e(Custos::usd((int)round(Config::int('CONCORRENTE_CANDIDATOS', 3) * Config::float('PRECO_ITEM_USD', 0.0175) * 1_000_000), 3)) ?>, uma vez só. Máximo de <?= Config::int('MAX_CONCORRENTES', 10) ?> concorrentes.</p>
</section>
<section class="painel" id="config">
  <h2>Configurações</h2>
  <p class="mut">Ficam guardadas no arquivo .env do servidor, fora da pasta pública.</p>
  <form method="post" class="form-grade">
    <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
    <label class="largo">Chave da AIsa <span class="mut small">(<?= e(chave_mascarada()) ?>)</span>
      <input type="password" name="chave" autocomplete="off" placeholder="Deixe vazio para manter a atual"></label>
    <label>Teto por dia (US$)<input name="DAILY_BUDGET_USD" value="<?= e(Config::str('DAILY_BUDGET_USD', '2.00')) ?>" inputmode="decimal" required></label>
    <label>Anúncios por nicho<input type="number" name="ADS_PER_NICHE" value="<?= Config::int('ADS_PER_NICHE', 20) ?>" min="1" max="250" required></label>
    <label>Anunciantes com contagem real<input type="number" name="ANALYTICS_TOP" value="<?= Config::int('ANALYTICS_TOP', 20) ?>" min="0" max="100" required></label>
    <label>Raio-X por dia<input type="number" name="RAIOX_POR_DIA" value="<?= Config::int('RAIOX_POR_DIA', 30) ?>" min="0" max="500" required></label>
    <fieldset class="largo opcoes">
      <legend>Acesso e agendamento</legend>
      <label class="check"><input type="checkbox" name="AGENDAMENTO_ATIVO" value="1" <?= Config::int('AGENDAMENTO_ATIVO', 1) === 1 ? 'checked' : '' ?>> <span><b>Agendamento automático</b><br><span class="mut small">Desmarcado, a Tarefa Cron não faz nada. O botão Rodar agora continua funcionando.</span></span></label>
      <label class="check"><input type="checkbox" name="SITE_PRIVADO" value="1" <?= Config::int('SITE_PRIVADO', 0) === 1 ? 'checked' : '' ?>> <span><b>Site só para mim</b><br><span class="mut small">As ofertas só abrem com login. Quem não está logado cai na tela de senha.</span></span></label>
      <label class="check"><input type="checkbox" name="ESCONDER_ENTRAR" value="1" <?= Config::int('ESCONDER_ENTRAR', 0) === 1 ? 'checked' : '' ?>> <span><b>Esconder o botão Entrar</b><br><span class="mut small">Some do menu. Você entra direto pelo endereço /admin.php.</span></span></label>
    </fieldset>
    <label>Senha atual <span class="mut small">(só para trocar a senha)</span><input type="password" name="senha_atual" autocomplete="current-password"></label>
    <label>Senha nova<input type="password" name="senha_nova" minlength="8" autocomplete="new-password"></label>
    <div class="largo"><button class="btn btn-pri" name="acao" value="config_salvar">Salvar configurações</button>
      <span class="mut small">Uma chave nova é testada na AIsa antes de salvar.</span></div>
  </form>
</section>
<?php layout_rodape();
