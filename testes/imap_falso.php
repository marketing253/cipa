<?php
/* Servidor IMAP de mentira para testes/teste_colab_email.php: php imap_falso.php <porta> <pasta com N.eml> */
$srv = stream_socket_server('tcp://127.0.0.1:' . $argv[1], $en, $es);
$msgs = []; foreach (glob($argv[2] . '/*.eml') as $f) $msgs[(int)basename($f, '.eml')] = str_replace("\n", "\r\n", str_replace("\r\n", "\n", file_get_contents($f)));
ksort($msgs);
while ($c = @stream_socket_accept($srv, 60)) {
    fwrite($c, "* OK falso\r\n");
    while (($l = fgets($c)) !== false) {
        [$tag, $cmd] = explode(' ', trim($l), 2) + [1 => ''];
        if (stripos($cmd, 'LOGIN') === 0) { fwrite($c, str_contains($cmd, '"errada"') ? "$tag NO [AUTHENTICATIONFAILED] Invalid credentials\r\n" : "$tag OK\r\n"); continue; }
        if (stripos($cmd, 'EXAMINE') === 0) { fwrite($c, "* " . count($msgs) . " EXISTS\r\n* OK [UIDVALIDITY 77] ok\r\n$tag OK\r\n"); continue; }
        if (stripos($cmd, 'UID SEARCH') === 0) { fwrite($c, "* SEARCH " . implode(' ', array_keys($msgs)) . "\r\n$tag OK\r\n"); continue; }
        if (preg_match('/^UID FETCH ([\d,]+) \((UID )?BODY\.PEEK\[(.*?)\]/i', $cmd, $m)) {
            $n = 0;
            foreach (explode(',', $m[1]) as $u) { if (!isset($msgs[(int)$u])) continue; $n++;
                $raw = $msgs[(int)$u];
                $d = $m[3] === '' ? $raw : preg_replace('/\r\n\r\n.*$/s', '', $raw) . "\r\n\r\n";
                if ($m[3] !== '') { preg_match('/^Subject:.*(\r\n[ \t].*)*/mi', $d, $s); $d = ($s[0] ?? '') . "\r\n\r\n"; }
                // UID depois do literal no 2º, antes no 1º (os dois jeitos existem)
                if ($n % 2) fwrite($c, "* $n FETCH (UID $u BODY[{$m[3]}] {" . strlen($d) . "}\r\n$d)\r\n");
                else fwrite($c, "* $n FETCH (BODY[{$m[3]}] {" . strlen($d) . "}\r\n$d UID $u)\r\n");
            }
            fwrite($c, "$tag OK\r\n"); continue;
        }
        if (stripos($cmd, 'LOGOUT') === 0) { fwrite($c, "* BYE\r\n$tag OK\r\n"); break; }
        fwrite($c, "$tag BAD\r\n");
    }
    fclose($c);
}
