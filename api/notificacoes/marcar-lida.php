<?php
declare(strict_types=1);

header("Content-Type: application/json; charset=UTF-8");
header("Cache-Control: no-store");
header("Pragma: no-cache");

function responderNotificacaoLida(int $status, array $dados): void
{
    http_response_code($status);
    echo json_encode(
        $dados,
        JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Allow: POST");
    responderNotificacaoLida(405, [
        "sucesso" => false,
        "mensagem" => "Método não permitido."
    ]);
}

require_once __DIR__ . "/../../config/protecao-csrf.php";
exigirOrigemConfiavelCsrf();

$tipoConteudo = strtolower(trim(explode(";", (string) ($_SERVER["CONTENT_TYPE"] ?? ""))[0]));
if ($tipoConteudo !== "application/json") {
    responderNotificacaoLida(415, [
        "sucesso" => false,
        "mensagem" => "Envie os dados no formato JSON."
    ]);
}

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
) || (int) ($_SERVER["SERVER_PORT"] ?? 0) === 443;

$nomeCookie = session_name();
if (
    !isset($_COOKIE[$nomeCookie]) ||
    !is_string($_COOKIE[$nomeCookie]) ||
    $_COOKIE[$nomeCookie] === ""
) {
    responderNotificacaoLida(401, [
        "sucesso" => false,
        "mensagem" => "Sua sessão expirou. Entre novamente."
    ]);
}

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
        throw new RuntimeException("Não foi possível iniciar a sessão.");
    }

    $usuarioId = filter_var(
        $_SESSION["usuario_id"] ?? null,
        FILTER_VALIDATE_INT,
        ["options" => ["min_range" => 1]]
    );
    $perfil = strtolower(trim((string) ($_SESSION["perfil"] ?? "")));
    if ($perfil === "administrador") {
        $perfil = "admin";
    }
    session_write_close();

    if ($usuarioId === false || $usuarioId === null) {
        responderNotificacaoLida(401, [
            "sucesso" => false,
            "mensagem" => "Sua sessão expirou. Entre novamente."
        ]);
    }
    if ($perfil !== "editor") {
        responderNotificacaoLida(403, [
            "sucesso" => false,
            "mensagem" => "Você não tem permissão para alterar esta notificação."
        ]);
    }

    $corpo = file_get_contents("php://input");
    if ($corpo === false) {
        responderNotificacaoLida(400, [
            "sucesso" => false,
            "mensagem" => "Não foi possível ler os dados enviados."
        ]);
    }

    try {
        $dados = json_decode($corpo, false, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $erro) {
        responderNotificacaoLida(400, [
            "sucesso" => false,
            "mensagem" => "O corpo da requisição contém JSON inválido."
        ]);
    }

    if (!$dados instanceof stdClass || !property_exists($dados, "id")) {
        responderNotificacaoLida(400, [
            "sucesso" => false,
            "mensagem" => "Identificador da notificação inválido."
        ]);
    }

    $id = filter_var(
        $dados->id,
        FILTER_VALIDATE_INT,
        ["options" => ["min_range" => 1]]
    );
    if ($id === false || $id === null) {
        responderNotificacaoLida(400, [
            "sucesso" => false,
            "mensagem" => "Identificador da notificação inválido."
        ]);
    }

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    require_once __DIR__ . "/../../config/banco.php";
    if (!isset($conn) || !($conn instanceof mysqli)) {
        throw new RuntimeException("A conexão com o banco não foi inicializada.");
    }
    $conn->set_charset("utf8mb4");

    $usuarioId = (int) $usuarioId;
    $id = (int) $id;
    $stmt = $conn->prepare(
        "UPDATE notificacoes_editor
         SET lida_em = CURRENT_TIMESTAMP
         WHERE id = ? AND usuario_id = ? AND lida_em IS NULL"
    );
    $stmt->bind_param("ii", $id, $usuarioId);
    $stmt->execute();
    $alteradas = $stmt->affected_rows;
    $stmt->close();
    $conn->close();

    if ($alteradas !== 1) {
        responderNotificacaoLida(404, [
            "sucesso" => false,
            "mensagem" => "Notificação não encontrada ou já lida."
        ]);
    }

    responderNotificacaoLida(200, [
        "sucesso" => true,
        "mensagem" => "Notificação marcada como lida."
    ]);
} catch (Throwable $erro) {
    error_log(
        "Erro ao marcar notificação como lida: " . $erro->getMessage()
    );
    if (isset($stmt) && $stmt instanceof mysqli_stmt) {
        try { $stmt->close(); } catch (Throwable $ignorado) {}
    }
    if (isset($conn) && $conn instanceof mysqli) {
        try { $conn->close(); } catch (Throwable $ignorado) {}
    }
    responderNotificacaoLida(500, [
        "sucesso" => false,
        "mensagem" => "Não foi possível atualizar a notificação."
    ]);
}
