<?php
/* Cadastro de colaboradores pelos e-mails de admitidos/demitidos, direto nas funções (sem servidor).
   Uso: php testes/teste_colaboradores.php  (pasta de dados temporária) */
declare(strict_types=1);
$tmp = sys_get_temp_dir() . '/colab-teste-' . bin2hex(random_bytes(4));
putenv("CIPA_DATA=$tmp");
putenv('CIPA_ADMIN_SENHA=senha-teste-1');
$_SERVER['HTTP_HOST'] = 'urna.test'; $_SERVER['SCRIPT_NAME'] = '/api.php'; $_SERVER['REMOTE_ADDR'] = '10.0.0.1';
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
function mats(array $l): array { return array_column($l['linhas'], 'matricula'); }

$A = api_adminLogin('admin', 'senha-teste-1')['token'];
api_saveConfig($A, ['verificacao' => 'cpf3', 'vagasTitulares' => 4]);
api_importar($A, [
    ['matricula' => '1001', 'nome' => 'Ana Souza', 'escala' => 'Aninha', 'setor' => 'Tráfego', 'codigo' => '111.222.333-44'],
    ['matricula' => '1002', 'nome' => 'Bruno Lima', 'escala' => 'Bruninho', 'setor' => 'Manutenção', 'codigo' => '222.333.444-55'],
    ['matricula' => '1003', 'nome' => 'Carla Dias', 'setor' => 'Tráfego', 'codigo' => '333.444.555-66'],
], true);
$aptos = (int)db()->query('SELECT COUNT(*) FROM eleitores')->fetchColumn();

/* ---------- leitura dos formatos de e-mail ---------- */
$l = colab_ler("Bom dia,\nSegue a relação de admitidos:\n\nMatrícula\tNome\tCargo\tSetor\tAdmissão\n2001\tDIEGO ROCHA\tMOTORISTA\tTRÁFEGO\t03/11/2026\n2002\tELISA MOURA\tCOBRADOR\tTRÁFEGO\t03/11/2026\n\nAtt,\nRH 2026");
t('tabela com tab', mats($l) === ['2001', '2002'] && $l['linhas'][0]['nome'] === 'DIEGO ROCHA' && $l['linhas'][0]['setor'] === 'TRÁFEGO' && $l['linhas'][1]['cargo'] === 'COBRADOR');
t('admissão lida', $l['linhas'][0]['admissao'] === '03/11/2026');
$l = colab_ler('<p>Admitidos</p><table><tr><th>Chapa</th><th>Nome do colaborador</th><th>Função</th><th>Departamento</th><th>CPF</th></tr>'
    . '<tr><td>2003</td><td>Fábio Nunes</td><td>Mecânico</td><td>Manutenção</td><td>444.555.666-77</td></tr></table>');
t('tabela HTML', mats($l) === ['2003'] && $l['linhas'][0]['nome'] === 'Fábio Nunes' && $l['linhas'][0]['cpf'] === '44455566677' && $l['linhas'][0]['setor'] === 'Manutenção');
$l = colab_ler("Matrícula: 2004\nNome: Gabi Torres\nCargo: Fiscal\nSetor: Tráfego\n\nMatrícula: 2005\nNome: Hugo Pires");
t('rótulo: valor', mats($l) === ['2004', '2005'] && $l['linhas'][0]['setor'] === 'Tráfego' && $l['linhas'][1]['nome'] === 'Hugo Pires');
$l = colab_ler("Demitidos em 05/11/2026:\n1002 - BRUNO LIMA - MECÂNICO\n- 1003 – Carla Dias\n");
t('linhas soltas com traço', mats($l) === ['1002', '1003'] && $l['linhas'][0]['nome'] === 'BRUNO LIMA' && $l['linhas'][1]['nome'] === 'Carla Dias');
$l = colab_ler("Matr.;Nome;Setor\n007048;Ivo Reis;Tráfego\n007048;Ivo Reis;Tráfego");
t('ponto e vírgula + repetido', mats($l) === ['007048']);
t('tipo pelo assunto', colab_tipo('auto', 'Relação de demitidos 05/11', '') === 'demitidos' && colab_tipo('auto', 'Admissões do dia', '') === 'admitidos');
erro('tipo indefinido', fn() => colab_tipo('auto', 'Relatório', 'texto'), 'admitidos ou de demitidos');

/* ---------- admitidos ---------- */
$txtAdm = "Matrícula\tNome\tCargo\tSetor\tCPF\n2001\tDiego Rocha\tMotorista\tTráfego\t555.666.777-88\n2002\tElisa Moura\tCobradora\tTráfego\t\n1001\tAna Souza\tFiscal\t\t";
$r = api_colabColar($A, $txtAdm, 'admitidos', false);
t('prévia não grava', $r['previa']['novos'] === 2 && $r['previa']['atualizados'] === 1 && !$r['aplicado']
    && (int)db()->query('SELECT COUNT(*) FROM colaboradores')->fetchColumn() === 0);
$r = api_colabColar($A, $txtAdm, 'admitidos', true);
t('aplicou', $r['aplicado'] && $r['previa']['novos'] === 2 && count($r['historico']) === 1);
t('lista da eleição não muda', (int)db()->query('SELECT COUNT(*) FROM eleitores')->fetchColumn() === $aptos);
$e = colab_por_mat('2001');
t('admitido encontrado', $e && $e['nome'] === 'Diego Rocha' && $e['setor'] === 'Tráfego');
t('admitido com CPF faz check-in', api_preQr('2001', '555')['nome'] === 'Diego Rocha');
erro('admitido sem CPF não confere', fn() => api_preQr('2002', '123'), 'não conferem');
$e = colab_por_mat('1001');
t('existente: cargo novo, setor e CPF mantidos', $e['cargo'] === 'Fiscal' && $e['setor'] === 'Tráfego' && $e['escala'] === 'Aninha');
t('existente ainda confere CPF', api_preQr('1001', '111')['nome'] === 'Ana Souza');

/* edição manual: CPF de quem veio sem */
api_colabSalvar($A, '2002', ['nome' => 'Elisa Moura', 'escala' => 'Lisa', 'cargo' => 'Cobradora', 'setor' => 'Tráfego', 'cpf' => '666']);
t('CPF incluído à mão', api_preQr('2002', '666')['nome'] === 'Elisa Moura');
t('escala nova vale na paródia', par_escala('2002') === 'Lisa');
erro('CPF incompleto', fn() => api_colabSalvar($A, '2002', ['cpf' => '12345']), '3 primeiros');

/* ---------- demitidos ---------- */
$d = api_preEvento($A, 'Palestra'); $ev = $d['evento'];
api_preMarcar($A, $ev, '1002');
api_acessoIncluir($A, '1003', 'comissao');
$r = api_colabColar($A, "Relação de demitidos\n1002 - BRUNO LIMA\n1003 - CARLA DIAS\n9999 - NINGUEM CADASTRADO", 'auto', true);
t('detectou demitidos', $r['previa']['tipo'] === 'demitidos' && $r['previa']['bloqueados'] === 2 && $r['previa']['naoAchados'] === 1);
erro('demitido não faz QR', fn() => api_preQr('1002', '222'), 'bloqueado');
erro('demitido não faz check-in na mesa', fn() => api_preMarcar($A, $ev, '1002'), 'bloqueado');
erro('demitido não entra no /admin', fn() => api_acessoInicio('1003'), 'bloqueado');
erro('demitido não vira jurado', fn() => api_parJurado($A, '1002'), 'bloqueado');
t('presença antiga continua', count(api_preDados($A, $ev)['presentes']) === 1);
t('ainda na lista da eleição', (int)db()->query("SELECT COUNT(*) FROM eleitores WHERE matricula='1002'")->fetchColumn() === 1);
$est = api_preEstat($A);
t('estatística sem os desligados', $est['colaboradores'] === 3);   // 1001, 2001, 2002 (1002, 1003 e 9999 bloqueados)
$r = api_colabColar($A, "Demitidos\n1002 - Bruno Lima", 'demitidos', true);
t('já bloqueado não conta de novo', $r['previa']['jaBloqueados'] === 1 && $r['previa']['bloqueados'] === 0);
$p = api_colabDados($A);
t('painel: ativos e bloqueados', $p['ativos'] === 3 && $p['bloqueados'] === 3);

/* readmissão e desbloqueio */
api_colabColar($A, "Admitidos\n1002 - Bruno Lima - Mecânico", 'auto', true);
t('readmitido volta', api_preQr('1002', '222')['nome'] === 'Bruno Lima');
api_colabBloquear($A, '1001', true);
erro('bloqueio manual', fn() => api_preQr('1001', '111'), 'bloqueado');
api_colabBloquear($A, '1001', false);
t('desbloqueio manual', api_preQr('1001', '111')['nome'] === 'Ana Souza');

/* ---------- integração n8n ---------- */
$chave = api_colabDados($A)['chave'];
t('chave gerada', strlen($chave) === 40);
erro('chave errada', fn() => api_colabSync('x', "1003 - Carla Dias", 'Admitidos'), 'Chave');
erro('sem chave', fn() => api_colabSync(null, "1003 - Carla Dias", 'Admitidos'), 'Chave');
$r = api_colabSync($chave, "<p>Seguem os admitidos</p><table><tr><td>Matrícula</td><td>Nome</td></tr><tr><td>3001</td><td>João Teles</td></tr></table>", 'ADMISSÕES 09/11/2026');
t('n8n incluiu', $r['ok'] && $r['tipo'] === 'admitidos' && $r['novos'] === 1);
$r = api_colabSync($chave, "Bom dia, sem movimentação hoje.", 'Demitidos do dia');
t('e-mail vazio só registra', $r['ok'] && $r['lidos'] === 0);
t('histórico guarda', count(api_colabDados($A)['historico']) === 7);
$nova = api_colabTrocarChave($A)['chave'];
erro('chave antiga para de valer', fn() => api_colabSync($chave, "3002 - Teste Novo", 'Admitidos'), 'Chave');
t('chave nova vale', api_colabSync($nova, "3002 - Teste Novo", 'Admitidos')['novos'] === 1);
erro('sem sessão', fn() => api_colabDados('x'), 'expirada');

/* ---------- e-mail real do RADSYSTEM: sem matrícula, acha pelo nome ---------- */
api_importar($A, [['matricula' => '6001', 'nome' => 'MARCELO DOS ANJOS TEIXEIRA', 'setor' => 'Tráfego', 'codigo' => '777.888.999-00'],
                  ['matricula' => '6002', 'nome' => 'Ítalo Vieira Brandão', 'setor' => 'Tráfego'],
                  ['matricula' => '6003', 'nome' => 'José Carlos Ferreira', 'setor' => 'Manutenção'],
                  ['matricula' => '6004', 'nome' => 'Jose Carlos Ferreira', 'setor' => 'Tráfego']], false);
$rad = "<html><body>\n<p>Estamos notificando que a tarefa [Rad_ColaboradorDemitidos]<br>\nfoi executada com exito na data [09/11/2026].</p>\n<table border=1>\n <tr>\n  <th>Nome</th>\n  <th>TelefoneCelular</th>\n  <th>Tipo</th>\n  <th>DtAdmissao</th>\n  <th>DtDemissao</th>\n </tr>\n"
  . " <tr>\n  <td>Marcelo Dos Anjos Teixeira</td>\n  <td>90000-0001</td>\n  <td>Demitido</td>\n  <td></td>\n  <td>03/11/2026</td>\n </tr>\n"
  . " <tr><td>Italo Vieira Brandao</td><td>90000-0002</td><td>Demitido</td><td></td><td>03/11/2026</td></tr>\n"
  . " <tr><td>Wagner Pereira da Costa Neto</td><td>90000-0003</td><td>Demitido</td><td></td><td>03/11/2026</td></tr>\n"
  . " <tr><td>Jose Carlos Ferreira</td><td>90000-0004</td><td>Demitido</td><td></td><td>06/11/2026</td></tr>\n</table>\n<p>Atenciosamente,<br>RADSYSTEM</p></body></html>";
$l = colab_ler($rad);
t('RAD: lê 4 linhas sem matrícula', count($l['linhas']) === 4 && $l['linhas'][0]['nome'] === 'Marcelo Dos Anjos Teixeira' && $l['linhas'][0]['matricula'] === '' && $l['linhas'][0]['demissao'] === '03/11/2026' && !$l['ignoradas']);
t('RAD: tipo pelo assunto', colab_tipo('auto', 'Rad_ColaboradorDemitidos Enviado em: 09/11/2026 09:10:09', $rad) === 'demitidos');
$chave = api_colabDados($A)['chave'];
$r = api_colabSync($chave, $rad, 'Rad_ColaboradorDemitidos Enviado em: 09/11/2026 09:10:09');
t('RAD: 2 bloqueados pelo nome, 2 para conferir (não achado e nome repetido)', $r['bloqueados'] === 2 && $r['naoAchados'] === 0 && $r['conferir'] === 2);
erro('RAD: Marcelo bloqueado', fn() => api_preQr('6001', '777'), 'bloqueado');
erro('RAD: Ítalo (com acento no cadastro) bloqueado', fn() => colab_por_mat('6002'), 'bloqueado');
t('RAD: homônimos não foram mexidos', colab_por_mat('6003') !== null && colab_por_mat('6004') !== null);
$h = api_colabDados($A)['historico'][0];
t('RAD: histórico mostra a matrícula achada', $h['pessoas'][0]['matricula'] === '6001' && str_contains($h['pessoas'][0]['acao'], 'pelo nome'));

/* admitido sem matrícula: fica pendente até completar */
$adm = '<table><tr><th>Nome</th><th>TelefoneCelular</th><th>Tipo</th><th>DtAdmissao</th><th>DtDemissao</th></tr><tr><td>Paula Regina Souza</td><td>90000-0005</td><td>Admitido</td><td>05/11/2026</td><td></td></tr></table>';
$r = api_colabSync($chave, $adm, 'Rad_ColaboradorAdmitidos Enviado em: 09/11/2026 09:10:09');
t('RAD admitido: entra pendente', $r['tipo'] === 'admitidos' && $r['novos'] === 1);
$p = api_colabDados($A); $pend = array_values(array_filter($p['colaboradores'], fn($x) => $x['semMatricula']));
t('RAD admitido: aparece sem matrícula', count($pend) === 1 && $pend[0]['nome'] === 'Paula Regina Souza' && $pend[0]['chave'] === 'n:paula regina souza');
$r = api_colabSync($chave, $adm, 'Rad_ColaboradorAdmitidos Enviado em: 10/11/2026');
t('RAD admitido: e-mail repetido só atualiza', $r['novos'] === 0 && $r['atualizados'] === 1);
api_colabSalvar($A, $pend[0]['chave'], ['nome' => 'Paula Regina Souza', 'matricula' => '6100', 'cpf' => '123', 'setor' => 'RH']);
t('RAD admitido: matrícula completada vale no check-in', api_preQr('6100', '123')['nome'] === 'Paula Regina Souza');
t('RAD admitido: sem pendente', !array_filter(api_colabDados($A)['colaboradores'], fn($x) => $x['semMatricula']));
erro('RAD admitido: chave inexistente', fn() => api_colabSalvar($A, 'n:xx', ['matricula' => '6100']), 'não encontrada');

echo "\n$ok ok, $ko falha(s)\n";
exit($ko ? 1 : 0);
