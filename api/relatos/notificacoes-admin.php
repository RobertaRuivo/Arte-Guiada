<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/painel-api.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    header('Allow: GET');
    ag_responder_json(405, ['sucesso' => false, 'mensagem' => 'Método não permitido.']);
}
$sessao = ag_exigir_perfil(['admin']);
$conn = null;

try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    require __DIR__ . '/../../config/banco.php';
    if (!$conn instanceof mysqli) throw new RuntimeException('Conexão indisponível.');
    $conn->set_charset('utf8mb4');
    $stmt = $conn->prepare(
        "SELECT id, relato_id, tipo, titulo, mensagem,
                DATE_FORMAT(criada_em, '%Y-%m-%d %H:%i:%s') AS criada_em
         FROM notificacoes_admin
         WHERE usuario_id = ? AND lida_em IS NULL
         ORDER BY criada_em DESC, id DESC
         LIMIT 30"
    );
    $usuarioId = (int) $sessao['usuario_id'];
    $stmt->bind_param('i', $usuarioId);
    $stmt->execute();
    $resultado = $stmt->get_result();
    $avisos = [];
    while ($linha = $resultado->fetch_assoc()) {
        $avisos[] = [
            'id' => (int) $linha['id'],
            'relato_id' => (int) $linha['relato_id'],
            'tipo' => (string) $linha['tipo'],
            'titulo' => (string) $linha['titulo'],
            'mensagem' => (string) $linha['mensagem'],
            'criada_em' => (string) $linha['criada_em']
        ];
    }
    $stmt->close();
    $conn->close();
    $conn = null;
    ag_responder_json(200, ['sucesso' => true, 'total' => count($avisos), 'avisos' => $avisos]);
} catch (Throwable $erro) {
    error_log('Falha ao consultar notificações administrativas: ' . $erro->getMessage());
    if ($conn instanceof mysqli) { try { $conn->close(); } catch (Throwable $ignorado) {} }
    ag_responder_json(500, ['sucesso' => false, 'mensagem' => 'Não foi possível consultar os avisos administrativos.']);
}
