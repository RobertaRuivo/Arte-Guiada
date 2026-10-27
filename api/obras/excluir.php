<?php
declare(strict_types=1);

session_set_cookie_params([
    "lifetime" => 0,
    "path" => "/Arte-Guiada/",
    "secure" => false,
    "httponly" => true,
    "samesite" => "Lax"
]);

session_start();

header("Content-Type: application/json; charset=UTF-8");
header("Cache-Control: no-store");

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

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

$tipoConteudo = strtolower(
    trim(explode(";", $_SERVER["CONTENT_TYPE"] ?? "")[0])
);

if ($tipoConteudo !== "application/json") {
    responderJson(415, [
        "sucesso" => false,
        "mensagem" => "Envie os dados no formato JSON."
    ]);
}

$corpo = file_get_contents("php://input");
$dados = json_decode($corpo, true);

if (!is_array($dados) || json_last_error() !== JSON_ERROR_NONE) {
    responderJson(400, [
        "sucesso" => false,
        "mensagem" => "O corpo da requisição contém JSON inválido."
    ]);
}

$obraId = filter_var(
    $dados["obra_id"] ?? null,
    FILTER_VALIDATE_INT
);

if ($obraId === false || $obraId === null || $obraId < 1) {
    responderJson(400, [
        "sucesso" => false,
        "mensagem" => "Identificador da obra inválido."
    ]);
}

$usuarioId = filter_var(
    $_SESSION["usuario_id"] ?? null,
    FILTER_VALIDATE_INT
);

$perfil = strtolower(
    trim((string) ($_SESSION["perfil"] ?? ""))
);

if ($usuarioId === false || $usuarioId === null || $usuarioId < 1) {
    responderJson(401, [
        "sucesso" => false,
        "mensagem" => "Sua sessão expirou. Entre novamente."
    ]);
}

if ($perfil === "administrador") {
    $perfil = "admin";
}

if (!in_array($perfil, ["editor", "admin"], true)) {
    responderJson(403, [
        "sucesso" => false,
        "mensagem" => "Você não tem permissão para excluir obras."
    ]);
}

$isAdmin = ($perfil === "admin");

try {
    require_once __DIR__ . "/../../config/banco.php";

    if (!isset($conn) || !($conn instanceof mysqli)) {
        throw new RuntimeException(
            "A conexão com o banco não foi disponibilizada."
        );
    }

    $conn->set_charset("utf8mb4");
    $conn->begin_transaction();

    /*
     * Editores só podem excluir as próprias obras.
     * Administradores podem excluir qualquer obra.
     *
     * A exclusão é lógica: o registro permanece no banco,
     * mas deixa de aparecer nas consultas que filtram excluida_em.
     */
    $sql = "
        UPDATE obras
        SET excluida_em = CURRENT_TIMESTAMP
        WHERE id = ?
          AND (criada_por = ? OR ? = 1)
          AND excluida_em IS NULL
    ";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param(
        "iii",
        $obraId,
        $usuarioId,
        $isAdmin
    );

    $stmt->execute();
    $linhasAlteradas = $stmt->affected_rows;

    $stmt->close();

    if ($linhasAlteradas !== 1) {
        $conn->rollback();
        $conn->close();

        responderJson(404, [
            "sucesso" => false,
            "mensagem" =>
                "Obra não encontrada ou indisponível para exclusão."
        ]);
    }

    $conn->commit();
    $conn->close();

    error_log(
        "[" . date("Y-m-d H:i:s") . "] " .
        "Exclusão lógica de obra concluída. " .
        "obra_id=" . $obraId .
        "; usuario_id=" . $usuarioId .
        "; perfil=" . $perfil
    );

    responderJson(200, [
        "sucesso" => true,
        "mensagem" => "Obra excluída com sucesso.",
        "obra_id" => $obraId
    ]);

} catch (Throwable $erro) {
    if (isset($conn) && $conn instanceof mysqli) {
        try {
            $conn->rollback();
        } catch (Throwable $erroRollback) {
            // A transação pode já ter sido encerrada.
        }

        try {
            $conn->close();
        } catch (Throwable $erroFechamento) {
            // A conexão pode já ter sido encerrada.
        }
    }

    error_log(
        "[" . date("Y-m-d H:i:s") . "] " .
        "Erro ao excluir obra. " .
        "Tipo=" . get_class($erro) .
        "; codigo=" . $erro->getCode() .
        "; mensagem=" . $erro->getMessage()
    );

    responderJson(500, [
        "sucesso" => false,
        "mensagem" => "Não foi possível excluir a obra."
    ]);
}