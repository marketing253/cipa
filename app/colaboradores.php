<?php
/*
 * Cadastro de colaboradores (09/11/2026), atualizado todo dia pelos e-mails do RH:
 *   - e-mail de ADMITIDOS: quem é novo entra; quem já existe tem os dados atualizados (campo vazio não apaga nada);
 *   - e-mail de DEMITIDOS: a pessoa fica BLOQUEADA (não some): não entra mais no check-in, na paródia nem no /admin.
 *
 * Fica numa tabela própria, separada da lista de eleitores: a lista da eleição é o registro oficial da votação
 * e não muda. Quem não está nesta tabela continua sendo achado na lista de eleitores (o cadastro de antes).
 *
 * Os e-mails chegam pelo n8n (colabSync, com a chave da integração) ou colados no painel (colabColar).
 * O texto é lido por colab_ler(): aceita tabela com cabeçalho (tab, ; ou |, HTML), linhas "Matrícula: 123" / "Nome: ..."
 * ou linhas soltas com o número da matrícula e o nome. O CPF ainda não vem: quando vier, é aproveitado.
 *
 * O e-mail do RADSYSTEM (assunto "Rad_ColaboradorDemitidos Enviado em: ...") traz só Nome, Telefone, Tipo e datas,
 * SEM matrícula: a pessoa é achada pelo NOME COMPLETO no cadastro (sem acento e sem diferença de maiúsculas).
 * Nome que aparece duas vezes no cadastro não é mexido (fica "conferir"). Admitido sem matrícula entra como
 * pendente (chave "n:<nome>") e a comissão completa a matrícula no painel; até lá ele não consegue entrar em nada.
 */
declare(strict_types=1);

function colab_tabelas(): PDO {
    static $feito = false;
    $p = db();
    if ($feito) return $p;
    $p->exec(<<<SQL
        CREATE TABLE IF NOT EXISTS colaboradores (
            mnorm TEXT PRIMARY KEY, matricula TEXT NOT NULL, nome TEXT NOT NULL, escala TEXT, cargo TEXT, setor TEXT,
            codigo TEXT, admissao TEXT, ativo INTEGER NOT NULL DEFAULT 1, demissao TEXT, bloqueado_em TEXT,
            atualizado TEXT NOT NULL, origem TEXT);
        CREATE TABLE IF NOT EXISTS colab_sync (
            id INTEGER PRIMARY KEY AUTOINCREMENT, em TEXT NOT NULL, tipo TEXT NOT NULL, origem TEXT, assunto TEXT,
            lidos INTEGER, novos INTEGER, atualizados INTEGER, bloqueados INTEGER, reativados INTEGER, detalhe TEXT);
    SQL);
    $feito = true;
    return $p;
}

/* ---------- consulta (usada pela paródia, presença e /admin) ---------- */

/* Junta o cadastro novo com a lista de eleitores. Bloqueado → avisa e para. Não achado → null. */
function colab_por_mat($m): ?array {
    $k = mat_norm($m); if ($k === '') return null;
    $st = colab_tabelas()->prepare('SELECT * FROM colaboradores WHERE mnorm=?'); $st->execute([$k]);
    $c = $st->fetch();
    $e = eleitor_por_mat($m);
    if (!$c) return $e;
    if (!(int)$c['ativo']) falha('Cadastro bloqueado: esta matrícula consta como desligada da empresa. Em caso de dúvida, procure a comissão da CIPA.');
    $r = $e ?: ['matricula' => $c['matricula'], 'codigo' => ''];
    foreach (['nome', 'escala', 'cargo', 'setor', 'codigo'] as $f)
        if (trim((string)$c[$f]) !== '') $r[$f] = $c[$f];
        elseif (!isset($r[$f])) $r[$f] = '';
    return $r;
}

/* lista de todos os ativos (cadastro novo por cima da lista de eleitores), por mnorm */
function colab_todos(): array {
    $r = [];
    foreach (db()->query('SELECT mnorm,matricula,nome,escala,cargo,setor FROM eleitores ORDER BY ordem') as $e)
        if (!isset($r[$e['mnorm']])) $r[$e['mnorm']] = $e + ['ativo' => 1, 'fonte' => 'eleitores'];
    foreach (colab_tabelas()->query('SELECT * FROM colaboradores') as $c) {
        $b = $r[$c['mnorm']] ?? ['mnorm' => $c['mnorm'], 'matricula' => $c['matricula'], 'nome' => '', 'escala' => '', 'cargo' => '', 'setor' => ''];
        foreach (['nome', 'escala', 'cargo', 'setor'] as $f) if (trim((string)$c[$f]) !== '') $b[$f] = $c[$f];
        $b['ativo'] = (int)$c['ativo']; $b['fonte'] = 'colaboradores';
        $r[$c['mnorm']] = $b;
    }
    return $r;
}
function colab_ativos(): array { return array_filter(colab_todos(), fn($c) => $c['ativo']); }
function colab_escala(string $mat): string {
    static $m = null;
    if ($m === null) { $m = []; foreach (colab_todos() as $k => $c) if (trim((string)$c['escala']) !== '') $m[$k] = trim((string)$c['escala']); }
    return $m[mat_norm($mat)] ?? '';
}

/* ---------- leitura do texto do e-mail ---------- */

function colab_sem_acento(string $s): string {
    $s = mb_strtolower($s);
    return strtr($s, ['á'=>'a','à'=>'a','ã'=>'a','â'=>'a','é'=>'e','ê'=>'e','í'=>'i','ó'=>'o','õ'=>'o','ô'=>'o','ú'=>'u','ü'=>'u','ç'=>'c']);
}

/* nome para comparar: sem acento, minúsculo, só letras */
function colab_nome_norm(string $n): string {
    return trim(preg_replace('/[^a-z]+/', ' ', colab_sem_acento($n)));
}
/* nome normalizado → chaves (mnorm) de quem tem esse nome no cadastro */
function colab_indice_nomes(): array {
    $i = [];
    foreach (colab_todos() as $k => $c) { $n = colab_nome_norm((string)$c['nome']); if ($n !== '') $i[$n][] = (string)$k; }
    return $i;
}

/* nome da coluna → campo */
function colab_campo(string $rot): ?string {
    $r = colab_sem_acento(trim($rot, " \t:*-_."));
    if ($r === '') return null;
    if (preg_match('/cpf/', $r)) return 'cpf';
    if (preg_match('/^(matr|chapa|registro|re$|cod(igo)?\.? ?(do )?(func|colab)|id func)/', $r)) return 'matricula';
    if (preg_match('/escala|apelido|nome de guerra|nome social/', $r)) return 'escala';
    if (preg_match('/^(nome|colaborador|funcionario|empregado)/', $r)) return 'nome';
    if (preg_match('/cargo|funcao/', $r)) return 'cargo';
    if (preg_match('/setor|depart|lotacao|area|centro de custo|garagem|unidade/', $r)) return 'setor';
    if (preg_match('/^(tipo|situacao|movimentacao)$/', $r)) return 'tipo';
    if (preg_match('/telefone|celular|fone/', $r)) return 'telefone';
    if (preg_match('/admiss|data de entrada|inicio/', $r)) return 'admissao';
    if (preg_match('/demiss|deslig|rescis|data de saida|afastamento/', $r)) return 'demissao';
    return null;
}

function colab_partes(string $l): array {
    if (str_contains($l, "\t")) $p = explode("\t", $l);
    elseif (substr_count($l, ';') >= 1) $p = explode(';', $l);
    elseif (substr_count($l, '|') >= 2) $p = explode('|', $l);
    else $p = preg_split('/\s{3,}/', $l);
    if (count($p) === 1) $p = preg_split('/\s+[-–]\s+/u', $l);   // "1234 - FULANO DE TAL - MOTORISTA"
    return array_map('trim', $p);
}

function colab_texto_limpo(string $t): string {
    if (preg_match('/<(table|tr|td|br|p|div)\b/i', $t)) {   // corpo em HTML
        $t = preg_replace('#<(style|script|head)\b.*?</\1>#is', ' ', $t);
        $t = preg_replace('/\s+/u', ' ', $t);   // as quebras do código HTML não contam
        $t = preg_replace('#<\s*(br|/p|/div|/tr|/li|/h\d)\b[^>]*>#i', "\n", $t);
        $t = preg_replace('#<\s*/t[dh]\s*>#i', "\t", $t);
        $t = html_entity_decode(strip_tags($t), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    $t = str_replace(["\r\n", "\r", "\xC2\xA0"], ["\n", "\n", ' '], $t);
    return $t;
}

/* devolve ['linhas' => [{matricula,nome,escala,cargo,setor,cpf,admissao,demissao}], 'ignoradas' => [texto]] */
function colab_ler(string $texto): array {
    $linhas = array_values(array_filter(array_map(fn($l) => rtrim($l, " \t"), explode("\n", colab_texto_limpo($texto))), fn($l) => trim($l) !== ''));
    $out = []; $ign = [];
    $cab = null; $reg = [];
    $fecha = function () use (&$reg, &$out) { if ($reg && (($reg['matricula'] ?? '') !== '' || ($reg['nome'] ?? '') !== '')) $out[] = $reg; $reg = []; };
    foreach ($linhas as $l) {
        $partes = colab_partes($l);
        // cabeçalho de tabela: duas ou mais colunas conhecidas, uma delas matrícula ou nome
        $map = []; foreach ($partes as $i => $p) { $c = colab_campo($p); if ($c && !in_array($c, $map, true) && mb_strlen($p) <= 40) $map[$i] = $c; }
        if (count($partes) >= 2 && count($map) >= 2 && (in_array('matricula', $map, true) || in_array('nome', $map, true))
            && !preg_match('/\d{3,}/', $l)) { $fecha(); $cab = $map; continue; }
        // "Rótulo: valor" (um campo por linha, ou vários na mesma linha separados por tab/;)
        if (preg_match('/^[^:]{2,30}:\s*\S/u', $l)) {
            $achou = false;
            foreach (count($partes) > 1 ? $partes : [$l] as $pt) {
                if (!preg_match('/^([^:]{2,30}):\s*(.*)$/u', $pt, $m)) continue;
                $c = colab_campo($m[1]); if (!$c) continue;
                if ($c === 'matricula' && ($reg['matricula'] ?? '') !== '') $fecha();
                $reg[$c] = trim($m[2]); $achou = true;
            }
            if ($achou) continue;
        }
        // linha de tabela com cabeçalho já visto
        if ($cab !== null && count($partes) >= 2) {
            $fecha(); $r = [];
            foreach ($cab as $i => $c) $r[$c] = $partes[$i] ?? '';
            if (($r['matricula'] ?? '') !== '' || ($r['nome'] ?? '') !== '') { $out[] = $r; continue; }
        }
        // linha solta: número da matrícula + nome (CPF e datas são reconhecidos e separados)
        $s = $l; $r = [];
        if (preg_match('/\b(\d{3}\.?\d{3}\.?\d{3}-?\d{2})\b/', $s, $m)) { $r['cpf'] = $m[1]; $s = str_replace($m[1], ' ', $s); }
        if (preg_match_all('#\b\d{1,2}/\d{1,2}/\d{2,4}\b#', $s, $m)) { $r['data'] = $m[0][0]; $s = preg_replace('#\b\d{1,2}/\d{1,2}/\d{2,4}\b#', ' ', $s); }
        if (preg_match('/(?<![\d.,])(\d{3,8})(?![\d.,])/', $s, $m)) { $r['matricula'] = $m[1]; $s = preg_replace('/(?<![\d.,])' . $m[1] . '(?![\d.,])/', ' ', $s, 1); }
        $ps = array_values(array_filter(colab_partes(trim(preg_replace('/^[\s\-–•*.)]+|[\s\-–•*]+$/u', '', $s))), fn($x) => trim($x, " -–") !== ''));
        $nome = '';
        foreach ($ps as $i => $x) {   // o nome: o primeiro pedaço com duas palavras ou mais
            $x = trim($x, " -–:");
            if (preg_match('/^\p{L}[\p{L}\'’. ]+\s\p{L}[\p{L}\'’. ]*$/u', $x)) { $nome = $x; unset($ps[$i]); break; }
        }
        if (($r['matricula'] ?? '') !== '' && $nome !== '') {
            $fecha();
            $r['nome'] = $nome;
            $resto = array_values(array_map(fn($x) => trim($x, " -–:"), $ps));
            if (isset($resto[0]) && $resto[0] !== '') $r['cargo'] = $resto[0];
            if (isset($resto[1]) && $resto[1] !== '') $r['setor'] = $resto[1];
            $out[] = $r;
        } else $ign[] = mb_substr(trim($l), 0, 160);
    }
    $fecha();
    // limpa e normaliza
    $res = []; $vistos = [];
    foreach ($out as $r) {
        $mat = preg_replace('/\D/', '', (string)($r['matricula'] ?? ''));
        $nome = preg_replace('/\s+/', ' ', trim((string)($r['nome'] ?? '')));
        if (mat_norm($mat) === '') $mat = '';
        if ($mat === '' && colab_nome_norm($nome) === '') continue;
        $chave = $mat !== '' ? mat_norm($mat) : 'n:' . colab_nome_norm($nome);
        if (isset($vistos[$chave])) continue;
        $vistos[$chave] = true;
        $res[] = ['matricula' => $mat, 'nome' => txt($nome, 120), 'escala' => txt($r['escala'] ?? '', 60), 'cargo' => txt($r['cargo'] ?? '', 120),
                  'setor' => txt($r['setor'] ?? '', 120), 'cpf' => preg_replace('/\D/', '', (string)($r['cpf'] ?? '')),
                  'admissao' => txt($r['admissao'] ?? ($r['data'] ?? ''), 20), 'demissao' => txt($r['demissao'] ?? ($r['data'] ?? ''), 20)];
    }
    // linhas de saudação/assinatura não são erro: só mostra as que parecem ter número
    $ign = array_values(array_filter($ign, fn($x) => preg_match('/\d{3,}/', preg_replace('#\d{1,2}/\d{1,2}/\d{2,4}#', '', $x))));
    return ['linhas' => $res, 'ignoradas' => array_slice($ign, 0, 50)];
}

/* admitidos ou demitidos, pelo assunto (ou pelo texto) */
function colab_tipo(string $tipo, string $assunto, string $texto): string {
    $tipo = colab_sem_acento(trim($tipo));
    if (in_array($tipo, ['admitidos', 'demitidos'], true)) return $tipo;
    foreach ([$assunto, mb_substr($texto, 0, 600)] as $s) {
        $s = colab_sem_acento($s);
        $d = preg_match('/demiss|demitid|deslig|rescis/', $s); $a = preg_match('/admiss|admitid|contratad|novos colab/', $s);
        if ($d && !$a) return 'demitidos';
        if ($a && !$d) return 'admitidos';
    }
    falha('Não deu para saber se o e-mail é de admitidos ou de demitidos. Escolha o tipo.');
}

/* ---------- aplicar ---------- */

function colab_cpf_codigo(string $cpf): string {
    if (strlen($cpf) !== 11) return '';
    $mode = cfg()['verificacao'] ?: 'nenhuma';
    return $mode === 'cpf' ? substr($cpf, -4) : substr($cpf, 0, 3);
}

/* faz (ou só simula) a atualização; devolve o resumo com cada pessoa */
function colab_aplicar(string $tipo, array $linhas, bool $gravar, string $origem, string $assunto = ''): array {
    $p = colab_tabelas();
    $acha = $p->prepare('SELECT * FROM colaboradores WHERE mnorm=?');
    $res = ['tipo' => $tipo, 'lidos' => count($linhas), 'novos' => 0, 'atualizados' => 0, 'bloqueados' => 0, 'reativados' => 0, 'jaBloqueados' => 0, 'naoAchados' => 0, 'conferir' => 0, 'pessoas' => []];
    $idx = colab_indice_nomes();
    if ($gravar) $p->exec('BEGIN IMMEDIATE');
    try {
        $agora = agora();
        foreach ($linhas as $r) {
            $pelo = '';
            if ($r['matricula'] !== '') $k = mat_norm($r['matricula']);
            else {   // sem matrícula no e-mail: acha pelo nome completo
                $nn = colab_nome_norm($r['nome']); $ks = $idx[$nn] ?? [];
                if (count($ks) > 1) { $res['conferir']++; $res['pessoas'][] = ['matricula' => '', 'nome' => $r['nome'], 'acao' => 'Conferir: há ' . count($ks) . ' pessoas com esse nome no cadastro (nada foi mudado)']; continue; }
                if ($ks) { $k = $ks[0]; $pelo = ' (achado pelo nome)'; }
                elseif ($tipo === 'admitidos') { $k = 'n:' . $nn; $idx[$nn] = [$k]; }
                else { $res['conferir']++; $res['pessoas'][] = ['matricula' => '', 'nome' => $r['nome'], 'acao' => 'Conferir: não achei esse nome no cadastro (nada foi mudado)']; continue; }
            }
            $acha->execute([$k]); $c = $acha->fetch();
            $e = str_starts_with($k, 'n:') ? null : eleitor_por_mat($k);
            $mat = (string)($c['matricula'] ?? ($e['matricula'] ?? $r['matricula']));
            $r['matricula'] = $mat;
            $nome = $r['nome'] ?: (string)($c['nome'] ?? ($e['nome'] ?? ''));
            if ($tipo === 'admitidos') {
                if ($nome === '') { $res['pessoas'][] = ['matricula' => $r['matricula'], 'nome' => '', 'acao' => 'Sem nome: não incluído']; continue; }
                $acao = $c ? ((int)$c['ativo'] ? 'atualizado' : 'reativado') : ($e ? 'atualizado' : 'novo');
                $res[['novo' => 'novos', 'atualizado' => 'atualizados', 'reativado' => 'reativados'][$acao]]++;
                $res['pessoas'][] = ['matricula' => $mat, 'nome' => $nome, 'setor' => $r['setor'],
                    'acao' => ucfirst($acao) . ($mat === '' ? ' (falta a matrícula: complete no cadastro)' : $pelo)];
                if ($gravar) $p->prepare('INSERT INTO colaboradores(mnorm,matricula,nome,escala,cargo,setor,codigo,admissao,ativo,atualizado,origem)
                        VALUES(?,?,?,?,?,?,?,?,1,?,?) ON CONFLICT(mnorm) DO UPDATE SET nome=excluded.nome,
                        escala=COALESCE(NULLIF(excluded.escala,\'\'),escala), cargo=COALESCE(NULLIF(excluded.cargo,\'\'),cargo),
                        setor=COALESCE(NULLIF(excluded.setor,\'\'),setor), codigo=COALESCE(NULLIF(excluded.codigo,\'\'),codigo),
                        admissao=COALESCE(NULLIF(excluded.admissao,\'\'),admissao), ativo=1, demissao=NULL, bloqueado_em=NULL,
                        atualizado=excluded.atualizado, origem=excluded.origem')
                    ->execute([$k, $mat, $nome, $r['escala'], $r['cargo'], $r['setor'],
                               colab_cpf_codigo($r['cpf']), $r['admissao'], $agora, $origem]);
            } else {
                if (!$c && !$e) { $res['naoAchados']++; $res['pessoas'][] = ['matricula' => $r['matricula'], 'nome' => $nome, 'acao' => 'Não estava no cadastro (bloqueado mesmo assim)']; }
                elseif ($c && !(int)$c['ativo']) { $res['jaBloqueados']++; $res['pessoas'][] = ['matricula' => $r['matricula'], 'nome' => $nome, 'acao' => 'Já estava bloqueado']; continue; }
                else { $res['bloqueados']++; $res['pessoas'][] = ['matricula' => $r['matricula'], 'nome' => $nome, 'setor' => (string)($c['setor'] ?? ($e['setor'] ?? '')), 'acao' => 'Bloqueado' . $pelo]; }
                if ($nome === '') $nome = 'Matrícula ' . $r['matricula'];
                if ($gravar) {
                    $p->prepare('INSERT INTO colaboradores(mnorm,matricula,nome,ativo,demissao,bloqueado_em,atualizado,origem) VALUES(?,?,?,0,?,?,?,?)
                        ON CONFLICT(mnorm) DO UPDATE SET ativo=0, demissao=COALESCE(NULLIF(excluded.demissao,\'\'),demissao),
                        bloqueado_em=excluded.bloqueado_em, atualizado=excluded.atualizado, origem=excluded.origem')
                        ->execute([$k, $mat, $nome, $r['demissao'], $agora, $agora, $origem]);
                    // quem foi desligado perde o acesso ao painel na hora
                    $p->prepare('DELETE FROM sessoes WHERE tipo=? AND ref=?')->execute(['admin', 'c:' . $k]);
                }
            }
        }
        if ($gravar) {
            $p->prepare('INSERT INTO colab_sync(em,tipo,origem,assunto,lidos,novos,atualizados,bloqueados,reativados,detalhe) VALUES(?,?,?,?,?,?,?,?,?,?)')
                ->execute([$agora, $tipo, $origem, txt($assunto, 200), $res['lidos'], $res['novos'], $res['atualizados'], $res['bloqueados'] + $res['naoAchados'], $res['reativados'],
                           json_encode(array_slice($res['pessoas'], 0, 500), JSON_UNESCAPED_UNICODE)]);
            $p->exec('COMMIT');
        }
    } catch (Throwable $ex) { if ($gravar) $p->exec('ROLLBACK'); throw $ex; }
    if ($gravar) registrar($tipo === 'admitidos' ? 'Colaboradores admitidos' : 'Colaboradores desligados (bloqueados)',
        $tipo === 'admitidos' ? "{$res['novos']} novos, {$res['atualizados']} atualizados, {$res['reativados']} reativados · $origem"
                              : ($res['bloqueados'] + $res['naoAchados']) . " bloqueados · $origem", $origem === 'n8n' ? 'Integração n8n' : 'Comissão');
    return $res;
}

/* ---------- integração n8n ---------- */

function colab_chave(): string {
    $k = (string)kv_get('colab_chave', '');
    if ($k === '') { $k = bin2hex(random_bytes(20)); kv_set('colab_chave', $k); }
    return $k;
}

/* chamado pelo n8n: {"fn":"colabSync","args":["<chave>", "<texto do e-mail>", "<assunto>", "auto|admitidos|demitidos"]} */
function api_colabSync($chave, $texto, $assunto = '', $tipo = 'auto'): array {
    $k = (string)kv_get('colab_chave', '');
    if ($k === '' || !is_string($chave) || !hash_equals($k, $chave)) { usleep(400000); falha('Chave da integração inválida.'); }
    $texto = (string)$texto;
    if (strlen($texto) > 2000000) falha('E-mail grande demais.');
    $tipo = colab_tipo((string)$tipo, (string)$assunto, $texto);
    $l = colab_ler($texto);
    if (!$l['linhas']) {
        colab_tabelas()->prepare('INSERT INTO colab_sync(em,tipo,origem,assunto,lidos,novos,atualizados,bloqueados,reativados,detalhe) VALUES(?,?,?,?,0,0,0,0,0,?)')
            ->execute([agora(), $tipo, 'n8n', txt((string)$assunto, 200), json_encode(['ignoradas' => $l['ignoradas']], JSON_UNESCAPED_UNICODE)]);
        return ['ok' => true, 'tipo' => $tipo, 'lidos' => 0, 'aviso' => 'Nenhum colaborador encontrado no texto.'];
    }
    $r = colab_aplicar($tipo, $l['linhas'], true, 'n8n', (string)$assunto);
    unset($r['pessoas']);
    return ['ok' => true, 'ignoradas' => count($l['ignoradas'])] + $r;
}

/* ---------- painel (comissão) ---------- */

function colab_painel(): array {
    $p = colab_tabelas();
    $todos = colab_todos();
    $cad = [];
    foreach ($p->query('SELECT * FROM colaboradores') as $c) $cad[$c['mnorm']] = $c;
    $elei = [];
    foreach (db()->query('SELECT mnorm,codigo FROM eleitores') as $e) $elei[$e['mnorm']] ??= (string)$e['codigo'];
    $lista = [];
    foreach ($todos as $k => $c) {
        $x = $cad[$k] ?? null;
        $lista[] = ['chave' => (string)$k, 'semMatricula' => str_starts_with((string)$k, 'n:'), 'matricula' => (string)$c['matricula'], 'nome' => (string)$c['nome'], 'escala' => (string)$c['escala'], 'cargo' => (string)$c['cargo'],
            'setor' => (string)$c['setor'], 'ativo' => (bool)$c['ativo'], 'temCpf' => trim((string)($x['codigo'] ?? '')) !== '' || trim((string)($elei[$k] ?? '')) !== '',
            'admissao' => (string)($x['admissao'] ?? ''), 'demissao' => (string)($x['demissao'] ?? ''), 'bloqueadoEm' => (string)($x['bloqueado_em'] ?? ''),
            'atualizado' => (string)($x['atualizado'] ?? ''), 'novo' => $x !== null && !array_key_exists($k, $elei)];
    }
    usort($lista, fn($a, $b) => strcmp(colab_sem_acento($a['nome']), colab_sem_acento($b['nome'])));
    $hist = array_map(fn($h) => ['id' => (int)$h['id'], 'em' => $h['em'], 'tipo' => $h['tipo'], 'origem' => (string)$h['origem'], 'assunto' => (string)$h['assunto'],
        'lidos' => (int)$h['lidos'], 'novos' => (int)$h['novos'], 'atualizados' => (int)$h['atualizados'], 'bloqueados' => (int)$h['bloqueados'], 'reativados' => (int)$h['reativados'],
        'pessoas' => json_decode((string)$h['detalhe'], true) ?: []], $p->query('SELECT * FROM colab_sync ORDER BY id DESC LIMIT 60')->fetchAll());
    $ativos = count(array_filter($lista, fn($c) => $c['ativo']));
    return ['colaboradores' => $lista, 'ativos' => $ativos, 'bloqueados' => count($lista) - $ativos,
            'historico' => $hist, 'chave' => colab_chave(), 'endpoint' => url_base() . 'api.php'];
}

function api_colabDados($t): array { exige_admin($t); return colab_painel(); }

/* texto colado no painel: confere primeiro (aplicar=false), depois grava */
function api_colabColar($t, $texto, $tipo = 'auto', $aplicar = false): array {
    exige_admin($t);
    $texto = (string)$texto;
    if (trim($texto) === '') falha('Cole o texto do e-mail.');
    if (strlen($texto) > 2000000) falha('Texto grande demais.');
    $tipo = colab_tipo((string)$tipo, '', $texto);
    $l = colab_ler($texto);
    if (!$l['linhas']) falha('Não encontrei nenhum colaborador (nome ou matrícula) no texto colado.');
    $r = colab_aplicar($tipo, $l['linhas'], (bool)$aplicar, 'painel');
    return ['previa' => $r + ['ignoradas' => $l['ignoradas']], 'aplicado' => (bool)$aplicar] + ($aplicar ? colab_painel() : []);
}

/* edição manual de uma pessoa: escala, setor, cargo, CPF (3 primeiros ou completo) */
function colab_chave_de($m): string { $m = (string)$m; return str_starts_with($m, 'n:') ? $m : mat_norm($m); }

function api_colabSalvar($t, $m, $dados): array {
    exige_admin($t);
    $k = colab_chave_de($m); if ($k === '') falha('Matrícula inválida.');
    $d = (array)$dados;
    $p = colab_tabelas();
    $st = $p->prepare('SELECT * FROM colaboradores WHERE mnorm=?'); $st->execute([$k]); $c = $st->fetch();
    $e = str_starts_with($k, 'n:') ? null : eleitor_por_mat($k);
    if (!$c && !$e) falha('Matrícula não encontrada.');
    if (str_starts_with($k, 'n:')) {   // admitido que veio sem matrícula: completa agora
        $nm = mat_norm($d['matricula'] ?? '');
        if ($nm !== '') {
            $st->execute([$nm]);
            if ($st->fetch()) falha('Essa matrícula já está no cadastro de colaboradores.');
            $p->prepare('UPDATE colaboradores SET mnorm=?, matricula=? WHERE mnorm=?')->execute([$nm, preg_replace('/\D/', '', (string)$d['matricula']), $k]);
            $k = $nm; $st->execute([$k]); $c = $st->fetch(); $e = eleitor_por_mat($k);
        }
    }
    $nome = txt($d['nome'] ?? '', 120) ?: (string)($c['nome'] ?? $e['nome']);
    $cpf = preg_replace('/\D/', '', (string)($d['cpf'] ?? ''));
    $cod = strlen($cpf) === 11 ? colab_cpf_codigo($cpf) : ($cpf !== '' ? (strlen($cpf) === 3 ? $cpf : falha('CPF: digite os 3 primeiros números ou o CPF completo.')) : null);
    $p->prepare('INSERT INTO colaboradores(mnorm,matricula,nome,escala,cargo,setor,codigo,ativo,atualizado,origem) VALUES(?,?,?,?,?,?,?,1,?,?)
        ON CONFLICT(mnorm) DO UPDATE SET nome=excluded.nome, escala=excluded.escala, cargo=excluded.cargo, setor=excluded.setor,
        codigo=COALESCE(excluded.codigo,codigo), atualizado=excluded.atualizado, origem=excluded.origem')
        ->execute([$k, $c['matricula'] ?? $e['matricula'], $nome, txt($d['escala'] ?? '', 60), txt($d['cargo'] ?? '', 120), txt($d['setor'] ?? '', 120), $cod, agora(), 'edição']);
    registrar('Colaborador editado', (($c['matricula'] ?? $e['matricula']) ?: 'sem matrícula') . ' – ' . $nome);
    return colab_painel();
}

function api_colabBloquear($t, $m, $bloquear): array {
    exige_admin($t);
    $k = colab_chave_de($m);
    $e = str_starts_with($k, 'n:') ? null : eleitor_por_mat($k);
    $st = colab_tabelas()->prepare('SELECT * FROM colaboradores WHERE mnorm=?'); $st->execute([$k]); $c = $st->fetch();
    if (!$c && !$e) falha('Matrícula não encontrada.');
    $nome = (string)($c['nome'] ?? $e['nome']);
    if ($bloquear && !$e) {   // sem matrícula: só marca
        colab_tabelas()->prepare('UPDATE colaboradores SET ativo=0, bloqueado_em=?, atualizado=?, origem=? WHERE mnorm=?')->execute([agora(), agora(), 'painel', $k]);
        registrar('Colaborador bloqueado', 'sem matrícula – ' . $nome);
    } elseif ($bloquear) {
        colab_aplicar('demitidos', [['matricula' => $c['matricula'] ?? $e['matricula'], 'nome' => $nome, 'demissao' => '']], true, 'painel', 'Bloqueio manual');
    } else {
        if (!$c) falha('Esta pessoa não está bloqueada.');
        colab_tabelas()->prepare('UPDATE colaboradores SET ativo=1, bloqueado_em=NULL, demissao=NULL, atualizado=?, origem=? WHERE mnorm=?')->execute([agora(), 'painel', $k]);
        registrar('Colaborador desbloqueado', $c['matricula'] . ' – ' . $nome);
    }
    return colab_painel();
}

function api_colabTrocarChave($t): array {
    exige_admin($t);
    kv_set('colab_chave', bin2hex(random_bytes(20)));
    registrar('Chave da integração n8n trocada');
    return colab_painel();
}
