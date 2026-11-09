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
    // evento encerrado e guardado no histórico (09/11/2026)
    if (!in_array('arquivado', $cols, true)) $p->exec('ALTER TABLE presenca_eventos ADD COLUMN arquivado INTEGER NOT NULL DEFAULT 0');
    if (!in_array('arquivado_em', $cols, true)) $p->exec('ALTER TABLE presenca_eventos ADD COLUMN arquivado_em TEXT');
    // situação do brinde: sorteado (acabou de sair) → entregue, ou pendente (ausente, entregar depois do evento)
    $cs = array_column($p->query('PRAGMA table_info(sorteios)')->fetchAll(), 'name');
    if (!in_array('status', $cs, true)) $p->exec("ALTER TABLE sorteios ADD COLUMN status TEXT NOT NULL DEFAULT 'entregue'");
    if (!in_array('entregue_em', $cs, true)) $p->exec('ALTER TABLE sorteios ADD COLUMN entregue_em TEXT');
    // marca de quem estava ausente na hora: continua 1 depois de receber, para o registro "recebeu depois" (09/11/2026)
    if (!in_array('ausente', $cs, true)) { $p->exec('ALTER TABLE sorteios ADD COLUMN ausente INTEGER NOT NULL DEFAULT 0'); $p->exec("UPDATE sorteios SET ausente=1 WHERE status='pendente'"); }
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
    $e = colab_por_mat($mat);
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
    $e = colab_por_mat($m);
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
    $e = colab_por_mat($m);
    if (!$e) falha('Matrícula não encontrada no cadastro de colaboradores. Procure a comissão da CIPA.');
    confere($e, $c);
    $st = pre_tabelas()->prepare('INSERT OR IGNORE INTO presencas(evento,mnorm,matricula,nome,setor,como,em) VALUES(?,?,?,?,?,?,?)');
    $st->execute([$ev['id'], mat_norm($e['matricula']), $e['matricula'], $e['nome'], (string)$e['setor'], 'Celular', agora()]);
    return ['novo' => $st->rowCount() > 0, 'nome' => $e['nome'], 'evento' => $ev['nome']];
}

/* =================== comissão =================== */

function pre_dados(?int $ev = null): array {
    $p = pre_tabelas();
    $eventos = $p->query('SELECT e.id, e.nome, e.criado, e.token, e.aberto, e.arquivado, e.arquivado_em, (SELECT COUNT(*) FROM presencas WHERE evento=e.id) AS presentes,
        (SELECT COUNT(*) FROM sorteios WHERE evento=e.id) AS sorteados FROM presenca_eventos e ORDER BY e.id DESC')->fetchAll();
    $eventos = array_map(fn($e) => ['id' => (int)$e['id'], 'nome' => $e['nome'], 'criado' => $e['criado'],
        'token' => (string)$e['token'], 'aberto' => (bool)$e['aberto'],
        'arquivado' => (bool)$e['arquivado'], 'arquivadoEm' => (string)$e['arquivado_em'],
        'presentes' => (int)$e['presentes'], 'sorteados' => (int)$e['sorteados']], $eventos);
    if ($ev === null && $eventos) {   // abre no evento ativo mais recente; o histórico fica para quando pedir
        $ativos = array_values(array_filter($eventos, fn($e) => !$e['arquivado']));
        $ev = ($ativos ?: $eventos)[0]['id'];
    }
    $pres = $sort = [];
    if ($ev) {
        $st = $p->prepare('SELECT matricula,nome,setor,como,em FROM presencas WHERE evento=? ORDER BY em DESC'); $st->execute([$ev]);
        $pres = $st->fetchAll();
        // nome de escala (apelido) para o telão do QR; quem não tem fica vazio
        foreach ($pres as &$x) $x['escala'] = colab_escala((string)$x['matricula']); unset($x);
        $st = $p->prepare('SELECT id,matricula,nome,setor,brinde,em,status,entregue_em,ausente FROM sorteios WHERE evento=? ORDER BY id'); $st->execute([$ev]);
        $sort = array_map(fn($s) => ['id' => (int)$s['id'], 'ausente' => (bool)$s['ausente']] + $s, $st->fetchAll());
    }
    // ausentes de todos os eventos que ainda não receberam o brinde
    $pend = array_map(fn($s) => ['id' => (int)$s['id']] + $s, $p->query("SELECT s.id,s.matricula,s.nome,s.setor,s.brinde,s.em,e.nome AS evento
        FROM sorteios s JOIN presenca_eventos e ON e.id=s.evento WHERE s.status='pendente' ORDER BY s.id")->fetchAll());
    // ausentes que já receberam depois (registro de entrega)
    $rec = array_map(fn($s) => ['id' => (int)$s['id']] + $s, $p->query("SELECT s.id,s.matricula,s.nome,s.setor,s.brinde,s.em,s.entregue_em,e.nome AS evento
        FROM sorteios s JOIN presenca_eventos e ON e.id=s.evento WHERE s.status='entregue' AND s.ausente=1 ORDER BY s.entregue_em DESC")->fetchAll());
    return ['eventos' => $eventos, 'evento' => $ev, 'presentes' => $pres, 'sorteios' => $sort, 'pendentes' => $pend, 'recebidos' => $rec, 'urlApp' => function_exists('url_publica') ? url_publica() : url_base()];
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
    if ($aberto && !empty($e['arquivado'])) falha('Este evento está no histórico. Tire do histórico antes de reabrir o check-in.');
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
    if (!empty($ev['arquivado'])) falha('Este evento está no histórico. Tire do histórico para marcar presença.');
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
/* encerrar e guardar no histórico (fecha o check-in) | tirar do histórico (check-in continua fechado) */
function api_preArquivar($t, $evento, $arquivar): array {
    exige_admin($t);
    $e = pre_evento((int)$evento);
    if ($arquivar) pre_tabelas()->prepare('UPDATE presenca_eventos SET arquivado=1, arquivado_em=?, aberto=0 WHERE id=?')->execute([agora(), $e['id']]);
    else pre_tabelas()->prepare('UPDATE presenca_eventos SET arquivado=0, arquivado_em=NULL WHERE id=?')->execute([$e['id']]);
    registrar($arquivar ? 'Evento encerrado e arquivado' : 'Evento tirado do histórico', $e['nome']);
    return pre_dados($e['id']);
}

/* participação por setor: pessoas diferentes que fizeram check-in (num evento ou em todos) × colaboradores do setor */
function api_preEstat($t, $evento = null): array {
    exige_admin($t);
    $p = pre_tabelas();
    $semSetor = 'Sem setor';
    $tot = []; $setorDe = [];
    foreach (colab_ativos() as $e) {   // colaboradores ativos (desligados ficam fora da conta)
        if (isset($setorDe[$e['mnorm']])) continue;
        $s = trim((string)$e['setor']) ?: $semSetor;
        $setorDe[$e['mnorm']] = $s; $tot[$s] = ($tot[$s] ?? 0) + 1;
    }
    $ev = $evento ? pre_evento((int)$evento) : null;
    $st = $ev ? $p->prepare('SELECT mnorm,setor FROM presencas WHERE evento=?') : $p->prepare('SELECT mnorm,setor FROM presencas');
    $st->execute($ev ? [$ev['id']] : []);
    $part = []; $checkins = 0;
    foreach ($st as $r) {
        $checkins++;
        $s = $setorDe[$r['mnorm']] ?? (trim((string)$r['setor']) ?: $semSetor);   // setor atual da lista; se saiu da lista, o da hora do check-in
        $part[$s][$r['mnorm']] = true;
    }
    $setores = [];
    foreach (array_unique(array_merge(array_keys($tot), array_keys($part))) as $s)
        $setores[] = ['setor' => $s, 'total' => $tot[$s] ?? 0, 'participantes' => count($part[$s] ?? [])];
    usort($setores, fn($a, $b) => $b['participantes'] <=> $a['participantes'] ?: strcmp($a['setor'], $b['setor']));
    $unicos = array_sum(array_map('count', $part));
    $evs = array_map(fn($e) => ['id' => $e['id'], 'nome' => $e['nome'], 'presentes' => $e['presentes'], 'arquivado' => $e['arquivado']], pre_dados(null)['eventos']);
    return ['evento' => $ev ? (int)$ev['id'] : null, 'eventoNome' => $ev ? $ev['nome'] : 'Todos os eventos', 'eventos' => $evs,
            'colaboradores' => array_sum($tot), 'participantes' => $unicos, 'checkins' => $checkins, 'setores' => $setores];
}

function api_preSortear($t, $evento, $brinde = ''): array {
    exige_admin($t);
    $ev = pre_evento((int)$evento); $p = pre_tabelas();
    if (!empty($ev['arquivado'])) falha('Este evento está no histórico. Tire do histórico para sortear.');
    $st = $p->prepare('SELECT * FROM presencas WHERE evento=? AND mnorm NOT IN (SELECT mnorm FROM sorteios WHERE evento=?) ORDER BY mnorm');
    $st->execute([$ev['id'], $ev['id']]);
    $c = $st->fetchAll();
    if (!$c) falha('Não há mais presentes para sortear neste evento.');
    $g = $c[random_int(0, count($c) - 1)];
    $brinde = txt($brinde, 100);
    // quem saiu antes e não foi marcado como ausente recebeu o brinde
    $p->prepare("UPDATE sorteios SET status='entregue', entregue_em=? WHERE evento=? AND status='sorteado'")->execute([agora(), $ev['id']]);
    $p->prepare("INSERT INTO sorteios(evento,mnorm,matricula,nome,setor,brinde,em,status) VALUES(?,?,?,?,?,?,?,'sorteado')")
      ->execute([$ev['id'], $g['mnorm'], $g['matricula'], $g['nome'], $g['setor'], $brinde, agora()]);
    $idS = (int)$p->lastInsertId();
    registrar('Sorteio de brinde', "{$ev['nome']} · " . ($brinde ?: 'brinde') . " · {$g['matricula']} – {$g['nome']} (entre " . count($c) . ')');
    return ['ganhador' => ['id' => $idS, 'matricula' => $g['matricula'], 'nome' => $g['nome'],
            'escala' => colab_escala((string)$g['matricula']), 'setor' => (string)$g['setor'], 'brinde' => $brinde],
            'concorreram' => count($c)] + pre_dados($ev['id']);
}

/* sorteio feito por engano: tira o sorteio (e a presença, para não sair de novo). A tela usa o preStatus 'pendente' para ausentes. */
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

/* entregue / ausente (pendente: entregar depois do evento) / sorteado (desfazer) */
function api_preStatus($t, $id, $status, $evento = null): array {
    exige_admin($t);
    $rot = ['entregue' => 'Brinde entregue (recebeu)', 'pendente' => 'Ganhador ausente (entregar depois)', 'sorteado' => 'Entrega desfeita'];
    if (!isset($rot[$status])) falha('Situação inválida.');
    $p = pre_tabelas();
    $st = $p->prepare('SELECT * FROM sorteios WHERE id=?'); $st->execute([(int)$id]);
    $s = $st->fetch() ?: falha('Sorteio não encontrado.');
    // ausente fica marcado (ausente=1) mesmo depois de receber; "desfazer" volta ao normal
    $aus = $status === 'pendente' ? 1 : ($status === 'sorteado' ? 0 : (int)$s['ausente']);
    $p->prepare('UPDATE sorteios SET status=?, entregue_em=?, ausente=? WHERE id=?')
      ->execute([$status, $status === 'entregue' ? agora() : null, $aus, $s['id']]);
    registrar($rot[$status], "{$s['matricula']} – {$s['nome']}" . ($s['brinde'] ? " · {$s['brinde']}" : ''));
    return pre_dados($evento ? (int)$evento : (int)$s['evento']);   // continua no evento que está aberto na tela
}
