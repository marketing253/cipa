<?php
/*
 * Presença nos eventos da CIPA e sorteio de brindes (03/11/2026) — mesmo banco da urna.
 *
 * O colaborador abre /qr, entra com matrícula + a confirmação da urna e mostra o QR no celular.
 * Só a comissão (login do /admin) marca presença: lê o QR com a câmera em /presenca (ou digita a
 * matrícula, ou lê o número do crachá). O QR leva a matrícula com uma assinatura, para ninguém
 * gerar o QR de um colega ausente. O sorteio é entre os presentes do evento, sem repetir ganhador.
 * Check-in pelo celular: cada evento tem um QR para colar na mesa (/checkin/TOKEN); o colaborador lê
 * com o próprio celular e confirma com matrícula + CPF. A comissão fecha o check-in quando o evento acaba,
 * para quem recebeu a foto do QR não marcar presença de casa.
 */
declare(strict_types=1);

function pre_tabelas(): PDO {
    static $feito = false;
    $p = db();
    if ($feito) return $p;
    $p->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS presenca_eventos (id INTEGER PRIMARY KEY AUTOINCREMENT, nome TEXT NOT NULL, criado TEXT NOT NULL);
        CREATE TABLE IF NOT EXISTS presencas (
            evento INTEGER NOT NULL, mnorm TEXT NOT NULL, matricula TEXT NOT NULL, nome TEXT NOT NULL, setor TEXT,
            como TEXT NOT NULL, em TEXT NOT NULL, PRIMARY KEY (evento, mnorm));
        CREATE TABLE IF NOT EXISTS sorteios (
            id INTEGER PRIMARY KEY AUTOINCREMENT, evento INTEGER NOT NULL, mnorm TEXT NOT NULL, matricula TEXT NOT NULL,
            nome TEXT NOT NULL, setor TEXT, brinde TEXT, em TEXT NOT NULL);
    SQL);
    $cols = array_column($p->query('PRAGMA table_info(presenca_eventos)')->fetchAll(), 'name');
    if (!in_array('token', $cols, true)) $p->exec('ALTER TABLE presenca_eventos ADD COLUMN token TEXT');
    if (!in_array('aberto', $cols, true)) $p->exec('ALTER TABLE presenca_eventos ADD COLUMN aberto INTEGER NOT NULL DEFAULT 1');
    foreach ($p->query("SELECT id FROM presenca_eventos WHERE token IS NULL OR token=''")->fetchAll() as $r)
        $p->prepare('UPDATE presenca_eventos SET token=? WHERE id=?')->execute([bin2hex(random_bytes(12)), $r['id']]);
    $feito = true;
    return $p;
}

function pre_assinatura(string $mnorm): string {
    $k = kv_get('presenca_chave', '');
    if ($k === '') { $k = bin2hex(random_bytes(16)); kv_set('presenca_chave', $k); }
    return substr(hash_hmac('sha256', $mnorm, $k), 0, 10);
}

/* "CIPA:<matrícula>:<assinatura>" do QR, ou só o número (digitado ou do crachá) */
function pre_ler_codigo($cod): array {
    $cod = trim((string)$cod);
    if (preg_match('/^CIPA:([^:]+):([0-9a-f]{10})$/', $cod, $m)) {
        if (!hash_equals(pre_assinatura(mat_norm($m[1])), $m[2])) falha('QR Code inválido: peça para a pessoa abrir o QR de novo em /qr.');
        $mat = $m[1]; $como = 'QR Code';
    } elseif (preg_match('/^\d{1,20}$/', $cod)) { $mat = $cod; $como = 'Matrícula'; }
    else falha('Código não reconhecido. Leia o QR do colaborador ou digite a matrícula.');
    $e = eleitor_por_mat($mat);
    if (!$e) falha("Matrícula $mat não está na lista de colaboradores.");
    return [$e, $como];
}

function pre_evento(int $id): array {
    $st = pre_tabelas()->prepare('SELECT * FROM presenca_eventos WHERE id=?'); $st->execute([$id]);
    return $st->fetch() ?: falha('Evento não encontrado.');
}

/* =================== colaborador =================== */

function api_preQr($m, $c = ''): array {
    if (mat_norm($m) === '') falha('Digite sua matrícula.');
    $e = eleitor_por_mat($m);
    if (!$e) falha('Matrícula não encontrada no cadastro de colaboradores. Procure a comissão da CIPA.');
    confere($e, $c);
    return ['nome' => $e['nome'], 'matricula' => $e['matricula'],
            'codigo' => 'CIPA:' . $e['matricula'] . ':' . pre_assinatura(mat_norm($e['matricula']))];
}

function pre_evento_tok($tok): array {
    $tok = preg_replace('/[^a-f0-9]/', '', (string)$tok);
    if ($tok === '') falha('QR do evento inválido. Leia de novo o QR que está na mesa.');
    $st = pre_tabelas()->prepare('SELECT * FROM presenca_eventos WHERE token=?'); $st->execute([$tok]);
    return $st->fetch() ?: falha('QR do evento inválido. Leia de novo o QR que está na mesa.');
}

function api_preEventoInfo($tok): array {
    $e = pre_evento_tok($tok);
    return ['nome' => $e['nome'], 'aberto' => (bool)$e['aberto'], 'verificacao' => cfg()['verificacao'] ?: 'nenhuma',
            'verificacaoLbl' => VERIF[cfg()['verificacao'] ?: 'nenhuma'] ?? '', 'empresa' => cfg()['empresa']];
}

function api_preCheckin($tok, $m, $c = ''): array {
    $ev = pre_evento_tok($tok);
    if (!$ev['aberto']) falha('O check-in deste evento está encerrado. Procure a comissão da CIPA.');
    if (mat_norm($m) === '') falha('Digite sua matrícula.');
    $e = eleitor_por_mat($m);
    if (!$e) falha('Matrícula não encontrada no cadastro de colaboradores. Procure a comissão da CIPA.');
    confere($e, $c);
    $st = pre_tabelas()->prepare('INSERT OR IGNORE INTO presencas(evento,mnorm,matricula,nome,setor,como,em) VALUES(?,?,?,?,?,?,?)');
    $st->execute([$ev['id'], mat_norm($e['matricula']), $e['matricula'], $e['nome'], (string)$e['setor'], 'Celular', agora()]);
    return ['novo' => $st->rowCount() > 0, 'nome' => $e['nome'], 'evento' => $ev['nome']];
}

/* =================== comissão =================== */

function pre_dados(?int $ev = null): array {
    $p = pre_tabelas();
    $eventos = $p->query('SELECT e.id, e.nome, e.criado, e.token, e.aberto, (SELECT COUNT(*) FROM presencas WHERE evento=e.id) AS presentes,
        (SELECT COUNT(*) FROM sorteios WHERE evento=e.id) AS sorteados FROM presenca_eventos e ORDER BY e.id DESC')->fetchAll();
    $eventos = array_map(fn($e) => ['id' => (int)$e['id'], 'nome' => $e['nome'], 'criado' => $e['criado'],
        'token' => (string)$e['token'], 'aberto' => (bool)$e['aberto'],
        'presentes' => (int)$e['presentes'], 'sorteados' => (int)$e['sorteados']], $eventos);
    if ($ev === null && $eventos) $ev = $eventos[0]['id'];
    $pres = $sort = [];
    if ($ev) {
        $st = $p->prepare('SELECT matricula,nome,setor,como,em FROM presencas WHERE evento=? ORDER BY em DESC'); $st->execute([$ev]);
        $pres = $st->fetchAll();
        $st = $p->prepare('SELECT id,matricula,nome,setor,brinde,em FROM sorteios WHERE evento=? ORDER BY id'); $st->execute([$ev]);
        $sort = array_map(fn($s) => ['id' => (int)$s['id']] + $s, $st->fetchAll());
    }
    return ['eventos' => $eventos, 'evento' => $ev, 'presentes' => $pres, 'sorteios' => $sort, 'urlApp' => function_exists('url_publica') ? url_publica() : url_base()];
}

function api_preDados($t, $evento = null): array { exige_admin($t); return pre_dados($evento ? (int)$evento : null); }

function api_preEvento($t, $nome): array {
    exige_admin($t);
    $nome = txt($nome, 100);
    if (mb_strlen($nome) < 3) falha('Dê um nome ao evento (ex.: SIPAT 2026 – dia 1).');
    pre_tabelas()->prepare('INSERT INTO presenca_eventos(nome,criado,token,aberto) VALUES(?,?,?,1)')->execute([$nome, agora(), bin2hex(random_bytes(12))]);
    $id = (int)db()->lastInsertId();
    registrar('Evento de presença criado', $nome);
    return pre_dados($id);
}

function api_preAbrir($t, $evento, $aberto): array {
    exige_admin($t);
    $e = pre_evento((int)$evento);
    pre_tabelas()->prepare('UPDATE presenca_eventos SET aberto=? WHERE id=?')->execute([$aberto ? 1 : 0, $e['id']]);
    registrar($aberto ? 'Check-in do evento aberto' : 'Check-in do evento encerrado', $e['nome']);
    return pre_dados($e['id']);
}

function api_preExcluirEvento($t, $evento): array {
    exige_admin($t);
    $e = pre_evento((int)$evento); $p = pre_tabelas();
    foreach (['presencas', 'sorteios'] as $tb) $p->prepare("DELETE FROM $tb WHERE evento=?")->execute([$e['id']]);
    $p->prepare('DELETE FROM presenca_eventos WHERE id=?')->execute([$e['id']]);
    registrar('Evento de presença excluído', $e['nome']);
    return pre_dados();
}

function api_preMarcar($t, $evento, $codigo): array {
    exige_admin($t);
    $ev = pre_evento((int)$evento);
    [$e, $como] = pre_ler_codigo($codigo);
    $st = pre_tabelas()->prepare('INSERT OR IGNORE INTO presencas(evento,mnorm,matricula,nome,setor,como,em) VALUES(?,?,?,?,?,?,?)');
    $st->execute([$ev['id'], mat_norm($e['matricula']), $e['matricula'], $e['nome'], (string)$e['setor'], $como, agora()]);
    $novo = $st->rowCount() > 0;
    $n = pre_tabelas()->prepare('SELECT COUNT(*) FROM presencas WHERE evento=?'); $n->execute([$ev['id']]);
    return ['novo' => $novo, 'nome' => $e['nome'], 'matricula' => $e['matricula'], 'setor' => (string)$e['setor'], 'total' => (int)$n->fetchColumn()];
}

function api_preDesmarcar($t, $evento, $mat): array {
    exige_admin($t);
    $ev = pre_evento((int)$evento);
    pre_tabelas()->prepare('DELETE FROM presencas WHERE evento=? AND mnorm=?')->execute([$ev['id'], mat_norm($mat)]);
    registrar('Presença retirada', "{$ev['nome']} · $mat");
    return pre_dados($ev['id']);
}

/* sorteia entre os presentes que ainda não ganharam neste evento */
function api_preSortear($t, $evento, $brinde = ''): array {
    exige_admin($t);
    $ev = pre_evento((int)$evento); $p = pre_tabelas();
    $st = $p->prepare('SELECT * FROM presencas WHERE evento=? AND mnorm NOT IN (SELECT mnorm FROM sorteios WHERE evento=?) ORDER BY mnorm');
    $st->execute([$ev['id'], $ev['id']]);
    $c = $st->fetchAll();
    if (!$c) falha('Não há mais presentes para sortear neste evento.');
    $g = $c[random_int(0, count($c) - 1)];
    $brinde = txt($brinde, 100);
    $p->prepare('INSERT INTO sorteios(evento,mnorm,matricula,nome,setor,brinde,em) VALUES(?,?,?,?,?,?,?)')
      ->execute([$ev['id'], $g['mnorm'], $g['matricula'], $g['nome'], $g['setor'], $brinde, agora()]);
    $idS = (int)$p->lastInsertId();
    registrar('Sorteio de brinde', "{$ev['nome']} · " . ($brinde ?: 'brinde') . " · {$g['matricula']} – {$g['nome']} (entre " . count($c) . ')');
    $e = eleitor_por_mat($g['matricula']);   // nome de escala (apelido) para o telão
    return ['ganhador' => ['id' => $idS, 'matricula' => $g['matricula'], 'nome' => $g['nome'],
            'escala' => (string)($e['escala'] ?? ''), 'setor' => (string)$g['setor'], 'brinde' => $brinde],
            'concorreram' => count($c)] + pre_dados($ev['id']);
}

/* ganhador não estava lá na hora: tira o sorteio (e a presença, para não sair de novo) */
function api_preAnular($t, $id): array {
    exige_admin($t);
    $st = pre_tabelas()->prepare('SELECT * FROM sorteios WHERE id=?'); $st->execute([(int)$id]);
    $s = $st->fetch() ?: falha('Sorteio não encontrado.');
    $p = pre_tabelas();
    $p->prepare('DELETE FROM sorteios WHERE id=?')->execute([$s['id']]);
    $p->prepare('DELETE FROM presencas WHERE evento=? AND mnorm=?')->execute([$s['evento'], $s['mnorm']]);
    registrar('Sorteio anulado (ganhador ausente)', "{$s['matricula']} – {$s['nome']}" . ($s['brinde'] ? " · {$s['brinde']}" : ''));
    return pre_dados((int)$s['evento']);
}
