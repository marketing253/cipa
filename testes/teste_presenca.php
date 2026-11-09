<?php
/* Presença por QR e sorteio de brindes, direto nas funções (sem servidor).
   Uso: php testes/teste_presenca.php  (pasta de dados temporária) */
declare(strict_types=1);
$tmp = sys_get_temp_dir() . '/presenca-teste-' . bin2hex(random_bytes(4));
putenv("CIPA_DATA=$tmp");
putenv('CIPA_ADMIN_SENHA=senha-teste-1');
$_SERVER['HTTP_HOST'] = 'urna.test'; $_SERVER['SCRIPT_NAME'] = '/api.php'; $_SERVER['REMOTE_ADDR'] = '10.0.0.1';
require __DIR__ . '/../app/lib.php';
require __DIR__ . '/../app/presenca.php';

$ok = 0; $ko = 0;
function t(string $nome, bool $cond) { global $ok, $ko; if ($cond) $ok++; else { $ko++; echo "FALHOU: $nome\n"; } }
function erro(string $nome, callable $f, string $trecho) {
    try { $f(); t("$nome (devia dar erro)", false); }
    catch (Erro $e) { t("$nome → " . $e->getMessage(), str_contains($e->getMessage(), $trecho)); }
}

$A = api_adminLogin('admin', 'senha-teste-1')['token'];
api_saveConfig($A, ['verificacao' => 'cpf3', 'vagasTitulares' => 4]);
$rows = [];
for ($i = 1; $i <= 5; $i++) $rows[] = ['matricula' => (string)(3000 + $i), 'nome' => "Pessoa $i", 'escala' => "Apelido $i", 'setor' => 'Tráfego', 'codigo' => "45$i.456.789-00"];
api_importar($A, $rows, true);

erro('QR com CPF errado', fn() => api_preQr('3001', '999'), 'não conferem');
$q = api_preQr('3001', '451');
t('QR assinado', (bool)preg_match('/^CIPA:3001:[0-9a-f]{10}$/', $q['codigo']));
erro('colaborador não marca', fn() => api_preMarcar('x', 1, $q['codigo']), 'expirada');
erro('sem evento', fn() => api_preMarcar($A, 99, $q['codigo']), 'Evento não encontrado');
erro('evento sem nome', fn() => api_preEvento($A, 'x'), 'nome');
$d = api_preEvento($A, 'SIPAT 2026 – dia 1'); $ev = $d['evento'];
t('evento criado', count($d['eventos']) === 1);
$r = api_preMarcar($A, $ev, $q['codigo']);
t('marcou pelo QR', $r['novo'] && $r['nome'] === 'Pessoa 1' && $r['total'] === 1);
t('leitura repetida não duplica', !api_preMarcar($A, $ev, $q['codigo'])['novo']);
erro('QR falsificado', fn() => api_preMarcar($A, $ev, 'CIPA:3002:0123456789'), 'inválido');
erro('texto qualquer', fn() => api_preMarcar($A, $ev, 'https://site.com'), 'não reconhecido');
erro('matrícula inexistente', fn() => api_preMarcar($A, $ev, '777'), 'não está na lista');
api_preMarcar($A, $ev, '03002');   // digitada, com zero à esquerda
api_preMarcar($A, $ev, api_preQr('3003', '453')['codigo']);
$d = api_preDados($A, $ev);
t('3 presentes', count($d['presentes']) === 3 && $d['eventos'][0]['presentes'] === 3);

$ganhou = [];
for ($i = 0; $i < 3; $i++) { $g = api_preSortear($A, $ev, "Brinde $i")['ganhador']; $ganhou[] = $g['matricula']; }
t('ganhador traz nome de escala e id', str_starts_with($g['escala'], 'Apelido ') && $g['id'] > 0);
t('sorteio sem repetir', count(array_unique($ganhou)) === 3);
$d = api_preDados($A, $ev); $sv = array_column($d['sorteios'], 'status');
t('sortear o próximo marca o anterior como entregue', $sv === ['entregue', 'entregue', 'sorteado']);
t('entregue tem data', $d['sorteios'][0]['entregue_em'] !== null && $d['sorteios'][2]['entregue_em'] === null);
$d = api_preStatus($A, $d['sorteios'][1]['id'], 'pendente');
t('ausente vai para pendentes', count($d['pendentes']) === 1 && $d['pendentes'][0]['evento'] === 'SIPAT 2026 – dia 1' && $d['sorteios'][1]['status'] === 'pendente');
t('ausente continua na presença', count($d['presentes']) === 3);
erro('situação inválida', fn() => api_preStatus($A, $d['sorteios'][1]['id'], 'xyz'), 'inválida');
erro('status sem login', fn() => api_preStatus('x', $d['sorteios'][1]['id'], 'entregue'), 'expirada');
$idPend = $d['sorteios'][1]['id'];
erro('acabaram os presentes', fn() => api_preSortear($A, $ev), 'Não há mais');
$d = api_preDados($A, $ev);
$d = api_preAnular($A, $d['sorteios'][0]['id']);
t('anular tira sorteio e presença', count($d['sorteios']) === 2 && count($d['presentes']) === 2);
erro('ausente não volta ao sorteio', fn() => api_preSortear($A, $ev), 'Não há mais');

$d2 = api_preEvento($A, 'SIPAT 2026 – dia 2'); $ev2 = $d2['evento'];
api_preMarcar($A, $ev2, $q['codigo']);
t('outro evento é separado', count(api_preDados($A, $ev2)['presentes']) === 1);
t('quem ganhou no dia 1 concorre no dia 2', api_preSortear($A, $ev2)['ganhador']['matricula'] === '3001');
$d = api_preDados($A, $ev2);
t('pendente de outro evento aparece no dia 2', count($d['pendentes']) === 1 && $d['pendentes'][0]['id'] === $idPend);
t('pendente não vira entregue ao sortear outro evento', $d['pendentes'][0]['id'] === $idPend);
$d = api_preStatus($A, $idPend, 'entregue', $ev2);
t('entregar depois tira dos pendentes e fica no evento da tela', count($d['pendentes']) === 0 && $d['evento'] === $ev2);
// check-in pelo celular com o QR da mesa
$tk = api_preDados($A, $ev2)['eventos'][0]['token'];
t('evento tem token', (bool)preg_match('/^[0-9a-f]{24}$/', $tk));
erro('token inválido', fn() => api_preCheckin('abc', '3004', '454'), 'inválido');
erro('check-in com CPF errado', fn() => api_preCheckin($tk, '3004', '000'), 'não conferem');
$r = api_preCheckin($tk, '3004', '454');
t('check-in marcou', $r['novo'] && $r['nome'] === 'Pessoa 4');
t('check-in repetido', !api_preCheckin($tk, '3004', '454')['novo']);
t('info do evento', api_preEventoInfo($tk)['aberto'] === true);
api_preAbrir($A, $ev2, false);
erro('check-in encerrado', fn() => api_preCheckin($tk, '3005', '455'), 'encerrado');
api_preAbrir($A, $ev2, true);
t('reaberto', api_preCheckin($tk, '3005', '455')['novo']);
t('presentes do dia 2', count(api_preDados($A, $ev2)['presentes']) === 3);

$fica = api_preDados($A, $ev)['presentes'][0]['matricula'];   // o anulado já saiu; tira um dos que ficaram
$d = api_preDesmarcar($A, $ev, $fica);
t('desmarcou', count($d['presentes']) === 1);
$d = api_preExcluirEvento($A, $ev);
t('evento excluído', count($d['eventos']) === 1 && $d['evento'] === $ev2);

// estatística por setor (09/11/2026): pessoas diferentes × colaboradores do setor
api_importar($A, [['matricula' => '4001', 'nome' => 'Pessoa Oficina', 'setor' => 'Oficina', 'codigo' => '461.456.789-00']], false);
$es = api_preEstat($A);
$tr = array_values(array_filter($es['setores'], fn($x) => $x['setor'] === 'Tráfego'))[0];
$of = array_values(array_filter($es['setores'], fn($x) => $x['setor'] === 'Oficina'))[0];
t('estat: tráfego', $tr['total'] === 5 && $tr['participantes'] === $es['participantes']);
t('estat: oficina 0 de 1', $of['participantes'] === 0 && $of['total'] === 1);
t('estat: totais', $es['colaboradores'] === 6 && $es['checkins'] >= $es['participantes'] && $es['participantes'] > 0);
t('estat: por evento', api_preEstat($A, $ev2)['participantes'] === 3);

// encerrar e arquivar
$d = api_preArquivar($A, $ev2, true);
$e2 = array_values(array_filter($d['eventos'], fn($x) => $x['id'] === $ev2))[0];
t('arquivado fecha o check-in', $e2['arquivado'] && !$e2['aberto']);
erro('arquivado não reabre', fn() => api_preAbrir($A, $ev2, true), 'histórico');
erro('arquivado não marca', fn() => api_preMarcar($A, $ev2, '3001'), 'histórico');
erro('arquivado não sorteia', fn() => api_preSortear($A, $ev2), 'histórico');
erro('arquivado não faz check-in', fn() => api_preCheckin($tk, '3001', '451'), 'encerrado');
$d3 = api_preEvento($A, 'SIPAT 2026 – dia 3');
t('abre no evento ativo', api_preDados($A)['evento'] === $d3['evento']);
$d = api_preArquivar($A, $ev2, false);
$e2 = array_values(array_filter($d['eventos'], fn($x) => $x['id'] === $ev2))[0];
t('tirado do histórico', !$e2['arquivado'] && !$e2['aberto']);

echo "\n$ok ok, $ko falha(s)\n";
exit($ko ? 1 : 0);
