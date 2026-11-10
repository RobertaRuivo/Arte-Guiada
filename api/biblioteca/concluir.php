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

// Não cria uma sessão nova para requisições sem cookie.
if (
    !isset($_COOKIE[session_name()]) ||
    $_COOKIE[session_name()] === ""
) {
    responderJson(401, [
        "sucesso" => false,
        "mensagem" => "Entre na sua conta para registrar esta exploração."
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
        "mensagem" => "Entre na sua conta para registrar esta exploração."
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
        false,
        512,
        JSON_THROW_ON_ERROR
    );
} catch (JsonException $erro) {
    responderJson(400, [
        "sucesso" => false,
        "mensagem" => "O conteúdo enviado é inválido."
    ]);
}

if (!$dados instanceof stdClass) {
    responderJson(400, [
        "sucesso" => false,
        "mensagem" => "O conteúdo enviado é inválido."
    ]);
}

// Identificador da obra.
$obraRecebida = $dados->obra_id ?? null;

if (
    !is_int($obraRecebida) &&
    !is_string($obraRecebida)
) {
    responderJson(400, [
        "sucesso" => false,
        "mensagem" => "Não foi possível validar a conclusão da obra."
    ]);
}

$obraId = filter_var(
    $obraRecebida,
    FILTER_VALIDATE_INT
);

// Quantidade de etapas informada pelo cliente.
$etapasRecebidas = $dados->etapas_concluidas ?? null;

if (
    !is_int($etapasRecebidas) &&
    !is_string($etapasRecebidas)
) {
    responderJson(400, [
        "sucesso" => false,
        "mensagem" => "Não foi possível validar a conclusão da obra."
    ]);
}

$etapasConcluidas = filter_var(
    $etapasRecebidas,
    FILTER_VALIDATE_INT
);

if (
    $obraId === false ||
    $obraId === null ||
    $obraId < 1 ||
    $etapasConcluidas === false ||
    $etapasConcluidas === null ||
    $etapasConcluidas < 1
) {
    responderJson(400, [
        "sucesso" => false,
        "mensagem" => "Não foi possível validar a conclusão da obra."
    ]);
}

/*
|--------------------------------------------------------------------------
| 5. Verificar a obra e registrar a conclusão
|--------------------------------------------------------------------------
*/

$conn = null;

$stmtObra = null;
$stmtEtapas = null;
$stmt = null;

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

    // Inicia uma transação para manter a verificação e o registro consistentes.
    $conn->begin_transaction();
    $transacaoAtiva = true;

    /*
     * Verifica se a obra existe, está publicada
     * e não foi excluída.
     *
     * FOR UPDATE impede alterações concorrentes nessa obra
     * enquanto a conclusão está sendo registrada.
     */

    $stmtObra = $conn->prepare(
        "SELECT id
         FROM obras
         WHERE id = ?
           AND status = 'publicada'
           AND excluida_em IS NULL
         LIMIT 1
         FOR UPDATE"
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
            "mensagem" => "A obra não está disponível."
        ]);
    }

    /*
     * Consulta a quantidade real de etapas cadastradas.
     */

    $stmtEtapas = $conn->prepare(
        "SELECT COUNT(*) AS total
         FROM etapas_exploracao
         WHERE obra_id = ?"
    );

    $stmtEtapas->bind_param("i", $obraId);
    $stmtEtapas->execute();

    $resultadoEtapas = $stmtEtapas->get_result();
    $dadosEtapas = $resultadoEtapas->fetch_assoc();

    $resultadoEtapas->free();

    $stmtEtapas->close();
    $stmtEtapas = null;

    $totalEtapas = (int) ($dadosEtapas["total"] ?? 0);

    /*
     * A conclusão precisa corresponder à quantidade
     * de etapas cadastradas para a obra.
     */

    if (
        $totalEtapas < 1 ||
        $etapasConcluidas !== $totalEtapas
    ) {
        $conn->rollback();
        $transacaoAtiva = false;

        $conn->close();
        $conn = null;

        responderJson(409, [
            "sucesso" => false,
            "mensagem" => "A conclusão não corresponde ao número de etapas cadastradas."
        ]);
    }

    /*
     * Registra a exploração.
     *
     * Se já existir um registro na biblioteca,
     * preserva a data da primeira conclusão.
     *
     * Os outros estados, como favorito e quero_explorar,
     * não são alterados.
     */

    $stmt = $conn->prepare(
        "INSERT INTO biblioteca_usuario
            (usuario_id, obra_id, explorada_em)
         VALUES (?, ?, NOW())
         ON DUPLICATE KEY UPDATE
            explorada_em = COALESCE(explorada_em, NOW())"
    );

    $stmt->bind_param("ii", $usuarioId, $obraId);
    $stmt->execute();

    $stmt->close();
    $stmt = null;

    // Confirma o registro.
    $conn->commit();
    $transacaoAtiva = false;

    $conn->close();
    $conn = null;

    responderJson(200, [
        "sucesso" => true,
        "obra_id" => $obraId,
        "explorada" => true,
        "mensagem" => "Obra registrada em Já explorei."
    ]);

} catch (Throwable $erro) {
    error_log(
        "Erro ao registrar conclusão de obra: " .
        $erro->getMessage()
    );

    // Desfaz alterações caso a transação ainda esteja ativa.
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
        [$stmt, $stmtEtapas, $stmtObra] as $stmtPendente
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
        "mensagem" => "Não foi possível registrar a conclusão."
    ]);
}