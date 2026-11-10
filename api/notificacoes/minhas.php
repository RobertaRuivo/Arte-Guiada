<?php
declare(strict_types=1);

header("Content-Type: application/json; charset=UTF-8");
header("Cache-Control: no-store");
header("Pragma: no-cache");

function responderNotificacoes(int $status, array $dados): void
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
    responderNotificacoes(405, [
        "sucesso" => false,
        "mensagem" => "Método não permitido."
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
    responderNotificacoes(401, [
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
        responderNotificacoes(401, [
            "sucesso" => false,
            "mensagem" => "Sua sessão expirou. Entre novamente."
        ]);
    }

    if ($perfil !== "editor") {
        responderNotificacoes(403, [
            "sucesso" => false,
            "mensagem" => "As notificações são destinadas aos editores."
        ]);
    }

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    require_once __DIR__ . "/../../config/banco.php";

    if (!isset($conn) || !($conn instanceof mysqli)) {
        throw new RuntimeException("A conexão com o banco não foi inicializada.");
    }

    $conn->set_charset("utf8mb4");
    $stmt = $conn->prepare(
        "SELECT id, obra_id, tipo, titulo, mensagem, criada_em
         FROM notificacoes_editor
         WHERE usuario_id = ? AND lida_em IS NULL
         ORDER BY criada_em DESC, id DESC
         LIMIT 20"
    );
    $usuarioId = (int) $usuarioId;
    $stmt->bind_param("i", $usuarioId);
    $stmt->execute();
    $resultado = $stmt->get_result();

    $notificacoes = [];
    while ($linha = $resultado->fetch_assoc()) {
        $notificacoes[] = [
            "id" => (int) $linha["id"],
            "obra_id" => (int) $linha["obra_id"],
            "tipo" => (string) $linha["tipo"],
            "titulo" => (string) $linha["titulo"],
            "mensagem" => (string) $linha["mensagem"],
            "criada_em" => (string) $linha["criada_em"]
        ];
    }

    $resultado->free();
    $stmt->close();
    $conn->close();

    responderNotificacoes(200, [
        "sucesso" => true,
        "notificacoes" => $notificacoes
    ]);
} catch (Throwable $erro) {
    error_log(
        "Erro ao consultar notificações do editor: " . $erro->getMessage()
    );

    if (isset($stmt) && $stmt instanceof mysqli_stmt) {
        try { $stmt->close(); } catch (Throwable $ignorado) {}
    }
    if (isset($conn) && $conn instanceof mysqli) {
        try { $conn->close(); } catch (Throwable $ignorado) {}
    }

    responderNotificacoes(500, [
        "sucesso" => false,
        "mensagem" => "Não foi possível carregar suas notificações."
    ]);
}
