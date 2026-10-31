<?php
declare(strict_types=1);

/*
 * Arte Guiada
 * API pública para criação de relatos de problemas.
 *
 * Aceita usuários autenticados e anônimos.
 * Registra o relato, suas categorias e o histórico
 * em uma única transação.
 */

require_once __DIR__ . '/comum.php';

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
     * Assim, erros de conexão e consultas SQL geram exceções.
     */
    mysqli_report(
        MYSQLI_REPORT_ERROR |
        MYSQLI_REPORT_STRICT
    );

    /*
     * Utiliza a conexão compartilhada do projeto.
     * Não é necessário modificar config/banco.php.
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
     * Nenhuma parte do relato será confirmada isoladamente.
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
        "SELECT id
         FROM obras
         WHERE id = ?
           AND status = 'publicada'
           AND excluida_em IS NULL
         LIMIT 1"
    );

    $stmtObra->bind_param('i', $obraId);
    $stmtObra->execute();

    $stmtObra->bind_result($obraEncontrada);

    $obraExiste = $stmtObra->fetch();

    $stmtObra->close();

    if (!$obraExiste) {
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
       6.2. INSERIR O RELATO PRINCIPAL
    ----------------------------------------------------- */

    /*
     * A coluna tipo da tabela antiga é preservada.
     * A primeira categoria selecionada é utilizada como tipo principal.
     */
    $tipoPrincipal = $tipos[0];

    if ($usuarioId === null) {

        /*
         * Relato anônimo.
         * usuario_id recebe NULL explicitamente.
         */
        $stmtRelato = $conn->prepare(
            "INSERT INTO relatos_problema
                (usuario_id, obra_id, tipo, descricao, status)
             VALUES
                (NULL, ?, ?, ?, 'pendente')"
        );

        $stmtRelato->bind_param(
            'iss',
            $obraId,
            $tipoPrincipal,
            $descricao
        );

    } else {

        /*
         * Relato associado ao usuário autenticado.
         */
        $stmtRelato = $conn->prepare(
            "INSERT INTO relatos_problema
                (usuario_id, obra_id, tipo, descricao, status)
             VALUES
                (?, ?, ?, ?, 'pendente')"
        );

        $stmtRelato->bind_param(
            'iiss',
            $usuarioId,
            $obraId,
            $tipoPrincipal,
            $descricao
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
       6.4. REGISTRAR O HISTÓRICO INICIAL
    ----------------------------------------------------- */

    $observacaoInicial = 'Relato recebido pelo sistema.';

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
            (?, NULL, 'pendente', ?, NULL)"
    );

    $stmtHistorico->bind_param(
        'is',
        $relatoId,
        $observacaoInicial
    );

    $stmtHistorico->execute();
    $stmtHistorico->close();

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

    error_log(
        'Relato registrado com sucesso. ' .
        'ID: ' . $relatoId .
        '; obra: ' . $obraId .
        '; usuário: ' .
        ($usuarioId === null ? 'anônimo' : $usuarioId)
    );

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
                'Erro ao desfazer transação do relato: ' .
                $erroRollback->getMessage()
            );
        }
    }

    /*
     * Registra o erro técnico no log do PHP.
     */
    error_log(
        'Falha ao registrar relato. ' .
        'Obra: ' . $obraId .
        '; erro: ' . $erro->getMessage()
    );

    /*
     * Fecha a conexão, caso tenha sido estabelecida.
     */
    if ($conn instanceof mysqli) {
        try {
            $conn->close();
        } catch (Throwable $erroFechamento) {
            error_log(
                'Erro ao fechar conexão do relato: ' .
                $erroFechamento->getMessage()
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
