<?php

declare(strict_types=1);

header("Content-Type: application/json; charset=UTF-8");
header("Cache-Control: no-store");
header("Pragma: no-cache");

const TAMANHO_MAXIMO_IMAGEM = 7 * 1024 * 1024;

const TIPOS_IMAGEM_PERMITIDOS = [
    "image/jpeg",
    "image/png",
    "image/webp"
];

/**
 * Retorna JSON e encerra a execução.
 */
function responderJson(int $status, array $dados): void
{
    http_response_code($status);

    echo json_encode(
        $dados,
        JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
    );

    exit;
}

/**
 * Normaliza um campo de texto recebido.
 */
function obterTexto($valor, string $campo): string
{
    if ($valor === null) {
        return "";
    }

    if (!is_scalar($valor)) {
        throw new InvalidArgumentException(
            "O campo {$campo} possui um valor inválido."
        );
    }

    return trim((string) $valor);
}

/**
 * Calcula o tamanho do texto em caracteres.
 */
function tamanhoTexto(string $texto): int
{
    return function_exists("mb_strlen")
        ? mb_strlen($texto, "UTF-8")
        : strlen($texto);
}

/**
 * Valida uma coordenada da região de zoom.
 */
function lerCoordenadaZoom($valor, string $campo): ?float
{
    if ($valor === null) {
        return null;
    }

    if (!is_scalar($valor)) {
        throw new InvalidArgumentException(
            "O campo {$campo} contém uma coordenada inválida."
        );
    }

    $texto = trim((string) $valor);

    if ($texto === "") {
        return null;
    }

    if (!is_numeric($texto)) {
        throw new InvalidArgumentException(
            "O campo {$campo} contém uma coordenada inválida."
        );
    }

    $numero = (float) $texto;

    if (
        !is_finite($numero) ||
        $numero < 0 ||
        $numero > 1
    ) {
        throw new InvalidArgumentException(
            "O campo {$campo} deve estar entre 0 e 1."
        );
    }

    return $numero;
}

/**
 * Formata coordenadas para armazenamento decimal.
 */
function formatarCoordenada(?float $valor): ?string
{
    return $valor === null
        ? null
        : number_format($valor, 6, ".", "");
}

/**
 * Encerra uma transação e fecha a conexão antes
 * de devolver uma resposta de erro.
 */
function encerrarTransacaoParaResposta(
    mysqli $conn,
    int $status,
    array $dados
): void {
    try {
        $conn->rollback();
    } catch (Throwable $erro) {
        error_log(
            "Erro ao reverter transação da obra: " .
            $erro->getMessage()
        );
    }

    $conn->close();

    responderJson($status, $dados);
}

/*
 * 1. Validar método HTTP e origem.
 */

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Allow: POST");

    responderJson(405, [
        "sucesso" => false,
        "mensagem" => "Método não permitido."
    ]);
}

require_once __DIR__ . "/../../config/log.php";
require_once __DIR__ . "/../../config/protecao-csrf.php";

exigirOrigemConfiavelCsrf();

/*
 * 2. Verificar o tamanho da requisição.
 *
 * Quando post_max_size é ultrapassado, o PHP pode
 * deixar $_POST e $_FILES vazios.
 */

$tamanhoRequisicao = (int) ($_SERVER["CONTENT_LENGTH"] ?? 0);

if (
    $tamanhoRequisicao > 0 &&
    empty($_POST) &&
    empty($_FILES)
) {
    responderJson(413, [
        "sucesso" => false,
        "mensagem" =>
            "A requisição está vazia ou ultrapassou o limite de upload do servidor."
    ]);
}

/*
 * 3. Configurar e iniciar a sessão.
 *
 * Apache:
 * /Arte-Guiada/api/obras/salvar.php
 * Cookie: /Arte-Guiada/
 *
 * Servidor de testes:
 * /api/obras/salvar.php
 * Cookie: /
 */

$scriptName = str_replace(
    "\\",
    "/",
    (string) ($_SERVER["SCRIPT_NAME"] ?? "/")
);

$posicaoApi = strpos($scriptName, "/api/");

$caminhoCookie = $posicaoApi === false
    ? "/"
    : substr($scriptName, 0, $posicaoApi + 1);

$https = (
    !empty($_SERVER["HTTPS"]) &&
    strtolower((string) $_SERVER["HTTPS"]) !== "off"
) || (
    (int) ($_SERVER["SERVER_PORT"] ?? 0) === 443
);

/*
 * Não cria uma sessão vazia quando não existe
 * cookie de autenticação.
 */

$nomeCookie = session_name();

if (
    !isset($_COOKIE[$nomeCookie]) ||
    !is_string($_COOKIE[$nomeCookie]) ||
    $_COOKIE[$nomeCookie] === ""
) {
    responderJson(401, [
        "sucesso" => false,
        "mensagem" => "Sua sessão expirou. Entre novamente."
    ]);
}

try {
    ini_set("session.use_strict_mode", "1");
    ini_set("session.use_only_cookies", "1");
    ini_set("session.use_trans_sid", "0");

    session_cache_limiter("");

    session_set_cookie_params([
        "lifetime" => 0,
        "path" => $caminhoCookie,
        "secure" => $https,
        "httponly" => true,
        "samesite" => "Lax"
    ]);

    if (!session_start()) {
        throw new RuntimeException(
            "Não foi possível iniciar a sessão."
        );
    }

} catch (Throwable $erro) {
    error_log(
        "[" . date("Y-m-d H:i:s") . "] " .
        "Erro ao iniciar sessão para salvar obra: " .
        $erro->getMessage()
    );

    responderJson(500, [
        "sucesso" => false,
        "mensagem" => "Não foi possível verificar sua sessão."
    ]);
}

/*
 * 4. Validar o usuário e o perfil.
 */

$usuarioId = filter_var(
    $_SESSION["usuario_id"] ?? null,
    FILTER_VALIDATE_INT,
    ["options" => ["min_range" => 1]]
);

$perfil = strtolower(
    trim((string) ($_SESSION["perfil"] ?? ""))
);

if ($perfil === "administrador") {
    $perfil = "admin";
}

if ($usuarioId === false || $usuarioId === null) {
    session_write_close();

    responderJson(401, [
        "sucesso" => false,
        "mensagem" => "Sua sessão expirou. Entre novamente."
    ]);
}

if (!in_array($perfil, ["editor", "admin"], true)) {
    session_write_close();

    responderJson(403, [
        "sucesso" => false,
        "mensagem" => "Você não tem permissão para salvar obras."
    ]);
}

$usuarioId = (int) $usuarioId;

/*
 * Os dados da sessão já foram obtidos.
 * Liberamos o bloqueio antes das operações no banco.
 */

session_write_close();

/*
 * 5. Preparar e validar os dados do formulário.
 */

try {
    $status = obterTexto(
        $_POST["status"] ?? "",
        "status"
    );

    if (!in_array($status, ["rascunho", "publicada"], true)) {
        throw new InvalidArgumentException(
            "Informe um status válido para a obra."
        );
    }

    $obraIdTexto = obterTexto(
        $_POST["obra_id"] ?? "",
        "obra_id"
    );

    $obraId = 0;

    if ($obraIdTexto !== "") {
        $obraIdValidado = filter_var(
            $obraIdTexto,
            FILTER_VALIDATE_INT,
            ["options" => ["min_range" => 1]]
        );

        if ($obraIdValidado === false) {
            throw new InvalidArgumentException(
                "O identificador da obra é inválido."
            );
        }

        $obraId = (int) $obraIdValidado;
    }

    $titulo = obterTexto(
        $_POST["titulo"] ?? "",
        "titulo"
    );

    $autor = obterTexto(
        $_POST["autor"] ?? "",
        "autor"
    );

    $textoAlternativo = obterTexto(
        $_POST["texto_alternativo"] ?? "",
        "texto_alternativo"
    );

    if (tamanhoTexto($titulo) > 200) {
        throw new InvalidArgumentException(
            "O título deve ter no máximo 200 caracteres."
        );
    }

    if (tamanhoTexto($autor) > 200) {
        throw new InvalidArgumentException(
            "O nome do autor deve ter no máximo 200 caracteres."
        );
    }

    if (tamanhoTexto($textoAlternativo) > 255) {
        throw new InvalidArgumentException(
            "A descrição da imagem deve ter no máximo 255 caracteres."
        );
    }

    /*
     * 6. Validar e ler a imagem.
     */

    $imagemBinaria = null;
    $imagemMime = null;

    if (isset($_FILES["imagem"])) {
        $arquivoImagem = $_FILES["imagem"];

        if (!is_array($arquivoImagem)) {
            throw new InvalidArgumentException(
                "Os dados da imagem enviada são inválidos."
            );
        }

        $erroUploadBruto = $arquivoImagem["error"] ?? UPLOAD_ERR_NO_FILE;

        if (!is_scalar($erroUploadBruto)) {
            throw new InvalidArgumentException(
                "Os dados do upload são inválidos."
            );
        }

        $erroUploadValidado = filter_var(
            $erroUploadBruto,
            FILTER_VALIDATE_INT
        );

        if ($erroUploadValidado === false) {
            throw new InvalidArgumentException(
                "O código de erro do upload é inválido."
            );
        }

        $erroUpload = (int) $erroUploadValidado;

        if ($erroUpload === UPLOAD_ERR_OK) {
            $caminhoTemporario = $arquivoImagem["tmp_name"] ?? "";

            if (
                !is_string($caminhoTemporario) ||
                $caminhoTemporario === "" ||
                !is_uploaded_file($caminhoTemporario)
            ) {
                throw new InvalidArgumentException(
                    "O arquivo enviado não é um upload válido."
                );
            }

            $tamanhoReal = filesize($caminhoTemporario);

            if (
                $tamanhoReal === false ||
                $tamanhoReal <= 0 ||
                $tamanhoReal > TAMANHO_MAXIMO_IMAGEM
            ) {
                throw new InvalidArgumentException(
                    "A imagem deve ter tamanho maior que zero e máximo de 7 MB."
                );
            }

            $finfo = new finfo(FILEINFO_MIME_TYPE);

            $mimeDetectado = $finfo->file(
                $caminhoTemporario
            );

            if (
                !is_string($mimeDetectado) ||
                !in_array(
                    $mimeDetectado,
                    TIPOS_IMAGEM_PERMITIDOS,
                    true
                )
            ) {
                throw new InvalidArgumentException(
                    "Escolha uma imagem JPEG, PNG ou WebP."
                );
            }

            $informacoesImagem = @getimagesize(
                $caminhoTemporario
            );

            if (
                $informacoesImagem === false ||
                ($informacoesImagem["mime"] ?? "") !== $mimeDetectado
            ) {
                throw new InvalidArgumentException(
                    "O arquivo enviado não contém uma imagem válida."
                );
            }

            $imagemBinaria = file_get_contents(
                $caminhoTemporario
            );

            if ($imagemBinaria === false) {
                throw new RuntimeException(
                    "Não foi possível ler a imagem enviada."
                );
            }

            $imagemMime = $mimeDetectado;

        } elseif ($erroUpload !== UPLOAD_ERR_NO_FILE) {
            $mensagemUpload = match ($erroUpload) {
                UPLOAD_ERR_INI_SIZE,
                UPLOAD_ERR_FORM_SIZE =>
                    "A imagem ultrapassou o limite de upload do servidor.",

                UPLOAD_ERR_PARTIAL =>
                    "O envio da imagem foi interrompido.",

                UPLOAD_ERR_NO_TMP_DIR =>
                    "A pasta temporária de uploads não está disponível.",

                UPLOAD_ERR_CANT_WRITE =>
                    "O servidor não conseguiu gravar o arquivo temporário.",

                UPLOAD_ERR_EXTENSION =>
                    "Uma configuração do servidor interrompeu o upload.",

                default =>
                    "Não foi possível receber a imagem enviada."
            };

            throw new InvalidArgumentException($mensagemUpload);
        }
    }

    /*
     * 7. Validar e preparar as etapas.
     *
     * Etapas completamente vazias não são armazenadas.
     * Rascunhos podem conter etapas parcialmente preenchidas.
     * Obras publicadas precisam de pelo menos uma etapa completa.
     */

    $etapasRecebidas = $_POST["etapas"] ?? [];

    if (!is_array($etapasRecebidas)) {
        throw new InvalidArgumentException(
            "Os dados das etapas são inválidos."
        );
    }

    $etapasPreparadas = [];
    $errosPublicacao = [];
    $indiceEtapa = 0;

    foreach ($etapasRecebidas as $etapaRecebida) {
        $indiceEtapa++;

        if (!is_array($etapaRecebida)) {
            throw new InvalidArgumentException(
                "Os dados da etapa {$indiceEtapa} são inválidos."
            );
        }

        $tituloEtapa = obterTexto(
            $etapaRecebida["titulo"] ?? "",
            "título da etapa"
        );

        $textoEtapa = obterTexto(
            $etapaRecebida["texto"] ?? "",
            "texto da etapa"
        );

        if (tamanhoTexto($tituloEtapa) > 150) {
            throw new InvalidArgumentException(
                "O título da etapa {$indiceEtapa} deve ter no máximo 150 caracteres."
            );
        }

        $zoomX = lerCoordenadaZoom(
            $etapaRecebida["zoom_x"] ?? null,
            "zoom_x"
        );

        $zoomY = lerCoordenadaZoom(
            $etapaRecebida["zoom_y"] ?? null,
            "zoom_y"
        );

        $zoomLargura = lerCoordenadaZoom(
            $etapaRecebida["zoom_largura"] ?? null,
            "zoom_largura"
        );

        $zoomAltura = lerCoordenadaZoom(
            $etapaRecebida["zoom_altura"] ?? null,
            "zoom_altura"
        );

        $valoresZoom = [
            $zoomX,
            $zoomY,
            $zoomLargura,
            $zoomAltura
        ];

        $quantidadeZoom = count(
            array_filter(
                $valoresZoom,
                static fn($valor) => $valor !== null
            )
        );

        if ($quantidadeZoom !== 0 && $quantidadeZoom !== 4) {
            throw new InvalidArgumentException(
                "A região de zoom da etapa {$indiceEtapa} está incompleta."
            );
        }

        $temZoom = $quantidadeZoom === 4;

        if ($temZoom) {
            if (
                $zoomLargura <= 0 ||
                $zoomAltura <= 0 ||
                $zoomX + $zoomLargura > 1.000001 ||
                $zoomY + $zoomAltura > 1.000001
            ) {
                throw new InvalidArgumentException(
                    "A região de zoom da etapa {$indiceEtapa} é inválida."
                );
            }
        }

        $temConteudo =
            $tituloEtapa !== "" ||
            $textoEtapa !== "" ||
            $temZoom;

        if (!$temConteudo) {
            continue;
        }

        if ($status === "publicada") {
            if ($tituloEtapa === "") {
                $errosPublicacao[] =
                    "Informe o título da etapa {$indiceEtapa}.";
            }

            if ($textoEtapa === "") {
                $errosPublicacao[] =
                    "Preencha o texto da etapa {$indiceEtapa}.";
            }

            if (!$temZoom) {
                $errosPublicacao[] =
                    "Selecione a região de zoom da etapa {$indiceEtapa}.";
            }
        }

        $etapasPreparadas[] = [
            "titulo" => $tituloEtapa !== ""
                ? $tituloEtapa
                : null,

            "texto" => $textoEtapa !== ""
                ? $textoEtapa
                : null,

            "zoom_x" => formatarCoordenada($zoomX),
            "zoom_y" => formatarCoordenada($zoomY),

            "zoom_largura" => formatarCoordenada(
                $zoomLargura
            ),

            "zoom_altura" => formatarCoordenada(
                $zoomAltura
            ),

            "completa" =>
                $tituloEtapa !== "" &&
                $textoEtapa !== "" &&
                $temZoom
        ];
    }

    /*
     * 8. Validar os requisitos de publicação.
     */

    if ($status === "publicada") {
        if ($titulo === "") {
            $errosPublicacao[] = "Informe o título da obra.";
        }

        if ($autor === "") {
            $errosPublicacao[] = "Informe o autor da obra.";
        }

        if ($textoAlternativo === "") {
            $errosPublicacao[] =
                "Preencha a descrição da imagem para acessibilidade.";
        }

        $totalEtapasCompletas = count(
            array_filter(
                $etapasPreparadas,
                static fn($etapa) => $etapa["completa"]
            )
        );

        if ($totalEtapasCompletas === 0) {
            $errosPublicacao[] =
                "Adicione pelo menos uma etapa completa, com texto e região de zoom.";
        }

        if ($errosPublicacao !== []) {
            responderJson(422, [
                "sucesso" => false,
                "mensagem" => implode(" ", $errosPublicacao)
            ]);
        }
    }

} catch (InvalidArgumentException $erro) {
    responderJson(422, [
        "sucesso" => false,
        "mensagem" => $erro->getMessage()
    ]);
}

/*
 * 9. Salvar a obra e suas etapas no banco.
 */

$conn = null;
$stmt = null;
$stmtAtual = null;
$stmtEtapa = null;
$transacaoAberta = false;

try {
    mysqli_report(
        MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT
    );

    require_once __DIR__ . "/../../config/banco.php";

    if (!isset($conn) || !($conn instanceof mysqli)) {
        throw new RuntimeException(
            "A conexão com o banco de dados não está disponível."
        );
    }

    $conn->set_charset("utf8mb4");

    /*
     * Inicia a transação antes de consultar a obra.
     */

    $conn->begin_transaction();

    $transacaoAberta = true;

    $obraAtual = null;
    $imagemAnteriorSalva = false;

    /*
     * 10. Se for edição, bloqueia a linha durante
     * a verificação de propriedade e atualização.
     */

    if ($obraId > 0) {
        $sqlAtual = "
            SELECT
                id,
                criada_por,
                status,
                publicada_em,
                (
                    imagem IS NOT NULL
                    AND OCTET_LENGTH(imagem) > 0
                ) AS imagem_salva
            FROM obras
            WHERE id = ?
              AND excluida_em IS NULL
            LIMIT 1
            FOR UPDATE
        ";

        $stmtAtual = $conn->prepare($sqlAtual);

        $stmtAtual->bind_param("i", $obraId);
        $stmtAtual->execute();

        $resultadoAtual = $stmtAtual->get_result();

        $obraAtual = $resultadoAtual->fetch_assoc();

        $resultadoAtual->free();

        $stmtAtual->close();
        $stmtAtual = null;

        if (!$obraAtual) {
            encerrarTransacaoParaResposta(
                $conn,
                404,
                [
                    "sucesso" => false,
                    "mensagem" => "A obra não foi encontrada."
                ]
            );
        }

                $stmtAcessoEditor = $conn->prepare(
            "SELECT ativo FROM obras_editores_acesso
             WHERE obra_id = ? AND usuario_id = ?
             LIMIT 1"
        );
        $stmtAcessoEditor->bind_param("ii", $obraId, $usuarioId);
        $stmtAcessoEditor->execute();
        $registroAcessoEditor = $stmtAcessoEditor->get_result()->fetch_assoc();
        $stmtAcessoEditor->close();

        $editorTemRegistroAcesso = $registroAcessoEditor !== null;
        $editorTemAcessoDelegado = $editorTemRegistroAcesso &&
            (int) $registroAcessoEditor["ativo"] === 1;
        $editorEhAutorOriginal =
            (int) $obraAtual["criada_por"] === $usuarioId;
        $editorPodeEditarObra = $editorTemAcessoDelegado ||
            ($editorEhAutorOriginal && !$editorTemRegistroAcesso);

        if ($perfil !== "admin" && !$editorPodeEditarObra) {
            encerrarTransacaoParaResposta(
                $conn,
                403,
                [
                    "sucesso" => false,
                    "mensagem" =>
                        "Você só pode editar obras que pertencem à sua conta."
                ]
            );
        }

        $imagemAnteriorSalva = (bool) $obraAtual["imagem_salva"];
    }

    /*
     * 11. Verifica se existe imagem disponível.
     */

    $imagemDisponivel =
        $imagemBinaria !== null ||
        $imagemAnteriorSalva;

    if ($status === "publicada" && !$imagemDisponivel) {
        encerrarTransacaoParaResposta(
            $conn,
            422,
            [
                "sucesso" => false,
                "mensagem" =>
                    "Selecione a imagem principal antes de publicar."
            ]
        );
    }

    /*
     * 12. Determina a data da publicação.
     *
     * Ao editar uma obra publicada, preserva a data original.
     * Uma nova publicação recebe a data atual.
     * Ao transformar a obra em rascunho, a data é removida.
     */

    $publicadaEm = null;

    if ($status === "publicada") {
        if (
            $obraAtual &&
            $obraAtual["status"] === "publicada" &&
            !empty($obraAtual["publicada_em"])
        ) {
            $publicadaEm = $obraAtual["publicada_em"];
        } else {
            $publicadaEm = date("Y-m-d H:i:s");
        }
    }

    $tituloBanco = $titulo !== "" ? $titulo : null;
    $autorBanco = $autor !== "" ? $autor : null;

    $textoAlternativoBanco = $textoAlternativo !== ""
        ? $textoAlternativo
        : null;

    /*
     * 13. Cria ou atualiza a obra.
     */

    if ($obraId > 0) {
        if ($imagemBinaria !== null) {
            $sql = "
                UPDATE obras
                SET
                    titulo = ?,
                    autor = ?,
                    imagem = ?,
                    imagem_mime = ?,
                    texto_alternativo = ?,
                    status = ?,
                    publicada_em = ?
                WHERE id = ?
                  AND excluida_em IS NULL
            ";

            $stmt = $conn->prepare($sql);

            $stmt->bind_param(
                "sssssssi",
                $tituloBanco,
                $autorBanco,
                $imagemBinaria,
                $imagemMime,
                $textoAlternativoBanco,
                $status,
                $publicadaEm,
                $obraId
            );

        } else {
            $sql = "
                UPDATE obras
                SET
                    titulo = ?,
                    autor = ?,
                    texto_alternativo = ?,
                    status = ?,
                    publicada_em = ?
                WHERE id = ?
                  AND excluida_em IS NULL
            ";

            $stmt = $conn->prepare($sql);

            $stmt->bind_param(
                "sssssi",
                $tituloBanco,
                $autorBanco,
                $textoAlternativoBanco,
                $status,
                $publicadaEm,
                $obraId
            );
        }

        $stmt->execute();

        $stmt->close();
        $stmt = null;

        $obraIdSalva = $obraId;

        /*
         * As etapas enviadas representam o estado atual da obra.
         * Portanto, substituímos a sequência anterior pela nova.
         */

        $stmt = $conn->prepare(
            "DELETE FROM etapas_exploracao WHERE obra_id = ?"
        );

        $stmt->bind_param("i", $obraIdSalva);
        $stmt->execute();

        $stmt->close();
        $stmt = null;

    } else {
        $sql = "
            INSERT INTO obras (
                titulo,
                autor,
                imagem,
                imagem_mime,
                texto_alternativo,
                status,
                criada_por,
                publicada_em
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ";

        $stmt = $conn->prepare($sql);

        $stmt->bind_param(
            "ssssssis",
            $tituloBanco,
            $autorBanco,
            $imagemBinaria,
            $imagemMime,
            $textoAlternativoBanco,
            $status,
            $usuarioId,
            $publicadaEm
        );

        $stmt->execute();

        $obraIdSalva = (int) $conn->insert_id;

        $stmt->close();
        $stmt = null;
    }

    /*
     * 14. Grava as etapas na ordem do formulário.
     */

    if ($etapasPreparadas !== []) {
        $sqlEtapa = "
            INSERT INTO etapas_exploracao (
                obra_id,
                ordem,
                titulo,
                texto,
                zoom_x,
                zoom_y,
                zoom_largura,
                zoom_altura
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ";

        $stmtEtapa = $conn->prepare($sqlEtapa);

        $ordem = 0;

        foreach ($etapasPreparadas as $etapa) {
            $ordem++;

            $tituloEtapaBanco = $etapa["titulo"];
            $textoEtapaBanco = $etapa["texto"];

            $zoomXBanco = $etapa["zoom_x"];
            $zoomYBanco = $etapa["zoom_y"];

            $zoomLarguraBanco = $etapa["zoom_largura"];
            $zoomAlturaBanco = $etapa["zoom_altura"];

            $stmtEtapa->bind_param(
                "iissssss",
                $obraIdSalva,
                $ordem,
                $tituloEtapaBanco,
                $textoEtapaBanco,
                $zoomXBanco,
                $zoomYBanco,
                $zoomLarguraBanco,
                $zoomAlturaBanco
            );

            $stmtEtapa->execute();
        }

        $stmtEtapa->close();
        $stmtEtapa = null;
    }

    /*
     * 15. Confirma a transação.
     */

    /*
 * Notifica o editor responsável quando um administrador altera
 * uma obra criada por outra conta. O aviso é gravado na mesma
 * transaÃ§Ã£o da ediÃ§Ã£o: ou ambos sÃ£o confirmados, ou nenhum Ã©.
 */
if ($perfil === "admin" && $obraId > 0 && $obraAtual !== null) {
    $editorResponsavelId = (int) ($obraAtual["criada_por"] ?? 0);

    if ($editorResponsavelId > 0 && $editorResponsavelId !== $usuarioId) {
        $tituloNotificacao = $titulo !== ""
            ? $titulo
            : "Obra sem título";

        $statusDescricao = $status === "publicada"
            ? "publicada"
            : "salva como rascunho";

        $tipoNotificacao = "obra_editada_admin";
        $tituloAviso = "Uma obra sua foi alterada pelo administrador";
        $mensagemAviso = sprintf(
            'O administrador alterou a obra "%s". Ela foi %s.',
            $tituloNotificacao,
            $statusDescricao
        );

        $stmtNotificacao = $conn->prepare(
            "INSERT INTO notificacoes_editor
                (usuario_id, obra_id, tipo, titulo, mensagem)
             VALUES (?, ?, ?, ?, ?)"
        );

        $stmtNotificacao->bind_param(
            "iisss",
            $editorResponsavelId,
            $obraIdSalva,
            $tipoNotificacao,
            $tituloAviso,
            $mensagemAviso
        );

        $stmtNotificacao->execute();
        $stmtNotificacao->close();
    }
}
$conn->commit();

    $transacaoAberta = false;

    $conn->close();
    $conn = null;

    /*
     * 16. Registra a operação.
     */

    $tipoOperacao = $obraId > 0
        ? "Atualização"
        : "Criação";

    registrarLog('INFO', 'obra_salva', [
        'obra_id' => $obraIdSalva,
        'usuario_id' => $usuarioId,
        'perfil' => $perfil,
        'status' => $status,
        'operacao' => $tipoOperacao
    ]);

    /*
     * 17. Retorna a resposta.
     *
     * Mantemos HTTP 200 para criação e edição,
     * preservando o contrato atual do endpoint.
     */

    responderJson(200, [
        "sucesso" => true,
        "obra_id" => $obraIdSalva,
        "imagem_salva" => $imagemDisponivel,
        "status" => $status,
        "mensagem" => $status === "rascunho"
            ? "Rascunho salvo com sucesso."
            : "Obra publicada com sucesso."
    ]);

} catch (Throwable $erro) {

    /*
     * Fecha instruções que ainda estejam abertas.
     */

    foreach ([$stmt, $stmtAtual, $stmtEtapa] as $instrucao) {
        if ($instrucao instanceof mysqli_stmt) {
            try {
                $instrucao->close();
            } catch (Throwable $erroFechamento) {
                error_log(
                    "Erro ao fechar instrução SQL: " .
                    $erroFechamento->getMessage()
                );
            }
        }
    }

    /*
     * Reverte a operação se a transação ainda estiver ativa.
     */

    if ($transacaoAberta && $conn instanceof mysqli) {
        try {
            $conn->rollback();
        } catch (Throwable $erroRollback) {
            error_log(
                "Erro ao reverter salvamento da obra: " .
                $erroRollback->getMessage()
            );
        }
    }

    /*
     * Fecha a conexão.
     */

    if ($conn instanceof mysqli) {
        try {
            $conn->close();
        } catch (Throwable $erroFechamento) {
            error_log(
                "Erro ao fechar conexão de salvamento: " .
                $erroFechamento->getMessage()
            );
        }
    }

    /*
     * Registra o erro interno.
     */

    registrarLog('ERROR', 'obra_salvar_falhou', [
        'usuario_id' => $usuarioId,
        'perfil' => $perfil,
        'tipo' => get_class($erro),
        'codigo' => (int) $erro->getCode()
    ]);

    responderJson(500, [
        "sucesso" => false,
        "mensagem" =>
            "Não foi possível salvar a obra. Verifique o banco de dados e os dados enviados."
    ]);
}
