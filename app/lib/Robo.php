<?php
declare(strict_types=1);

/**
 * Orquestra o dia em 3 fases pela fila: coleta por nicho e dos concorrentes, analytics dos maiores anunciantes, Raio-X com IA.
 * Cada execucao trabalha por poucos segundos e termina; a proxima continua de onde parou.
 */
final class Robo
{
    public static function semearNichos(): void
    {
        if ((int)Db::val('SELECT COUNT(*) FROM nichos') > 0) {
            return;
        }
        foreach (array_filter(array_map('trim', explode(',', Config::str('NICHOS', 'emagrecimento,renda extra')))) as $n) {
            Db::q('INSERT INTO nichos (nome, termo, ativo, criado_em) VALUES (?,?,1,?)', [$n, $n, date('Y-m-d H:i:s')]);
        }
    }

    public static function rodar(string $origem, ?int $tempoMax = null, ?int $maxColetas = null): array
    {
        $inicio = microtime(true);
        $tempoMax ??= Config::int('TEMPO_MAX_EXECUCAO', 25);
        $maxColetas ??= Config::int('COLETAS_POR_EXECUCAO', 1);
        @set_time_limit($tempoMax + 120);

        $lock = fopen(DATA_DIR . '/robo.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            return ['ok' => false, 'msg' => 'Outra execucao ja esta rodando.'];
        }

        $log = [];
        $jobs = 0;
        $coletas = 0;
        try {
            if (!Aisa::temChave()) {
                throw new RuntimeException('AISA_API_KEY vazia no .env.');
            }
            self::semearNichos();
            Fila::limparTravados();
            $hoje = date('Y-m-d');

            if (Db::getConfig('teto_' . $hoje)) {
                $log[] = 'Teto diario ja atingido hoje. Nada a fazer.';
                return self::fim($inicio, $jobs, $origem, $log);
            }

            if (!Db::getConfig('fase1_' . $hoje)) {
                foreach (Db::all('SELECT id FROM nichos WHERE ativo = 1 ORDER BY id') as $n) {
                    Fila::adicionar('coleta', (string)$n['id']);
                }
                Concorrente::enfileirarDia();
                Db::setConfig('fase1_' . $hoje, date('H:i:s'));
                $log[] = 'Fila do dia criada.';
            }

            while (microtime(true) - $inicio < $tempoMax) {
                $job = Fila::proximo();
                if ($job === null) {
                    if (Fila::pendentes('coleta') + Fila::pendentes('concorrente_busca') + Fila::pendentes('concorrente') === 0 && !Db::getConfig('fase2_' . $hoje)) {
                        Escala::recalcularTodos();
                        foreach (Analytics::escolherTop() as $b) {
                            Fila::adicionar('analytics', $b);
                        }
                        Db::setConfig('fase2_' . $hoje, date('H:i:s'));
                        $log[] = 'Coleta terminada. Analytics enfileirado.';
                        continue;
                    }
                    if (Fila::pendentes('analytics') === 0 && !Db::getConfig('fase3_' . $hoje)) {
                        Escala::recalcularTodos();
                        $feitos = (int)Db::val("SELECT COUNT(*) FROM fila WHERE tipo = 'raiox' AND data = ? AND status = 'ok'", [$hoje]);
                        foreach (RaioX::pendentes(Config::int('RAIOX_POR_DIA', 30) - $feitos) as $ad) {
                            Fila::adicionar('raiox', $ad);
                        }
                        Db::setConfig('fase3_' . $hoje, date('H:i:s'));
                        $log[] = 'Raio-X enfileirado.';
                        continue;
                    }
                    break;
                }
                $ehColeta = in_array($job['tipo'], ['coleta', 'concorrente_busca', 'concorrente'], true);
                if ($ehColeta && $coletas >= $maxColetas) {
                    break;
                }

                Fila::marcar((int)$job['id'], 'rodando');
                $res = self::executar($job);
                $jobs++;
                if ($ehColeta) {
                    $coletas++;
                }

                if (!empty($res['teto'])) {
                    Fila::marcar((int)$job['id'], 'pulado', $res['erro']);
                    Db::setConfig('teto_' . $hoje, date('H:i:s'));
                    Db::erro('teto', $res['erro'] . ' Gasto hoje: ' . Custos::usd(Custos::gastoDia()));
                    $log[] = $res['erro'];
                    break;
                }
                if ($res['ok']) {
                    Fila::marcar((int)$job['id'], 'ok', isset($res['pulado']) ? 'pulado: ' . $res['pulado'] : null);
                } else {
                    Fila::marcar((int)$job['id'], 'erro', $res['erro']);
                    Db::erro($job['tipo'] . ':' . $job['ref'], $res['erro']);
                    // 401 ou 402 afetam tudo: para a execucao em vez de gastar tentativas.
                    if (in_array($res['http'] ?? 0, [401, 402, 429], true)) {
                        $log[] = 'Parado: ' . $res['erro'];
                        break;
                    }
                }
                $log[] = self::descrever($job, $res);
            }

            Escala::recalcularTodos();
            if (Db::getConfig('fase3_' . $hoje) && (int)Db::val("SELECT COUNT(*) FROM fila WHERE data = ? AND status IN ('pendente','rodando')", [$hoje]) === 0) {
                Db::setConfig('ultima_atualizacao', date('Y-m-d H:i:s'));
                $log[] = 'Dia completo.';
            }
        } catch (Throwable $e) {
            Db::erro('robo', $e->getMessage());
            $log[] = 'Erro: ' . $e->getMessage();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        return self::fim($inicio, $jobs, $origem, $log);
    }

    private static function executar(array $job): array
    {
        try {
            switch ($job['tipo']) {
                case 'coleta':
                    $n = Db::one('SELECT * FROM nichos WHERE id = ?', [(int)$job['ref']]);
                    return $n ? Coletor::coletarNicho($n) : ['ok' => true, 'pulado' => 'nicho removido'];
                case 'concorrente_busca':
                    return Concorrente::buscarMarca((int)$job['ref']);
                case 'concorrente':
                    return Concorrente::coletar((int)$job['ref']);
                case 'analytics':
                    return Analytics::atualizarMarca($job['ref']);
                case 'raiox':
                    return RaioX::analisar($job['ref']);
            }
            return ['ok' => false, 'erro' => 'Tipo de tarefa desconhecido'];
        } catch (Throwable $e) {
            return ['ok' => false, 'erro' => $e->getMessage()];
        }
    }

    private static function descrever(array $job, array $r): string
    {
        $s = $job['tipo'] . ' ' . $job['ref'] . ': ' . ($r['ok'] ? 'ok' : 'erro');
        foreach (['recebidos', 'unicos', 'novos', 'linhas', 'pulado'] as $k) {
            if (isset($r[$k])) {
                $s .= " $k=" . $r[$k];
            }
        }
        if (isset($r['micros'])) {
            $s .= ' custo=' . Custos::usd((int)$r['micros'], 4);
        }
        return $r['ok'] ? $s : $s . ' (' . $r['erro'] . ')';
    }

    private static function fim(float $inicio, int $jobs, string $origem, array $log): array
    {
        $seg = round(microtime(true) - $inicio, 2);
        Db::q(
            'INSERT INTO execucoes (inicio, fim, segundos, jobs, origem, resumo) VALUES (?,?,?,?,?,?)',
            [date('Y-m-d H:i:s', (int)$inicio), date('Y-m-d H:i:s'), $seg, $jobs, $origem, implode("\n", $log)]
        );
        return ['ok' => true, 'segundos' => $seg, 'jobs' => $jobs, 'log' => $log];
    }
}
