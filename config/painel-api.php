<?php
declare(strict_types=1);

/* Utilitários comuns das APIs novas do painel administrativo. */
function ag_responder_json(int $status, array $dados): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function ag_iniciar_sessao(): array
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        $usuarioId = filter_var($_SESSION['usuario_id'] ?? null, FILTER_VALIDATE_INT);
        $perfil = strtolower(trim((string) ($_SESSION['perfil'] ?? '')));
        if ($perfil === 'administrador') $perfil = 'admin';
        return ['usuario_id' => $usuarioId === false ? 0 : (int) $usuarioId, 'perfil' => $perfil];
    }

    $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/'));
    $posicaoApi = strpos($scriptName, '/api/');
    $caminhoCookie = $posicaoApi === false ? '/' : substr($scriptName, 0, $posicaoApi + 1);
    $https = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || (int) ($_SERVER['SERVER_PORT'] ?? 0) === 443;

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    session_cache_limiter('');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => $caminhoCookie,
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

    if (!session_start()) {
        ag_responder_json(500, ['sucesso' => false, 'mensagem' => 'Não foi possível validar a sessão.']);
    }

    $usuarioId = filter_var($_SESSION['usuario_id'] ?? null, FILTER_VALIDATE_INT);
    $perfil = strtolower(trim((string) ($_SESSION['perfil'] ?? '')));
    if ($perfil === 'administrador') $perfil = 'admin';
    session_write_close();

    return ['usuario_id' => $usuarioId === false ? 0 : (int) $usuarioId, 'perfil' => $perfil];
}

function ag_exigir_perfil(array $perfis): array
{
    $sessao = ag_iniciar_sessao();
    if ($sessao['usuario_id'] < 1) {
        ag_responder_json(401, ['sucesso' => false, 'mensagem' => 'Sua sessão expirou. Entre novamente.']);
    }
    if (!in_array($sessao['perfil'], $perfis, true)) {
        ag_responder_json(403, ['sucesso' => false, 'mensagem' => 'Você não tem permissão para executar esta operação.']);
    }
    return $sessao;
}

function ag_exigir_csrf(): void
{
    require_once __DIR__ . '/protecao-csrf.php';
    exigirOrigemConfiavelCsrf();
}

function ag_receber_json(): array
{
    $tipo = strtolower(trim(explode(';', (string) ($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
    if ($tipo !== 'application/json') {
        ag_responder_json(415, ['sucesso' => false, 'mensagem' => 'Envie os dados no formato JSON.']);
    }
    $corpo = file_get_contents('php://input');
    if (!is_string($corpo) || $corpo === '') {
        ag_responder_json(400, ['sucesso' => false, 'mensagem' => 'O corpo da requisição está vazio.']);
    }
    try {
        $dados = json_decode($corpo, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $erro) {
        ag_responder_json(400, ['sucesso' => false, 'mensagem' => 'O corpo da requisição contém JSON inválido.']);
    }
    if (!is_array($dados)) {
        ag_responder_json(400, ['sucesso' => false, 'mensagem' => 'Os dados enviados são inválidos.']);
    }
    return $dados;
}
