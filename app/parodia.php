<?php
/*
 * Concurso de paródias da CIPA (03/11/2026) — no mesmo sistema e banco da urna.
 *
 * Inscrição: o colaborador entra com a matrícula + a mesma confirmação da urna (cfg verificacao,
 * hoje os 3 primeiros dígitos do CPF) e cadastra equipe, música original e letra. Uma inscrição
 * por matrícula (o responsável pela equipe).
 * Comissão: usa o login da administração da urna (adminLogin); aprova ou não cada paródia,
 * indica os jurados, configura e acompanha a classificação.
 * Jurados: entram do mesmo jeito que o colaborador (matrícula + CPF), só veem as aprovadas
 * e dão nota (0 a 10, inteira, escolhida numa lista) em cada critério.
 */
declare(strict_types=1);

const PAR_STATUS = ['pendente' => 'Aguardando aprovação', 'aprovada' => 'Aprovada', 'recusada' => 'Não aprovada'];
const PAR_CRITERIOS = ['Letra e mensagem de segurança', 'Criatividade', 'Encaixe na melodia'];
const PAR_LETRA_MAX = 8000;
const PAR_FOTOS_MAX = 4;             // fotos da letra (papel), além ou no lugar da letra digitada
const PAR_FOTO_BYTES = 1500000;      // por foto, já reduzida no celular
const SESSAO_JURADO = 10 * 3600;
const PAR_URL_PUBLICA = 'https://parodia.redentorhub.com.br/';   // endereço dos links e QR Codes (muda na Configuração)

function par_tabelas(): PDO {
    static $feito = false;
    $p = db();
    if ($feito) return $p;
    $p->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS parodias (
            id INTEGER PRIMARY KEY AUTOINCREMENT, matricula TEXT NOT NULL, mnorm TEXT NOT NULL UNIQUE, responsavel TEXT NOT NULL,
            setor TEXT, equipe TEXT NOT NULL, equipe_norm TEXT NOT NULL UNIQUE, musica TEXT NOT NULL, artista TEXT NOT NULL,
            letra TEXT NOT NULL, integrantes TEXT, celular TEXT, status TEXT NOT NULL DEFAULT 'pendente', motivo TEXT,
            validado_por TEXT, validado_em TEXT, criada TEXT NOT NULL, atualizada TEXT NOT NULL);
        CREATE TABLE IF NOT EXISTS parodia_jurados (mnorm TEXT PRIMARY KEY, matricula TEXT NOT NULL, nome TEXT NOT NULL);
        CREATE TABLE IF NOT EXISTS parodia_notas (
            parodia INTEGER NOT NULL, jurado TEXT NOT NULL, notas TEXT NOT NULL, comentario TEXT, em TEXT NOT NULL,
            PRIMARY KEY (parodia, jurado));
        CREATE TABLE IF NOT EXISTS parodia_fotos (chave TEXT PRIMARY KEY, parodia INTEGER NOT NULL, ordem INTEGER NOT NULL, dados TEXT NOT NULL);
    SQL);
    $feito = true;
    return $p;
}

function par_cfg(): array {
    $c = kv_get('parodia', []) + ['titulo' => 'Concurso de Paródias da CIPA', 'tema' => 'Segurança no trabalho e no trânsito',
        'inscricoes' => 'sim', 'inicio' => '', 'fim' => '', 'criterios' => PAR_CRITERIOS, 'urlPublica' => PAR_URL_PUBLICA];
    if (!is_array($c['criterios']) || !$c['criterios']) $c['criterios'] = PAR_CRITERIOS;
    return $c;
}

/* endereço que vai nos links e QR Codes da comissão (o do próprio servidor se a comissão apagar) */
function url_publica(): string { return par_cfg()['urlPublica'] ?: url_base(); }

/* aberta | antes | depois | desabilitada */
function par_situacao(array $c): string {
    if ($c['inscricoes'] !== 'sim') return 'desabilitada';
    $n = agora_local();
    if ($c['inicio'] !== '' && $n < $c['inicio']) return 'antes';
    if ($c['fim'] !== '' && $n >= $c['fim']) return 'depois';
    return 'aberta';
}
function par_exige_aberta(array $c): void {
    switch (par_situacao($c)) {
        case 'aberta': return;
        case 'antes': falha('As inscrições de paródias abrem em ' . data_br($c['inicio']) . '.');
        case 'depois': falha('As inscrições de paródias foram encerradas em ' . data_br($c['fim']) . '.');
        default: falha('As inscrições de paródias estão encerradas.');
    }
}

function equipe_norm(string $s): string { return mb_strtolower(preg_replace('/\s+/u', ' ', trim($s))); }

/* nome de escala (apelido) de cada matrícula, da lista de colaboradores; vazio se não tiver */
function par_escala(string $mat): string {
    static $m = null;
    if ($m === null) { $m = []; foreach (db()->query("SELECT mnorm,escala FROM eleitores WHERE escala<>'' ORDER BY ordem") as $e) $m[$e['mnorm']] ??= trim((string)$e['escala']); }
    return $m[mat_norm($mat)] ?? '';
}
function par_linha(array $r): array {
    return ['id' => (int)$r['id'], 'matricula' => $r['matricula'], 'responsavel' => $r['responsavel'], 'escala' => par_escala($r['matricula']), 'setor' => (string)$r['setor'],
        'equipe' => $r['equipe'], 'musica' => $r['musica'], 'artista' => $r['artista'], 'letra' => $r['letra'],
        'integrantes' => (string)$r['integrantes'], 'celular' => (string)$r['celular'], 'status' => $r['status'],
        'motivo' => (string)$r['motivo'], 'validadoPor' => (string)$r['validado_por'], 'validadoEm' => (string)$r['validado_em'],
        'criada' => $r['criada'], 'atualizada' => $r['atualizada'], 'fotos' => par_fotos((int)$r['id'])];
}
/* fotos da letra: saem como /letra-foto/CHAVE (chave aleatória, a imagem não vai dentro do JSON) */
function par_fotos(int $id): array {
    $st = par_tabelas()->prepare('SELECT chave FROM parodia_fotos WHERE parodia=? ORDER BY ordem'); $st->execute([$id]);
    return array_map(fn($c) => 'letra-foto/' . $c, $st->fetchAll(PDO::FETCH_COLUMN));
}
/* confere a lista vinda do celular: "letra-foto/CHAVE" (já gravada) ou data:image/... (nova) */
function par_fotos_ok($fotos, ?int $id): array {
    $fotos = array_values(array_filter((array)$fotos, 'is_string'));
    if (count($fotos) > PAR_FOTOS_MAX) falha('Mande no máximo ' . PAR_FOTOS_MAX . ' fotos da letra.');
    $tem = $id ? array_map(fn($u) => substr($u, 11), par_fotos($id)) : [];
    $ok = [];
    foreach ($fotos as $f) {
        if (preg_match('#^letra-foto/([0-9a-f]{32})$#', $f, $m)) { if (in_array($m[1], $tem, true)) $ok[] = ['chave' => $m[1]]; continue; }
        if (!preg_match('#^data:image/(jpeg|png|webp);base64,([A-Za-z0-9+/=]+)$#', $f, $m)) falha('Foto da letra inválida. Tire a foto de novo.');
        $bin = base64_decode($m[2], true);
        if ($bin === false || strlen($bin) > PAR_FOTO_BYTES || !@getimagesizefromstring($bin)) falha('Foto da letra muito grande ou com defeito. Tire a foto de novo.');
        $ok[] = ['dados' => $f];
    }
    return $ok;
}
function par_da_matricula($m): ?array {
    $st = par_tabelas()->prepare('SELECT * FROM parodias WHERE mnorm=?'); $st->execute([mat_norm($m)]);
    $r = $st->fetch();
    return $r ? par_linha($r) : null;
}
/* pode mexer na própria inscrição enquanto as inscrições estão abertas e a comissão ainda não decidiu */
function par_pode_editar(?array $p, array $c): bool {
    if (!$p) return par_situacao($c) === 'aberta';
    return $p['status'] === 'pendente' && par_situacao($c) === 'aberta';
}

/* =================== colaborador =================== */

function api_parInfo(): array {
    $c = par_cfg(); $v = cfg()['verificacao'] ?: 'nenhuma';
    return ['titulo' => $c['titulo'], 'tema' => $c['tema'], 'inicio' => $c['inicio'], 'fim' => $c['fim'],
            'situacao' => par_situacao($c), 'verificacao' => $v, 'verificacaoLbl' => VERIF[$v] ?? '',
            'empresa' => cfg()['empresa'], 'criterios' => $c['criterios']];
}

function api_parConsultar($m, $c = ''): array {
    if (mat_norm($m) === '') falha('Digite sua matrícula.');
    $e = eleitor_por_mat($m);
    if (!$e) falha('Matrícula não encontrada no cadastro de colaboradores. Procure a comissão da CIPA.');
    confere($e, $c);
    $cfg = par_cfg(); $p = par_da_matricula($m);
    if (!$p) par_exige_aberta($cfg);
    return ['matricula' => $e['matricula'], 'nome' => $e['nome'], 'setor' => (string)$e['setor'],
            'parodia' => $p, 'podeEditar' => par_pode_editar($p, $cfg)];
}

function api_parSalvar($m, $c, $d, $aceite = false): array {
    if ($aceite !== true) falha('Marque a declaração de que a letra é da sua equipe para enviar a inscrição.');
    $r = api_parConsultar($m, $c);   // confirmação fora da transação (o erro conta como tentativa)
    if (!$r['podeEditar']) falha($r['parodia']['status'] === 'pendente'
        ? 'As inscrições foram encerradas: a sua paródia já está com a comissão.'
        : 'A comissão já decidiu sobre esta paródia (' . mb_strtolower(PAR_STATUS[$r['parodia']['status']]) . '): ela não pode mais ser alterada.');
    $d = (array)$d;
    $equipe = txt($d['equipe'] ?? '', 80); $musica = txt($d['musica'] ?? '', 120); $artista = txt($d['artista'] ?? '', 120);
    $letra = trim(str_replace("\r\n", "\n", (string)($d['letra'] ?? '')));
    $integ = txt(str_replace("\r\n", "\n", (string)($d['integrantes'] ?? '')), 1500);
    $cel = txt(preg_replace('/[^\d()+\- ]/', '', (string)($d['celular'] ?? '')), 20);
    if (mb_strlen($equipe) < 2) falha('Informe o nome da equipe.');
    if (mb_strlen($musica) < 2) falha('Informe o nome da música original.');
    if (mb_strlen($artista) < 2) falha('Informe o cantor ou banda da música original.');
    // a letra é opcional (a equipe pode apresentar sem mandar por escrito)
    if (mb_strlen($letra) > PAR_LETRA_MAX) falha('A letra passou de ' . PAR_LETRA_MAX . ' caracteres. Resuma um pouco.');
    $fotos = array_key_exists('fotos', $d) ? par_fotos_ok($d['fotos'], $r['parodia']['id'] ?? null) : null;   // sem a chave: mantém as fotos
    $p = par_tabelas(); $en = equipe_norm($equipe); $agora = agora();
    $p->exec('BEGIN IMMEDIATE');
    try {
        $st = $p->prepare('SELECT responsavel FROM parodias WHERE equipe_norm=? AND mnorm<>?'); $st->execute([$en, mat_norm($m)]);
        if ($dono = $st->fetchColumn()) falha("Já existe uma equipe chamada “{$equipe}”. Escolha outro nome.");
        $ja = $r['parodia'];
        if ($ja) {
            $p->prepare("UPDATE parodias SET equipe=?, equipe_norm=?, musica=?, artista=?, letra=?, integrantes=?, celular=?,
                         status='pendente', atualizada=? WHERE id=?")
              ->execute([$equipe, $en, $musica, $artista, $letra, $integ, $cel, $agora, $ja['id']]);
            $id = $ja['id'];
        } else {
            $p->prepare("INSERT INTO parodias(matricula,mnorm,responsavel,setor,equipe,equipe_norm,musica,artista,letra,integrantes,celular,status,criada,atualizada)
                         VALUES(?,?,?,?,?,?,?,?,?,?,?,'pendente',?,?)")
              ->execute([$r['matricula'], mat_norm($r['matricula']), $r['nome'], $r['setor'], $equipe, $en, $musica, $artista, $letra, $integ, $cel, $agora, $agora]);
            $id = (int)$p->lastInsertId();
        }
        if ($fotos !== null) {
            $fica = array_filter(array_column($fotos, 'chave'));
            $del = $p->prepare('DELETE FROM parodia_fotos WHERE parodia=? AND chave NOT IN (' . implode(',', array_fill(0, count($fica), '?') ?: ["''"]) . ')');
            $del->execute(array_merge([$id], $fica));
            foreach ($fotos as $k => $f) {
                if (isset($f['chave'])) $p->prepare('UPDATE parodia_fotos SET ordem=? WHERE chave=?')->execute([$k, $f['chave']]);
                else $p->prepare('INSERT INTO parodia_fotos(chave,parodia,ordem,dados) VALUES(?,?,?,?)')->execute([bin2hex(random_bytes(16)), $id, $k, $f['dados']]);
            }
        }
        registrar($ja ? 'Paródia alterada' : 'Paródia inscrita',
            "Equipe $equipe · “{$musica}” ({$artista}) · {$r['matricula']} – {$r['nome']}", 'Colaborador');
        $p->exec('COMMIT');
    } catch (Throwable $e) { $p->exec('ROLLBACK'); throw $e; }
    return ['id' => $id, 'equipe' => $equipe, 'nome' => $r['nome'], 'alterou' => (bool)$ja];
}

/* =================== jurados =================== */

function jurado_por_mat($m): ?array {
    $st = par_tabelas()->prepare('SELECT * FROM parodia_jurados WHERE mnorm=?'); $st->execute([mat_norm($m)]);
    return $st->fetch() ?: null;
}
function exige_jurado($t): array {
    $mn = sessao('jurado', $t);
    $j = $mn === null ? null : jurado_por_mat($mn);
    if (!$j) falha('Sessão do jurado expirada. Entre novamente.');
    db()->prepare('UPDATE sessoes SET expira=? WHERE token=?')->execute([time() + SESSAO_JURADO, $t]);
    return $j;
}

function api_parJuradoLogin($m, $c): array {
    if (mat_norm($m) === '') falha('Digite sua matrícula.');
    $e = eleitor_por_mat($m);
    if (!$e) falha('Matrícula não encontrada no cadastro de colaboradores.');
    confere($e, $c);
    $j = jurado_por_mat($m);
    if (!$j) falha('Esta matrícula não está na lista de jurados do concurso. Procure a comissão da CIPA.');
    registrar('Acesso de jurado', $j['matricula'] . ' – ' . $j['nome'] . ' · ' . ip(), 'Jurado');
    return ['token' => sessao_nova('jurado', $j['mnorm'], SESSAO_JURADO), 'nome' => $j['nome']];
}

function par_lista(): array {
    return array_map('par_linha', par_tabelas()->query('SELECT * FROM parodias ORDER BY id')->fetchAll());
}

/* o jurado só vê as aprovadas pela comissão (sem celular e sem o motivo das decisões) */
function api_parJuradoDados($t): array {
    $j = exige_jurado($t);
    $minhas = [];
    $st = par_tabelas()->prepare('SELECT parodia,notas,comentario FROM parodia_notas WHERE jurado=?'); $st->execute([$j['mnorm']]);
    foreach ($st as $n) $minhas[(int)$n['parodia']] = ['notas' => json_decode($n['notas'], true), 'comentario' => (string)$n['comentario']];
    $lista = array_map(function ($p) use ($minhas, $j) {
        unset($p['celular'], $p['motivo'], $p['validadoPor'], $p['validadoEm']);
        return $p + ['minhaNota' => $minhas[$p['id']] ?? null, 'propria' => mat_norm($p['matricula']) === $j['mnorm']];
    }, array_values(array_filter(par_lista(), fn($p) => $p['status'] === 'aprovada')));
    return ['jurado' => ['matricula' => $j['matricula'], 'nome' => $j['nome']], 'cfg' => api_parInfo(), 'parodias' => $lista];
}

function par_busca(int $id): array {
    $st = par_tabelas()->prepare('SELECT * FROM parodias WHERE id=?'); $st->execute([$id]);
    $r = $st->fetch();
    if (!$r) falha('Paródia não encontrada.');
    return par_linha($r);
}

function api_parNota($t, $id, $notas, $comentario = ''): array {
    $j = exige_jurado($t);
    $p = par_busca((int)$id);
    if (mat_norm($p['matricula']) === $j['mnorm']) falha('Você não pode dar nota à sua própria equipe.');
    if ($p['status'] !== 'aprovada') falha('Só dá para dar nota a paródias aprovadas.');
    $crit = par_cfg()['criterios']; $notas = array_values((array)$notas);
    if (count($notas) !== count($crit)) falha('Dê uma nota para cada critério.');
    $limpas = [];
    foreach ($notas as $i => $n) {
        if (!is_numeric($n) || $n < 0 || $n > 10 || (float)$n != (int)$n) falha('Escolha uma nota de 0 a 10 (' . $crit[$i] . ').');
        $limpas[] = (int)$n;
    }
    par_tabelas()->prepare('INSERT INTO parodia_notas(parodia,jurado,notas,comentario,em) VALUES(?,?,?,?,?)
        ON CONFLICT(parodia,jurado) DO UPDATE SET notas=excluded.notas, comentario=excluded.comentario, em=excluded.em')
        ->execute([$p['id'], $j['mnorm'], json_encode($limpas), txt($comentario, 400), agora()]);
    registrar('Nota de jurado', "Equipe {$p['equipe']} · total " . array_sum($limpas), 'Jurado ' . $j['nome']);
    return api_parJuradoDados($t);
}

/* =================== comissão =================== */

function par_admin_dados(): array {
    $p = par_tabelas(); $crit = par_cfg()['criterios'];
    $jur = $p->query('SELECT matricula,nome,mnorm FROM parodia_jurados ORDER BY nome')->fetchAll();
    foreach ($jur as &$j) { $j['escala'] = par_escala($j['matricula']); $j['apelido'] = $j['escala'] !== '' ? $j['escala'] : $j['nome']; } unset($j);
    $nomes = array_column($jur, 'apelido', 'mnorm');
    $notas = []; $deu = [];
    foreach ($p->query('SELECT parodia,jurado,notas,comentario,em FROM parodia_notas') as $n) {
        $notas[(int)$n['parodia']][] = ['jurado' => $nomes[$n['jurado']] ?? $n['jurado'], 'notas' => json_decode($n['notas'], true),
            'comentario' => (string)$n['comentario'], 'em' => $n['em']];
        $deu[(int)$n['parodia']][$n['jurado']] = true;
    }
    // acompanhamento: quem ainda falta votar em cada aprovada (o responsável pela equipe não vota nela)
    $avaliou = array_fill_keys(array_column($jur, 'mnorm'), 0); $deve = $avaliou;
    $lista = array_map(function ($x) use ($notas, $deu, $jur, &$avaliou, &$deve) {
        $ns = $notas[$x['id']] ?? [];
        $tot = array_map(fn($n) => array_sum($n['notas']), $ns);
        $faltam = [];
        if ($x['status'] === 'aprovada') foreach ($jur as $j) {
            if ($j['mnorm'] === mat_norm($x['matricula'])) continue;
            $deve[$j['mnorm']]++;
            if (isset($deu[$x['id']][$j['mnorm']])) $avaliou[$j['mnorm']]++; else $faltam[] = $j['apelido'];
        }
        return $x + ['notas' => $ns, 'faltam' => $faltam, 'media' => $tot ? round(array_sum($tot) / count($tot), 2) : null];
    }, par_lista());
    return ['cfg' => par_cfg(), 'situacao' => par_situacao(par_cfg()), 'criterios' => $crit,
            'jurados' => array_map(fn($j) => ['matricula' => $j['matricula'], 'nome' => $j['nome'], 'escala' => $j['escala'],
                'avaliou' => $avaliou[$j['mnorm']], 'deve' => $deve[$j['mnorm']]], $jur),
            'parodias' => $lista, 'agora' => agora_local(), 'urlApp' => url_publica()];
}

/* aprovar | recusar (não aprovar, motivo opcional, a equipe vê) | reabrir (volta para aguardando) */
function api_parValidar($t, $id, $acao, $motivo = ''): array {
    exige_admin($t);
    $p = par_busca((int)$id);
    $map = ['aprovar' => 'aprovada', 'recusar' => 'recusada', 'reabrir' => 'pendente'];
    $novo = $map[(string)$acao] ?? falha('Ação inválida.');
    $motivo = $novo === 'recusada' ? txt($motivo, 400) : '';
    if ($novo !== 'aprovada') par_tabelas()->prepare('DELETE FROM parodia_notas WHERE parodia=?')->execute([$p['id']]);   // nota só vale para aprovada
    par_tabelas()->prepare('UPDATE parodias SET status=?, motivo=?, validado_por=?, validado_em=? WHERE id=?')
        ->execute([$novo, $motivo, 'Comissão', agora(), $p['id']]);
    registrar('Paródia: ' . mb_strtolower(PAR_STATUS[$novo]), "Equipe {$p['equipe']}" . ($motivo !== '' ? " · $motivo" : ''));
    return par_admin_dados();
}

function api_parAdmin($t): array { exige_admin($t); return par_admin_dados(); }

function api_parSaveCfg($t, $c): array {
    exige_admin($t); $c = (array)$c; $cfg = par_cfg();
    foreach (['titulo' => 120, 'tema' => 200] as $k => $max) if (isset($c[$k])) $cfg[$k] = txt($c[$k], $max) ?: $cfg[$k];
    if (isset($c['urlPublica'])) {
        $u = trim((string)$c['urlPublica']);
        if ($u !== '' && !preg_match('#^https?://[a-z0-9.-]+(:\d+)?(/.*)?$#i', $u)) falha('Endereço inválido. Ex.: https://parodia.redentorhub.com.br/');
        $cfg['urlPublica'] = $u === '' ? '' : rtrim($u, '/') . '/';
    }
    if (in_array($c['inscricoes'] ?? null, ['sim', 'nao'], true)) $cfg['inscricoes'] = $c['inscricoes'];
    $ini = data_ok($c['inicio'] ?? $cfg['inicio']); $fim = data_ok($c['fim'] ?? $cfg['fim']);
    if ($ini !== '' && $fim !== '' && $fim <= $ini) falha('O fim das inscrições precisa ser depois do início.');
    $cfg['inicio'] = $ini; $cfg['fim'] = $fim;
    if (isset($c['criterios'])) {
        $cr = array_values(array_filter(array_map(fn($x) => txt($x, 60), (array)$c['criterios'])));
        if (!$cr || count($cr) > 6) falha('Use de 1 a 6 critérios de nota.');
        if ($cr !== $cfg['criterios']) {
            $n = (int)par_tabelas()->query('SELECT COUNT(*) FROM parodia_notas')->fetchColumn();
            if ($n) falha("Os jurados já deram $n nota(s): os critérios não podem mais mudar.");
        }
        $cfg['criterios'] = $cr;
    }
    kv_set('parodia', $cfg);
    registrar('Concurso de paródias configurado', ($cfg['inscricoes'] === 'sim' ? 'Inscrições habilitadas' : 'Inscrições desabilitadas') .
        ($cfg['inicio'] || $cfg['fim'] ? ' de ' . (data_br($cfg['inicio']) ?: '—') . ' a ' . (data_br($cfg['fim']) ?: '—') : ''));
    return par_admin_dados();
}

function api_parJurado($t, $m, $remover = false): array {
    exige_admin($t);
    $p = par_tabelas();
    if ($remover) {
        $j = jurado_por_mat($m);
        if (!$j) falha('Jurado não encontrado.');
        $p->prepare('DELETE FROM parodia_jurados WHERE mnorm=?')->execute([$j['mnorm']]);
        $p->prepare("DELETE FROM sessoes WHERE tipo='jurado' AND ref=?")->execute([$j['mnorm']]);
        registrar('Jurado removido do concurso de paródias', $j['matricula'] . ' – ' . $j['nome']);
        return par_admin_dados();
    }
    $e = eleitor_por_mat($m);
    if (!$e) falha('Matrícula não encontrada no cadastro de colaboradores.');
    if (jurado_por_mat($m)) falha($e['nome'] . ' já é jurado.');
    $p->prepare('INSERT INTO parodia_jurados(mnorm,matricula,nome) VALUES(?,?,?)')->execute([mat_norm($e['matricula']), $e['matricula'], $e['nome']]);
    registrar('Jurado incluído no concurso de paródias', $e['matricula'] . ' – ' . $e['nome']);
    return par_admin_dados();
}

function api_parExcluir($t, $id): array {
    exige_admin($t);
    $x = par_busca((int)$id);
    $p = par_tabelas();
    $p->prepare('DELETE FROM parodia_notas WHERE parodia=?')->execute([$x['id']]);
    $p->prepare('DELETE FROM parodia_fotos WHERE parodia=?')->execute([$x['id']]);
    $p->prepare('DELETE FROM parodias WHERE id=?')->execute([$x['id']]);
    registrar('Paródia excluída pela comissão', "Equipe {$x['equipe']} · {$x['matricula']} – {$x['responsavel']}");
    return par_admin_dados();
}
