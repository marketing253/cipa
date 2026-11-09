<?php
/* /admin unificado: comissão com senha pessoal e jurados, direto nas funções (sem servidor).
   Uso: php testes/teste_acesso.php  (pasta de dados temporária) */
declare(strict_types=1);
$tmp = sys_get_temp_dir() . '/acesso-teste-' . bin2hex(random_bytes(4));
putenv("CIPA_DATA=$tmp");
putenv('CIPA_ADMIN_SENHA=senha-teste-1');
$_SERVER['HTTP_HOST'] = 'urna.test'; $_SERVER['SCRIPT_NAME'] = '/api.php'; $_SERVER['REMOTE_ADDR'] = '10.0.0.9';
require __DIR__ . '/../app/lib.php';
require __DIR__ . '/../app/parodia.php';
require __DIR__ . '/../app/presenca.php';
require __DIR__ . '/../app/acesso.php';

$ok = 0; $ko = 0;
function t(string $nome, bool $cond) { global $ok, $ko; if ($cond) $ok++; else { $ko++; echo "FALHOU: $nome\n"; } }
function erro(string $nome, callable $f, string $trecho) {
    try { $f(); t("$nome (devia dar erro)", false); }
    catch (Erro $e) { t("$nome → " . $e->getMessage(), str_contains($e->getMessage(), $trecho)); }
}

$A = api_adminLogin('admin', 'senha-teste-1')['token'];
api_saveConfig($A, ['verificacao' => 'cpf3', 'vagasTitulares' => 4]);
$rows = [];
for ($i = 1; $i <= 4; $i++) $rows[] = ['matricula' => (string)(5000 + $i), 'nome' => "Pessoa $i Silva", 'escala' => "Apelido $i", 'setor' => 'RH', 'codigo' => "47$i.456.789-00"];
api_importar($A, $rows, true);

erro('sem acesso', fn() => api_acessoInicio('5001'), 'não tem acesso');
erro('incluir sem login', fn() => api_acessoIncluir('x', '5001', 'comissao'), 'expirada');
erro('nível inválido', fn() => api_acessoIncluir($A, '5001', 'chefe'), 'inválido');
erro('matrícula inexistente', fn() => api_acessoIncluir($A, '999', 'comissao'), 'não encontrada');
$l = api_acessoIncluir($A, '5001', 'comissao');
$l = api_acessoIncluir($A, '5002', 'jurado');
t('lista com comissão e jurado', count($l['pessoas']) === 2 && $l['pessoas'][0]['nivel'] === 'comissao' && $l['pessoas'][1]['nivel'] === 'jurado');
erro('duplicado', fn() => api_acessoIncluir($A, '05001', 'comissao'), 'já é da comissão');

$i = api_acessoInicio('5001');
t('passo 1: comissão sem senha', $i['nivel'] === 'comissao' && !$i['temSenha']);
t('passo 1: jurado', api_acessoInicio('5002')['nivel'] === 'jurado');
erro('entrar sem senha criada', fn() => api_acessoEntrar('5001', 'qualquer'), 'Crie sua senha');
erro('criar com CPF errado', fn() => api_acessoCriarSenha('5001', '999', 'segredo1'), 'não conferem');
erro('senha curta', fn() => api_acessoCriarSenha('5001', '471', '123'), 'pelo menos');
$s = api_acessoCriarSenha('5001', '471', 'segredo1');
t('criou senha e entrou', $s['nivel'] === 'comissao' && strlen($s['token']) > 20);
t('sessão da comissão vale para a paródia', isset(api_parAdmin($s['token'])['parodias']));
t('e para a presença', isset(api_preDados($s['token'])['eventos']));
t('quem sou eu', api_acessoEu($s['token'])['nome'] === 'Pessoa 1 Silva');
erro('criar senha de novo', fn() => api_acessoCriarSenha('5001', '471', 'outra123'), 'já tem senha');
t('passo 1 com senha', api_acessoInicio('5001')['temSenha']);
erro('senha errada', fn() => api_acessoEntrar('5001', 'errada'), 'incorreta');
$s2 = api_acessoEntrar('5001', 'segredo1');
t('entrou com senha', $s2['nivel'] === 'comissao');

// jurado entra pela lista da paródia
$j = api_parJuradoLogin('5002', '472');
t('jurado entra com CPF', strlen($j['token']) > 20);
erro('jurado não acessa a comissão', fn() => api_parAdmin($j['token']), 'expirada');

// zerar senha derruba a sessão; remover tira o acesso
api_acessoZerarSenha($A, '5001');
erro('sessão caiu ao zerar', fn() => api_parAdmin($s2['token']), 'expirada');
t('volta a pedir senha nova', !api_acessoInicio('5001')['temSenha']);
$s3 = api_acessoCriarSenha('5001', '471', 'novasenha');
$l = api_acessoRemover($A, '5001', 'comissao');
t('removeu da comissão', count($l['pessoas']) === 1);
erro('sessão caiu ao remover', fn() => api_parAdmin($s3['token']), 'expirada');
$l = api_acessoRemover($A, '5002', 'jurado');
t('removeu jurado', $l['pessoas'] === []);

// trava depois de muitas senhas erradas
api_acessoIncluir($A, '5003', 'comissao'); api_acessoCriarSenha('5003', '473', 'segredo3');
for ($k = 0; $k < 6; $k++) { try { api_acessoEntrar('5003', 'x'); } catch (Erro $e) {} }
erro('travou', fn() => api_acessoEntrar('5003', 'segredo3'), 'Muitas tentativas');

echo "\n$ok ok, $ko falha(s)\n";
exit($ko ? 1 : 0);
