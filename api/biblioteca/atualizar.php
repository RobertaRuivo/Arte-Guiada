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
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );

    exit;
}

/*
|--------------------------------------------------------------------------
| 1. Validar método HTTP
|--------------------------------------------------------------------------
*/

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Allow: POST");

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

// Não cria uma sessão vazia quando não existe cookie.
if (
    !isset($_COOKIE[session_name()]) ||
    $_COOKIE[session_name()] === ""
) {
    responderJson(401, [
        "sucesso" => false,
        "mensagem" => "Entre na sua conta para salvar obras na biblioteca."
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
        "mensagem" => "Entre na sua conta para salvar obras na biblioteca."
    ]);
}

/*
|--------------------------------------------------------------------------
| 3. Validar origem da requisição
|--------------------------------------------------------------------------
*/

require_once __DIR__ . "/../../config/protecao-csrf.php";

exigirOrigemConfiavelCsrf();

/*
|--------------------------------------------------------------------------
| 4. Validar dados recebidos
|--------------------------------------------------------------------------
*/

$corpo = file_get_contents("php://input");

try {
    $dados = json_decode(
        $corpo,
        true,
        512,
        JSON_THROW_ON_ERROR
    );
} catch (JsonException $erro) {
    responderJson(400, [
        "sucesso" => false,
        "mensagem" => "O conteúdo enviado é inválido."
    ]);
}

if (!is_array($dados) || array_is_list($dados)) {
    responderJson(400, [
        "sucesso" => false,
        "mensagem" => "O conteúdo enviado é inválido."
    ]);
}

$obraRecebida = $dados["obra_id"] ?? null;

if (
    !is_int($obraRecebida) &&
    !is_string($obraRecebida)
) {
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

$acao = $dados["acao"] ?? null;

if (
    !is_string($acao) ||
    !in_array($acao, ["favorito", "quero_explorar"], true)
) {
    responderJson(400, [
        "sucesso" => false,
        "mensagem" => "Ação de biblioteca inválida."
    ]);
}

$ativo = $dados["ativo"] ?? null;

if (!is_bool($ativo)) {
    responderJson(400, [
        "sucesso" => false,
        "mensagem" => "O estado da ação deve ser verdadeiro ou falso."
    ]);
}

/*
|--------------------------------------------------------------------------
| 5. Atualizar estado da biblioteca
|--------------------------------------------------------------------------
*/

$conn = null;

$stmtObra = null;
$stmt = null;
$stmtLimpar = null;
$stmtEstado = null;

$transacaoAtiva = false;

try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    require_once __DIR__ . "/../../config/banco.php";

    if (!isset($conn) || !($conn instanceof mysqli)) {
        throw new RuntimeException(
            "A conexão com o banco não foi inicializada."
        );
    }

    $conn->set_charset("utf8mb4");

    // Inicia transação para manter as operações consistentes.
    $conn->begin_transaction();
    $transacaoAtiva = true;

    /*
     * Verifica se a obra existe, está publicada
     * e não foi excluída.
     */

    $stmtObra = $conn->prepare(
        "SELECT id
         FROM obras
         WHERE id = ?
           AND status = 'publicada'
           AND excluida_em IS NULL
         LIMIT 1"
    );

    $stmtObra->bind_param("i", $obraId);
    $stmtObra->execute();

    $resultadoObra = $stmtObra->get_result();
    $obra = $resultadoObra->fetch_assoc();

    $resultadoObra->free();

    $stmtObra->close();
    $stmtObra = null;

    if (!$obra) {
        $conn->rollback();
        $transacaoAtiva = false;

        $conn->close();
        $conn = null;

        responderJson(404, [
            "sucesso" => false,
            "mensagem" => "A obra não está disponível para a biblioteca."
        ]);
    }

    $valor = $ativo ? 1 : 0;

    /*
     * Atualiza somente a ação solicitada.
     * Os outros estados da biblioteca são preservados.
     */

    if ($acao === "favorito") {
        $stmt = $conn->prepare(
            "INSERT INTO biblioteca_usuario
                (usuario_id, obra_id, favorito, quero_explorar)
             VALUES (?, ?, ?, 0)
             ON DUPLICATE KEY UPDATE
                favorito = VALUES(favorito)"
        );
    } else {
        $stmt = $conn->prepare(
            "INSERT INTO biblioteca_usuario
                (usuario_id, obra_id, favorito, quero_explorar)
             VALUES (?, ?, 0, ?)
             ON DUPLICATE KEY UPDATE
                quero_explorar = VALUES(quero_explorar)"
        );
    }

    $stmt->bind_param("iii", $usuarioId, $obraId, $valor);
    $stmt->execute();

    $stmt->close();
    $stmt = null;

    /*
     * Remove registros sem qualquer estado ativo.
     * Favoritos e obras já exploradas são preservados.
     */

    $stmtLimpar = $conn->prepare(
        "DELETE FROM biblioteca_usuario
         WHERE usuario_id = ?
           AND obra_id = ?
           AND favorito = 0
           AND quero_explorar = 0
           AND explorada_em IS NULL"
    );

    $stmtLimpar->bind_param("ii", $usuarioId, $obraId);
    $stmtLimpar->execute();

    $stmtLimpar->close();
    $stmtLimpar = null;

    /*
     * Consulta o estado atualizado.
     */

    $stmtEstado = $conn->prepare(
        "SELECT favorito, quero_explorar, explorada_em
         FROM biblioteca_usuario
         WHERE usuario_id = ?
           AND obra_id = ?
         LIMIT 1"
    );

    $stmtEstado->bind_param("ii", $usuarioId, $obraId);
    $stmtEstado->execute();

    $resultadoEstado = $stmtEstado->get_result();
    $estado = $resultadoEstado->fetch_assoc();

    $resultadoEstado->free();

    $stmtEstado->close();
    $stmtEstado = null;

    // Confirma todas as operações.
    $conn->commit();
    $transacaoAtiva = false;

    $conn->close();
    $conn = null;

    responderJson(200, [
        "sucesso" => true,
        "obra_id" => $obraId,
        "acao" => $acao,
        "estado" => [
            "favorito" => (bool) ($estado["favorito"] ?? false),

            "quero_explorar" => (bool) (
                $estado["quero_explorar"] ?? false
            ),

            "explorada" => !empty($estado["explorada_em"]),

            "explorada_em" => $estado["explorada_em"] ?? null
        ]
    ]);

} catch (Throwable $erro) {
    error_log(
        "Erro ao atualizar biblioteca: " .
        $erro->getMessage()
    );

    // Desfaz alterações caso alguma operação tenha falhado.
    if (
        $transacaoAtiva &&
        $conn instanceof mysqli
    ) {
        try {
            $conn->rollback();
        } catch (Throwable $erroRollback) {
            // Preserva o erro original.
        }
    }

    // Fecha comandos que ainda estejam abertos.
    foreach (
        [$stmtEstado, $stmtLimpar, $stmt, $stmtObra] as $stmtPendente
    ) {
        if ($stmtPendente instanceof mysqli_stmt) {
            try {
                $stmtPendente->close();
            } catch (Throwable $erroFechamento) {
                // Preserva o erro original.
            }
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
        "mensagem" => "Não foi possível atualizar sua biblioteca."
    ]);
}