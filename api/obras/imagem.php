<?php

session_set_cookie_params([
    "lifetime" => 0,
    "path" => "/Arte-Guiada/",
    "secure" => false,
    "httponly" => true,
    "samesite" => "Lax"
]);

session_start();

$usuarioId = (int) ($_SESSION["usuario_id"] ?? 0);
$perfil = strtolower(trim((string) ($_SESSION["perfil"] ?? "")));
$id = filter_input(INPUT_GET, "id", FILTER_VALIDATE_INT);

function falhaImagem(int $status, string $mensagem): void
{
    http_response_code($status);
    header("Content-Type: application/json; charset=UTF-8");
    echo json_encode(["sucesso" => false, "mensagem" => $mensagem], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "GET") {
    header("Allow: GET");
    falhaImagem(405, "Método não permitido.");
}
if ($usuarioId <= 0) {
    falhaImagem(401, "Sua sessão expirou. Entre novamente.");
}
if (!in_array($perfil, ["editor", "admin"], true)) {
    falhaImagem(403, "Você não tem permissão para acessar esta imagem.");
}
if (!$id || $id < 1) {
    falhaImagem(400, "Identificador de obra inválido.");
}

require_once "../../config/banco.php";

try {
    $stmt = $conn->prepare(
        "SELECT criada_por, imagem, imagem_mime
         FROM obras
         WHERE id = ? AND excluida_em IS NULL"
    );
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $obra = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $conn->close();

    if (!$obra) {
        falhaImagem(404, "A imagem não foi encontrada.");
    }
    if ($perfil !== "admin" && (int) $obra["criada_por"] !== $usuarioId) {
        falhaImagem(403, "Você só pode acessar imagens das obras que criou.");
    }
    if (!is_string($obra["imagem"]) || $obra["imagem"] === "") {
        falhaImagem(404, "Esta obra ainda não possui uma imagem salva.");
    }

    $mime = (string) $obra["imagem_mime"];
    if (!in_array($mime, ["image/jpeg", "image/png", "image/webp"], true)) {
        falhaImagem(415, "Formato de imagem armazenado não permitido.");
    }

    header("Content-Type: " . $mime);
    header("Content-Length: " . strlen($obra["imagem"]));
    header("Cache-Control: private, no-store, max-age=0");
    header("X-Content-Type-Options: nosniff");
    echo $obra["imagem"];
} catch (Throwable $erro) {
    error_log("Erro ao carregar imagem de obra: " . $erro->getMessage());
    if (isset($conn) && $conn instanceof mysqli) $conn->close();
    falhaImagem(500, "Não foi possível carregar a imagem.");
}
