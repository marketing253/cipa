<?php
/* Teste de carga: muitos eleitores votando AO MESMO TEMPO no mesmo banco (processos separados,
   como o Apache faz). Confere se nenhum voto se perde, ninguém vota duas vezes e quanto pesa a cédula.
   Uso: php testes/carga.php [processos=20] [eleitores por processo=15]
   (usa uma pasta de dados temporária; o php.exe precisa de pdo_sqlite) */
declare(strict_types=1);

if (($argv[1] ?? '') === '--worker') {          // um "celular": consulta a cédula, entra e vota
    [, , $dir, $ini, $qtd] = $argv;
    putenv("CIPA_DATA=$dir"); putenv('CIPA_ADMIN_SENHA=carga-123');
    $_SERVER += ['HTTP_HOST' => 'carga.test', 'SCRIPT_NAME' => '/api.php', 'REMOTE_ADDR' => '10.1.1.1', 'HTTP_USER_AGENT' => 'carga'];
    require __DIR__ . '/../app/lib.php';
    $nums = array_column(api_ballot()['candidatos'], 'numero');
    $ok = 0; $erros = []; $lento = 0.0;
    for ($i = 0; $i < (int)$qtd; $i++) {
        $m = (string)((int)$ini + $i); $t0 = microtime(true);
        try {
            api_ballot();
            $s = api_login($m, substr($m, 0, 3));
            api_votar($s['token'], $nums[array_rand($nums)], false, "ap$m");
            $ok++;
        } catch (Throwable $e) { $erros[] = $e->getMessage(); }
        $lento = max($lento, microtime(true) - $t0);
    }
    echo json_encode(['ok' => $ok, 'erros' => $erros, 'pior' => $lento]);
    exit;
}

$proc = (int)($argv[1] ?? 20); $porProc = (int)($argv[2] ?? 15); $total = $proc * $porProc;
$dir = sys_get_temp_dir() . '/urna-carga-' . bin2hex(random_bytes(4));
putenv("CIPA_DATA=$dir"); putenv('CIPA_ADMIN_SENHA=carga-123');
$_SERVER += ['HTTP_HOST' => 'carga.test', 'SCRIPT_NAME' => '/api.php', 'REMOTE_ADDR' => '10.1.1.1', 'HTTP_USER_AGENT' => 'carga'];
require __DIR__ . '/../app/lib.php';

$A = api_adminLogin('admin', 'carga-123')['token'];
api_saveConfig($A, ['verificacao' => 'cpf3']);
$rows = [];
for ($i = 0; $i < $total; $i++) { $m = (string)(100000 + $i); $rows[] = ['matricula' => $m, 'nome' => "Colaborador $m", 'codigo' => substr($m, 0, 3) . '.000.000-00']; }
api_importar($A, $rows, true);
$foto = 'data:image/jpeg;base64,' . base64_encode(random_bytes(68000));   // ~90 KB, como uma foto 480x600 da urna
for ($n = 1; $n <= 40; $n++) api_saveCand($A, ['numero' => sprintf('%02d', $n), 'nome' => "Candidato $n", 'foto' => $foto], '');
api_status($A, 'aberta');

$leve = strlen(json_encode(api_ballot(), JSON_UNESCAPED_UNICODE));
$pesada = strlen(json_encode(['candidatos' => candidatos()], JSON_UNESCAPED_UNICODE));
printf("Cédula com 40 candidatos: %.1f KB agora (fotos à parte) · seria %.1f MB com as fotos embutidas\n", $leve / 1024, $pesada / 1048576);

$php = PHP_BINARY; $ini = php_ini_loaded_file(); $ext = ini_get('extension_dir');
$extra = $ini ? '' : sprintf('-d extension_dir="%s" -d extension=pdo_sqlite -d extension=mbstring', $ext);
$t0 = microtime(true); $pipes = []; $ps = [];
for ($k = 0; $k < $proc; $k++) {
    $cmd = sprintf('"%s" %s "%s" --worker "%s" %d %d', $php, $extra, __FILE__, $dir, 100000 + $k * $porProc, $porProc);
    $ps[$k] = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$k]);
}
$ok = 0; $erros = []; $pior = 0.0;
foreach ($ps as $k => $pr) {
    $out = stream_get_contents($pipes[$k][1]); $err = stream_get_contents($pipes[$k][2]); proc_close($pr);
    $r = json_decode($out, true);
    if (!$r) { $erros[] = "processo $k: " . trim($err . $out); continue; }
    $ok += $r['ok']; $erros = array_merge($erros, $r['erros']); $pior = max($pior, $r['pior']);
}
$seg = microtime(true) - $t0;
$p = db();
$urna = (int)$p->query('SELECT COUNT(*) FROM votos')->fetchColumn();
$votaram = (int)$p->query('SELECT COUNT(*) FROM eleitores WHERE votou=1')->fetchColumn();
printf("%d processos ao mesmo tempo, %d votos em %.1f s (%.0f votos/min) · pior espera de um eleitor: %.2f s\n", $proc, $ok, $seg, $ok / $seg * 60, $pior);
printf("Votos na urna: %d · marcados como votou: %d · erros: %d\n", $urna, $votaram, count($erros));
foreach (array_slice(array_unique($erros), 0, 5) as $e) echo "  erro: $e\n";
$certo = $ok === $total && $urna === $total && $votaram === $total && !$erros;
echo $certo ? "RESULTADO: OK — nenhum voto perdido nem duplicado\n" : "RESULTADO: FALHOU\n";
foreach (glob("$dir/*") as $f) @unlink($f); @rmdir($dir);
exit($certo ? 0 : 1);
