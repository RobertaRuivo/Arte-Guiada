<?php
declare(strict_types=1);

/*
 * Arte Guiada
 * API administrativa para atualizar o atendimento de um relato.
 * Toda alteração é registrada no histórico dentro da mesma transação.
 */

require_once __DIR__ . '/comum.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
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

/* Proteção contra requisições de outra origem. */
require_once __DIR__ . '/../../config/protecao-csrf.php';
exigirOrigemConfiavelCsrf();

$dados = receberJsonRelato();

$relatoId = filter_var(
    $dados['relato_id'] ?? null,
    FILTER_VALIDATE_INT
);

if ($relatoId === false || $relatoId === null || $relatoId < 1) {
    responderRelato(400, [
        'sucesso' => false,
        'mensagem' => 'Identificador do relato inválido.'
    ]);
}

$statusNovo = $dados['status'] ?? null;
$statusPermitidos = ['pendente', 'em_analise', 'resolvido'];

if (
    !is_string($statusNovo) ||
    !in_array($statusNovo, $statusPermitidos, true)
) {
    responderRelato(400, [
        'sucesso' => false,
        'mensagem' => 'O estado informado é inválido.'
    ]);
}

$observacaoRecebida = $dados['observacao'] ?? '';

if (!is_string($observacaoRecebida)) {
    responderRelato(400, [
        'sucesso' => false,
        'mensagem' => 'A observação informada é inválida.'
    ]);
}

$observacao = trim($observacaoRecebida);

if (mb_strlen($observacao, 'UTF-8') > 1000) {
    responderRelato(400, [
        'sucesso' => false,
        'mensagem' => 'A observação deve ter no máximo 1.000 caracteres.'
    ]);
}

if ($observacao === '') {
    $rotulosStatus = [
        'pendente' => 'Pendente',
        'em_analise' => 'Em análise',
        'resolvido' => 'Resolvido'
    ];

    $observacao = 'Status atualizado para ' . $rotulosStatus[$statusNovo] . '.';
}

$conn = null;
$transacaoIniciada = false;

try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    require __DIR__ . '/../../config/banco.php';

    if (!$conn instanceof mysqli) {
        throw new RuntimeException('A conexão com o banco não foi inicializada.');
    }

    $conn->set_charset('utf8mb4');
    $conn->begin_transaction();
    $transacaoIniciada = true;

    /* Bloqueia o registro durante a leitura e a atualização. */
    $stmtAtual = $conn->prepare(
        "SELECT status
         FROM relatos_problema
         WHERE id = ?
         LIMIT 1
         FOR UPDATE"
    );

    $stmtAtual->bind_param('i', $relatoId);
    $stmtAtual->execute();
    $stmtAtual->bind_result($statusAnterior);
    $relatoExiste = $stmtAtual->fetch();
    $stmtAtual->close();

    if (!$relatoExiste) {
        $conn->rollback();
        $transacaoIniciada = false;
        $conn->close();
        $conn = null;

        responderRelato(404, [
            'sucesso' => false,
            'mensagem' => 'O relato solicitado não foi encontrado.'
        ]);
    }

    if ($statusAnterior === $statusNovo) {
        $conn->commit();
        $transacaoIniciada = false;
        $conn->close();
        $conn = null;

        responderRelato(200, [
            'sucesso' => true,
            'mensagem' => 'O relato já está nesse estado.',
            'relato_id' => $relatoId,
            'status' => $statusNovo,
            'alterado' => false
        ]);
    }

    /*
     * Ao voltar para pendente, remove o responsável atual.
     * Em análise ou resolvido, registra o administrador responsável.
     */
    if ($statusNovo === 'pendente') {
        $stmtUpdate = $conn->prepare(
            "UPDATE relatos_problema
             SET status = ?, atendido_por = NULL
             WHERE id = ?"
        );

        $stmtUpdate->bind_param('si', $statusNovo, $relatoId);

    } else {
        $stmtUpdate = $conn->prepare(
            "UPDATE relatos_problema
             SET status = ?, atendido_por = ?
             WHERE id = ?"
        );

        $stmtUpdate->bind_param(
            'sii',
            $statusNovo,
            $usuarioId,
            $relatoId
        );
    }

    $stmtUpdate->execute();
    $stmtUpdate->close();

    /* Registra a transição de estado no histórico. */
    $stmtHistorico = $conn->prepare(
        "INSERT INTO relatos_problema_historico
            (
                relato_id,
                status_anterior,
                status_novo,
                observacao,
                responsavel_id
            )
         VALUES
            (?, ?, ?, ?, ?)"
    );

    $stmtHistorico->bind_param(
        'isssi',
        $relatoId,
        $statusAnterior,
        $statusNovo,
        $observacao,
        $usuarioId
    );

    $stmtHistorico->execute();
    $stmtHistorico->close();

    $conn->commit();
    $transacaoIniciada = false;

    $conn->close();
    $conn = null;

    error_log(
        'Relato atualizado pelo administrador. ID: ' . $relatoId .
        '; status anterior: ' . $statusAnterior .
        '; status novo: ' . $statusNovo .
        '; responsável: ' . $usuarioId
    );

    responderRelato(200, [
        'sucesso' => true,
        'mensagem' => 'O atendimento do relato foi atualizado.',
        'relato_id' => $relatoId,
        'status_anterior' => $statusAnterior,
        'status' => $statusNovo,
        'alterado' => true
    ]);

} catch (Throwable $erro) {
    if ($transacaoIniciada && $conn instanceof mysqli) {
        try {
            $conn->rollback();
        } catch (Throwable $erroRollback) {
            error_log(
                'Falha ao desfazer atualização de relato: ' .
                $erroRollback->getMessage()
            );
        }
    }

    error_log(
        'Falha ao atualizar relato ' . $relatoId .
        '; administrador: ' . $usuarioId .
        '; erro: ' . $erro->getMessage()
    );

    if ($conn instanceof mysqli) {
        try {
            $conn->close();
        } catch (Throwable $erroFechamento) {
            error_log(
                'Falha ao fechar conexão da atualização de relato: ' .
                $erroFechamento->getMessage()
            );
        }
    }

    responderRelato(500, [
        'sucesso' => false,
        'mensagem' => 'Não foi possível atualizar o relato. Nenhuma alteração foi confirmada.'
    ]);
}
