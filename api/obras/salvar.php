<?php

session_set_cookie_params([
    "lifetime" => 0,
    "path" => "/Arte-Guiada/",
    "secure" => false,
    "httponly" => true,
    "samesite" => "Lax"
]);

session_start();

header("Content-Type: application/json; charset=UTF-8");
header("Cache-Control: no-store");

const TAMANHO_MAXIMO_IMAGEM = 7 * 1024 * 1024;

const TIPOS_IMAGEM_PERMITIDOS = [
    "image/jpeg",
    "image/png",
    "image/webp"
];

function responderJson(int $status, array $dados): void
{
    http_response_code($status);

    echo json_encode(
        $dados,
        JSON_UNESCAPED_UNICODE
    );

    exit;
}

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

function tamanhoTexto(string $texto): int
{
    return function_exists("mb_strlen")
        ? mb_strlen($texto, "UTF-8")
        : strlen($texto);
}

function lerCoordenadaZoom($valor, string $campo): ?float
{
    if ($valor === null || trim((string) $valor) === "") {
        return null;
    }

    if (!is_scalar($valor) || !is_numeric($valor)) {
        throw new InvalidArgumentException(
            "O campo {$campo} contém uma coordenada inválida."
        );
    }

    $numero = (float) $valor;

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

function formatarCoordenada(?float $valor): ?string
{
    return $valor === null
        ? null
        : number_format($valor, 6, ".", "");
}


/*
 * 1. Validar a requisição e a sessão.
 */

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Allow: POST");

    responderJson(405, [
        "sucesso" => false,
        "mensagem" => "Método não permitido."
    ]);
}

$usuarioId = (int) ($_SESSION["usuario_id"] ?? 0);

$perfil = strtolower(
    trim((string) ($_SESSION["perfil"] ?? ""))
);

if ($usuarioId <= 0) {
    responderJson(401, [
        "sucesso" => false,
        "mensagem" => "Sua sessão expirou. Entre novamente."
    ]);
}

if (!in_array($perfil, ["editor", "admin"], true)) {
    responderJson(403, [
        "sucesso" => false,
        "mensagem" => "Você não tem permissão para salvar obras."
    ]);
}


/*
 * Se o tamanho da requisição ultrapassar post_max_size,
 * o PHP poderá deixar $_POST e $_FILES vazios.
 */

$tamanhoRequisicao = (int) ($_SERVER["CONTENT_LENGTH"] ?? 0);

if (
    $tamanhoRequisicao > 0 &&
    empty($_POST) &&
    empty($_FILES)
) {
    responderJson(413, [
        "sucesso" => false,
        "mensagem" => "A requisição está vazia ou ultrapassou o limite de upload do servidor."
    ]);
}


/*
 * 2. Preparar os dados recebidos do formulário.
 */

$status = obterTexto(
    $_POST["status"] ?? "",
    "status"
);

if (!in_array($status, ["rascunho", "publicada"], true)) {
    responderJson(422, [
        "sucesso" => false,
        "mensagem" => "Informe um status válido para a obra."
    ]);
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
        responderJson(422, [
            "sucesso" => false,
            "mensagem" => "O identificador da obra é inválido."
        ]);
    }

    $obraId = (int) $obraIdValidado;
}

try {
    $titulo = obterTexto($_POST["titulo"] ?? "", "titulo");
    $autor = obterTexto($_POST["autor"] ?? "", "autor");

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
     * 3. Validar e ler o arquivo de imagem.
     */

    $imagemBinaria = null;
    $imagemMime = null;

    $arquivoImagem = $_FILES["imagem"] ?? null;

    if (is_array($arquivoImagem)) {
        $erroUpload = (int) (
            $arquivoImagem["error"] ?? UPLOAD_ERR_NO_FILE
        );

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
     * 4. Validar e preparar as etapas.
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
 * 5. Conferir a obra existente e as permissões de edição.
 */

$conn = null;
$transacaoAberta = false;

try {
    require_once __DIR__ . "/../../config/banco.php";

    if (!isset($conn) || !($conn instanceof mysqli)) {
        throw new RuntimeException(
            "A conexão com o banco de dados não está disponível."
        );
    }

    $obraAtual = null;
    $imagemAnteriorSalva = false;

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
        ";

        $stmt = $conn->prepare($sqlAtual);
        $stmt->bind_param("i", $obraId);
        $stmt->execute();

        $obraAtual = $stmt->get_result()->fetch_assoc();

        $stmt->close();

        if (!$obraAtual) {
            responderJson(404, [
                "sucesso" => false,
                "mensagem" => "A obra não foi encontrada."
            ]);
        }

        if (
            $perfil !== "admin" &&
            (int) $obraAtual["criada_por"] !== $usuarioId
        ) {
            responderJson(403, [
                "sucesso" => false,
                "mensagem" =>
                    "Você só pode editar obras que pertencem à sua conta."
            ]);
        }

        $imagemAnteriorSalva = (bool) $obraAtual["imagem_salva"];
    }

    $imagemDisponivel =
        $imagemBinaria !== null ||
        $imagemAnteriorSalva;

    if ($status === "publicada" && !$imagemDisponivel) {
        responderJson(422, [
            "sucesso" => false,
            "mensagem" =>
                "Selecione a imagem principal antes de publicar."
        ]);
    }

    /*
     * Ao editar uma obra publicada, a data original é preservada.
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

    $textoAlternativoBanco =
        $textoAlternativo !== ""
            ? $textoAlternativo
            : null;

    /*
     * 6. Salvar a obra e suas etapas em uma transação.
     *
     * Assim, se uma etapa falhar, as alterações da operação
     * não ficam parcialmente gravadas no banco.
     */

    $conn->begin_transaction();
    $transacaoAberta = true;

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

        $obraIdSalva = $obraId;

        /*
         * As etapas enviadas representam o estado atual da obra.
         * Por isso, substituímos a sequência anterior pela nova.
         */

        $stmt = $conn->prepare(
            "DELETE FROM etapas_exploracao WHERE obra_id = ?"
        );

        $stmt->bind_param("i", $obraIdSalva);
        $stmt->execute();
        $stmt->close();

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
    }

    /*
     * 7. Gravar as etapas na ordem do formulário.
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
    }

    $conn->commit();
    $transacaoAberta = false;

    $conn->close();

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

    error_log(
        "Erro ao salvar obra no Arte Guiada: " .
        $erro->getMessage()
    );

    if ($conn instanceof mysqli) {
        $conn->close();
    }

    responderJson(500, [
        "sucesso" => false,
        "mensagem" =>
            "Não foi possível salvar a obra. Verifique o banco de dados e os dados enviados."
    ]);
}