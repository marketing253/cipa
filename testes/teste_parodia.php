<?php
/* Regras do concurso de paródias, direto nas funções (sem servidor).
   Uso: php testes/teste_parodia.php  (pasta de dados temporária) */
declare(strict_types=1);
$tmp = sys_get_temp_dir() . '/parodia-teste-' . bin2hex(random_bytes(4));
putenv("CIPA_DATA=$tmp");
putenv('CIPA_ADMIN_SENHA=senha-teste-1');
$_SERVER['HTTP_HOST'] = 'urna.test'; $_SERVER['SCRIPT_NAME'] = '/api.php'; $_SERVER['REMOTE_ADDR'] = '10.0.0.1';
require __DIR__ . '/../app/lib.php';
require __DIR__ . '/../app/parodia.php';

$ok = 0; $ko = 0;
function t(string $nome, bool $cond) { global $ok, $ko; if ($cond) $ok++; else { $ko++; echo "FALHOU: $nome\n"; } }
function erro(string $nome, callable $f, string $trecho) {
    try { $f(); t("$nome (devia dar erro)", false); }
    catch (Erro $e) { t("$nome → " . $e->getMessage(), str_contains($e->getMessage(), $trecho)); }
}

$A = api_adminLogin('admin', 'senha-teste-1')['token'];
api_saveConfig($A, ['verificacao' => 'cpf3', 'vagasTitulares' => 4, 'vagasSuplentes' => 4]);
$rows = [];
for ($i = 1; $i <= 6; $i++) $rows[] = ['matricula' => (string)(2000 + $i), 'nome' => "Pessoa $i", 'setor' => 'Tráfego', 'codigo' => "12$i.456.789-00"];
api_importar($A, $rows, true);

$LETRA = str_repeat("Use o cinto, respeite a faixa, motorista consciente\n", 3);
$d = ['equipe' => 'Os Prevenidos', 'musica' => 'Evidências', 'artista' => 'Chitãozinho & Xororó', 'letra' => $LETRA, 'integrantes' => "Ana\nBeto"];

t('info pública', api_parInfo()['verificacaoLbl'] === '3 primeiros dígitos do CPF' && api_parInfo()['situacao'] === 'aberta');
erro('CPF errado', fn() => api_parConsultar('2001', '999'), 'não conferem');
erro('matrícula inexistente', fn() => api_parConsultar('9999', '121'), 'não encontrada');
$r = api_parConsultar('02001', '121');
t('consulta ok, sem inscrição', $r['nome'] === 'Pessoa 1' && $r['parodia'] === null && $r['podeEditar']);
erro('sem aceite', fn() => api_parSalvar('2001', '121', $d, false), 'declaração');
erro('letra longa demais', fn() => api_parSalvar('2001', '121', ['letra' => str_repeat('a', 8001)] + $d, true), 'passou de');
erro('sem música', fn() => api_parSalvar('2001', '121', ['musica' => ''] + $d, true), 'música original');
$x = api_parSalvar('2001', '121', ['letra' => ''] + $d, true);
t('inscreve sem letra (opcional)', api_parConsultar('2001', '121')['parodia']['letra'] === '');
$x = api_parSalvar('2001', '121', $d, true);
t('completou a letra depois', $x['equipe'] === 'Os Prevenidos' && $x['alterou'] && api_parConsultar('2001', '121')['parodia']['letra'] !== '');
erro('nome de equipe repetido', fn() => api_parSalvar('2002', '122', ['equipe' => '  os   PREVENIDOS '] + $d, true), 'Já existe');
$x = api_parSalvar('2001', '121', ['musica' => 'Evidências (ao vivo)'] + $d, true);
t('alterou a própria', $x['alterou'] && api_parConsultar('2001', '121')['parodia']['musica'] === 'Evidências (ao vivo)');
api_parSalvar('2002', '122', ['equipe' => 'Freio de Mão'] + $d, true);
api_parSalvar('2003', '123', ['equipe' => 'Cinto Afivelado'] + $d, true);

// jurados
erro('jurado sem cadastro', fn() => api_parJuradoLogin('2004', '124'), 'lista de jurados');
erro('incluir jurado inexistente', fn() => api_parJurado($A, '777'), 'não encontrada');
api_parJurado($A, '2004'); api_parJurado($A, '2005');
$ad = api_parJurado($A, '2001');   // o responsável dos Prevenidos também é jurado
t('3 jurados', count($ad['jurados']) === 3);
erro('jurado duplicado', fn() => api_parJurado($A, '002004'), 'já é jurado');
erro('jurado CPF errado', fn() => api_parJuradoLogin('2004', '000'), 'não conferem');
$J = api_parJuradoLogin('2004', '124')['token'];
$J1 = api_parJuradoLogin('2001', '121')['token'];
t('jurado não vê pendentes', api_parJuradoDados($J)['parodias'] === []);
erro('jurado não aprova', fn() => api_parValidar($J, 1, 'aprovar'), 'expirada');
erro('nota em pendente', fn() => api_parNota($J, 1, [8, 8, 8]), 'aprovadas');
api_parValidar($A, 1, 'aprovar');
api_parValidar($A, 2, 'aprovar');
api_parValidar($A, 3, 'recusar', 'Letra copiada de outra equipe');
erro('ação antiga de ajuste', fn() => api_parValidar($A, 2, 'ajuste', 'x'), 'inválida');
$jd = api_parJuradoDados($J);
t('jurado vê só as 2 aprovadas', count($jd['parodias']) === 2 && !isset($jd['parodias'][0]['celular']));
t('própria marcada', api_parJuradoDados($J1)['parodias'][0]['propria'] === true);
erro('nota incompleta', fn() => api_parNota($J, 1, [8, 8]), 'cada critério');
erro('nota fora da escala', fn() => api_parNota($J, 1, [8, 11, 8]), '0 a 10');
erro('nota quebrada', fn() => api_parNota($J, 1, [8, 9.5, 8]), '0 a 10');
erro('nota à própria', fn() => api_parNota($J1, 1, [10, 10, 10]), 'própria');
api_parNota($J, 1, [8, 9, 7], 'Muito boa');
$J2 = api_parJuradoLogin('2005', '125')['token'];
api_parNota($J2, 1, [6, 6, 6]);
api_parNota($J, 1, [9, 10, 7]);   // atualiza a própria nota
$ad = api_parAdmin($A);
$p1 = $ad['parodias'][0];
t('duas notas, média', count($p1['notas']) === 2 && abs($p1['media'] - (26 + 18) / 2) < 0.01);
t('ninguém falta na 1 (responsável não vota)', $p1['faltam'] === []);
t('na 2 faltam os 3', count($ad['parodias'][1]['faltam']) === 3);
$jx = array_column($ad['jurados'], null, 'matricula');
t('andamento do jurado', $jx['2004']['avaliou'] === 1 && $jx['2004']['deve'] === 2 && $jx['2001']['deve'] === 1);
erro('critérios travados com nota', fn() => api_parSaveCfg($A, ['criterios' => ['Só um']]), 'não podem mais mudar');

// colaborador: travas por situação
erro('aprovada não edita', fn() => api_parSalvar('2001', '121', $d, true), 'aprovada');
erro('não aprovada não edita', fn() => api_parSalvar('2003', '123', ['equipe' => 'Cinto Afivelado'] + $d, true), 'não aprovada');
t('motivo aparece ao colaborador', api_parConsultar('2003', '123')['parodia']['motivo'] === 'Letra copiada de outra equipe');

// inscrições encerradas
api_parSaveCfg($A, ['inscricoes' => 'nao']);
erro('nova com inscrição fechada', fn() => api_parConsultar('2006', '126'), 'encerradas');
api_parSaveCfg($A, ['inscricoes' => 'sim', 'inicio' => '2099-01-01T08:00']);
erro('antes de abrir', fn() => api_parConsultar('2006', '126'), 'abrem em');
erro('datas invertidas', fn() => api_parSaveCfg($A, ['inicio' => '2026-11-10T08:00', 'fim' => '2026-11-01T08:00']), 'depois do início');
api_parSaveCfg($A, ['inicio' => '', 'fim' => '']);

// reabrir apaga notas; remover jurado derruba a sessão
api_parValidar($A, 1, 'reabrir');
t('reabrir zera notas', api_parAdmin($A)['parodias'][0]['notas'] === []);
api_parJurado($A, '2005', true);
erro('jurado removido perde acesso', fn() => api_parJuradoDados($J2), 'expirada');
erro('sem sessão de comissão', fn() => api_parAdmin('x'), 'expirada');
$ad = api_parExcluir($A, 3);
t('excluiu', count($ad['parodias']) === 2);
t('registro no log', (int)db()->query("SELECT COUNT(*) FROM log WHERE acao LIKE 'Paródia%'")->fetchColumn() >= 5);

echo "\n$ok ok, $ko falha(s)\n";
exit($ko ? 1 : 0);
