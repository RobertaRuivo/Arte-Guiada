<?php
declare(strict_types=1);

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id || $id < 1) {
    http_response_code(400);
    exit('Identificador inválido.');
}
require_once __DIR__ . '/../../config/banco.php';
try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $stmt = $conn->prepare(
        "SELECT imagem, imagem_mime
         FROM obras
         WHERE id = ? AND status = 'publicada' AND excluida_em IS NULL
         LIMIT 1"
    );
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $obra = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $conn->close();
    if (!$obra || !is_string($obra['imagem']) || $obra['imagem'] === '') {
        http_response_code(404);
        exit('Imagem não encontrada.');
    }
    $mime = (string) ($obra['imagem_mime'] ?? '');
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
        http_response_code(415);
        exit('Formato de imagem não permitido.');
    }
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . strlen($obra['imagem']));
    header('Cache-Control: public, max-age=3600');
    header('X-Content-Type-Options: nosniff');
    echo $obra['imagem'];
} catch (Throwable $erro) {
    error_log('Erro ao carregar imagem da biblioteca: ' . $erro->getMessage());
    if (isset($conn) && $conn instanceof mysqli) $conn->close();
    http_response_code(500);
    exit('Não foi possível carregar a imagem.');
}
