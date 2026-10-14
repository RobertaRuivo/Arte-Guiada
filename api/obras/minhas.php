<?php

session_set_cookie_params([
    "lifetime" => 0,
    "path" => "/Arte-Guiada/",
    "secure" => false,
    "httponly" => true,
    "samesite" => "Lax"
]);

session_start();
header("Content-Type: application/json; charset=UTF-8");

function responderJson(int $status, array $dados): void
{
    http_response_code($status);
    echo json_encode($dados, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    header("Allow: GET");
    responderJson(405, ["sucesso" => false, "mensagem" => "Método não permitido."]);
}

$usuarioId = (int) ($_SESSION["usuario_id"] ?? 0);
$perfil = strtolower(trim((string) ($_SESSION["perfil"] ?? "")));

if ($usuarioId <= 0) {
    responderJson(401, ["sucesso" => false, "mensagem" => "Sua sessão expirou. Entre novamente."]);
}

if (!in_array($perfil, ["editor", "admin"], true)) {
    responderJson(403, ["sucesso" => false, "mensagem" => "Você não tem permissão para consultar estas obras."]);
}

require_once "../../config/banco.php";

try {
    $sql = "SELECT
                o.id,
                o.titulo,
                o.autor,
                o.status,
                o.imagem_mime,
                (o.imagem IS NOT NULL AND OCTET_LENGTH(o.imagem) > 0) AS imagem_salva,
                o.criada_em,
                o.atualizada_em,
                o.publicada_em,
                (SELECT COUNT(*) FROM etapas_exploracao e WHERE e.obra_id = o.id) AS total_etapas
            FROM obras o
            WHERE o.excluida_em IS NULL";

    if ($perfil !== "admin") {
        $sql .= " AND o.criada_por = ?";
    }

    $sql .= " ORDER BY o.atualizada_em DESC, o.id DESC";

    $stmt = $conn->prepare($sql);
    if ($perfil !== "admin") {
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
            "criada_em" => $obra["criada_em"],
            "atualizada_em" => $obra["atualizada_em"],
            "publicada_em" => $obra["publicada_em"],
            "total_etapas" => (int) $obra["total_etapas"]
        ];
    }

    $stmt->close();
    $conn->close();

    responderJson(200, ["sucesso" => true, "obras" => $obras]);
} catch (Throwable $erro) {
    error_log("Erro ao listar obras do editor: " . $erro->getMessage());
    $conn->close();
    responderJson(500, ["sucesso" => false, "mensagem" => "Não foi possível carregar suas obras."]);
}
