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
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_INVALID_UTF8_SUBSTITUTE
    );

    exit;
}

/*
|--------------------------------------------------------------------------
| 1. Validar método HTTP
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    header("Allow: GET");

    responderJson(405, [
        "sucesso" => false,
        "mensagem" => "Método não permitido."
    ]);
}

/*
|--------------------------------------------------------------------------
| 2. Configurar e validar sessão
|--------------------------------------------------------------------------
*/

$caminhoScript = str_replace(
    "\\",
    "/",
    (string) ($_SERVER["SCRIPT_NAME"] ?? "/")
);

$caminhoAplicacao = dirname(
    dirname(
        dirname($caminhoScript)
    )
);

if (
    $caminhoAplicacao === "." ||
    $caminhoAplicacao === DIRECTORY_SEPARATOR
) {
    $caminhoCookie = "/";
} else {
    $caminhoCookie = rtrim($caminhoAplicacao, "/") . "/";
}

$httpsAtivo = (
    !empty($_SERVER["HTTPS"]) &&
    $_SERVER["HTTPS"] !== "off"
);

session_set_cookie_params([
    "lifetime" => 0,
    "path" => $caminhoCookie,
    "secure" => $httpsAtivo,
    "httponly" => true,
    "samesite" => "Lax"
]);

ini_set("session.use_strict_mode", "1");
ini_set("session.use_only_cookies", "1");
ini_set("session.use_trans_sid", "0");

// Não cria uma sessão nova quando não existe cookie.
if (
    !isset($_COOKIE[session_name()]) ||
    $_COOKIE[session_name()] === ""
) {
    responderJson(401, [
        "sucesso" => false,
        "mensagem" => "Entre na sua conta para salvar esta obra."
    ]);
}

if (!session_start()) {
    responderJson(500, [
        "sucesso" => false,
        "mensagem" => "Não foi possível validar sua sessão."
    ]);
}

$usuarioId = (int) ($_SESSION["usuario_id"] ?? 0);

session_write_close();

if ($usuarioId <= 0) {
    responderJson(401, [
        "sucesso" => false,
        "mensagem" => "Entre na sua conta para salvar esta obra."
    ]);
}

/*
|--------------------------------------------------------------------------
| 3. Validar identificador da obra
|--------------------------------------------------------------------------
*/

$obraRecebida = $_GET["obra_id"] ?? null;

if (!is_string($obraRecebida) && !is_int($obraRecebida)) {
    responderJson(400, [
        "sucesso" => false,
        "mensagem" => "Identificador de obra inválido."
    ]);
}

$obraId = filter_var(
    $obraRecebida,
    FILTER_VALIDATE_INT
);

if ($obraId === false || $obraId === null || $obraId < 1) {
    responderJson(400, [
        "sucesso" => false,
        "mensagem" => "Identificador de obra inválido."
    ]);
}

/*
|--------------------------------------------------------------------------
| 4. Consultar estado da biblioteca
|--------------------------------------------------------------------------
*/

$conn = null;
$stmt = null;

try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    require_once __DIR__ . "/../../config/banco.php";

    if (!isset($conn) || !($conn instanceof mysqli)) {
        throw new RuntimeException(
            "A conexão com o banco não foi inicializada."
        );
    }

    $conn->set_charset("utf8mb4");

    /*
     * Consulta a obra e o estado da biblioteca em uma única operação.
     *
     * O LEFT JOIN permite localizar uma obra publicada mesmo quando
     * o usuário ainda não possui registro na biblioteca.
     */

    $sql = "
        SELECT
            b.favorito,
            b.quero_explorar,
            b.explorada_em
        FROM obras o
        LEFT JOIN biblioteca_usuario b
            ON b.obra_id = o.id
           AND b.usuario_id = ?
        WHERE o.id = ?
          AND o.status = 'publicada'
          AND o.excluida_em IS NULL
        LIMIT 1
    ";

    $stmt = $conn->prepare($sql);

    $stmt->bind_param("ii", $usuarioId, $obraId);

    $stmt->execute();

    $resultado = $stmt->get_result();

    $linha = $resultado->fetch_assoc();

    $resultado->free();

    $stmt->close();
    $stmt = null;

    // Obra inexistente, não publicada ou excluída.
    if (!$linha) {
        $conn->close();
        $conn = null;

        responderJson(404, [
            "sucesso" => false,
            "mensagem" => "A obra não está disponível."
        ]);
    }

    $conn->close();
    $conn = null;

    /*
     * Se ainda não existir registro na biblioteca, os valores
     * retornam como NULL e são convertidos para false.
     */

    responderJson(200, [
        "sucesso" => true,
        "obra_id" => $obraId,
        "estado" => [
            "favorito" => (bool) ($linha["favorito"] ?? false),

            "quero_explorar" => (bool) (
                $linha["quero_explorar"] ?? false
            ),

            "explorada" => !empty($linha["explorada_em"]),

            "explorada_em" => $linha["explorada_em"] ?? null
        ]
    ]);

} catch (Throwable $erro) {
    error_log(
        "Erro ao consultar estado da biblioteca: " .
        $erro->getMessage()
    );

    if ($stmt instanceof mysqli_stmt) {
        try {
            $stmt->close();
        } catch (Throwable $erroFechamento) {
            // Preserva o erro original.
        }
    }

    if ($conn instanceof mysqli) {
        try {
            $conn->close();
        } catch (Throwable $erroFechamento) {
            // Preserva o erro original.
        }
    }

    responderJson(500, [
        "sucesso" => false,
        "mensagem" => "Não foi possível consultar esta obra."
    ]);
}