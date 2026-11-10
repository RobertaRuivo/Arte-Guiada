<?php

header("Content-Type: application/json; charset=UTF-8");
header("Cache-Control: no-store");
header("Pragma: no-cache");

function responderJsonLogout(int $status, array $dados): void
{
    http_response_code($status);

    echo json_encode(
        $dados,
        JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );

    exit;
}

/*
 * 1. Verifica o método HTTP.
 */

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Allow: POST");

    responderJsonLogout(405, [
        "sucesso" => false,
        "mensagem" => "Método não permitido."
    ]);
}

/*
 * 2. Verifica a origem.
 */

require_once __DIR__ . "/../../config/protecao-csrf.php";

exigirOrigemConfiavelCsrf();

/*
 * 3. Configura o cookie.
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
        throw new RuntimeException("Falha ao iniciar sessão.");
    }

    /*
     * Remove os dados da sessão.
     */

    $_SESSION = [];

    /*
     * Expira o cookie.
     */

    if (ini_get("session.use_cookies")) {
        $parametros = session_get_cookie_params();

        $opcoes = [
            "expires" => time() - 42000,
            "path" => $parametros["path"],
            "secure" => $parametros["secure"],
            "httponly" => $parametros["httponly"],
            "samesite" => $parametros["samesite"] ?? "Lax"
        ];

        if ($parametros["domain"] !== "") {
            $opcoes["domain"] = $parametros["domain"];
        }

        if (!setcookie(session_name(), "", $opcoes)) {
            throw new RuntimeException("Falha ao remover cookie.");
        }
    }

    /*
     * Destrói a sessão no servidor.
     */

    if (!session_destroy()) {
        throw new RuntimeException("Falha ao destruir sessão.");
    }

} catch (Throwable $erro) {

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_abort();
    }

    error_log(
        "[" . date("Y-m-d H:i:s") . "] Erro no logout: " .
        $erro->getMessage()
    );

    responderJsonLogout(500, [
        "sucesso" => false,
        "mensagem" => "Erro ao encerrar a sessão."
    ]);
}

responderJsonLogout(200, [
    "sucesso" => true,
    "mensagem" => "Sessão encerrada."
]);