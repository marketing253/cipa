<?php
/* Teste de ponta a ponta das regras da urna, direto nas funções (sem servidor).
   Uso: php testes/teste.php  (usa uma pasta de dados temporária) */
declare(strict_types=1);
$tmp = sys_get_temp_dir() . '/urna-teste-' . bin2hex(random_bytes(4));
putenv("CIPA_DATA=$tmp");
putenv('CIPA_ADMIN_SENHA=senha-teste-1');
putenv('CIPA_PERMITIR_ZERAR');
$_SERVER['HTTP_HOST'] = 'urna.test'; $_SERVER['SCRIPT_NAME'] = '/api.php'; $_SERVER['REMOTE_ADDR'] = '10.0.0.1';
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

$tk = api_fotoToken($A); t('url da foto', str_starts_with($tk['url'], 'http://urna.test/?p=foto&t='));
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
    $v = api_votar($s['token'], $n);
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

echo "\n$ok ok, $ko falha(s)\n";
exit($ko ? 1 : 0);
