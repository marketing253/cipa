<?php
/*
 * Serve a urna (app/urna.html) no modo "php", que fala com api.php.
 *   /             eleitor
 *   /admin        administração da comissão eleitoral (também /comissao)
 *   /inscricao    inscrição de candidatos pelo celular
 *   /totem        tablet fixo: tela cheia, tela ligada, volta ao início sem uso
 *   /foto/TOKEN   foto do candidato pelo celular (QR Code)
 * O Apache manda para cá tudo que não é arquivo (FallbackResource). Os endereços antigos
 * com ?p=... continuam valendo, para não quebrar link já enviado.
 */
declare(strict_types=1);

// servidor embutido do PHP (teste local): arquivos existentes saem direto
if (PHP_SAPI === 'cli-server' && is_file(__DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH))) return false;

$caminho = trim((string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
$partes = $caminho === '' ? [] : explode('/', $caminho);
$p = (string)($_GET['p'] ?? ($partes[0] ?? ''));
$tok = (string)($_GET['t'] ?? ($partes[1] ?? ''));
if ($p === 'index.php') $p = '';
if ($p === 'admin') $p = 'comissao';
if (!in_array($p, ['', 'comissao', 'inscricao', 'foto', 'totem'], true)) {
    header('Location: /', true, 302);
    exit;
}
$tok = preg_replace('/[^a-f0-9]/', '', $tok);

$html = file_get_contents(dirname(__DIR__) . '/app/urna.html');
// <base> faz "api.php" e "fim.mp3" funcionarem em /admin, /totem/ etc.
$html = preg_replace('/<head>/', '<head><base href="/">', $html, 1);
$cfg = '<script>window.__MODE__="php";window.__PAGE__=' . json_encode($p) . ';window.__TOK__=' . json_encode($tok) . ';</script>';
$pos = strpos($html, "<script>\n(function(){");
$html = $pos === false ? str_replace('</head>', $cfg . '</head>', $html) : substr_replace($html, $cfg . "\n", $pos, 0);

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
echo $html;
