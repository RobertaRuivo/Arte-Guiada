<?php

header("Content-Type: application/json; charset=UTF-8");
header("Cache-Control: no-store");
header("Pragma: no-cache");

function responderJsonSessao(int $status, array $dados): void
{
    http_response_code($status);

    echo json_encode(
        $dados,
        JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );

    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    header("Allow: GET");

    responderJsonSessao(405, [
        "autenticado" => false,
        "mensagem" => "Método não permitido."
    ]);
}

/*
 * Não cria uma sessão nova para visitantes
 * que não apresentam um cookie de sessão.
 */

$nomeCookie = session_name();

if (
    !isset($_COOKIE[$nomeCookie]) ||
    !is_string($_COOKIE[$nomeCookie]) ||
    $_COOKIE[$nomeCookie] === ""
) {
    responderJsonSessao(401, [
        "autenticado" => false,
        "mensagem" => "Usuário não autenticado."
    ]);
}

/*
 * Calcula o caminho da aplicação.
 */

$scriptName = str_replace(
    "\\",
    "/",
    (string) ($_SERVER["SCRIPT_NAME"] ?? "/")
);

$posicaoApi = strpos($scriptName, "/api/");

$caminhoCookie = $posicaoApi === false
    ? "/"
    : substr($scriptName, 0, $posicaoApi + 1);

$https = (
    !empty($_SERVER["HTTPS"]) &&
    strtolower((string) $_SERVER["HTTPS"]) !== "off"
) || (
    (int) ($_SERVER["SERVER_PORT"] ?? 0) === 443
);

try {
    ini_set("session.use_strict_mode", "1");
    ini_set("session.use_only_cookies", "1");
    ini_set("session.use_trans_sid", "0");

    session_cache_limiter("");

    session_set_cookie_params([
        "lifetime" => 0,
        "path" => $caminhoCookie,
        "secure" => $https,
        "httponly" => true,
        "samesite" => "Lax"
    ]);

    if (!session_start()) {
        throw new RuntimeException(
            "Não foi possível iniciar a sessão."
        );
    }

} catch (Throwable $erro) {

    error_log(
        "[" . date("Y-m-d H:i:s") . "] Erro ao consultar sessão: " .
        $erro->getMessage()
    );

    responderJsonSessao(500, [
        "autenticado" => false,
        "mensagem" => "Erro ao consultar a sessão."
    ]);
}

/*
 * Recupera e valida os dados da sessão.
 */

$usuarioId = (int) ($_SESSION["usuario_id"] ?? 0);
$nome = (string) ($_SESSION["nome"] ?? "");
$perfil = (string) ($_SESSION["perfil"] ?? "");

$perfisPermitidos = [
    "visitante",
    "editor",
    "admin"
];

if (
    $usuarioId <= 0 ||
    !in_array($perfil, $perfisPermitidos, true)
) {
    session_write_close();

    responderJsonSessao(401, [
        "autenticado" => false,
        "mensagem" => "Usuário não autenticado."
    ]);
}

if (!session_write_close()) {
    responderJsonSessao(500, [
        "autenticado" => false,
        "mensagem" => "Não foi possível consultar a sessão."
    ]);
}

responderJsonSessao(200, [
    "autenticado" => true,
    "usuario" => [
        "id" => $usuarioId,
        "nome" => $nome,
        "perfil" => $perfil
    ]
]);