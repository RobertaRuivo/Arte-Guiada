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

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    header("Allow: GET");
    responderJson(405, [
        "sucesso" => false,
        "mensagem" => "Método não permitido."
    ]);
}

/* Calcula o caminho do cookie para Apache e servidor PHP de testes. */
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

$caminhoCookie = (
    $caminhoAplicacao === "." ||
    $caminhoAplicacao === DIRECTORY_SEPARATOR
)
    ? "/"
    : rtrim($caminhoAplicacao, "/") . "/";

$httpsAtivo = (
    !empty($_SERVER["HTTPS"]) &&
    strtolower((string) $_SERVER["HTTPS"]) !== "off"
);

ini_set("session.use_strict_mode", "1");
ini_set("session.use_only_cookies", "1");
ini_set("session.use_trans_sid", "0");
session_cache_limiter("");

session_set_cookie_params([
    "lifetime" => 0,
    "path" => $caminhoCookie,
    "secure" => $httpsAtivo,
    "httponly" => true,
    "samesite" => "Lax"
]);

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

if (!session_start()) {
    responderJson(500, [
        "sucesso" => false,
        "mensagem" => "Não foi possível validar sua sessão."
    ]);
}

$usuarioId = filter_var(
    $_SESSION["usuario_id"] ?? null,
    FILTER_VALIDATE_INT,
    ["options" => ["min_range" => 1]]
);

$perfil = strtolower(trim((string) ($_SESSION["perfil"] ?? "")));
session_write_close();

if ($perfil === "administrador") {
    $perfil = "admin";
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
        "mensagem" => "Você não tem permissão para consultar obras excluídas."
    ]);
}

$conn = null;
$stmt = null;

try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    require_once __DIR__ . "/../../config/banco.php";

    if (!isset($conn) || !($conn instanceof mysqli)) {
        throw new RuntimeException(
            "A conexão com o banco de dados não foi inicializada."
        );
    }

    $conn->set_charset("utf8mb4");

    $sql = "
        SELECT
            o.id,
            o.titulo,
            o.autor,
            o.status,
            o.imagem_mime,
            o.criada_por,
            o.destaque,
            o.ordem_destaque,
            NULLIF(
                TRIM(CONCAT_WS(' ', u_editor.nome, u_editor.sobrenome)),
                ''
            ) AS editor_nome,
            (
                o.imagem IS NOT NULL AND
                OCTET_LENGTH(o.imagem) > 0
            ) AS imagem_salva,
            o.criada_em,
            o.atualizada_em,
            o.publicada_em,
            o.excluida_em,
            (
                SELECT COUNT(*)
                FROM etapas_exploracao e
                WHERE e.obra_id = o.id
            ) AS total_etapas
        FROM obras o
        LEFT JOIN usuarios u_editor
            ON u_editor.id = o.criada_por
        WHERE o.excluida_em IS NOT NULL
    ";

    if ($perfil === "editor") {
        $sql .= " AND o.criada_por = ? ";
    }

    $sql .= " ORDER BY o.excluida_em DESC, o.id DESC ";

    $stmt = $conn->prepare($sql);

    if ($perfil === "editor") {
        $usuarioId = (int) $usuarioId;
        $stmt->bind_param("i", $usuarioId);
    }

    $stmt->execute();
    $resultado = $stmt->get_result();

    $obras = [];
    while ($obra = $resultado->fetch_assoc()) {
        $obras[] = [
            "id" => (int) $obra["id"],
            "titulo" => $obra["titulo"],
            "autor" => $obra["autor"],
            "status" => $obra["status"],
            "imagem_mime" => $obra["imagem_mime"],
            "imagem_salva" => (bool) $obra["imagem_salva"],
            "criada_por" => (int) $obra["criada_por"],
            "editor_nome" => $obra["editor_nome"],
            "destaque" => (bool) $obra["destaque"],
            "ordem_destaque" => $obra["ordem_destaque"] === null
                ? null
                : (int) $obra["ordem_destaque"],
            "criada_em" => $obra["criada_em"],
            "atualizada_em" => $obra["atualizada_em"],
            "publicada_em" => $obra["publicada_em"],
            "excluida_em" => $obra["excluida_em"],
            "total_etapas" => (int) $obra["total_etapas"]
        ];
    }

    $resultado->free();
    $stmt->close();
    $stmt = null;
    $conn->close();
    $conn = null;

    responderJson(200, [
        "sucesso" => true,
        "total" => count($obras),
        "obras" => $obras
    ]);
} catch (Throwable $erro) {
    error_log(
        "[" . date("Y-m-d H:i:s") . "] " .
        "Erro ao listar obras excluídas: " .
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
        "mensagem" => "Não foi possível consultar as obras excluídas."
    ]);
}
