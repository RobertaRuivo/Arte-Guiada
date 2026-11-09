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

// Não cria uma sessão vazia quando não existe cookie.
if (
    !isset($_COOKIE[session_name()]) ||
    $_COOKIE[session_name()] === ""
) {
    responderJson(401, [
        "sucesso" => false,
        "mensagem" => "Entre na sua conta para consultar sua biblioteca."
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
        "mensagem" => "Entre na sua conta para consultar sua biblioteca."
    ]);
}

/*
|--------------------------------------------------------------------------
| 3. Validar coleção solicitada
|--------------------------------------------------------------------------
*/

$colecao = $_GET["colecao"] ?? "favoritos";

$condicoes = [
    "favoritos" => "b.favorito = 1",
    "quero_explorar" => "b.quero_explorar = 1",
    "exploradas" => "b.explorada_em IS NOT NULL"
];

if (
    !is_string($colecao) ||
    !isset($condicoes[$colecao])
) {
    responderJson(400, [
        "sucesso" => false,
        "mensagem" => "Coleção inválida."
    ]);
}

/*
|--------------------------------------------------------------------------
| 4. Consultar obras da biblioteca
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
     * A condição SQL vem exclusivamente da lista permitida.
     * O usuário não consegue inserir comandos SQL pelo parâmetro
     * colecao.
     */

    $sql = "
        SELECT
            o.id,
            o.titulo,
            o.autor,
            o.texto_alternativo,
            b.favorito,
            b.quero_explorar,
            b.explorada_em,
            (
                SELECT COUNT(*)
                FROM etapas_exploracao e
                WHERE e.obra_id = o.id
            ) AS total_etapas
        FROM biblioteca_usuario b
        INNER JOIN obras o
            ON o.id = b.obra_id
        WHERE b.usuario_id = ?
          AND o.status = 'publicada'
          AND o.excluida_em IS NULL
          AND {$condicoes[$colecao]}
        ORDER BY
            COALESCE(b.explorada_em, b.atualizada_em) DESC,
            o.titulo ASC
    ";

    $stmt = $conn->prepare($sql);

    $stmt->bind_param("i", $usuarioId);

    $stmt->execute();

    $resultado = $stmt->get_result();

    $obras = [];

    while ($linha = $resultado->fetch_assoc()) {
        $obraId = (int) $linha["id"];

        $obras[] = [
            "id" => $obraId,
            "titulo" => (string) ($linha["titulo"] ?? ""),
            "autor" => (string) ($linha["autor"] ?? ""),
            "texto_alternativo" => (string) (
                $linha["texto_alternativo"] ?? ""
            ),

            "favorito" => (bool) $linha["favorito"],

            "quero_explorar" => (bool) (
                $linha["quero_explorar"]
            ),

            "explorada_em" => $linha["explorada_em"],

            "total_etapas" => (int) $linha["total_etapas"],

            "imagem_url" =>
                "../../api/obras/imagem-publica.php?id=" . $obraId,

        ];
    }

    $resultado->free();

    $stmt->close();
    $stmt = null;

    $conn->close();
    $conn = null;

    responderJson(200, [
        "sucesso" => true,
        "colecao" => $colecao,
        "obras" => $obras
    ]);

} catch (Throwable $erro) {
    error_log(
        "Erro ao carregar biblioteca: " .
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
        "mensagem" => "Não foi possível carregar esta coleção."
    ]);
}