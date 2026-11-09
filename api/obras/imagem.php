<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/painel-api.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    ag_responder_json(405, ['sucesso' => false, 'mensagem' => 'Método não permitido.']);
}
$sessao = ag_iniciar_sessao();
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$id) ag_responder_json(400, ['sucesso' => false, 'mensagem' => 'Identificador de obra inválido.']);

$conn = null;
try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    require __DIR__ . '/../../config/banco.php';
    if (!$conn instanceof mysqli) throw new RuntimeException('Conexão indisponível.');
    $conn->set_charset('utf8mb4');

    $stmt = $conn->prepare(
        "SELECT criada_por, status, imagem, imagem_mime
         FROM obras
         WHERE id = ? AND excluida_em IS NULL
         LIMIT 1"
    );
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $obra = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$obra) {
        $conn->close();
        ag_responder_json(404, ['sucesso' => false, 'mensagem' => 'A imagem não foi encontrada.']);
    }

    $obraPublicada = $obra['status'] === 'publicada';
    if (!$obraPublicada) {
        if ($sessao['usuario_id'] < 1) {
            $conn->close();
            ag_responder_json(401, ['sucesso' => false, 'mensagem' => 'Entre na sua conta para acessar esta imagem.']);
        }
        if (!in_array($sessao['perfil'], ['editor', 'admin'], true)) {
            $conn->close();
            ag_responder_json(403, ['sucesso' => false, 'mensagem' => 'Você não tem permissão para acessar esta imagem.']);
        }
        if ($sessao['perfil'] === 'editor') {
            $stmtAcesso = $conn->prepare(
                'SELECT ativo FROM obras_editores_acesso WHERE obra_id = ? AND usuario_id = ? LIMIT 1'
            );
            $usuarioId = (int) $sessao['usuario_id'];
            $stmtAcesso->bind_param('ii', $id, $usuarioId);
            $stmtAcesso->execute();
            $permissao = $stmtAcesso->get_result()->fetch_assoc();
            $stmtAcesso->close();
            $editorEhAutor = (int) $obra['criada_por'] === $usuarioId;
            $podeAcessar = $permissao !== null
                ? (int) $permissao['ativo'] === 1
                : $editorEhAutor;
            if (!$podeAcessar) {
                $conn->close();
                ag_responder_json(403, ['sucesso' => false, 'mensagem' => 'Você não tem mais permissão para acessar esta imagem.']);
            }
        }
    }

    if (!is_string($obra['imagem']) || $obra['imagem'] === '') {
        $conn->close();
        ag_responder_json(404, ['sucesso' => false, 'mensagem' => 'Esta obra ainda não possui uma imagem salva.']);
    }
    $mime = (string) $obra['imagem_mime'];
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
        $conn->close();
        ag_responder_json(415, ['sucesso' => false, 'mensagem' => 'Formato de imagem armazenado não permitido.']);
    }
    $imagem = $obra['imagem'];
    $conn->close();
    $conn = null;

    header('Content-Type: ' . $mime);
    header('Content-Length: ' . strlen($imagem));
    header('X-Content-Type-Options: nosniff');
    header($obraPublicada ? 'Cache-Control: public, max-age=3600' : 'Cache-Control: private, no-store, max-age=0');
    echo $imagem;
} catch (Throwable $erro) {
    error_log('Erro ao carregar imagem de obra: ' . $erro->getMessage());
    if ($conn instanceof mysqli) { try { $conn->close(); } catch (Throwable $ignorado) {} }
    ag_responder_json(500, ['sucesso' => false, 'mensagem' => 'Não foi possível carregar a imagem.']);
}
