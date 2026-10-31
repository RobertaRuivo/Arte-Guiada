<?php
declare(strict_types=1);

/*
 * Arte Guiada
 * API administrativa para consulta dos relatos.
 * Somente usuários autenticados com perfil admin podem acessar.
 */

require_once __DIR__ . '/comum.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    responderRelato(405, [
        'sucesso' => false,
        'mensagem' => 'Método não permitido.'
    ]);
}

$usuarioId = (int) ($_SESSION['usuario_id'] ?? 0);
$perfil = strtolower(trim((string) ($_SESSION['perfil'] ?? '')));

if ($usuarioId < 1 || $perfil !== 'admin') {
    responderRelato(403, [
        'sucesso' => false,
        'mensagem' => 'Acesso permitido somente ao administrador.'
    ]);
}

$conn = null;

try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    require __DIR__ . '/../../config/banco.php';

    if (!$conn instanceof mysqli) {
        throw new RuntimeException('A conexão com o banco não foi inicializada.');
    }

    $conn->set_charset('utf8mb4');

    /*
     * A subconsulta agrega as categorias adicionais de cada relato.
     * O campo tipo da tabela principal continua sendo usado como
     * alternativa para registros antigos sem linhas na tabela de tipos.
     */
    $sql = "
        SELECT
            r.id,
            r.usuario_id,
            r.obra_id,
            o.titulo AS obra_titulo,
            o.autor AS obra_autor,
            r.tipo,
            r.descricao,
            r.status,
            DATE_FORMAT(r.enviado_em, '%Y-%m-%d %H:%i:%s') AS enviado_em,
            DATE_FORMAT(r.atualizado_em, '%Y-%m-%d %H:%i:%s') AS atualizado_em,
            CASE
                WHEN u.id IS NULL THEN 'Visitante anônimo'
                ELSE TRIM(CONCAT_WS(' ', u.nome, u.sobrenome))
            END AS relator_nome,
            CASE
                WHEN atendente.id IS NULL THEN NULL
                ELSE TRIM(CONCAT_WS(' ', atendente.nome, atendente.sobrenome))
            END AS atendente_nome,
            (
                SELECT GROUP_CONCAT(t.tipo ORDER BY t.id SEPARATOR '|')
                FROM relatos_problema_tipos AS t
                WHERE t.relato_id = r.id
            ) AS tipos
        FROM relatos_problema AS r
        INNER JOIN obras AS o
            ON o.id = r.obra_id
        LEFT JOIN usuarios AS u
            ON u.id = r.usuario_id
        LEFT JOIN usuarios AS atendente
            ON atendente.id = r.atendido_por
        ORDER BY r.enviado_em DESC, r.id DESC
        LIMIT 500
    ";

    $stmt = $conn->prepare($sql);
    $stmt->execute();

    $resultado = $stmt->get_result();
    $relatos = [];

    while ($relato = $resultado->fetch_assoc()) {
        $tipos = [];

        if (is_string($relato['tipos']) && $relato['tipos'] !== '') {
            $tipos = array_values(array_unique(array_filter(
                explode('|', $relato['tipos']),
                static fn ($tipo) => $tipo !== ''
            )));
        }

        if ($tipos === [] && !empty($relato['tipo'])) {
            $tipos = [$relato['tipo']];
        }

        $relato['id'] = (int) $relato['id'];
        $relato['obra_id'] = (int) $relato['obra_id'];
        $relato['usuario_id'] = $relato['usuario_id'] !== null
            ? (int) $relato['usuario_id']
            : null;
        $relato['tipos'] = $tipos;
        unset($relato['tipo']);

        $relatos[] = $relato;
    }

    $stmt->close();
    $conn->close();
    $conn = null;

    responderRelato(200, [
        'sucesso' => true,
        'total' => count($relatos),
        'relatos' => $relatos
    ]);

} catch (Throwable $erro) {
    error_log(
        'Falha ao listar relatos administrativos: ' . $erro->getMessage()
    );

    if ($conn instanceof mysqli) {
        try {
            $conn->close();
        } catch (Throwable $erroFechamento) {
            error_log(
                'Falha ao fechar conexão da listagem de relatos: ' .
                $erroFechamento->getMessage()
            );
        }
    }

    responderRelato(500, [
        'sucesso' => false,
        'mensagem' => 'Não foi possível carregar os relatos.'
    ]);
}
