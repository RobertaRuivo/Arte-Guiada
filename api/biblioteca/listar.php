<?php
declare(strict_types=1);

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/Arte-Guiada/',
    'secure' => false,
    'httponly' => true,
    'samesite' => 'Lax'
]);
session_start();
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

function responderJson(int $status, array $dados): void
{
    http_response_code($status);
    echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    responderJson(405, ['sucesso' => false, 'mensagem' => 'Método não permitido.']);
}

$usuarioId = (int) ($_SESSION['usuario_id'] ?? 0);
if ($usuarioId <= 0) {
    responderJson(401, ['sucesso' => false, 'mensagem' => 'Entre na sua conta para consultar sua biblioteca.']);
}

$colecao = (string) ($_GET['colecao'] ?? 'favoritos');
$condicoes = [
    'favoritos' => 'b.favorito = 1',
    'quero_explorar' => 'b.quero_explorar = 1',
    'exploradas' => 'b.explorada_em IS NOT NULL'
];
if (!isset($condicoes[$colecao])) {
    responderJson(400, ['sucesso' => false, 'mensagem' => 'Coleção inválida.']);
}

require_once __DIR__ . '/../../config/banco.php';

try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    $conn->set_charset('utf8mb4');
    $sql = "SELECT o.id, o.titulo, o.autor, o.texto_alternativo,
                   b.favorito, b.quero_explorar, b.explorada_em,
                   (SELECT COUNT(*) FROM etapas_exploracao e WHERE e.obra_id = o.id) AS total_etapas
            FROM biblioteca_usuario b
            INNER JOIN obras o ON o.id = b.obra_id
            WHERE b.usuario_id = ?
              AND o.status = 'publicada'
              AND o.excluida_em IS NULL
              AND {$condicoes[$colecao]}
            ORDER BY COALESCE(b.explorada_em, b.atualizada_em) DESC, o.titulo ASC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $usuarioId);
    $stmt->execute();
    $resultado = $stmt->get_result();
    $obras = [];

    while ($linha = $resultado->fetch_assoc()) {
        $obras[] = [
            'id' => (int) $linha['id'],
            'titulo' => (string) ($linha['titulo'] ?? ''),
            'autor' => (string) ($linha['autor'] ?? ''),
            'texto_alternativo' => (string) ($linha['texto_alternativo'] ?? ''),
            'favorito' => (bool) $linha['favorito'],
            'quero_explorar' => (bool) $linha['quero_explorar'],
            'explorada_em' => $linha['explorada_em'],
            'total_etapas' => (int) $linha['total_etapas'],
            'imagem_url' => '../../api/biblioteca/imagem.php?id=' . (int) $linha['id'],
            'url_exploracao' => (int) $linha['id'] === 3 ? 'galeria.html?obra_id=3' : null
        ];
    }

    $stmt->close();
    $conn->close();
    responderJson(200, ['sucesso' => true, 'colecao' => $colecao, 'obras' => $obras]);
} catch (Throwable $erro) {
    error_log('Erro ao carregar biblioteca: ' . $erro->getMessage());
    if (isset($conn) && $conn instanceof mysqli) $conn->close();
    responderJson(500, ['sucesso' => false, 'mensagem' => 'Não foi possível carregar esta coleção.']);
}
