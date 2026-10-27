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
erro('foto obrigatória', fn() => api_inscrever('1001', '', '', true), 'Tire a sua foto');
$r = api_inscrever('1001', '', 'data:image/jpeg;base64,AAAA', true); t('inscrição número 01', $r['numero'] === '01');
erro('inscrição dupla', fn() => api_inscrever('01001', '', 'data:image/jpeg;base64,AAAA', true), 'já está inscrito');
erro('foto inválida', fn() => api_inscrever('1002', '', 'javascript:alert(1)', true), 'inválido');
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

// confirmação pelos 3 primeiros dígitos do CPF (inscrição e votação)
putenv('CIPA_PERMITIR_ZERAR=1');
$A3 = sessao_nova('admin', 'admin', 3600);
api_recomecar($A3, true);
api_saveConfig($A3, ['vagasTitulares' => 2, 'vagasSuplentes' => 2, 'verificacao' => 'cpf3']);
api_saveAgenda($A3, ['inscricoes' => 'sim', 'inscInicio' => '', 'inscFim' => '', 'votoInicio' => '', 'votoFim' => '']);
$d = api_importar($A3, [
    ['matricula' => '2001', 'nome' => 'Ana CPF', 'codigo' => '123.456.789-09'],
    ['matricula' => '2002', 'nome' => 'Beto CPF', 'codigo' => '987.654.321-00'],
    ['matricula' => '2003', 'nome' => 'Caio CPF', 'codigo' => '555.444.333-22'],
], true);
t('CPF guardado só com 3 dígitos', $d['eleitores'][0]['codigo'] === '123');
erro('inscrição sem CPF', fn() => api_inscConsultar('2001', ''), 'Informe os 3 primeiros');
erro('inscrição CPF errado', fn() => api_inscConsultar('2001', '999'), 'não confere');
t('inscrição CPF certo mostra o nome', api_inscConsultar('2001', '123')['nome'] === 'Ana CPF');
erro('inscrever com CPF errado', fn() => api_inscrever('2001', '124', 'data:image/jpeg;base64,AAAA', true), 'não confere');
$r = api_inscrever('2001', '123', 'data:image/jpeg;base64,AAAA', true); t('inscreveu com CPF', $r['numero'] === '01');
api_saveCand($A3, ['numero' => '02', 'nome' => 'Outro'], '');
api_status($A3, 'aberta');
erro('consulta da urna sem CPF não revela nome', fn() => api_consultar('2002', ''), 'Informe os 3 primeiros');
t('consulta com CPF certo', api_consultar('2002', '987')['nome'] === 'Beto CPF');
erro('login com CPF errado', fn() => api_login('2002', '988'), 'não confere');
$s = api_login('2002', '98765'); t('aceita CPF digitado a mais (usa só os 3 primeiros)', isset($s['token']));
for ($i = 0; $i < 6; $i++) { try { api_consultar('2003', '000'); } catch (Erro $e) {} }
erro('bloqueio após 5 erros na matrícula', fn() => api_consultar('2003', '555'), 'Muitas tentativas');
t('bloqueio é só daquela matrícula', api_consultar('2002', '987')['nome'] === 'Beto CPF');
api_status($A3, 'encerrada'); api_recomecar($A3, true);
putenv('CIPA_PERMITIR_ZERAR');

// importação que junta listas (PDF/planilha nova sem apagar quem já está)
putenv('CIPA_PERMITIR_ZERAR=1');
$A4 = sessao_nova('admin', 'admin', 3600);
api_recomecar($A4, true);
api_saveConfig($A4, ['vagasTitulares' => 2, 'vagasSuplentes' => 2, 'verificacao' => 'cpf3']);
api_importar($A4, [
    ['matricula' => '007048', 'nome' => 'Renaldo Antigo', 'codigo' => '123.456.789-09', 'cargo' => 'Motorista', 'setor' => 'Tráfego'],
    ['matricula' => '5000', 'nome' => 'Fica Igual', 'codigo' => '555.444.333-22', 'cargo' => 'Fiscal', 'setor' => 'Tráfego'],
], true);
$d = api_importar($A4, [
    ['matricula' => '7048', 'nome' => 'Renaldo Novo Nome', 'codigo' => '', 'cargo' => 'Supervisor', 'setor' => ''],
    ['matricula' => '9001', 'nome' => 'Pessoa Nova', 'codigo' => '987.654.321-00', 'cargo' => 'Mecânico', 'setor' => 'Oficina'],
], false);
$porMat = []; foreach ($d['eleitores'] as $e) $porMat[$e['matricula']] = $e;
t('juntar: total 3', count($d['eleitores']) === 3);
t('juntar: contagem novos/atualizados', $d['importacao']['novos'] === 1 && $d['importacao']['atualizados'] === 1);
t('juntar: sem zeros acha a mesma pessoa', isset($porMat['007048']) && !isset($porMat['7048']));
t('juntar: atualiza nome e cargo', $porMat['007048']['nome'] === 'Renaldo Novo Nome' && $porMat['007048']['cargo'] === 'Supervisor');
t('juntar: campo vazio mantém CPF e lotação', $porMat['007048']['codigo'] === '123' && $porMat['007048']['setor'] === 'Tráfego');
t('juntar: quem não veio no arquivo fica', isset($porMat['5000']) && $porMat['5000']['codigo'] === '555');
t('juntar: pessoa nova entra com CPF', $porMat['9001']['codigo'] === '987');
$d = api_importar($A4, [['matricula' => '1', 'nome' => 'Só Ela']], true);
t('substituir: fica só o arquivo', count($d['eleitores']) === 1 && $d['importacao']['substituiu']);
api_recomecar($A4, true);
putenv('CIPA_PERMITIR_ZERAR');

// comissão pede nova foto; candidato manda pelo link (mesmo com inscrições fechadas)
putenv('CIPA_PERMITIR_ZERAR=1');
$A5 = sessao_nova('admin', 'admin', 3600);
api_recomecar($A5, true);
api_saveConfig($A5, ['vagasTitulares' => 2, 'vagasSuplentes' => 2, 'verificacao' => 'cpf3']);
api_saveAgenda($A5, ['inscricoes' => 'sim', 'inscInicio' => '', 'inscFim' => '', 'votoInicio' => '', 'votoFim' => '']);
api_importar($A5, [['matricula' => '4001', 'nome' => 'Lia Foto', 'codigo' => '321.000.000-00', 'cargo' => 'Fiscal']], true);
$r = api_inscrever('4001', '321', 'data:image/jpeg;base64,VELHA', true);
$num = $r['numero'];
erro('sem pedido não troca foto', fn() => api_inscNovaFoto('4001', '321', 'data:image/jpeg;base64,NOVA', true), 'Não há pedido');
$d = api_pedirFoto($A5, $num, 'Foto tremida ou fora de foco');
t('pedido registrado', $d['candidatos'][0]['foto_pedido'] === '1' && $d['candidatos'][0]['foto_motivo'] === 'Foto tremida ou fora de foco');
t('cédula não mostra o pedido', !isset(api_ballot()['candidatos'][0]['foto_pedido']));
api_saveAgenda($A5, ['inscricoes' => 'nao', 'inscInicio' => '', 'inscFim' => '', 'votoInicio' => '', 'votoFim' => '']);
$c = api_inscConsultar('4001', '321');
t('com inscrição fechada o candidato com pedido entra', $c['fotoPedida'] && $c['fotoMotivo'] === 'Foto tremida ou fora de foco' && $c['jaNumero'] === $num);
erro('nova foto exige CPF certo', fn() => api_inscNovaFoto('4001', '999', 'data:image/jpeg;base64,NOVA', true), 'não conferem');
api_inscNovaFoto('4001', '321', 'data:image/jpeg;base64,NOVA', true);
$d = api_adminData($A5);
t('foto trocada e pedido resolvido', $d['candidatos'][0]['foto'] === 'data:image/jpeg;base64,NOVA' && $d['candidatos'][0]['foto_pedido'] === '0');
erro('sem pedido e inscrição fechada', fn() => api_inscConsultar('4001', '321'), 'encerradas');
api_pedirFoto($A5, $num, 'Foto escura');
$cand = $d['candidatos'][0];
$d = api_saveCand($A5, $cand + ['nome' => 'Lia Foto Editada'], $num);
t('editar sem trocar a foto mantém o pedido', $d['candidatos'][0]['foto_pedido'] === '1' && $d['candidatos'][0]['foto_motivo'] === 'Foto escura');
$d = api_saveCand($A5, ['foto' => 'data:image/jpeg;base64,DACOMISSAO'] + $d['candidatos'][0], $num);
t('comissão troca a foto e resolve o pedido', $d['candidatos'][0]['foto_pedido'] === '0');
api_pedirFoto($A5, $num, 'x');
$d = api_pedirFoto($A5, $num, '', true);
t('cancelar pedido', $d['candidatos'][0]['foto_pedido'] === '0');
api_recomecar($A5, true);
putenv('CIPA_PERMITIR_ZERAR');

// autorização de uso da imagem é obrigatória
putenv('CIPA_PERMITIR_ZERAR=1');
$A6 = sessao_nova('admin', 'admin', 3600);
api_recomecar($A6, true);
api_saveConfig($A6, ['vagasTitulares' => 2, 'vagasSuplentes' => 2, 'verificacao' => 'nenhuma']);
api_saveAgenda($A6, ['inscricoes' => 'sim', 'inscInicio' => '', 'inscFim' => '', 'votoInicio' => '', 'votoFim' => '']);
api_importar($A6, [['matricula' => '6001', 'nome' => 'Ivo Imagem']], true);
erro('inscrição sem autorizar a imagem', fn() => api_inscrever('6001', '', 'data:image/jpeg;base64,AAAA'), 'autorização de uso da imagem');
api_inscrever('6001', '', 'data:image/jpeg;base64,AAAA', true);
t('registro guarda a autorização', str_contains(api_adminData($A6)['log'][0]['detalhe'], 'autorizou o uso da imagem'));
api_pedirFoto($A6, '01', 'x');
erro('nova foto sem autorizar', fn() => api_inscNovaFoto('6001', '', 'data:image/jpeg;base64,BBBB'), 'autorização de uso da imagem');
api_recomecar($A6, true);
putenv('CIPA_PERMITIR_ZERAR');
echo "\n$ok ok, $ko falha(s)\n";
exit($ko ? 1 : 0);
