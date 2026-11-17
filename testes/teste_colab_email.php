<?php
/* Leitura direta da caixa do RH (IMAP) contra um servidor falso local. Uso: php testes/teste_colab_email.php */
declare(strict_types=1);
$tmp = sys_get_temp_dir() . '/colab-email-' . bin2hex(random_bytes(4)); mkdir("$tmp/caixa", 0777, true);
putenv("CIPA_DATA=$tmp"); putenv('CIPA_ADMIN_SENHA=senha-teste-1');
$_SERVER['HTTP_HOST'] = 'urna.test'; $_SERVER['SCRIPT_NAME'] = '/api.php'; $_SERVER['REMOTE_ADDR'] = '10.0.0.1';
require __DIR__ . '/../app/lib.php';
require __DIR__ . '/../app/parodia.php';
require __DIR__ . '/../app/presenca.php';
require __DIR__ . '/../app/acesso.php';
$ok = 0; $ko = 0;
function t(string $n, bool $c) { global $ok, $ko; if ($c) $ok++; else { $ko++; echo "FALHOU: $n\n"; } }

$html = '<p>Estamos notificando que a tarefa [Rad_ColaboradorDemitidos]<br>foi executada</p><table border=1><tr><th>Nome</th><th>TelefoneCelular</th><th>Tipo</th><th>DtAdmissao</th><th>DtDemissao</th></tr>'
  . '<tr><td>Marcelo Dos Anjos Teixeira</td><td>90000-0001</td><td>Demitido</td><td></td><td>14/11/2026</td></tr>'
  . '<tr><td>Ítalo Vieira Brandão</td><td>90000-0002</td><td>Demitido</td><td></td><td>14/11/2026</td></tr></table>';
$adm = '<table><tr><th>Nome</th><th>Tipo</th><th>DtAdmissao</th></tr><tr><td>Paula Regina Souza</td><td>Admitido</td><td>16/11/2026</td></tr></table>';
file_put_contents("$tmp/caixa/5.eml", "From: LinkedIn <x@linkedin.com>\nSubject: Você tem 1 mensagem nova\nContent-Type: text/plain\n\nOi");
// demitidos: multipart, HTML em quoted-printable e ISO-8859-1, assunto codificado
file_put_contents("$tmp/caixa/6.eml", "From: rs@exemplo\nSubject: =?ISO-8859-1?Q?Rad=5FColaboradorDemitidos_Enviado_em:?=\n =?ISO-8859-1?Q?_17/11/2026_09:10:53?=\nMIME-Version: 1.0\n"
  . "Content-Type: multipart/alternative; boundary=\"XX\"\n\n--XX\nContent-Type: text/plain; charset=ISO-8859-1\n\nveja o html\n--XX\nContent-Type: text/html; charset=\"ISO-8859-1\"\nContent-Transfer-Encoding: quoted-printable\n\n"
  . quoted_printable_encode(mb_convert_encoding($html, 'ISO-8859-1', 'UTF-8')) . "\n--XX--\n");
// admitidos: HTML em base64
file_put_contents("$tmp/caixa/7.eml", "From: rs@exemplo\nSubject: Rad_ColaboradorAdmitidos Enviado em: 17/11/2026 09:11:00\nContent-Type: text/html; charset=utf-8\nContent-Transfer-Encoding: base64\n\n" . chunk_split(base64_encode($adm)));

$porta = 21000 + random_int(0, 3000);
$proc = proc_open([PHP_BINARY, __DIR__ . '/imap_falso.php', (string)$porta, "$tmp/caixa"], [], $pipes);
usleep(600000);

$A = api_adminLogin('admin', 'senha-teste-1')['token'];
api_importar($A, [['matricula' => '6001', 'nome' => 'MARCELO DOS ANJOS TEIXEIRA', 'setor' => 'Tráfego', 'codigo' => '777.888.999-00'],
                  ['matricula' => '6002', 'nome' => 'Italo Vieira Brandao', 'setor' => 'Tráfego'],
                  ['matricula' => '6003', 'nome' => 'Outra Pessoa', 'setor' => 'Tráfego']], true);

t('sem configuração: não faz nada', colab_email_painel()['configurado'] === false);
colab_email_auto();
t('sem configuração: nenhuma leitura', colab_email_estado()['ultima'] === '');

putenv('CIPA_EMAIL_USUARIO=rh@exemplo'); putenv('CIPA_EMAIL_SENHA=errada'); putenv('CIPA_EMAIL_SERVIDOR=127.0.0.1'); putenv("CIPA_EMAIL_PORTA=$porta");
try { api_colabEmailAgora($A); t('senha errada dá erro', false); } catch (Erro $e) { t('senha errada → ' . $e->getMessage(), str_contains($e->getMessage(), 'senha de app')); }
t('erro aparece no painel', str_contains(api_colabDados($A)['email']['erro'], 'senha de app'));

putenv('CIPA_EMAIL_SENHA=abcd efgh ijkl mnop');
$r = api_colabEmailAgora($A);
t('2 e-mails do RH aplicados (LinkedIn ignorado)', $r['novos'] === 2);
t('sem erro', $r['email']['erro'] === '' && $r['email']['ultimoEmail'] !== '');
t('Marcelo bloqueado', !colab_por_mat_ok('6001'));
t('Ítalo bloqueado (acento e ISO-8859-1)', !colab_por_mat_ok('6002'));
t('outra pessoa segue ativa', colab_por_mat_ok('6003'));
$h = $r['historico'];
t('histórico: 2 entradas pelo e-mail', count($h) === 2 && $h[0]['origem'] === 'email' && $h[1]['origem'] === 'email');
$dem = array_values(array_filter($h, fn($x) => $x['tipo'] === 'demitidos'))[0] ?? null;
t('assunto decodificado', $dem && $dem['assunto'] === 'Rad_ColaboradorDemitidos Enviado em: 17/11/2026 09:10:53');
t('admitida entra pendente', (bool)array_filter($r['colaboradores'], fn($x) => $x['nome'] === 'Paula Regina Souza' && $x['semMatricula']));

$r = api_colabEmailAgora($A);
t('segunda busca: nada repetido', $r['novos'] === 0 && count($r['historico']) === 2);

// automático respeita o intervalo
$e = colab_email_estado(); $antes = $e['ultima'];
colab_email_auto();
t('automático não busca de novo antes de 5 min', colab_email_estado()['ultima'] === $antes);

function colab_por_mat_ok(string $m): bool { try { return colab_por_mat($m) !== null; } catch (Erro $e) { return false; } }

proc_terminate($proc);
echo "\n$ok ok, $ko falha(s)\n";
exit($ko ? 1 : 0);
