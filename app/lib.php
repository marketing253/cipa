<?php
/*
 * Urna CIPA — servidor (PHP + SQLite)
 *
 * Mesmas regras da versão de demonstração que roda no navegador (demoApi em urna.html),
 * agora com os dados num banco único em /data. Cada função pública abaixo corresponde a
 * uma chamada do front-end; api.php só despacha.
 *
 * Sigilo: a tabela votos guarda apenas {id aleatório, número}, sem matrícula nem hora,
 * e é WITHOUT ROWID — a ordem física segue o id sorteado, não a ordem de chegada.
 */
declare(strict_types=1);

final class Erro extends RuntimeException {}

function falha(string $msg): never { throw new Erro($msg); }

const VERIF = ['nenhuma' => '', 'nascimento' => 'Data de nascimento', 'cpf' => '4 últimos dígitos do CPF', 'codigo' => 'Código de acesso'];
const SESSAO_ELEITOR = 20 * 60;
const SESSAO_ADMIN = 10 * 3600;
const FOTO_MAX = 600000; // bytes do data URL (a tela já reduz para 240x300 JPEG, ~20 KB)

function pasta_dados(): string {
    $d = getenv('CIPA_DATA') ?: dirname(__DIR__) . '/data';
    if (!is_dir($d)) mkdir($d, 0750, true);
    return $d;
}

function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $pdo = new PDO('sqlite:' . pasta_dados() . '/urna.sqlite', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA journal_mode=WAL');
    $pdo->exec('PRAGMA busy_timeout=10000');
    $pdo->exec('PRAGMA synchronous=FULL');
    $pdo->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS kv (k TEXT PRIMARY KEY, v TEXT NOT NULL);
        CREATE TABLE IF NOT EXISTS candidatos (
            numero TEXT PRIMARY KEY, matricula TEXT, nome TEXT NOT NULL, escala TEXT, cargo TEXT,
            setor TEXT, admissao TEXT, foto TEXT, origem TEXT);
        CREATE TABLE IF NOT EXISTS eleitores (
            matricula TEXT PRIMARY KEY, mnorm TEXT NOT NULL, ordem INTEGER NOT NULL, nome TEXT NOT NULL,
            escala TEXT, cargo TEXT, setor TEXT, codigo TEXT, votou INTEGER NOT NULL DEFAULT 0,
            votou_em TEXT, comprovante TEXT);
        CREATE INDEX IF NOT EXISTS eleitores_mnorm ON eleitores(mnorm);
        CREATE TABLE IF NOT EXISTS votos (id TEXT PRIMARY KEY, voto TEXT NOT NULL) WITHOUT ROWID;
        CREATE TABLE IF NOT EXISTS log (id INTEGER PRIMARY KEY AUTOINCREMENT, em TEXT, usuario TEXT, acao TEXT, detalhe TEXT);
        CREATE TABLE IF NOT EXISTS sessoes (token TEXT PRIMARY KEY, tipo TEXT NOT NULL, ref TEXT, expira INTEGER NOT NULL);
        CREATE TABLE IF NOT EXISTS fotos (token TEXT PRIMARY KEY, foto TEXT, expira INTEGER NOT NULL);
        CREATE TABLE IF NOT EXISTS falhas (ip TEXT, em INTEGER);
    SQL);
    // auditoria (08/10/2026): de onde cada matrícula votou — nunca em quem
    $cols = array_column($pdo->query('PRAGMA table_info(eleitores)')->fetchAll(), 'name');
    foreach (['voto_ip' => 'TEXT', 'voto_disp' => 'TEXT', 'voto_aparelho' => 'TEXT', 'voto_totem' => 'INTEGER'] as $c => $tipo)
        if (!in_array($c, $cols, true)) $pdo->exec("ALTER TABLE eleitores ADD COLUMN $c $tipo");
    return $pdo;
}

/* ---------- utilidades ---------- */
function agora(): string { return date('c'); }
function rand_code(int $n): string {
    $a = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; $s = '';
    for ($i = 0; $i < $n; $i++) $s .= $a[random_int(0, strlen($a) - 1)];
    return $s;
}
function token(): string { return bin2hex(random_bytes(24)); }
function mat_norm($m): string { return ltrim(preg_replace('/\D/', '', (string)$m), '0'); }
function pad2($n): string { $n = preg_replace('/\D/', '', (string)$n); return strlen($n) < 2 ? '0' . $n : $n; }
function txt($v, int $max = 200): string { return mb_substr(trim((string)($v ?? '')), 0, $max); }
function pct(int $a, int $b): float { return $b ? $a / $b * 100 : 0.0; }

function conf_norm(string $mode, $v): string {
    $v = trim((string)($v ?? ''));
    if ($mode === 'nascimento') {
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $v, $m)) return $m[1] . pad2($m[2]) . pad2($m[3]);
        if (preg_match('#^(\d{1,2})[/.\-](\d{1,2})[/.\-](\d{2,4})$#', $v, $m)) {
            $y = strlen($m[3]) === 2 ? (((int)$m[3] > 30 ? '19' : '20') . $m[3]) : $m[3];
            return $y . pad2($m[2]) . pad2($m[1]);
        }
        return preg_replace('/\D/', '', $v);
    }
    if ($mode === 'cpf') return substr(preg_replace('/\D/', '', $v), -4);
    return mb_strtoupper($v);
}

function foto_ok($f): string {
    $f = (string)($f ?? '');
    if ($f === '') return '';
    if (strlen($f) > FOTO_MAX) falha('Foto grande demais. Tente outra imagem.');
    if (!preg_match('#^data:image/(jpeg|png|webp);base64,[A-Za-z0-9+/=]+$#', $f)) falha('Formato de foto inválido.');
    return $f;
}

function url_base(): string {
    $https = ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' || (($_SERVER['HTTPS'] ?? '') && $_SERVER['HTTPS'] !== 'off');
    $host = preg_replace('/[^A-Za-z0-9.\-:]/', '', $_SERVER['HTTP_HOST'] ?? 'localhost');
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return ($https ? 'https' : 'http') . '://' . $host . $dir . '/';
}

/* ---------- configuração ---------- */
function kv_get(string $k, $padrao = null) {
    $st = db()->prepare('SELECT v FROM kv WHERE k=?'); $st->execute([$k]);
    $v = $st->fetchColumn();
    return $v === false ? $padrao : json_decode($v, true);
}
function kv_set(string $k, $v): void {
    db()->prepare('INSERT INTO kv(k,v) VALUES(?,?) ON CONFLICT(k) DO UPDATE SET v=excluded.v')
        ->execute([$k, json_encode($v, JSON_UNESCAPED_UNICODE)]);
}
function cfg(): array {
    return kv_get('cfg', []) + [
        'titulo' => 'Eleição CIPA 2026', 'gestao' => 'Gestão 2026/2027', 'empresa' => 'Auto Viação Redentor',
        'cnpj' => '', 'estabelecimento' => 'Matriz', 'vagasTitulares' => 4, 'vagasSuplentes' => 4,
        'verificacao' => 'nenhuma', 'inscricoes' => 'sim', 'status' => 'preparacao',
        'abertura' => '', 'encerramento' => '', 'hash' => '',
    ];
}
function salvar_cfg(array $c): void { kv_set('cfg', $c); }
function registrar(string $acao, string $det = '', string $usuario = 'Comissão'): void {
    db()->prepare('INSERT INTO log(em,usuario,acao,detalhe) VALUES(?,?,?,?)')->execute([agora(), $usuario, $acao, $det]);
}

/* ---------- leituras ---------- */
function candidatos(): array {
    $r = db()->query('SELECT numero,matricula,nome,escala,cargo,setor,admissao,foto,origem FROM candidatos')->fetchAll();
    usort($r, fn($a, $b) => strnatcmp($a['numero'], $b['numero']));
    foreach ($r as &$c) foreach ($c as $k => $v) $c[$k] = (string)($v ?? '');
    return $r;
}
function eleitores(): array {
    $r = db()->query('SELECT matricula,nome,escala,cargo,setor,codigo,votou,votou_em,comprovante FROM eleitores ORDER BY ordem')->fetchAll();
    return array_map(fn($e) => [
        'matricula' => $e['matricula'], 'nome' => $e['nome'], 'escala' => (string)$e['escala'], 'cargo' => (string)$e['cargo'],
        'setor' => (string)$e['setor'], 'codigo' => (string)$e['codigo'], 'votou' => (bool)$e['votou'],
        'votouEm' => (string)$e['votou_em'], 'comprovante' => (string)$e['comprovante'],
    ], $r);
}
function eleitor_por_mat($m): ?array {
    $k = mat_norm($m); if ($k === '') return null;
    $st = db()->prepare('SELECT * FROM eleitores WHERE mnorm=? ORDER BY ordem LIMIT 1'); $st->execute([$k]);
    return $st->fetch() ?: null;
}
function admin_data(): array {
    $p = db();
    return [
        'cfg' => cfg(), 'candidatos' => candidatos(), 'eleitores' => eleitores(),
        'log' => $p->query('SELECT em,usuario,acao,detalhe FROM log ORDER BY id DESC LIMIT 300')->fetchAll(),
        'votantes' => (int)$p->query('SELECT COUNT(*) FROM eleitores WHERE votou=1')->fetchColumn(),
        'urna' => (int)$p->query('SELECT COUNT(*) FROM votos')->fetchColumn(),
        'urlApp' => url_base(),
        'podeZerar' => getenv('CIPA_PERMITIR_ZERAR') === '1',
    ];
}

/* ---------- sessões ---------- */
function sessao_nova(string $tipo, string $ref, int $dur): string {
    $p = db();
    $p->prepare('DELETE FROM sessoes WHERE expira<?')->execute([time()]);
    $t = token();
    $p->prepare('INSERT INTO sessoes(token,tipo,ref,expira) VALUES(?,?,?,?)')->execute([$t, $tipo, $ref, time() + $dur]);
    return $t;
}
function sessao(string $tipo, $t): ?string {
    if (!is_string($t) || $t === '') return null;
    $st = db()->prepare('SELECT ref FROM sessoes WHERE token=? AND tipo=? AND expira>=?');
    $st->execute([$t, $tipo, time()]);
    $r = $st->fetchColumn();
    return $r === false ? null : (string)$r;
}
function exige_admin($t): void {
    if (sessao('admin', $t) === null) falha('Sessão do administrador expirada.');
    db()->prepare('UPDATE sessoes SET expira=? WHERE token=?')->execute([time() + SESSAO_ADMIN, $t]);
}
/* Atrás do proxy do EasyPanel (Traefik) o REMOTE_ADDR é o do proxy: o IP real é o último
   que o proxy acrescentou ao X-Forwarded-For (os anteriores podem ter vindo do próprio cliente). */
function ip(): string {
    $xff = array_filter(array_map('trim', explode(',', (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''))));
    $ult = end($xff);
    if ($ult && filter_var($ult, FILTER_VALIDATE_IP)) return $ult;
    $real = (string)($_SERVER['HTTP_X_REAL_IP'] ?? '');
    if (filter_var($real, FILTER_VALIDATE_IP)) return $real;
    return $_SERVER['REMOTE_ADDR'] ?? '?';
}
/* "Android · Chrome", "iPhone · Safari", "Windows · Edge"... */
function aparelho_desc(string $ua): string {
    $so = match (true) {
        str_contains($ua, 'iPhone') => 'iPhone', str_contains($ua, 'iPad') => 'iPad',
        str_contains($ua, 'Android') => 'Android', str_contains($ua, 'Windows') => 'Windows',
        str_contains($ua, 'Mac OS') => 'Mac', str_contains($ua, 'CrOS') => 'Chromebook',
        str_contains($ua, 'Linux') => 'Linux', default => 'Outro',
    };
    $nav = match (true) {
        str_contains($ua, 'SamsungBrowser') => 'Samsung Internet', str_contains($ua, 'Edg') => 'Edge',
        str_contains($ua, 'OPR') || str_contains($ua, 'Opera') => 'Opera', str_contains($ua, 'Firefox') || str_contains($ua, 'FxiOS') => 'Firefox',
        str_contains($ua, 'CriOS') || str_contains($ua, 'Chrome') => 'Chrome', str_contains($ua, 'Safari') => 'Safari', default => 'navegador',
    };
    return "$so · $nav";
}

/* =================== chamadas do front-end =================== */

function api_ballot(): array {
    $c = cfg();
    return ['cfg' => array_intersect_key($c, array_flip(['titulo', 'gestao', 'empresa', 'estabelecimento', 'status', 'verificacao'])),
            'candidatos' => array_map(fn($x) => array_diff_key($x, ['matricula' => 1, 'admissao' => 1, 'origem' => 1]), candidatos())];
}

function api_consultar($m): array {
    if (cfg()['status'] !== 'aberta') falha('A votação não está aberta.');
    if (mat_norm($m) === '') falha('Digite sua matrícula.');
    $e = eleitor_por_mat($m);
    if (!$e) falha('Matrícula não encontrada na lista de eleitores. Confira o número ou procure a comissão eleitoral.');
    return ['matricula' => $e['matricula'], 'nome' => $e['nome'], 'jaVotou' => (bool)$e['votou'], 'votouEm' => (string)$e['votou_em']];
}

function api_login($m, $c): array {
    $cfg = cfg(); $mode = $cfg['verificacao'] ?: 'nenhuma';
    if ($cfg['status'] !== 'aberta') falha('A votação não está aberta.');
    $e = eleitor_por_mat($m);
    if (!$e) falha('Matrícula não encontrada.');
    if ($e['votou']) falha('Esta matrícula já votou. Cada colaborador vota uma única vez.');
    if ($mode !== 'nenhuma') {
        $dig = conf_norm($mode, $c);
        if ($dig === '' || !hash_equals(conf_norm($mode, $e['codigo']), $dig))
            falha(VERIF[$mode] . ' não confere com o cadastro. Em caso de dúvida, procure a comissão eleitoral.');
    }
    return ['token' => sessao_nova('eleitor', $e['matricula'], SESSAO_ELEITOR), 'nome' => $e['nome'], 'jaVotou' => false];
}

function api_votar($t, $n, $totem = false, $aparelho = ''): array {
    $p = db();
    $p->exec('BEGIN IMMEDIATE');
    try {
        $mat = sessao('eleitor', $t);
        if ($mat === null) falha('Sessão expirada. Entre novamente.');
        if (cfg()['status'] !== 'aberta') falha('A votação foi encerrada.');
        $st = $p->prepare('SELECT votou FROM eleitores WHERE matricula=?'); $st->execute([$mat]);
        $votou = $st->fetchColumn();
        if ($votou === false) falha('Matrícula não encontrada.');
        if ((int)$votou) falha('Seu voto já foi registrado anteriormente.');
        $n = (string)$n;
        if ($n !== 'BRANCO' && $n !== 'NULO') {
            $st = $p->prepare('SELECT 1 FROM candidatos WHERE numero=?'); $st->execute([$n]);
            if (!$st->fetchColumn()) falha('Número inválido.');
        }
        $ins = $p->prepare('INSERT OR IGNORE INTO votos(id,voto) VALUES(?,?)');
        do { $ins->execute([rand_code(12), $n]); } while ($ins->rowCount() === 0);
        $comp = rand_code(4) . '-' . rand_code(4); $em = agora();
        $p->prepare('UPDATE eleitores SET votou=1, votou_em=?, comprovante=?, voto_ip=?, voto_disp=?, voto_aparelho=?, voto_totem=? WHERE matricula=?')
          ->execute([$em, $comp, ip(), aparelho_desc((string)($_SERVER['HTTP_USER_AGENT'] ?? '')),
                     substr(preg_replace('/[^A-Za-z0-9]/', '', (string)$aparelho), 0, 16), $totem === true ? 1 : 0, $mat]);
        $p->prepare('DELETE FROM sessoes WHERE token=?')->execute([$t]);
        $p->exec('COMMIT');
        return ['comprovante' => $comp, 'em' => $em];
    } catch (Throwable $e) { $p->exec('ROLLBACK'); throw $e; }
}

function api_adminLogin($u, $s): array {
    $p = db();
    $p->prepare('DELETE FROM falhas WHERE em<?')->execute([time() - 900]);
    $st = $p->prepare('SELECT COUNT(*) FROM falhas WHERE ip=?'); $st->execute([ip()]);
    if ((int)$st->fetchColumn() >= 8) falha('Muitas tentativas. Aguarde 15 minutos.');
    $ad = kv_get('admin');
    if (!$ad) {
        $env = (string)getenv('CIPA_ADMIN_SENHA');
        if (strlen($env) < 6) falha('Acesso ainda não configurado: defina a variável CIPA_ADMIN_SENHA no servidor.');
        $ad = ['usuario' => 'admin', 'hash' => password_hash($env, PASSWORD_DEFAULT)];
    }
    if (mb_strtolower(trim((string)$u)) !== mb_strtolower($ad['usuario']) || !password_verify((string)$s, $ad['hash'])) {
        $p->prepare('INSERT INTO falhas(ip,em) VALUES(?,?)')->execute([ip(), time()]);
        falha('Usuário ou senha incorretos.');
    }
    registrar('Acesso do administrador', $ad['usuario'] . ' · ' . ip());
    return ['token' => sessao_nova('admin', $ad['usuario'], SESSAO_ADMIN)];
}

function api_adminSair($t): array { db()->prepare('DELETE FROM sessoes WHERE token=?')->execute([(string)$t]); return []; }

function api_trocarSenha($t, $atual, $nova, $usuario): array {
    exige_admin($t);
    $ad = kv_get('admin') ?: ['usuario' => 'admin', 'hash' => password_hash((string)getenv('CIPA_ADMIN_SENHA'), PASSWORD_DEFAULT)];
    if (!password_verify((string)$atual, $ad['hash'])) falha('Senha atual incorreta.');
    if (mb_strlen((string)$nova) < 8) falha('A nova senha precisa de pelo menos 8 caracteres.');
    $ad['hash'] = password_hash((string)$nova, PASSWORD_DEFAULT);
    if (trim((string)$usuario) !== '') $ad['usuario'] = txt($usuario, 60);
    kv_set('admin', $ad);
    registrar('Senha do administrador alterada', $ad['usuario']);
    return [];
}

function api_adminData($t): array { exige_admin($t); return admin_data(); }

function api_saveConfig($t, $c): array {
    exige_admin($t); $c = (array)$c; $cfg = cfg();
    foreach (['titulo', 'gestao', 'empresa', 'cnpj', 'estabelecimento'] as $k) if (isset($c[$k])) $cfg[$k] = txt($c[$k], 160);
    if (in_array($c['inscricoes'] ?? null, ['sim', 'nao'], true)) $cfg['inscricoes'] = $c['inscricoes'];
    if ($cfg['status'] === 'preparacao' && isset($c['vagasTitulares'])) {
        if (isset(VERIF[$c['verificacao'] ?? ''])) $cfg['verificacao'] = $c['verificacao'];
        $cfg['vagasTitulares'] = max(1, (int)$c['vagasTitulares']);
        $cfg['vagasSuplentes'] = max(0, (int)($c['vagasSuplentes'] ?? 0));
    }
    salvar_cfg($cfg); registrar('Configuração alterada', $cfg['titulo']);
    return admin_data();
}

function api_saveCand($t, $c, $orig): array {
    exige_admin($t); $c = (array)$c; $orig = (string)$orig;
    if (cfg()['status'] !== 'preparacao') falha('Candidatos só podem ser alterados antes da abertura da votação.');
    $num = pad2($c['numero'] ?? '');
    if (!preg_match('/^\d{2,3}$/', $num)) falha('Informe um número com 2 ou 3 dígitos.');
    $nome = txt($c['nome'] ?? '', 120);
    if ($nome === '') falha('Informe o nome do candidato.');
    $p = db();
    $st = $p->prepare('SELECT nome FROM candidatos WHERE numero=? AND numero<>?'); $st->execute([$num, $orig]);
    if ($dono = $st->fetchColumn()) falha("O número $num já pertence a $dono.");
    $st = $p->prepare('SELECT origem FROM candidatos WHERE numero=?'); $st->execute([$orig ?: $num]);
    $antes = $st->fetchColumn();
    $p->exec('BEGIN IMMEDIATE');
    if ($antes !== false) $p->prepare('DELETE FROM candidatos WHERE numero=?')->execute([$orig ?: $num]);
    $p->prepare('INSERT INTO candidatos(numero,matricula,nome,escala,cargo,setor,admissao,foto,origem) VALUES(?,?,?,?,?,?,?,?,?)')
      ->execute([$num, txt($c['matricula'] ?? '', 20), $nome, txt($c['escala'] ?? '', 60), txt($c['cargo'] ?? '', 120),
                 txt($c['setor'] ?? '', 120), txt($c['admissao'] ?? '', 10), foto_ok($c['foto'] ?? ''),
                 txt(($c['origem'] ?? '') ?: ($antes ?: 'comissao'), 20)]);
    $p->exec('COMMIT');
    registrar($antes !== false ? 'Candidato alterado' : 'Candidato cadastrado', "$num – $nome");
    return admin_data();
}

function api_delCand($t, $n): array {
    exige_admin($t);
    if (cfg()['status'] !== 'preparacao') falha('Candidatos só podem ser excluídos antes da abertura da votação.');
    $st = db()->prepare('SELECT nome FROM candidatos WHERE numero=?'); $st->execute([(string)$n]);
    $nome = $st->fetchColumn();
    db()->prepare('DELETE FROM candidatos WHERE numero=?')->execute([(string)$n]);
    registrar('Candidato excluído', $n . ($nome ? " – $nome" : ''));
    return admin_data();
}

function api_importar($t, $rows, $subst): array {
    exige_admin($t);
    $cfg = cfg();
    if ($cfg['status'] !== 'preparacao') falha('A lista de eleitores só pode ser alterada antes da abertura.');
    if (!is_array($rows) || count($rows) > 20000) falha('Lista inválida.');
    $p = db();
    $p->exec('BEGIN IMMEDIATE');
    try {
        if ($subst) $p->exec('DELETE FROM eleitores');
        $ordem = (int)$p->query('SELECT COALESCE(MAX(ordem),0) FROM eleitores')->fetchColumn();
        $existe = $p->prepare('SELECT 1 FROM eleitores WHERE matricula=?');
        $up = $p->prepare('INSERT INTO eleitores(matricula,mnorm,ordem,nome,escala,cargo,setor,codigo) VALUES(?,?,?,?,?,?,?,?)
            ON CONFLICT(matricula) DO UPDATE SET mnorm=excluded.mnorm,nome=excluded.nome,escala=excluded.escala,cargo=excluded.cargo,
            setor=excluded.setor,codigo=excluded.codigo,votou=0,votou_em=NULL,comprovante=NULL,voto_ip=NULL,voto_disp=NULL,voto_aparelho=NULL,voto_totem=NULL');
        $n = 0; $seq = $ordem;
        foreach ($rows as $r) {
            $r = (array)$r;
            $nome = txt($r['nome'] ?? '', 120); if ($nome === '') continue;
            $mat = txt($r['matricula'] ?? '', 20);
            if ($mat === '') { do { $seq++; $mat = 'E' . str_pad((string)$seq, 4, '0', STR_PAD_LEFT); $existe->execute([$mat]); } while ($existe->fetchColumn()); }
            $cf = txt($r['codigo'] ?? '', 40);
            if (preg_match('/^\d{3}\.?\d{3}\.?\d{3}-?\d{2}$/', $cf)) $cf = substr(preg_replace('/\D/', '', $cf), -4);
            if ($cf === '' && $cfg['verificacao'] === 'codigo') $cf = rand_code(6);
            $up->execute([$mat, mat_norm($mat), ++$ordem, $nome, txt($r['escala'] ?? '', 60), txt($r['cargo'] ?? '', 120), txt($r['setor'] ?? '', 120), $cf]);
            $n++;
        }
        $p->exec('COMMIT');
    } catch (Throwable $e) { $p->exec('ROLLBACK'); throw $e; }
    registrar('Eleitores importados', $n . ' registros' . ($subst ? ' (lista substituída)' : ''));
    return admin_data();
}

function api_status($t, $s): array {
    exige_admin($t);
    $p = db(); $p->exec('BEGIN IMMEDIATE');
    try {
        $cfg = cfg(); $cur = $cfg['status'];
        if ($s === 'aberta') {
            if ($cur === 'preparacao') {
                if (!$p->query('SELECT COUNT(*) FROM candidatos')->fetchColumn()) falha('Cadastre ao menos um candidato.');
                if (!$p->query('SELECT COUNT(*) FROM eleitores')->fetchColumn()) falha('Importe a lista de eleitores.');
                $p->exec('DELETE FROM votos'); $p->exec('UPDATE eleitores SET votou=0,votou_em=NULL,comprovante=NULL,voto_ip=NULL,voto_disp=NULL,voto_aparelho=NULL,voto_totem=NULL');
                $cfg['abertura'] = agora(); registrar('Votação aberta', 'Zerésima emitida: 0 votos na urna');
            } elseif ($cur === 'encerrada') {
                $cfg['hash'] = ''; $cfg['encerramento'] = '';
                registrar('Votação reaberta (prorrogação)', $p->query('SELECT COUNT(*) FROM votos')->fetchColumn() . ' votos já na urna');
            }
            $cfg['status'] = 'aberta';
        } elseif ($s === 'encerrada') {
            if ($cur !== 'aberta') falha('A votação não está aberta.');
            $cfg['status'] = 'encerrada'; $cfg['encerramento'] = agora();
            $l = array_map(fn($v) => $v['id'] . ':' . $v['voto'], $p->query('SELECT id,voto FROM votos')->fetchAll());
            sort($l, SORT_STRING);
            $cfg['hash'] = hash('sha256', implode('|', $l));
            registrar('Votação encerrada', count($l) . ' votos · hash ' . substr($cfg['hash'], 0, 16) . '…');
        } elseif ($s === 'preparacao') {
            if ($cur === 'aberta' || getenv('CIPA_PERMITIR_ZERAR') !== '1')
                falha('Zerar a urna não é permitido na eleição oficial.');
            $p->exec('DELETE FROM votos'); $p->exec('UPDATE eleitores SET votou=0,votou_em=NULL,comprovante=NULL,voto_ip=NULL,voto_disp=NULL,voto_aparelho=NULL,voto_totem=NULL');
            $cfg = array_merge($cfg, ['status' => 'preparacao', 'hash' => '', 'abertura' => '', 'encerramento' => '']);
            registrar('Urna zerada (modo de teste)', '');
        } else falha('Situação inválida.');
        salvar_cfg($cfg);
        $p->exec('COMMIT');
    } catch (Throwable $e) { $p->exec('ROLLBACK'); throw $e; }
    return admin_data();
}

/* mesma regra de apurar() em urna.html */
function api_resultado($t): array {
    exige_admin($t);
    $cfg = cfg();
    if ($cfg['status'] !== 'encerrada') falha('A apuração só é liberada após o encerramento da votação.');
    $p = db(); $cands = candidatos();
    $cont = array_fill_keys(array_column($cands, 'numero'), 0); $brancos = 0; $nulos = 0; $total = 0;
    foreach ($p->query('SELECT voto, COUNT(*) n FROM votos GROUP BY voto') as $v) {
        $n = (int)$v['n']; $total += $n;
        if ($v['voto'] === 'BRANCO') $brancos += $n;
        elseif ($v['voto'] === 'NULO' || !array_key_exists($v['voto'], $cont)) $nulos += $n;
        else $cont[$v['voto']] += $n;
    }
    $lista = array_map(fn($c) => $c + ['votos' => $cont[$c['numero']]], $cands);
    usort($lista, function ($a, $b) {
        if ($a['votos'] !== $b['votos']) return $b['votos'] <=> $a['votos'];
        $da = $a['admissao'] ?: '9999'; $db = $b['admissao'] ?: '9999';
        if ($da !== $db) return strcmp($da, $db); // empate: maior tempo de serviço
        return strcmp(mb_strtolower($a['nome']), mb_strtolower($b['nome']));
    });
    $vt = (int)$cfg['vagasTitulares']; $vs = (int)$cfg['vagasSuplentes']; $validos = 0;
    foreach ($lista as $i => &$c) {
        unset($c['origem']);
        $c['pos'] = $i + 1;
        $c['situacao'] = $c['votos'] > 0 && $i < $vt ? 'Titular' : ($c['votos'] > 0 && $i < $vt + $vs ? 'Suplente' : '');
        $validos += $c['votos'];
    }
    unset($c);
    $aptos = (int)$p->query('SELECT COUNT(*) FROM eleitores')->fetchColumn();
    $votantes = (int)$p->query('SELECT COUNT(*) FROM eleitores WHERE votou=1')->fetchColumn();
    return ['candidatos' => $lista, 'validos' => $validos, 'brancos' => $brancos, 'nulos' => $nulos, 'total' => $total,
            'aptos' => $aptos, 'votantes' => $votantes, 'participacao' => pct($votantes, $aptos),
            'integridade' => $total === $votantes, 'quorum' => pct($votantes, $aptos) >= 50, 'cfg' => $cfg];
}

function api_gerarAta($t, $txt): array {
    exige_admin($t);
    if (cfg()['status'] !== 'encerrada') falha('A ata só pode ser registrada após o encerramento.');
    $atas = kv_get('atas', []);
    $atas[] = ['em' => agora(), 'texto' => mb_substr((string)$txt, 0, 100000)];
    kv_set('atas', $atas);
    registrar('Ata de apuração registrada', 'versão ' . count($atas));
    return [];
}

/* foto pelo celular: o computador da comissão pede um token, o celular envia, o computador busca */
function api_fotoToken($t): array {
    exige_admin($t);
    $p = db(); $p->prepare('DELETE FROM fotos WHERE expira<?')->execute([time()]);
    $tok = token();
    $p->prepare('INSERT INTO fotos(token,foto,expira) VALUES(?,NULL,?)')->execute([$tok, time() + 900]);
    return ['token' => $tok, 'url' => url_base() . 'foto/' . $tok];
}
function api_fotoBuscar($t, $tok): array {
    exige_admin($t);
    $st = db()->prepare('SELECT foto FROM fotos WHERE token=? AND expira>=?'); $st->execute([(string)$tok, time()]);
    $f = $st->fetchColumn();
    if (!$f) return [];
    db()->prepare('DELETE FROM fotos WHERE token=?')->execute([(string)$tok]);
    return ['foto' => $f];
}
function api_fotoEnviar($tok, $data): array {
    $f = foto_ok($data);
    if ($f === '') falha('Tire a foto antes de enviar.');
    $st = db()->prepare('UPDATE fotos SET foto=? WHERE token=? AND expira>=?'); $st->execute([$f, (string)$tok, time()]);
    if (!$st->rowCount()) falha('Este código expirou. Gere um novo QR Code no computador.');
    return [];
}

function api_inscConsultar($m): array {
    $cfg = cfg();
    if ($cfg['inscricoes'] !== 'sim' || $cfg['status'] !== 'preparacao') falha('As inscrições de candidatos estão encerradas.');
    if (mat_norm($m) === '') falha('Digite sua matrícula.');
    $e = eleitor_por_mat($m);
    if (!$e) falha('Matrícula não encontrada no cadastro de colaboradores. Procure a comissão eleitoral.');
    $st = db()->prepare('SELECT numero,matricula FROM candidatos'); $st->execute();
    $ja = '';
    foreach ($st as $c) if (mat_norm($c['matricula']) === mat_norm($m)) { $ja = $c['numero']; break; }
    return ['matricula' => $e['matricula'], 'nome' => $e['nome'], 'escala' => (string)$e['escala'],
            'cargo' => (string)$e['cargo'], 'setor' => (string)$e['setor'], 'jaNumero' => $ja];
}

function api_inscrever($m, $foto): array {
    $p = db(); $p->exec('BEGIN IMMEDIATE');
    try {
        $r = api_inscConsultar($m);
        if ($r['jaNumero'] !== '') falha('Você já está inscrito com o número ' . $r['jaNumero'] . '.');
        $mx = 0; foreach ($p->query('SELECT numero FROM candidatos') as $c) $mx = max($mx, (int)$c['numero']);
        $num = pad2((string)($mx + 1));
        $p->prepare("INSERT INTO candidatos(numero,matricula,nome,escala,cargo,setor,admissao,foto,origem) VALUES(?,?,?,?,?,?,'',?,'link')")
          ->execute([$num, $r['matricula'], $r['nome'], $r['escala'], $r['cargo'], $r['setor'], foto_ok($foto)]);
        registrar('Inscrição pelo link', "$num – {$r['nome']}", 'Colaborador');
        $p->exec('COMMIT');
    } catch (Throwable $e) { $p->exec('ROLLBACK'); throw $e; }
    return ['numero' => $num, 'nome' => $r['nome'], 'escala' => $r['escala']];
}

/* Extrato de auditoria: participação (quem votou, quando, de onde) + boletim + registro.
   Não existe — e não pode existir — ligação entre a matrícula e o voto. */
function api_auditoria($t): array {
    exige_admin($t);
    $p = db(); $cfg = cfg();
    $vot = $p->query('SELECT matricula,nome,setor,votou_em,comprovante,voto_ip,voto_disp,voto_aparelho,voto_totem
                      FROM eleitores WHERE votou=1 ORDER BY votou_em')->fetchAll();
    $vot = array_map(fn($e) => ['matricula' => $e['matricula'], 'nome' => $e['nome'], 'setor' => (string)$e['setor'],
        'em' => (string)$e['votou_em'], 'comprovante' => (string)$e['comprovante'], 'ip' => (string)$e['voto_ip'],
        'disp' => (string)$e['voto_disp'], 'aparelho' => (string)$e['voto_aparelho'], 'totem' => (bool)$e['voto_totem']], $vot);

    $alertas = [];
    $porAp = []; $porIp = [];
    foreach ($vot as $v) {
        if ($v['totem']) continue;
        if ($v['aparelho'] !== '') $porAp[$v['aparelho']][] = $v;
        if ($v['ip'] !== '') $porIp[$v['ip']][] = $v;
        $h = (int)substr($v['em'], 11, 2);
        if ($v['em'] !== '' && $h < 5) $alertas[] = ['nivel' => 'info', 'texto' => 'Voto de madrugada: ' . $v['matricula'] . ' – ' . $v['nome'], 'em' => $v['em']];
    }
    foreach ($porAp as $ap => $l) if (count($l) > 1)
        $alertas[] = ['nivel' => 'alto', 'texto' => count($l) . ' matrículas votaram pelo MESMO aparelho (fora do totem): ' .
            implode(', ', array_map(fn($v) => $v['matricula'] . ' – ' . $v['nome'], $l)), 'em' => $l[0]['em']];
    foreach ($porIp as $ip => $l) if (count($l) >= 5)
        $alertas[] = ['nivel' => 'medio', 'texto' => count($l) . " votos fora do totem pela mesma rede (IP $ip). Normal se for o Wi-Fi da empresa; confira se não for.", 'em' => $l[0]['em']];

    $res = null;
    if ($cfg['status'] === 'encerrada') { $res = api_resultado($t); unset($res['cfg']); }
    return [
        'cfg' => $cfg, 'emitido' => agora(), 'emitidoIp' => ip(), 'votantes' => $vot, 'alertas' => $alertas,
        'aptos' => (int)$p->query('SELECT COUNT(*) FROM eleitores')->fetchColumn(),
        'urna' => (int)$p->query('SELECT COUNT(*) FROM votos')->fetchColumn(),
        'resultado' => $res,
        'log' => $p->query('SELECT em,usuario,acao,detalhe FROM log ORDER BY id')->fetchAll(),
    ];
}
