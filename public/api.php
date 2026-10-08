<?php
/* Recebe {fn, args} do front-end e chama api_<fn> em app/lib.php. Resposta sempre JSON. */
declare(strict_types=1);
require dirname(__DIR__) . '/app/lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

const PERMITIDAS = ['ballot', 'consultar', 'login', 'votar', 'adminLogin', 'adminSair', 'trocarSenha', 'adminData',
    'saveConfig', 'saveCand', 'delCand', 'importar', 'status', 'resultado', 'gerarAta', 'fotoToken', 'fotoBuscar',
    'fotoEnviar', 'inscConsultar', 'inscrever', 'auditoria', 'saveAgenda', 'recomecar'];

function responder($v, int $code = 200): never {
    http_response_code($code);
    echo json_encode($v, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') responder(['erro' => 'Método não permitido.'], 405);
$req = json_decode((string)file_get_contents('php://input'), true);
$fn = is_array($req) ? ($req['fn'] ?? '') : '';
$args = is_array($req['args'] ?? null) ? array_values($req['args']) : [];
if (!in_array($fn, PERMITIDAS, true)) responder(['erro' => 'Chamada desconhecida.'], 400);

try {
    agenda_aplicar();   // abre/encerra sozinha no horário do cronograma
    $f = 'api_' . $fn;
    $n = (new ReflectionFunction($f))->getNumberOfParameters();
    $args = array_pad(array_slice($args, 0, $n), $n, null);
    responder($f(...$args) ?: (object)[]);
} catch (Erro $e) {
    responder(['erro' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('[urna-cipa] ' . $fn . ': ' . $e);
    responder(['erro' => 'Erro interno no servidor. Tente novamente em instantes.'], 500);
}
