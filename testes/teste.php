<?php
/* Teste de ponta a ponta das regras da urna, direto nas funções (sem servidor).
   Uso: php testes/teste.php  (usa uma pasta de dados temporária) */
declare(strict_types=1);
$tmp = sys_get_temp_dir() . '/urna-teste-' . bin2hex(random_bytes(4));
putenv("CIPA_DATA=$tmp");
putenv('CIPA_ADMIN_SENHA=senha-teste-1');
putenv('CIPA_PERMITIR_ZERAR');
$_SERVER['HTTP_HOST'] = 'urna.test'; $_SERVER['SCRIPT_NAME'] = '/api.php'; $_SERVER['REMOTE_ADDR'] = '10.0.0.1'; $_SERVER['HTTP_X_FORWARDED_FOR'] = '6.6.6.6, 200.10.20.30'; $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 Chrome/129 Mobile Safari/537.36';
require __DIR__ . '/../app/lib.php';

$ok = 0; $ko = 0;
function t(string $nome, bool $cond) { global $ok, $ko; if ($cond) $ok++; else { $ko++; echo "FALHOU: $nome\n"; } }
function erro(string $nome, callable $f, string $trecho) {
    try { $f(); t("$nome (devia dar erro)", false); }
    catch (Erro $e) { t("$nome → " . $e->getMessage(), str_contains($e->getMessage(), $trecho)); }
}

erro('admin senha errada', fn() => api_adminLogin('admin', 'x'), 'incorretos');
$A = api_adminLogin('ADMIN', 'senha-teste-1')['token'];
t('admin entra', strlen($A) === 48);
erro('sem sessão admin', fn() => api_adminData('xyz'), 'expirada');

$rows = [];
for ($i = 1; $i <= 10; $i++) $rows[] = ['matricula' => (string)(1000 + $i), 'nome' => "Colaborador $i", 'escala' => "C$i", 'cargo' => 'Motorista', 'setor' => $i <= 5 ? 'Tráfego' : 'Oficina'];
$rows[] = ['matricula' => '007048', 'nome' => 'Zero À Esquerda', 'setor' => ''];
$d = api_importar($A, $rows, true);
t('importou 11', count($d['eleitores']) === 11);
t('matrícula com zero preservada', $d['eleitores'][10]['matricula'] === '007048');

erro('consultar antes de abrir', fn() => api_consultar('1001'), 'não está aberta');
$r = api_inscConsultar('1001'); t('inscrição consulta', $r['nome'] === 'Colaborador 1' && $r['jaNumero'] === '');
$r = api_inscrever('1001', ''); t('inscrição número 01', $r['numero'] === '01');
erro('inscrição dupla', fn() => api_inscrever('01001', ''), 'já está inscrito');
erro('foto inválida', fn() => api_inscrever('1002', 'javascript:alert(1)'), 'inválido');
api_saveCand($A, ['numero' => '7', 'nome' => 'Candidato Sete', 'escala' => 'Sete', 'admissao' => '2010-01-01'], '');
api_saveCand($A, ['numero' => '12', 'nome' => 'Candidato Doze', 'admissao' => '2005-01-01'], '');
erro('número duplicado', fn() => api_saveCand($A, ['numero' => '07', 'nome' => 'Outro'], ''), 'já pertence');
$d = api_saveCand($A, ['numero' => '13', 'nome' => 'Candidato Doze', 'admissao' => '2005-01-01'], '12');
t('renumerar 12→13', array_column($d['candidatos'], 'numero') === ['01', '07', '13']);
$b = api_ballot(); t('cédula sem matrícula', !isset($b['candidatos'][0]['matricula']));

$tk = api_fotoToken($A); t('url da foto', str_starts_with($tk['url'], 'http://urna.test/foto/'));
api_fotoEnviar($tk['token'], 'data:image/jpeg;base64,AAAA');
t('foto chega', api_fotoBuscar($A, $tk['token'])['foto'] === 'data:image/jpeg;base64,AAAA');
t('foto consumida', api_fotoBuscar($A, $tk['token']) === []);

api_status($A, 'aberta');
erro('candidato travado', fn() => api_delCand($A, '07'), 'antes da abertura');
erro('importar travado', fn() => api_importar($A, $rows, true), 'antes da abertura');
erro('zerar proibido', fn() => api_status($A, 'preparacao'), 'não é permitido');
t('consulta por matrícula sem zeros', api_consultar('7048')['nome'] === 'Zero À Esquerda');
erro('matrícula inexistente', fn() => api_consultar('999'), 'não encontrada');

$votos = ['07', '07', '07', '13', '13', '01', 'BRANCO', 'NULO'];
foreach ($votos as $i => $n) {
    $s = api_login((string)(1001 + $i), '');
    $v = api_votar($s['token'], $n, $i === 7, $i < 2 ? 'MESMOAPARELHO' : "ap$i");
    t("voto $i", (bool)preg_match('/^[A-Z2-9]{4}-[A-Z2-9]{4}$/', $v['comprovante']));
}
erro('voto duplo', fn() => api_login('1001', ''), 'já votou');
$s = api_login('1009', '');
erro('número inválido', fn() => api_votar($s['token'], '99'), 'inválido');
erro('token falso', fn() => api_votar('abc', '07'), 'expirada');
t('consulta já votou', api_consultar('1001')['jaVotou'] === true);

$d = api_adminData($A);
t('urna = votantes', $d['urna'] === 8 && $d['votantes'] === 8);
$cols = db()->query('PRAGMA table_info(votos)')->fetchAll();
t('votos sem coluna de eleitor/hora', array_column($cols, 'name') === ['id', 'voto']);
erro('resultado antes de encerrar', fn() => api_resultado($A), 'após o encerramento');

api_status($A, 'encerrada');
erro('votar após encerrar', fn() => api_votar($s['token'], '07'), 'encerrada');
$r = api_resultado($A);
t('hash', strlen($r['cfg']['hash']) === 64);
t('apuração', array_column($r['candidatos'], 'votos') === [3, 2, 1] && $r['brancos'] === 1 && $r['nulos'] === 1 && $r['validos'] === 6);
t('integridade', $r['integridade'] && $r['total'] === 8);
t('quórum 8/11', $r['quorum'] && round($r['participacao'], 1) === 72.7);
t('situação', $r['candidatos'][0]['situacao'] === 'Titular');
api_gerarAta($A, "ATA\nteste");
t('ata registrada', count(kv_get('atas')) === 1);

api_status($A, 'aberta'); // prorrogação mantém votos
t('prorrogação mantém votos', api_adminData($A)['urna'] === 8);
api_status($A, 'encerrada');

erro('senha curta', fn() => api_trocarSenha($A, 'senha-teste-1', '123', ''), '8 caracteres');
api_trocarSenha($A, 'senha-teste-1', 'nova-senha-9', 'comissao');
erro('senha antiga não vale', fn() => api_adminLogin('admin', 'senha-teste-1'), 'incorretos');
t('nova senha vale', strlen(api_adminLogin('comissao', 'nova-senha-9')['token']) === 48);

for ($i = 0; $i < 8; $i++) { try { api_adminLogin('x', 'y'); } catch (Erro $e) {} }
erro('bloqueio por tentativas', fn() => api_adminLogin('comissao', 'nova-senha-9'), 'Muitas tentativas');

// auditoria: participação com IP/aparelho, alertas, sem ligação matrícula→voto
$au = api_auditoria($A);
t('auditoria lista 8 votantes', count($au['votantes']) === 8);
t('IP real atrás do proxy', $au['votantes'][0]['ip'] === '200.10.20.30');
t('aparelho descrito', $au['votantes'][0]['disp'] === 'Android · Chrome');
t('voto do totem marcado', count(array_filter($au['votantes'], fn($v) => $v['totem'])) === 1);
t('alerta mesmo aparelho', (bool)array_filter($au['alertas'], fn($a) => $a['nivel'] === 'alto' && str_contains($a['texto'], '2 matrículas')));
t('boletim no extrato', $au['resultado']['total'] === 8);
t('votante não carrega voto', !array_key_exists('voto', $au['votantes'][0]));
// cronograma e modo de teste
putenv('CIPA_PERMITIR_ZERAR=1');
$A2 = sessao_nova('admin', 'admin', 3600);   // o IP de teste já está bloqueado pelas senhas erradas acima
$d = api_recomecar($A2, false);
t('zerar votos mantém candidatos e colaboradores', count($d['candidatos']) === 3 && count($d['eleitores']) === 11 && $d['urna'] === 0 && $d['votantes'] === 0 && $d['cfg']['status'] === 'preparacao');
$f = fn(int $min) => date('Y-m-d\TH:i', time() + $min * 60);
$ag = fn(array $x) => api_saveAgenda($A2, $x + ['inscricoes' => 'sim', 'inscInicio' => '', 'inscFim' => '', 'votoInicio' => '', 'votoFim' => '']);
$ag(['inscInicio' => $f(60), 'inscFim' => $f(120)]);
erro('inscrição antes do início', fn() => api_inscConsultar('1005'), 'abrem em');
t('cédula informa situação', api_ballot()['cfg']['inscSituacao'] === 'antes');
$ag(['inscInicio' => $f(-120), 'inscFim' => $f(-60)]);
erro('inscrição depois do fim', fn() => api_inscConsultar('1005'), 'encerradas em');
$ag(['inscInicio' => $f(-60), 'inscFim' => $f(60)]);
t('inscrição dentro do prazo', api_inscConsultar('1005')['nome'] === 'Colaborador 5');
$ag(['inscricoes' => 'nao']);
erro('inscrição desabilitada', fn() => api_inscConsultar('1005'), 'estão encerradas');
erro('fim antes do início', fn() => $ag(['inscInicio' => $f(10), 'inscFim' => $f(5)]), 'depois do início');
erro('data inválida', fn() => $ag(['votoInicio' => '10/10/2026']), 'Data inválida');
$ag(['inscricoes' => 'nao', 'votoInicio' => $f(30), 'votoFim' => $f(90)]);
t('não abre antes da hora', cfg()['status'] === 'preparacao');
$ag(['inscricoes' => 'nao', 'votoInicio' => $f(-1), 'votoFim' => $f(60)]);
t('abriu sozinha no horário', cfg()['status'] === 'aberta');
erro('início não muda depois de aberta', fn() => $ag(['inscricoes' => 'nao', 'votoInicio' => $f(5), 'votoFim' => $f(60)]), 'não pode mudar');
$s = api_login('1001', ''); api_votar($s['token'], '07');
$c = cfg(); $c['votoFim'] = $f(-1); salvar_cfg($c); agenda_aplicar();
t('encerrou sozinha no horário', cfg()['status'] === 'encerrada' && strlen(cfg()['hash']) === 64);
api_status($A2, 'aberta');
t('prorrogação limpa o fim vencido', cfg()['status'] === 'aberta' && cfg()['votoFim'] === '');
api_status($A2, 'encerrada');
$d = api_recomecar($A2, true);
t('recomeçar do zero apaga tudo', !$d['candidatos'] && !$d['eleitores'] && $d['urna'] === 0 && $d['cfg']['status'] === 'preparacao');
t('recomeçar mantém senha', (bool)kv_get('admin'));
putenv('CIPA_PERMITIR_ZERAR');
erro('apagar proibido sem a variável', fn() => api_recomecar($A2, false), 'não é permitido');

echo "\n$ok ok, $ko falha(s)\n";
exit($ko ? 1 : 0);
