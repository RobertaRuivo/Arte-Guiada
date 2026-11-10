<?php
declare(strict_types=1);

/*
 * Arte Guiada
 * API pública para criação de relatos de problemas.
 *
 * Aceita usuários autenticados e anônimos.
 * Registra o relato, suas categorias, o evento inicial e as notificações
 * em uma única transação.
 */

require_once __DIR__ . '/../../config/log.php';
require_once __DIR__ . '/comum.php';
require_once __DIR__ . '/gestao-comum.php';

/* =========================================================
   1. VALIDAÇÃO DA REQUISIÇÃO
========================================================= */

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');

    responderRelato(405, [
        'sucesso' => false,
        'mensagem' => 'Método não permitido.'
    ]);
}

/* =========================================================
   2. PROTEÇÃO CONTRA CSRF
========================================================= */

require_once __DIR__ . '/../../config/protecao-csrf.php';

exigirOrigemConfiavelCsrf();

/* =========================================================
   3. RECEBIMENTO DOS DADOS
========================================================= */

$dados = receberJsonRelato();

/* Identificador da obra */

$obraId = filter_var(
    $dados['obra_id'] ?? null,
    FILTER_VALIDATE_INT
);

if ($obraId === false || $obraId === null || $obraId < 1) {
    responderRelato(400, [
        'sucesso' => false,
        'mensagem' => 'Identificador de obra inválido.'
    ]);
}

/* Categorias permitidas */

$tiposPermitidos = [
    'imagem',
    'informacoes',
    'texto',
    'etapa_zoom',
    'navegacao',
    'acessibilidade',
    'outro'
];

$tiposRecebidos = $dados['tipos'] ?? null;

if (
    !is_array($tiposRecebidos) ||
    !array_is_list($tiposRecebidos) ||
    count($tiposRecebidos) < 1 ||
    count($tiposRecebidos) > count($tiposPermitidos)
) {
    responderRelato(400, [
        'sucesso' => false,
        'mensagem' => 'Selecione pelo menos um tipo de problema.'
    ]);
}

/* Validação e normalização das categorias */

$tipos = [];

foreach ($tiposRecebidos as $tipo) {
    if (
        !is_string($tipo) ||
        !in_array($tipo, $tiposPermitidos, true)
    ) {
        responderRelato(400, [
            'sucesso' => false,
            'mensagem' => 'Uma das categorias selecionadas é inválida.'
        ]);
    }

    if (!in_array($tipo, $tipos, true)) {
        $tipos[] = $tipo;
    }
}

/* Descrição */

$descricaoRecebida = $dados['descricao'] ?? '';

if (!is_string($descricaoRecebida)) {
    responderRelato(400, [
        'sucesso' => false,
        'mensagem' => 'A descrição enviada é inválida.'
    ]);
}

$descricao = trim($descricaoRecebida);

$tamanhoDescricao = mb_strlen($descricao, 'UTF-8');

if ($tamanhoDescricao > 2000) {
    responderRelato(400, [
        'sucesso' => false,
        'mensagem' => 'A descrição deve ter no máximo 2.000 caracteres.'
    ]);
}

if (
    in_array('outro', $tipos, true) &&
    $descricao === ''
) {
    responderRelato(400, [
        'sucesso' => false,
        'mensagem' => 'Explique o problema selecionado em "Outro".'
    ]);
}

/* =========================================================
   4. LIMITAÇÃO DE ENVIOS REPETIDOS
========================================================= */

/*
 * Este controle evita envios repetidos na mesma sessão.
 * Não substitui uma proteção persistente contra spam.
 */

$ultimoEnvio = (int) (
    $_SESSION['ultimo_relato_enviado'] ?? 0
);

if (
    $ultimoEnvio > 0 &&
    (time() - $ultimoEnvio) < 20
) {
    responderRelato(429, [
        'sucesso' => false,
        'mensagem' => 'Aguarde alguns segundos antes de enviar outro relato.'
    ]);
}

/* =========================================================
   5. IDENTIFICAÇÃO DO USUÁRIO
========================================================= */

$usuarioId = usuarioRelatoAtual();

$conn = null;
$transacaoIniciada = false;

/* =========================================================
   6. GRAVAÇÃO NO BANCO DE DADOS
========================================================= */

try {

    /*
     * Ativa o tratamento de erros do MySQLi.
     */
    mysqli_report(
        MYSQLI_REPORT_ERROR |
        MYSQLI_REPORT_STRICT
    );

    /*
     * Utiliza a conexão compartilhada do projeto.
     */
    require __DIR__ . '/../../config/banco.php';

    if (!$conn instanceof mysqli) {
        throw new RuntimeException(
            'A conexão com o banco de dados não foi inicializada.'
        );
    }

    $conn->set_charset('utf8mb4');

    /*
     * Inicia a transação.
     */
    $conn->begin_transaction();
    $transacaoIniciada = true;

    /* -----------------------------------------------------
       6.1. VERIFICAR A OBRA
    ----------------------------------------------------- */

    /*
     * Somente obras publicadas e não excluídas
     * podem receber relatos.
     */
    $stmtObra = $conn->prepare(
        "SELECT
            o.id,
            o.criada_por,
            LOWER(TRIM(COALESCE(criador.perfil, ''))) AS criador_perfil,
            (
                SELECT a.usuario_id
                FROM obras_editores_acesso a
                INNER JOIN usuarios editor_delegado ON editor_delegado.id = a.usuario_id
                WHERE a.obra_id = o.id
                  AND a.ativo = 1
                  AND a.principal = 1
                  AND LOWER(TRIM(editor_delegado.perfil)) = 'editor'
                ORDER BY a.atualizado_em DESC, a.usuario_id ASC
                LIMIT 1
            ) AS editor_principal_id
         FROM obras o
         LEFT JOIN usuarios criador ON criador.id = o.criada_por
         WHERE o.id = ?
           AND o.status = 'publicada'
           AND o.excluida_em IS NULL
         LIMIT 1"
    );

    $stmtObra->bind_param('i', $obraId);
    $stmtObra->execute();
    $obraEncontrada = $stmtObra->get_result()->fetch_assoc();
    $stmtObra->close();

    if (!$obraEncontrada) {
        $conn->rollback();
        $transacaoIniciada = false;

        $conn->close();
        $conn = null;

        responderRelato(404, [
            'sucesso' => false,
            'mensagem' => 'A obra não está disponível para receber relatos.'
        ]);
    }

    /* -----------------------------------------------------
       6.2. DEFINIR A RESPONSABILIDADE INICIAL
    ----------------------------------------------------- */

    /*
     * Um acesso principal delegado ativo tem prioridade. Na ausência dele,
     * o autor original recebe o relato se ainda possuir perfil Editor.
     * O administrador pode transferir a responsabilidade posteriormente.
     */
    $editorResponsavelId = null;
    if (!empty($obraEncontrada['editor_principal_id'])) {
        $editorResponsavelId = (int) $obraEncontrada['editor_principal_id'];
    } elseif (strtolower(trim((string) ($obraEncontrada['criador_perfil'] ?? ''))) === 'editor') {
        $editorResponsavelId = (int) $obraEncontrada['criada_por'];
    }

    $statusInicial = $editorResponsavelId !== null ? 'atribuido' : 'pendente';
    $tipoPrincipal = $tipos[0];

    /* -----------------------------------------------------
       6.3. INSERIR O RELATO PRINCIPAL
    ----------------------------------------------------- */

    if ($usuarioId === null) {
        $stmtRelato = $conn->prepare(
            "INSERT INTO relatos_problema
                (usuario_id, obra_id, tipo, descricao, status, prioridade, editor_responsavel_id)
             VALUES
                (NULL, ?, ?, ?, ?, 'media', ?)"
        );

        $stmtRelato->bind_param(
            'isssi',
            $obraId,
            $tipoPrincipal,
            $descricao,
            $statusInicial,
            $editorResponsavelId
        );
    } else {
        $stmtRelato = $conn->prepare(
            "INSERT INTO relatos_problema
                (usuario_id, obra_id, tipo, descricao, status, prioridade, editor_responsavel_id)
             VALUES
                (?, ?, ?, ?, ?, 'media', ?)"
        );

        $stmtRelato->bind_param(
            'iisssi',
            $usuarioId,
            $obraId,
            $tipoPrincipal,
            $descricao,
            $statusInicial,
            $editorResponsavelId
        );
    }

    $stmtRelato->execute();

    /*
     * Recupera o ID gerado pelo MySQL.
     */
    $relatoId = (int) $conn->insert_id;

    $stmtRelato->close();

    if ($relatoId < 1) {
        throw new RuntimeException(
            'O banco não retornou o identificador do relato.'
        );
    }

    /* -----------------------------------------------------
       6.3. INSERIR AS CATEGORIAS
    ----------------------------------------------------- */

    $stmtTipo = $conn->prepare(
        "INSERT INTO relatos_problema_tipos
            (relato_id, tipo)
         VALUES
            (?, ?)"
    );

    foreach ($tipos as $tipo) {
        $stmtTipo->bind_param(
            'is',
            $relatoId,
            $tipo
        );

        $stmtTipo->execute();
    }

    $stmtTipo->close();

    /* -----------------------------------------------------
       6.4. REGISTRAR O EVENTO INICIAL E NOTIFICAR
    ----------------------------------------------------- */

    $observacaoInicial = 'Relato recebido pelo sistema.';
    ag_relato_registrar_evento(
        $conn,
        $relatoId,
        $usuarioId,
        'relato_criado',
        null,
        $statusInicial,
        null,
        'media',
        null,
        $editorResponsavelId,
        $observacaoInicial
    );

    ag_relato_notificar_admins(
        $conn,
        $relatoId,
        'novo_relato',
        'Novo relato de problema recebido',
        'Um novo relato foi registrado para a obra #' . $obraId . '.'
    );

    if ($editorResponsavelId !== null) {
        ag_relato_notificar_editor(
            $conn,
            $editorResponsavelId,
            $obraId,
            'novo_relato_obra',
            'Um problema foi relatado em uma obra sob sua responsabilidade',
            'Um novo relato foi recebido para a obra #' . $obraId . '. Consulte suas pendências no painel do editor.'
        );
    }

    /* -----------------------------------------------------
       6.5. CONFIRMAR A TRANSAÇÃO
    ----------------------------------------------------- */

    /*
     * O commit só acontece depois de todas as gravações.
     */
    $conn->commit();
    $transacaoIniciada = false;

    /*
     * Atualiza o controle de frequência somente
     * depois da gravação confirmada.
     */
    $_SESSION['ultimo_relato_enviado'] = time();

    $conn->close();
    $conn = null;

    /*
     * Registra a criação com timestamp.
     */
    registrarLog('INFO', 'relato_criado', [
        'relato_id' => $relatoId,
        'obra_id' => $obraId,
        'usuario_id' => $usuarioId
    ]);

    /*
     * Resposta de sucesso enviada ao JavaScript.
     */
    responderRelato(201, [
        'sucesso' => true,
        'mensagem' => 'Seu relato foi registrado com sucesso. Obrigado por ajudar a melhorar o Arte Guiada.',
        'relato_id' => $relatoId
    ]);

} catch (Throwable $erro) {

    /* -----------------------------------------------------
       7. TRATAMENTO DE ERROS
    ----------------------------------------------------- */

    /*
     * Se qualquer gravação falhar, desfaz todas as anteriores.
     */
    if (
        $transacaoIniciada &&
        $conn instanceof mysqli
    ) {
        try {
            $conn->rollback();
        } catch (Throwable $erroRollback) {
            error_log(
                sprintf(
                    '[Arte Guiada][relatos][%s] Erro ao desfazer transação: %s',
                    date(DATE_ATOM),
                    str_replace(
                        ["\r", "\n"],
                        ' ',
                        $erroRollback->getMessage()
                    )
                )
            );
        }
    }

    /*
     * Registra o erro técnico no log do PHP.
     * Quebras de linha são removidas para evitar
     * a inserção de entradas falsas no log.
     */
    registrarLog('ERROR', 'relato_criacao_falhou', [
        'obra_id' => $obraId,
        'usuario_id' => $usuarioId,
        'tipo' => get_class($erro),
        'codigo' => (int) $erro->getCode()
    ]);

    /*
     * Fecha a conexão, caso tenha sido estabelecida.
     */
    if ($conn instanceof mysqli) {
        try {
            $conn->close();
        } catch (Throwable $erroFechamento) {
            error_log(
                sprintf(
                    '[Arte Guiada][relatos][%s] Erro ao fechar conexão: %s',
                    date(DATE_ATOM),
                    str_replace(
                        ["\r", "\n"],
                        ' ',
                        $erroFechamento->getMessage()
                    )
                )
            );
        }
    }

    /*
     * Retorna uma mensagem clara ao visitante.
     * Nenhum sucesso é comunicado quando a gravação falha.
     */
    responderRelato(500, [
        'sucesso' => false,
        'mensagem' => 'Não foi possível registrar seu relato. Nenhum envio foi confirmado. Tente novamente.'
    ]);
}
