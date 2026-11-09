<?php
/*
 * /admin unificado (09/11/2026): uma entrada só para a comissão e para os jurados.
 *
 * O nível de cada pessoa é definido em Configuração pela comissão:
 *   - comissão: matrícula + senha pessoal (criada no 1º acesso, confirmando os 3 primeiros dígitos do CPF).
 *     A sessão é do tipo 'admin', então vale para a eleição, a paródia e a presença.
 *   - jurado: matrícula + 3 primeiros dígitos do CPF (a mesma lista de jurados da paródia); só vê a votação.
 * O usuário administrador (usuário e senha do /admin antigo) continua entrando, como reserva.
 */
declare(strict_types=1);

const SENHA_MIN = 6;

function acesso_tabelas(): PDO {
    static $feito = false;
    $p = par_tabelas();
    if ($feito) return $p;
    $p->exec('CREATE TABLE IF NOT EXISTS comissao_acessos (mnorm TEXT PRIMARY KEY, matricula TEXT NOT NULL, nome TEXT NOT NULL,
        senha TEXT, criado TEXT NOT NULL, ultimo TEXT)');
    $feito = true;
    return $p;
}

function comissao_por_mat($m): ?array {
    $st = acesso_tabelas()->prepare('SELECT * FROM comissao_acessos WHERE mnorm=?'); $st->execute([mat_norm($m)]);
    return $st->fetch() ?: null;
}

/* limite de tentativas de senha por matrícula (mesma tabela de falhas do login do administrador) */
function acesso_trava(string $mnorm): void {
    $p = db(); $k = 'adm:' . $mnorm;
    $p->prepare('DELETE FROM falhas WHERE em<?')->execute([time() - 900]);
    $st = $p->prepare('SELECT COUNT(*) FROM falhas WHERE ip=?'); $st->execute([$k]);
    if ((int)$st->fetchColumn() >= 6) falha('Muitas tentativas. Aguarde 15 minutos.');
}
function acesso_falhou(string $mnorm): void { db()->prepare('INSERT INTO falhas(ip,em) VALUES(?,?)')->execute(['adm:' . $mnorm, time()]); }

/* passo 1: a matrícula diz o que pedir no passo 2 */
function api_acessoInicio($m): array {
    if (mat_norm($m) === '') falha('Digite sua matrícula.');
    colab_por_mat($m);   // desligado (bloqueado pelo e-mail de demitidos) para aqui
    $c = comissao_por_mat($m);
    if ($c) return ['nivel' => 'comissao', 'nome' => explode(' ', $c['nome'])[0], 'temSenha' => (string)$c['senha'] !== ''];
    if (jurado_por_mat($m)) return ['nivel' => 'jurado', 'nome' => ''];
    falha('Esta matrícula não tem acesso. Procure a comissão da CIPA.');
}

function acesso_sessao(array $c): array {
    acesso_tabelas()->prepare('UPDATE comissao_acessos SET ultimo=? WHERE mnorm=?')->execute([agora(), $c['mnorm']]);
    registrar('Acesso da comissão', $c['matricula'] . ' – ' . $c['nome'] . ' · ' . ip(), $c['nome']);
    return ['token' => sessao_nova('admin', 'c:' . $c['mnorm'], SESSAO_ADMIN), 'nivel' => 'comissao', 'nome' => $c['nome']];
}

function api_acessoEntrar($m, $senha): array {
    $c = comissao_por_mat($m) ?: falha('Esta matrícula não é da comissão.');
    colab_por_mat($m);
    acesso_trava($c['mnorm']);
    if ((string)$c['senha'] === '') falha('Crie sua senha primeiro.');
    if (!password_verify((string)$senha, $c['senha'])) { acesso_falhou($c['mnorm']); falha('Senha incorreta.'); }
    return acesso_sessao($c);
}

/* 1º acesso (ou senha zerada pela comissão): confirma os 3 primeiros dígitos do CPF e cria a senha */
function api_acessoCriarSenha($m, $cpf3, $senha): array {
    $c = comissao_por_mat($m) ?: falha('Esta matrícula não é da comissão.');
    if ((string)$c['senha'] !== '') falha('Você já tem senha. Se esqueceu, peça para outra pessoa da comissão zerar.');
    $e = colab_por_mat($m) ?: falha('Matrícula não encontrada no cadastro de colaboradores.');
    acesso_trava($c['mnorm']);
    $ok = preg_replace('/\D/', '', (string)$e['codigo']);
    if ($ok === '' || substr($ok, 0, 3) !== preg_replace('/\D/', '', (string)$cpf3)) { acesso_falhou($c['mnorm']); falha('Os 3 primeiros números do CPF não conferem.'); }
    if (mb_strlen((string)$senha) < SENHA_MIN) falha('A senha precisa de pelo menos ' . SENHA_MIN . ' caracteres.');
    acesso_tabelas()->prepare('UPDATE comissao_acessos SET senha=? WHERE mnorm=?')->execute([password_hash((string)$senha, PASSWORD_DEFAULT), $c['mnorm']]);
    registrar('Senha da comissão criada', $c['matricula'] . ' – ' . $c['nome'], $c['nome']);
    return acesso_sessao($c);
}

/* =================== configuração (só comissão) =================== */

function acesso_lista(): array {
    $esc = fn($mat) => function_exists('par_escala') ? par_escala($mat) : '';
    $com = array_map(fn($c) => ['matricula' => $c['matricula'], 'nome' => $c['nome'], 'escala' => $esc($c['matricula']), 'nivel' => 'comissao',
        'temSenha' => (string)$c['senha'] !== '', 'ultimo' => (string)$c['ultimo']], acesso_tabelas()->query('SELECT * FROM comissao_acessos ORDER BY nome')->fetchAll());
    $jur = array_map(fn($j) => ['matricula' => $j['matricula'], 'nome' => $j['nome'], 'escala' => $esc($j['matricula']), 'nivel' => 'jurado'],
        par_tabelas()->query('SELECT matricula,nome FROM parodia_jurados ORDER BY nome')->fetchAll());
    return ['pessoas' => array_merge($com, $jur)];
}

function api_acessoLista($t): array { exige_admin($t); return acesso_lista(); }

function api_acessoIncluir($t, $m, $nivel): array {
    exige_admin($t);
    if ($nivel === 'jurado') { api_parJurado($t, $m); return acesso_lista(); }
    if ($nivel !== 'comissao') falha('Nível inválido.');
    $e = colab_por_mat($m) ?: falha('Matrícula não encontrada no cadastro de colaboradores.');
    if (comissao_por_mat($e['matricula'])) falha('Esta pessoa já é da comissão.');
    acesso_tabelas()->prepare('INSERT INTO comissao_acessos(mnorm,matricula,nome,criado) VALUES(?,?,?,?)')
        ->execute([mat_norm($e['matricula']), $e['matricula'], $e['nome'], agora()]);
    registrar('Acesso da comissão incluído', $e['matricula'] . ' – ' . $e['nome']);
    return acesso_lista();
}

function api_acessoRemover($t, $m, $nivel): array {
    exige_admin($t);
    if ($nivel === 'jurado') { api_parJurado($t, $m, true); return acesso_lista(); }
    $c = comissao_por_mat($m) ?: falha('Pessoa não encontrada.');
    $p = acesso_tabelas();
    $p->prepare('DELETE FROM comissao_acessos WHERE mnorm=?')->execute([$c['mnorm']]);
    $p->prepare("DELETE FROM sessoes WHERE tipo='admin' AND ref=?")->execute(['c:' . $c['mnorm']]);   // sai na hora
    registrar('Acesso da comissão removido', $c['matricula'] . ' – ' . $c['nome']);
    return acesso_lista();
}

/* esqueceu a senha: outra pessoa da comissão zera, e no próximo acesso ela cria outra com o CPF */
function api_acessoZerarSenha($t, $m): array {
    exige_admin($t);
    $c = comissao_por_mat($m) ?: falha('Pessoa não encontrada.');
    $p = acesso_tabelas();
    $p->prepare('UPDATE comissao_acessos SET senha=NULL WHERE mnorm=?')->execute([$c['mnorm']]);
    $p->prepare("DELETE FROM sessoes WHERE tipo='admin' AND ref=?")->execute(['c:' . $c['mnorm']]);
    registrar('Senha da comissão zerada', $c['matricula'] . ' – ' . $c['nome']);
    return acesso_lista();
}

/* quem está logado (para o menu mostrar o nome) */
function api_acessoEu($t): array {
    exige_admin($t);
    $ref = (string)sessao('admin', $t);
    if (str_starts_with($ref, 'c:') && ($c = comissao_por_mat(substr($ref, 2)))) return ['nome' => $c['nome'], 'nivel' => 'comissao'];
    return ['nome' => 'Administrador', 'nivel' => 'comissao'];
}
