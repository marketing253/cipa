<?php
/*
 * Serve a urna (app/urna.html) no modo "php", que fala com api.php.
 *   /                  eleitor
 *   /?p=comissao       administração da comissão eleitoral
 *   /?p=inscricao      inscrição de candidatos pelo celular
 *   /?p=foto&t=...     foto do candidato pelo celular (QR Code)
 *   /?p=totem          tablet fixo: tela cheia, tela ligada, volta ao início sem uso
 */
declare(strict_types=1);

$p = (string)($_GET['p'] ?? '');
if (!in_array($p, ['', 'admin', 'comissao', 'inscricao', 'foto', 'totem'], true)) $p = '';
$tok = preg_replace('/[^a-f0-9]/', '', (string)($_GET['t'] ?? ''));

$html = file_get_contents(dirname(__DIR__) . '/app/urna.html');
$cfg = '<script>window.__MODE__="php";window.__PAGE__=' . json_encode($p) . ';window.__TOK__=' . json_encode($tok) . ';</script>';
$pos = strpos($html, "<script>\n(function(){");
$html = $pos === false ? str_replace('</head>', $cfg . '</head>', $html) : substr_replace($html, $cfg . "\n", $pos, 0);

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
echo $html;
