<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/painel-api.php';

$metodo = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if (!in_array($metodo, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    ag_responder_json(405, [
        'sucesso' => false,
        'mensagem' => 'Método não permitido.'
    ]);
}

$sessao = ag_exigir_perfil(['admin']);
if ($metodo === 'POST') {
    ag_exigir_csrf();
}

$conn = null;

try {
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    require __DIR__ . '/../../config/banco.php';
    if (!$conn instanceof mysqli) {
        throw new RuntimeException('Conexão indisponível.');
    }
    $conn->set_charset('utf8mb4');

    if ($metodo === 'GET') {
        $termoRecebido = $_GET['q'] ?? '';
        if (!is_string($termoRecebido)) {
            ag_responder_json(400, [
                'sucesso' => false,
                'mensagem' => 'O termo de busca é inválido.'
            ]);
        }
        $termo = trim($termoRecebido);
        if (mb_strlen($termo, 'UTF-8') > 100) {
            ag_responder_json(400, [
                'sucesso' => false,
                'mensagem' => 'A busca deve ter no máximo 100 caracteres.'
            ]);
        }

        $resultados = [];
        if ($termo !== '') {
            if (mb_strlen($termo, 'UTF-8') < 2) {
                ag_responder_json(400, [
                    'sucesso' => false,
                    'mensagem' => 'Digite ao menos dois caracteres para pesquisar.'
                ]);
            }

            $padrao = '%' . $termo . '%';
            $stmtBusca = $conn->prepare(
                "SELECT id, nome, sobrenome, email, perfil
                 FROM usuarios
                 WHERE LOWER(TRIM(COALESCE(perfil, ''))) NOT IN ('admin', 'administrador')
                   AND (
                       nome LIKE ? OR sobrenome LIKE ?
                       OR CONCAT_WS(' ', nome, sobrenome) LIKE ?
                       OR email LIKE ?
                   )
                 ORDER BY
                    CASE WHEN LOWER(TRIM(COALESCE(perfil, ''))) = 'editor' THEN 0 ELSE 1 END,
                    nome, sobrenome, id
                 LIMIT 30"
            );
            $stmtBusca->bind_param('ssss', $padrao, $padrao, $padrao, $padrao);
            $stmtBusca->execute();
            $resultadoBusca = $stmtBusca->get_result();
            while ($usuario = $resultadoBusca->fetch_assoc()) {
                $perfilBruto = strtolower(trim((string) ($usuario['perfil'] ?? '')));
                if (in_array($perfilBruto, ['editor', 'editora'], true)) {
                    $perfilExibicao = 'Editor';
                    $podePromover = false;
                } else {
                    $perfilExibicao = in_array($perfilBruto, ['visitante', 'visitor', 'usuario', 'user', ''], true)
                        ? 'Visitante'
                        : (trim((string) ($usuario['perfil'] ?? '')) ?: 'Sem perfil');
                    $podePromover = true;
                }

                $resultados[] = [
                    'id' => (int) $usuario['id'],
                    'nome' => trim((string) ($usuario['nome'] ?? '')),
                    'sobrenome' => trim((string) ($usuario['sobrenome'] ?? '')),
                    'nome_completo' => trim((string) ($usuario['nome'] ?? '') . ' ' . (string) ($usuario['sobrenome'] ?? '')),
                    'email' => (string) $usuario['email'],
                    'perfil' => $perfilExibicao,
                    'pode_promover' => $podePromover
                ];
            }
            $stmtBusca->close();
        }

        $stmtEditores = $conn->prepare(
            "SELECT
                u.id, u.nome, u.sobrenome, u.email, u.data_cadastro,
                (
                    SELECT COUNT(*)
                    FROM relatos_problema r
                    WHERE r.editor_responsavel_id = u.id
                      AND r.status NOT IN ('resolvido', 'improcedente')
                ) AS relatos_abertos
             FROM usuarios u
             WHERE LOWER(TRIM(COALESCE(u.perfil, ''))) IN ('editor', 'editora')
             ORDER BY u.nome, u.sobrenome, u.id
             LIMIT 200"
        );
        $stmtEditores->execute();
        $resultadoEditores = $stmtEditores->get_result();
        $editores = [];
        while ($editor = $resultadoEditores->fetch_assoc()) {
            $editores[] = [
                'id' => (int) $editor['id'],
                'nome_completo' => trim((string) $editor['nome'] . ' ' . (string) $editor['sobrenome']),
                'email' => (string) $editor['email'],
                'data_cadastro' => $editor['data_cadastro'],
                'relatos_abertos' => (int) $editor['relatos_abertos']
            ];
        }
        $stmtEditores->close();

        $conn->close();
        $conn = null;
        ag_responder_json(200, [
            'sucesso' => true,
            'resultados' => $resultados,
            'total_resultados' => count($resultados),
            'editores' => $editores,
            'total_editores' => count($editores)
        ]);
    }

    $dados = ag_receber_json();
    if (!is_array($dados)) {
        ag_responder_json(400, ['sucesso' => false, 'mensagem' => 'O conteúdo enviado é inválido.']);
    }
    $acao = $dados['acao'] ?? '';
    if (!is_string($acao) || !in_array($acao, ['promover', 'revogar'], true)) {
        ag_responder_json(400, [
            'sucesso' => false,
            'mensagem' => 'Ação inválida.'
        ]);
    }

    $usuarioId = filter_var(
        $dados['usuario_id'] ?? null,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1]]
    );
    if ($usuarioId === false || $usuarioId === null) {
        ag_responder_json(400, [
            'sucesso' => false,
            'mensagem' => 'Selecione uma conta válida.'
        ]);
    }

    $adminId = (int) $sessao['usuario_id'];
    $usuarioId = (int) $usuarioId;
    if ($usuarioId === $adminId) {
        ag_responder_json(409, [
            'sucesso' => false,
            'mensagem' => 'A conta administrativa não pode ser alterada por esta operação.'
        ]);
    }

    $conn->begin_transaction();
    $stmtUsuario = $conn->prepare(
        "SELECT id, nome, sobrenome, email, perfil
         FROM usuarios
         WHERE id = ?
         LIMIT 1
         FOR UPDATE"
    );
    $stmtUsuario->bind_param('i', $usuarioId);
    $stmtUsuario->execute();
    $usuario = $stmtUsuario->get_result()->fetch_assoc();
    $stmtUsuario->close();

    if (!$usuario) {
        $conn->rollback();
        ag_responder_json(404, [
            'sucesso' => false,
            'mensagem' => 'A conta selecionada não foi encontrada.'
        ]);
    }

    $perfilAnteriorLog = $usuario['perfil'] === null
        ? null
        : trim((string) $usuario['perfil']);
    $perfilAnterior = strtolower($perfilAnteriorLog ?? '');
    if (in_array($perfilAnterior, ['admin', 'administrador'], true)) {
        $conn->rollback();
        ag_responder_json(409, [
            'sucesso' => false,
            'mensagem' => 'Contas administrativas não podem ser alteradas por esta tela.'
        ]);
    }

    $ehEditor = in_array($perfilAnterior, ['editor', 'editora'], true);
    if ($acao === 'promover' && $ehEditor) {
        $conn->commit();
        $conn->close();
        $conn = null;
        ag_responder_json(200, [
            'sucesso' => true,
            'ja_editor' => true,
            'mensagem' => 'Esta conta já possui perfil de editor.'
        ]);
    }
    if ($acao === 'revogar' && !$ehEditor) {
        $conn->rollback();
        ag_responder_json(409, [
            'sucesso' => false,
            'mensagem' => 'Esta conta não possui perfil de editor.'
        ]);
    }

    if ($acao === 'revogar') {
        // Não deixa relatos ativos órfãos. O administrador deve encaminhá-los
        // para outro editor pela seção Obras com problemas antes da revogação.
        $stmtPendencias = $conn->prepare(
            "SELECT id
             FROM relatos_problema
             WHERE editor_responsavel_id = ?
               AND status NOT IN ('resolvido', 'improcedente')
             FOR UPDATE"
        );
        $stmtPendencias->bind_param('i', $usuarioId);
        $stmtPendencias->execute();
        $resultadoPendencias = $stmtPendencias->get_result();
        $quantidadePendencias = 0;
        while ($resultadoPendencias->fetch_assoc()) {
            $quantidadePendencias++;
        }
        $stmtPendencias->close();

        if ($quantidadePendencias > 0) {
            $conn->rollback();
            ag_responder_json(409, [
                'sucesso' => false,
                'mensagem' => 'Não é possível revogar este perfil ainda. Encaminhe ' . $quantidadePendencias . ' relato(s) aberto(s) para outro editor ou conclua a verificação antes.'
            ]);
        }
    }

    $perfilNovo = $acao === 'promover' ? 'Editor' : 'Visitante';
    if ($acao === 'revogar') {
        $stmtRevogarAcessos = $conn->prepare(
            "UPDATE obras_editores_acesso
             SET ativo = 0, principal = 0, atualizado_em = CURRENT_TIMESTAMP
             WHERE usuario_id = ? AND ativo = 1"
        );
        $stmtRevogarAcessos->bind_param('i', $usuarioId);
        $stmtRevogarAcessos->execute();
        $acessosRevogados = $stmtRevogarAcessos->affected_rows;
        $stmtRevogarAcessos->close();
    } else {
        $acessosRevogados = 0;
    }

    $stmtAtualizar = $conn->prepare(
        "UPDATE usuarios SET perfil = ? WHERE id = ?"
    );
    $stmtAtualizar->bind_param('si', $perfilNovo, $usuarioId);
    $stmtAtualizar->execute();
    $stmtAtualizar->close();

    $stmtLog = $conn->prepare(
        "INSERT INTO gerenciamento_editores_log
            (administrador_id, usuario_id, perfil_anterior, perfil_novo)
         VALUES (?, ?, ?, ?)"
    );
    $stmtLog->bind_param('iiss', $adminId, $usuarioId, $perfilAnteriorLog, $perfilNovo);
    $stmtLog->execute();
    $stmtLog->close();

    $conn->commit();
    $nomeCompleto = trim((string) $usuario['nome'] . ' ' . (string) $usuario['sobrenome']);
    $email = (string) $usuario['email'];
    $conn->close();
    $conn = null;

    error_log(
        '[Arte Guiada] Administrador ' . $adminId .
        ($acao === 'promover' ? ' promoveu usuario ' : ' revogou o perfil Editor do usuario ') .
        $usuarioId . '.'
    );

    if ($acao === 'promover') {
        ag_responder_json(200, [
            'sucesso' => true,
            'ja_editor' => false,
            'mensagem' => ($nomeCompleto !== '' ? $nomeCompleto : $email) . ' agora possui perfil de editor.',
            'usuario' => [
                'id' => $usuarioId,
                'nome_completo' => $nomeCompleto,
                'email' => $email,
                'perfil' => $perfilNovo
            ],
            'relogin_necessario' => true
        ]);
    }

    ag_responder_json(200, [
        'sucesso' => true,
        'mensagem' => 'O perfil Editor de ' . ($nomeCompleto !== '' ? $nomeCompleto : $email) . ' foi revogado. Os acessos delegados foram desativados (' . $acessosRevogados . '). A conta e as obras permanecem preservadas.',
        'relogin_necessario' => true
    ]);
} catch (Throwable $erro) {
    if ($conn instanceof mysqli) {
        try { $conn->rollback(); } catch (Throwable $ignorado) {}
        try { $conn->close(); } catch (Throwable $ignorado) {}
    }
    error_log('Falha em api/admin/gerenciar-editores.php: ' . $erro->getMessage());
    ag_responder_json(500, [
        'sucesso' => false,
        'mensagem' => 'Não foi possível concluir a operação de gerenciamento de editores.'
    ]);
}
