<?php
/*
 * Leitura DIRETA da caixa de e-mail do RH (17/11/2026), sem depender do n8n.
 *
 * O próprio sistema entra na caixa por IMAP (Gmail: imap.gmail.com:993 com "senha de app") e procura e-mails
 * de admitidos/demitidos (ex.: "Rad_ColaboradorDemitidos Enviado em: ..."). Cada e-mail é lido uma vez só
 * (guarda o UID) e passa pelo mesmo colab_ler()/colab_aplicar() do n8n e do "Colar e-mail".
 *
 * Não há cron: a busca roda junto com as chamadas da API, no máximo a cada COLAB_EMAIL_INTERVALO segundos
 * (e sempre antes de alguém tentar entrar, então quem foi desligado já está bloqueado). Botão "Buscar agora" no painel.
 *
 * Configuração no EasyPanel (Ambiente do serviço) — a senha fica só lá, nunca no código:
 *   CIPA_EMAIL_USUARIO   e-mail da caixa que recebe os avisos do RH
 *   CIPA_EMAIL_SENHA     senha de app do Google (16 letras)
 *   CIPA_EMAIL_SERVIDOR  opcional, padrão imap.gmail.com   ·  CIPA_EMAIL_PORTA opcional, padrão 993
 *   CIPA_EMAIL_PASTA     opcional, padrão INBOX
 */
declare(strict_types=1);

const COLAB_EMAIL_INTERVALO = 300;   // 5 min entre buscas automáticas
const COLAB_EMAIL_ASSUNTO = '/admiti|admiss|demiti|demiss|deslig/';

function colab_email_cfg(): ?array {
    $u = trim((string)getenv('CIPA_EMAIL_USUARIO')); $s = (string)getenv('CIPA_EMAIL_SENHA');
    if ($u === '' || $s === '') return null;
    return ['usuario' => $u, 'senha' => str_replace(' ', '', $s), 'servidor' => trim((string)getenv('CIPA_EMAIL_SERVIDOR')) ?: 'imap.gmail.com',
            'porta' => (int)(getenv('CIPA_EMAIL_PORTA') ?: 993), 'pasta' => trim((string)getenv('CIPA_EMAIL_PASTA')) ?: 'INBOX'];
}

function colab_email_estado(): array {
    $e = kv_get('colab_email', []);
    return is_array($e) ? $e + ['ultima' => '', 'erro' => '', 'uidvalidity' => '', 'lidos' => [], 'aplicados' => 0, 'ultimoEmail' => ''] : [];
}

/* chamado em toda chamada da API: só busca se já passou o intervalo; nunca derruba a chamada */
function colab_email_auto(): void {
    if (!colab_email_cfg()) return;
    $e = colab_email_estado();
    if ($e['ultima'] !== '' && time() - (int)strtotime($e['ultima']) < COLAB_EMAIL_INTERVALO) return;
    try { colab_email_buscar(); } catch (Throwable $x) { error_log('[urna-cipa] e-mail: ' . $x->getMessage()); }
}

/* busca e aplica os e-mails novos; devolve o estado (com 'novos' = quantos e-mails foram aplicados agora) */
function colab_email_buscar(): array {
    $cfg = colab_email_cfg();
    if (!$cfg) falha('A leitura do e-mail não está configurada (CIPA_EMAIL_USUARIO e CIPA_EMAIL_SENHA no EasyPanel).');
    $dir = getenv('CIPA_DATA') ?: dirname(__DIR__) . '/data';
    $trava = @fopen($dir . '/colab-email.lock', 'c');
    if ($trava && !flock($trava, LOCK_EX | LOCK_NB)) return colab_email_estado() + ['novos' => 0];   // outra chamada já está buscando
    $e = colab_email_estado();
    $e['ultima'] = agora(); kv_set('colab_email', $e);   // marca antes: se o servidor estiver fora, não tenta a cada chamada
    $novos = 0;
    try {
        $im = new ColabImap($cfg['servidor'], $cfg['porta']);
        $im->entrar($cfg['usuario'], $cfg['senha']);
        $uv = $im->pasta($cfg['pasta']);
        if ($uv !== (string)$e['uidvalidity']) { $e['uidvalidity'] = $uv; $e['lidos'] = []; }
        // primeira vez: só os últimos 2 dias; depois, desde a última busca menos 1 dia (pega atrasados)
        $desde = $e['lidos'] ? strtotime('-3 days') : strtotime('-2 days');
        $uids = $im->buscar('SINCE ' . date('j-M-Y', $desde));
        $uids = array_values(array_diff($uids, array_map('intval', $e['lidos'])));
        if ($uids) {
            foreach ($im->assuntos($uids) as $uid => $assunto) {
                if (preg_match(COLAB_EMAIL_ASSUNTO, colab_sem_acento($assunto))) {
                    $msg = colab_mime($im->mensagem($uid));
                    $novos += colab_email_aplicar($msg['html'] !== '' ? $msg['html'] : $msg['texto'], $msg['assunto'] ?: $assunto);
                    $e['ultimoEmail'] = agora();
                }
                $e['lidos'][] = $uid;   // só depois de aplicado: se der erro, tenta de novo na próxima busca
            }
        }
        $im->sair();
        $e['lidos'] = array_slice(array_values(array_unique(array_map('intval', $e['lidos']))), -2000);
        $e['erro'] = '';
    } catch (Erro $x) {
        $e['erro'] = $x->getMessage();
    } catch (Throwable $x) {
        $e['erro'] = 'Não consegui ler a caixa: ' . $x->getMessage();
    }
    $e['aplicados'] = (int)$e['aplicados'] + $novos;
    kv_set('colab_email', $e);
    if ($trava) { flock($trava, LOCK_UN); fclose($trava); }
    return $e + ['novos' => $novos];
}

/* mesmo caminho do n8n, com origem "e-mail" */
function colab_email_aplicar(string $corpo, string $assunto): int {
    try { $tipo = colab_tipo('auto', $assunto, $corpo); } catch (Erro $x) { return 0; }
    $l = colab_ler($corpo);
    if (!$l['linhas']) {
        colab_tabelas()->prepare('INSERT INTO colab_sync(em,tipo,origem,assunto,lidos,novos,atualizados,bloqueados,reativados,detalhe) VALUES(?,?,?,?,0,0,0,0,0,?)')
            ->execute([agora(), $tipo, 'email', txt($assunto, 200), json_encode(['ignoradas' => $l['ignoradas']], JSON_UNESCAPED_UNICODE)]);
        return 1;
    }
    colab_aplicar($tipo, $l['linhas'], true, 'email', $assunto);
    return 1;
}

function api_colabEmailAgora($t): array {
    exige_admin($t);
    $r = colab_email_buscar();
    if ($r['erro'] !== '') falha($r['erro']);
    return ['novos' => $r['novos']] + colab_painel();
}

function colab_email_painel(): array {
    $cfg = colab_email_cfg(); $e = colab_email_estado();
    return ['configurado' => (bool)$cfg, 'usuario' => $cfg['usuario'] ?? '', 'ultima' => (string)$e['ultima'], 'erro' => (string)$e['erro'],
            'ultimoEmail' => (string)$e['ultimoEmail'], 'intervaloMin' => intdiv(COLAB_EMAIL_INTERVALO, 60)];
}

/* ---------- MIME: assunto e corpo (HTML de preferência) já em UTF-8 ---------- */

function colab_mime_cabecalho(string $raw): array {
    $h = [];
    foreach (preg_split('/\r?\n(?![ \t])/', $raw) as $l) {
        if (!preg_match('/^([\w-]+):\s*(.*)$/s', $l, $m)) continue;
        $h[strtolower($m[1])] = preg_replace('/\r?\n[ \t]+/', ' ', $m[2]);
    }
    return $h;
}

function colab_mime_palavra(string $s): string {
    if (!str_contains($s, '=?')) return trim($s);
    $s = preg_replace('/\?=\s+=\?/', '?==?', $s);   // palavras codificadas seguidas não levam espaço
    $d = @iconv_mime_decode($s, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
    return trim($d !== false ? $d : mb_decode_mimeheader($s));
}

function colab_mime_param(string $v, string $p): string {
    return preg_match('/' . $p . '\s*=\s*(?:"([^"]*)"|([^;\s]+))/i', $v, $m) ? ($m[1] !== '' ? $m[1] : $m[2]) : '';
}

function colab_mime(string $raw): array {
    $out = ['assunto' => '', 'html' => '', 'texto' => ''];
    $parte = function (string $raw) use (&$parte, &$out) {
        $sep = preg_split('/\r?\n\r?\n/', $raw, 2);
        $h = colab_mime_cabecalho($sep[0]); $corpo = $sep[1] ?? '';
        if ($out['assunto'] === '' && isset($h['subject'])) $out['assunto'] = colab_mime_palavra($h['subject']);
        $ct = strtolower($h['content-type'] ?? 'text/plain');
        if (str_starts_with($ct, 'multipart/')) {
            $b = colab_mime_param($h['content-type'], 'boundary');
            if ($b === '') return;
            foreach (array_slice(explode('--' . $b, $corpo), 1) as $p) {
                if (str_starts_with($p, '--')) break;
                $parte(ltrim($p, "\r\n"));
            }
            return;
        }
        if (str_starts_with($ct, 'message/rfc822')) { $parte($corpo); return; }   // e-mail encaminhado como anexo
        $tipo = str_starts_with($ct, 'text/html') ? 'html' : (str_starts_with($ct, 'text/plain') ? 'texto' : '');
        if ($tipo === '' || $out[$tipo] !== '' || str_starts_with(strtolower($h['content-disposition'] ?? ''), 'attachment')) return;
        $enc = strtolower(trim($h['content-transfer-encoding'] ?? ''));
        if ($enc === 'base64') $corpo = (string)base64_decode(preg_replace('/\s+/', '', $corpo));
        elseif ($enc === 'quoted-printable') $corpo = quoted_printable_decode($corpo);
        $cs = strtoupper(colab_mime_param($h['content-type'] ?? '', 'charset') ?: 'UTF-8');
        if ($cs !== 'UTF-8' && $cs !== 'US-ASCII') { $c = @mb_convert_encoding($corpo, 'UTF-8', $cs === 'WINDOWS-1252' ? 'Windows-1252' : $cs); if ($c !== false) $corpo = $c; }
        elseif (!mb_check_encoding($corpo, 'UTF-8')) $corpo = mb_convert_encoding($corpo, 'UTF-8', 'Windows-1252');
        $out[$tipo] = $corpo;
    };
    $parte($raw);
    return $out;
}

/* ---------- cliente IMAP mínimo (só leitura: BODY.PEEK não marca como lido) ---------- */

final class ColabImap {
    private $s; private int $n = 0;

    public function __construct(string $host, int $porta) {
        $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true]]);
        $esq = $porta === 993 ? 'ssl' : 'tcp';   // Gmail = 993 (SSL); outra porta só nos testes locais
        $this->s = @stream_socket_client("$esq://$host:$porta", $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $ctx);
        if (!$this->s) falha("Não consegui conectar em $host:$porta ($errstr).");
        stream_set_timeout($this->s, 20);
        $this->linha();   // saudação
    }

    private function linha(): string {
        $l = fgets($this->s);
        if ($l === false) falha('O servidor de e-mail não respondeu a tempo.');
        return $l;
    }

    private static function q(string $s): string { return '"' . addcslashes($s, "\"\\") . '"'; }

    /* manda o comando e devolve as respostas (com os literais {n} já lidos) */
    private function cmd(string $c): array {
        $tag = 'C' . (++$this->n);
        fwrite($this->s, "$tag $c\r\n");
        $res = [];
        while (true) {
            $l = $this->linha();
            if (str_starts_with($l, "$tag ")) {
                if (!preg_match('/^' . $tag . ' OK/i', $l)) {
                    $m = trim(substr($l, strlen($tag) + 1));
                    if (preg_match('/AUTHENTICATIONFAILED|Invalid credentials|LOGIN failed/i', $m))
                        falha('O e-mail recusou o usuário/senha. No Gmail é preciso uma "senha de app" (com verificação em 2 etapas ligada) e o IMAP ativado.');
                    falha('O servidor de e-mail respondeu: ' . mb_substr($m, 0, 160));
                }
                return $res;
            }
            while (preg_match('/\{(\d+)\}\r\n$/', $l, $m)) {   // literal: lê exatamente n bytes
                $falta = (int)$m[1]; $buf = '';
                while ($falta > 0 && !feof($this->s)) { $p = fread($this->s, min(65536, $falta)); if ($p === false || $p === '') break; $buf .= $p; $falta -= strlen($p); }
                $res[] = ['linha' => $l, 'dados' => $buf];
                $l = $this->linha();
            }
            $res[] = ['linha' => $l, 'dados' => null];
        }
    }

    public function entrar(string $u, string $s): void { $this->cmd('LOGIN ' . self::q($u) . ' ' . self::q($s)); }

    public function pasta(string $p): string {
        $uv = '';
        foreach ($this->cmd('EXAMINE ' . self::q($p)) as $r) if (preg_match('/UIDVALIDITY (\d+)/i', $r['linha'], $m)) $uv = $m[1];
        return $uv;
    }

    /** @return int[] */
    public function buscar(string $criterio): array {
        $u = [];
        foreach ($this->cmd("UID SEARCH $criterio") as $r)
            if (preg_match('/^\* SEARCH(.*)$/i', trim($r['linha']), $m)) foreach (preg_split('/\s+/', trim($m[1])) as $x) if ($x !== '') $u[] = (int)$x;
        return $u;
    }

    /** @return array<int,string> uid => assunto */
    public function assuntos(array $uids): array {
        $out = [];
        foreach (array_chunk($uids, 200) as $lote)
        {
            $rs = $this->cmd('UID FETCH ' . implode(',', $lote) . ' (UID BODY.PEEK[HEADER.FIELDS (SUBJECT)])');
            foreach ($rs as $i => $r) {
                if ($r['dados'] === null) continue;
                // o UID pode vir antes do literal ou na linha seguinte, depois dele
                if (!preg_match('/UID (\d+)/i', $r['linha'], $m) && !preg_match('/UID (\d+)/i', $rs[$i + 1]['linha'] ?? '', $m)) continue;
                $out[(int)$m[1]] = colab_mime_palavra(colab_mime_cabecalho($r['dados'])['subject'] ?? '');
            }
        }
        return $out;
    }

    public function mensagem(int $uid): string {
        foreach ($this->cmd("UID FETCH $uid (BODY.PEEK[])") as $r) if ($r['dados'] !== null) return $r['dados'];
        return '';
    }

    public function sair(): void { try { $this->cmd('LOGOUT'); } catch (Throwable $e) {} fclose($this->s); }
}
