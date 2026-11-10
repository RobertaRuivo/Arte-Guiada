<?php
declare(strict_types=1);

header("Content-Type: application/json; charset=UTF-8");
header("Cache-Control: no-store");
header("Pragma: no-cache");

function responderJson(int $status, array $dados): void
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
    responderJson(405, [
        "sucesso" => false,
        "mensagem" => "Método não permitido."
    ]);
}

require_once __DIR__ . "/../../config/protecao-csrf.php";
exigirOrigemConfiavelCsrf();

$tipoConteudo = strtolower(
    trim(explode(";", (string) ($_SERVER["CONTENT_TYPE"] ?? ""))[0])
);

if ($tipoConteudo !== "application/json") {
    responderJson(415, [
        "sucesso" => false,
        "mensagem" => "Envie os dados no formato JSON."
    ]);
}

$corpo = file_get_contents("php://input");
if ($corpo === false || $corpo === "") {
    responderJson(400, [
        "sucesso" => false,
        "mensagem" => "Não foi possível ler os dados enviados."
    ]);
}

try {
    $dados = json_decode($corpo, false, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $erro) {
    responderJson(400, [
        "sucesso" => false,
        "mensagem" => "O corpo da requisição contém JSON inválido."
    ]);
}

if (!$dados instanceof stdClass) {
    responderJson(400, [
        "sucesso" => false,
        "mensagem" => "Os dados enviados são inválidos."
    ]);
}

if (
    !property_exists($dados, "obra_id") ||
    (!is_int($dados->obra_id) && !is_string($dados->obra_id))
) {
    responderJson(400, [
        "sucesso" => false,
        "mensagem" => "Identificador da obra inválido."
    ]);
}

$obraId = filter_var(
    $dados->obra_id,
    FILTER_VALIDATE_INT,
    ["options" => ["min_range" => 1]]
);

if ($obraId === false || $obraId === null) {
    responderJson(400, [
        "sucesso" => false,
        "mensagem" => "Identificador da obra inválido."
    ]);
}
$obraId = (int) $obraId;

/* Configuração de sessão compatível com Apache e servidor PHP de testes. */
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
    responderJson(401, [
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
} catch (Throwable $erro) {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_abort();
    }

    error_log(
        "[" . date("Y-m-d H:i:s") . "] Erro ao validar sessão para restaurar obra: " .
        $erro->getMessage()
    );

    responderJson(500, [
        "sucesso" => false,
        "mensagem" => "Não foi possível verificar sua sessão."
    ]);
}

if ($usuarioId === false || $usuarioId === null) {
    responderJson(401, [
        "sucesso" => false,
        "mensagem" => "Sua sessão expirou. Entre novamente."
    ]);
}

if (!in_array($perfil, ["editor", "admin"], true)) {
    responderJson(403, [
        "sucesso" => false,
        "mensagem" => "Você não tem permissão para restaurar obras."
    ]);
}

$usuarioId = (int) $usuarioId;
$isAdmin = $perfil === "admin" ? 1 : 0;

$conn = null;
$stmt = null;
$transacaoAberta = false;

try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    require_once __DIR__ . "/../../config/banco.php";

    if (!isset($conn) || !($conn instanceof mysqli)) {
        throw new RuntimeException("A conexão com o banco não está disponível.");
    }

    $conn->set_charset("utf8mb4");
    $conn->begin_transaction();
    $transacaoAberta = true;

    /*
     * A autorização é aplicada no próprio UPDATE:
     * editor restaura somente obras próprias; admin pode restaurar todas.
     * A imagem e as etapas não são tocadas.
     */
    $sql = "
        UPDATE obras
        SET excluida_em = NULL
        WHERE id = ?
          AND excluida_em IS NOT NULL
          AND (criada_por = ? OR ? = 1)
    ";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("iii", $obraId, $usuarioId, $isAdmin);
    $stmt->execute();
    $linhasAlteradas = $stmt->affected_rows;
    $stmt->close();
    $stmt = null;

    if ($linhasAlteradas !== 1) {
        $conn->rollback();
        $transacaoAberta = false;
        $conn->close();
        $conn = null;

        responderJson(404, [
            "sucesso" => false,
            "mensagem" => "Obra não encontrada ou indisponível para restauração."
        ]);
    }

    $conn->commit();
    $transacaoAberta = false;
    $conn->close();
    $conn = null;

    error_log(
        "[" . date("Y-m-d H:i:s") . "] Restauração de obra concluída. " .
        "obra_id=" . $obraId .
        "; usuario_id=" . $usuarioId .
        "; perfil=" . $perfil
    );

    responderJson(200, [
        "sucesso" => true,
        "obra_id" => $obraId,
        "mensagem" => "Obra restaurada com sucesso."
    ]);
} catch (Throwable $erro) {
    if ($stmt instanceof mysqli_stmt) {
        try {
            $stmt->close();
        } catch (Throwable $erroFechamento) {
            // Preserva o erro original.
        }
    }

    if ($transacaoAberta && $conn instanceof mysqli) {
        try {
            $conn->rollback();
        } catch (Throwable $erroRollback) {
            error_log("Erro ao reverter restauração: " . $erroRollback->getMessage());
        }
    }

    if ($conn instanceof mysqli) {
        try {
            $conn->close();
        } catch (Throwable $erroFechamento) {
            // Preserva o erro original.
        }
    }

    error_log(
        "[" . date("Y-m-d H:i:s") . "] Erro ao restaurar obra. " .
        "Tipo=" . get_class($erro) .
        "; mensagem=" . $erro->getMessage()
    );

    responderJson(500, [
        "sucesso" => false,
        "mensagem" => "Não foi possível restaurar a obra."
    ]);
}
