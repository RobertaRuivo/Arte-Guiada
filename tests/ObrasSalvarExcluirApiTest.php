<?php

use PHPUnit\Framework\TestCase;

final class ObrasSalvarExcluirApiTest extends TestCase
{
    private const BASE_URL = "http://127.0.0.1:8001";
    private const ORIGEM = "http://127.0.0.1:8001";
    private const SENHA = "SenhaObras#2026";

    private ?mysqli $db = null;

    private array $usuarios = [];
    private array $cookies = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->usuarios = [];
        $this->cookies = [];

        $this->db = $this->conectarBancoTeste();

        $perfis = [
            "editorA" => "editor",
            "editorB" => "editor",
            "admin" => "admin",
            "visitante" => "visitante"
        ];

        foreach ($perfis as $chave => $perfil) {
            $this->criarUsuario($chave, $perfil);
        }
    }

    protected function tearDown(): void
    {
        /*
         * Encerra as sessões criadas pelos testes.
         */

        foreach ($this->cookies as $cookie) {
            if ($cookie !== "") {
                try {
                    $this->requisitarHttp(
                        "POST",
                        "/api/login/logout.php",
                        "{}",
                        "application/json",
                        self::ORIGEM,
                        $cookie
                    );
                } catch (Throwable $erro) {
                    // A limpeza do banco continua mesmo se o logout falhar.
                }
            }
        }

        if ($this->db instanceof mysqli) {
            /*
             * Remove somente as obras criadas pelos usuários
             * temporários desta classe de testes.
             *
             * As etapas são removidas pela FK ON DELETE CASCADE.
             */
            /*
             * Remove os registros de biblioteca dos usuários temporários.
             * Isso precisa acontecer antes da exclusão das obras,
             * pois a biblioteca possui chave estrangeira para obras.
             */

            foreach ($this->usuarios as $usuarioTeste) {
                $usuarioId = $usuarioTeste["id"];

                $stmt = $this->db->prepare(
                    "DELETE FROM biblioteca_usuario WHERE usuario_id = ?"
                );

                $stmt->bind_param("i", $usuarioId);
                $stmt->execute();
                $stmt->close();
            }
            foreach ($this->usuarios as $usuarioTeste) {
                $usuarioId = $usuarioTeste["id"];

                $stmt = $this->db->prepare(
                    "DELETE FROM obras WHERE criada_por = ?"
                );

                $stmt->bind_param("i", $usuarioId);
                $stmt->execute();
                $stmt->close();
            }

            /*
             * Remove somente as quatro contas temporárias.
             */

            foreach ($this->usuarios as $usuarioTeste) {
                $usuarioId = $usuarioTeste["id"];

                $stmt = $this->db->prepare(
                    "DELETE FROM usuarios WHERE id = ?"
                );

                $stmt->bind_param("i", $usuarioId);
                $stmt->execute();
                $stmt->close();
            }

            $this->db->close();
            $this->db = null;
        }

        parent::tearDown();
    }

    /*
     * CONEXÃO E PREPARAÇÃO DOS USUÁRIOS
     */

    private function conectarBancoTeste(): mysqli
    {
        $this->assertSame(
            "arte_guiada_test",
            getenv("ARTE_GUIADA_DB_NAME")
        );

        require __DIR__ . "/../config/banco.php";

        $this->assertInstanceOf(mysqli::class, $conn);

        $resultado = $conn->query("SELECT DATABASE() AS banco");
        $linha = $resultado->fetch_assoc();
        $resultado->free();

        $this->assertSame(
            "arte_guiada_test",
            $linha["banco"]
        );

        return $conn;
    }

    private function criarUsuario(string $chave, string $perfil): void
    {
        $nome = "Teste " . $chave;
        $sobrenome = "Obras PHPUnit";

        $email = "obras." . bin2hex(random_bytes(8)) . "@example.com";

        $senhaHash = password_hash(
            self::SENHA,
            PASSWORD_DEFAULT
        );

        $mensagem = "Conta temporária para testes de obras.";

        $stmt = $this->db->prepare(
            "INSERT INTO usuarios
            (nome, sobrenome, email, senha, perfil, mensagem)
            VALUES (?, ?, ?, ?, ?, ?)"
        );

        $stmt->bind_param(
            "ssssss",
            $nome,
            $sobrenome,
            $email,
            $senhaHash,
            $perfil,
            $mensagem
        );

        $stmt->execute();

        $id = (int) $this->db->insert_id;

        $stmt->close();

        $this->usuarios[$chave] = [
            "id" => $id,
            "email" => $email,
            "perfil" => $perfil
        ];
    }

    private function criarSessao(string $chave): string
    {
        $usuarioTeste = $this->usuarios[$chave];

        $corpo = json_encode([
            "email" => $usuarioTeste["email"],
            "senha" => self::SENHA
        ], JSON_THROW_ON_ERROR);

        $resposta = $this->requisitarHttp(
            "POST",
            "/api/login/login.php",
            $corpo,
            "application/json",
            self::ORIGEM
        );

        $this->assertSame(
            200,
            $resposta["status"],
            "Não foi possível autenticar o usuário de teste: " . $chave
        );

        $dados = $this->decodificarResposta($resposta);

        $this->assertTrue($dados["sucesso"]);

        $this->assertNotNull(
            $resposta["cookie"],
            "O login não retornou cookie de sessão."
        );

        $this->cookies[$chave] = $resposta["cookie"];

        return $resposta["cookie"];
    }

    /*
     * REQUISIÇÕES HTTP
     */

    private function requisitarHttp(
        string $metodo,
        string $endpoint,
        ?string $corpo = null,
        string $tipoConteudo = "application/json",
        ?string $origem = self::ORIGEM,
        ?string $cookie = null
    ): array {
        $cabecalhos = [
            "Content-Type: " . $tipoConteudo
        ];

        if ($origem !== null) {
            $cabecalhos[] = "Origin: " . $origem;
        }

        if ($cookie !== null) {
            $cabecalhos[] = "Cookie: PHPSESSID=" . $cookie;
        }

        if ($corpo !== null) {
            $cabecalhos[] = "Content-Length: " . strlen($corpo);
        }

        $opcoes = [
            "method" => $metodo,
            "header" => implode("\r\n", $cabecalhos),
            "ignore_errors" => true,
            "timeout" => 15
        ];

        if ($corpo !== null) {
            $opcoes["content"] = $corpo;
        }

        $contexto = stream_context_create([
            "http" => $opcoes
        ]);

        $http_response_header = [];

        $resposta = @file_get_contents(
            self::BASE_URL . $endpoint,
            false,
            $contexto
        );

        $cabecalhosResposta = $http_response_header;
        $status = 0;
        $cookieSessao = null;

        if (
            isset($cabecalhosResposta[0]) &&
            preg_match(
                '/\s(\d{3})(?:\s|$)/',
                $cabecalhosResposta[0],
                $correspondencia
            )
        ) {
            $status = (int) $correspondencia[1];
        }

        foreach ($cabecalhosResposta as $cabecalho) {
            if (
                preg_match(
                    '/^Set-Cookie:\s*PHPSESSID=([^;]*)/i',
                    $cabecalho,
                    $correspondencia
                )
            ) {
                $cookieSessao = $correspondencia[1];
            }
        }

        return [
            "status" => $status,
            "body" => $resposta === false ? "" : $resposta,
            "headers" => $cabecalhosResposta,
            "cookie" => $cookieSessao
        ];
    }

    private function montarMultipart(
        array $campos,
        ?array $arquivo = null
    ): array {
        $boundary = "--------------------------" .
            bin2hex(random_bytes(12));

        $corpo = "";

        foreach ($campos as $nome => $valor) {
            $corpo .= "--{$boundary}\r\n";

            $corpo .=
                'Content-Disposition: form-data; name="' .
                $nome .
                '"' .
                "\r\n\r\n";

            $corpo .= (string) $valor . "\r\n";
        }

        if ($arquivo !== null) {
            $corpo .= "--{$boundary}\r\n";

            $corpo .=
                'Content-Disposition: form-data; ' .
                'name="imagem"; filename="' .
                $arquivo["nome"] .
                '"' .
                "\r\n";

            $corpo .=
                "Content-Type: " .
                $arquivo["tipo"] .
                "\r\n\r\n";

            $corpo .= $arquivo["conteudo"] . "\r\n";
        }

        $corpo .= "--{$boundary}--\r\n";

        return [
            "corpo" => $corpo,
            "tipo" => "multipart/form-data; boundary=" . $boundary
        ];
    }

    private function requisitarSalvar(
        array $campos,
        ?string $cookie = null,
        ?string $origem = self::ORIGEM,
        ?array $arquivo = null
    ): array {
        $multipart = $this->montarMultipart(
            $campos,
            $arquivo
        );

        return $this->requisitarHttp(
            "POST",
            "/api/obras/salvar.php",
            $multipart["corpo"],
            $multipart["tipo"],
            $origem,
            $cookie
        );
    }

    private function requisitarExcluir(
        int $obraId,
        ?string $cookie = null,
        ?string $origem = self::ORIGEM
    ): array {
        $corpo = json_encode([
            "obra_id" => $obraId
        ], JSON_THROW_ON_ERROR);

        return $this->requisitarHttp(
            "POST",
            "/api/obras/excluir.php",
            $corpo,
            "application/json",
            $origem,
            $cookie
        );
    }

    private function decodificarResposta(array $resposta): array
    {
        $dados = json_decode($resposta["body"], true);

        $this->assertIsArray(
            $dados,
            "A resposta da API não contém JSON válido. Corpo recebido: " .
            $resposta["body"]
        );

        return $dados;
    }

    /*
     * DADOS DE TESTE DAS OBRAS
     */

    private function camposRascunho(string $titulo = "Obra de teste"): array
    {
        return [
            "status" => "rascunho",
            "titulo" => $titulo,
            "autor" => "Autor de teste",
            "texto_alternativo" => "Imagem usada em teste automatizado.",

            "etapas[0][titulo]" => "Primeira etapa",
            "etapas[0][texto]" => "Texto da primeira etapa.",

            "etapas[0][zoom_x]" => "0.100000",
            "etapas[0][zoom_y]" => "0.100000",
            "etapas[0][zoom_largura]" => "0.300000",
            "etapas[0][zoom_altura]" => "0.300000"
        ];
    }
    private function consultarNomeCompletoUsuario(string $chave): string
    {
        $usuarioId = $this->usuarios[$chave]["id"];

        $stmt = $this->db->prepare(
            "SELECT nome, sobrenome
         FROM usuarios
         WHERE id = ?"
        );

        $stmt->bind_param("i", $usuarioId);
        $stmt->execute();

        $stmt->bind_result($nome, $sobrenome);

        if (!$stmt->fetch()) {
            $stmt->close();

            throw new RuntimeException(
                "Não foi possível consultar o usuário de teste: " . $chave
            );
        }

        $stmt->close();

        return trim($nome . " " . $sobrenome);
    }
    private function criarObraDiretamenteNoBanco(string $chave): int
    {
        $usuarioId = $this->usuarios[$chave]["id"];

        $titulo = "Obra de propriedade de " . $chave;
        $autor = "Autor de teste";
        $status = "rascunho";

        $stmt = $this->db->prepare(
            "INSERT INTO obras (titulo, autor, status, criada_por)
             VALUES (?, ?, ?, ?)"
        );

        $stmt->bind_param(
            "sssi",
            $titulo,
            $autor,
            $status,
            $usuarioId
        );

        $stmt->execute();

        $obraId = (int) $this->db->insert_id;

        $stmt->close();

        return $obraId;
    }

    private function consultarObra(int $obraId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT id, titulo, criada_por, status, excluida_em
             FROM obras
             WHERE id = ?"
        );

        $stmt->bind_param("i", $obraId);
        $stmt->execute();

        $resultado = $stmt->get_result();
        $obra = $resultado->fetch_assoc();

        $stmt->close();

        return $obra ?: null;
    }

    /*
     * TESTES DE SALVAR.PHP
     */

    public function testSalvarRecusaMetodoGet(): void
    {
        $resposta = $this->requisitarHttp(
            "GET",
            "/api/obras/salvar.php"
        );

        $this->assertSame(405, $resposta["status"]);
    }

    public function testSalvarBloqueiaOrigemNaoAutorizada(): void
    {
        $resposta = $this->requisitarSalvar(
            $this->camposRascunho(),
            null,
            "http://site-malicioso.test"
        );

        $this->assertSame(403, $resposta["status"]);
    }

    public function testSalvarExigeSessao(): void
    {
        $resposta = $this->requisitarSalvar(
            $this->camposRascunho()
        );

        $this->assertSame(401, $resposta["status"]);
    }

    public function testVisitanteNaoPodeSalvarObra(): void
    {
        $cookie = $this->criarSessao("visitante");

        $resposta = $this->requisitarSalvar(
            $this->camposRascunho(),
            $cookie
        );

        $this->assertSame(403, $resposta["status"]);
    }

    public function testStatusInvalidoEhRecusado(): void
    {
        $cookie = $this->criarSessao("editorA");

        $campos = $this->camposRascunho();

        unset($campos["status"]);
        $campos["status[]"] = "rascunho";

        $resposta = $this->requisitarSalvar(
            $campos,
            $cookie
        );

        $this->assertSame(422, $resposta["status"]);
    }

    public function testCoordenadaDeZoomComTipoIncorretoEhRecusada(): void
    {
        $cookie = $this->criarSessao("editorA");

        $campos = $this->camposRascunho();

        unset($campos["etapas[0][zoom_x]"]);

        $campos["etapas[0][zoom_x][]"] = "0.2";

        $resposta = $this->requisitarSalvar(
            $campos,
            $cookie
        );

        $this->assertSame(422, $resposta["status"]);
    }

    public function testPublicacaoSemEtapaCompletaEhRecusada(): void
    {
        $cookie = $this->criarSessao("editorA");

        $campos = [
            "status" => "publicada",
            "titulo" => "Obra incompleta",
            "autor" => "Autor de teste",
            "texto_alternativo" => "Descrição da imagem."
        ];

        $resposta = $this->requisitarSalvar(
            $campos,
            $cookie
        );

        $this->assertSame(422, $resposta["status"]);
    }

    public function testUploadDeArquivoComMimeNaoPermitidoEhRecusado(): void
    {
        $cookie = $this->criarSessao("editorA");

        $resposta = $this->requisitarSalvar(
            $this->camposRascunho(),
            $cookie,
            self::ORIGEM,
            [
                "nome" => "arquivo.txt",
                "tipo" => "text/plain",
                "conteudo" => "Isto nao e uma imagem."
            ]
        );

        $this->assertSame(422, $resposta["status"]);
    }

    public function testEditorCriaRascunhoEGravaEtapas(): void
    {
        $cookie = $this->criarSessao("editorA");

        $resposta = $this->requisitarSalvar(
            $this->camposRascunho(),
            $cookie
        );

        $dados = $this->decodificarResposta($resposta);

        $this->assertSame(200, $resposta["status"]);
        $this->assertTrue($dados["sucesso"]);
        $this->assertSame("rascunho", $dados["status"]);

        $obraId = (int) $dados["obra_id"];

        $obra = $this->consultarObra($obraId);

        $this->assertNotNull($obra);

        $this->assertSame(
            $this->usuarios["editorA"]["id"],
            (int) $obra["criada_por"]
        );

        $this->assertSame(
            "Obra de teste",
            $obra["titulo"]
        );

        $stmt = $this->db->prepare(
            "SELECT COUNT(*) AS total
             FROM etapas_exploracao
             WHERE obra_id = ?"
        );

        $stmt->bind_param("i", $obraId);
        $stmt->execute();

        $resultado = $stmt->get_result();
        $linha = $resultado->fetch_assoc();

        $stmt->close();

        $this->assertSame(1, (int) $linha["total"]);
    }

    public function testEditorAtualizaPropriaObra(): void
    {
        $cookie = $this->criarSessao("editorA");

        $criacao = $this->requisitarSalvar(
            $this->camposRascunho("Título original"),
            $cookie
        );

        $dadosCriacao = $this->decodificarResposta($criacao);

        $this->assertSame(200, $criacao["status"]);

        $obraId = (int) $dadosCriacao["obra_id"];

        $campos = $this->camposRascunho("Título atualizado");
        $campos["obra_id"] = (string) $obraId;

        $resposta = $this->requisitarSalvar(
            $campos,
            $cookie
        );

        $dados = $this->decodificarResposta($resposta);

        $this->assertSame(200, $resposta["status"]);
        $this->assertTrue($dados["sucesso"]);

        $obra = $this->consultarObra($obraId);

        $this->assertNotNull($obra);
        $this->assertSame(
            "Título atualizado",
            $obra["titulo"]
        );

        $this->assertSame(
            $this->usuarios["editorA"]["id"],
            (int) $obra["criada_por"]
        );
    }

    public function testEditorNaoPodeEditarObraDeOutroEditor(): void
    {
        $cookie = $this->criarSessao("editorA");

        $obraId = $this->criarObraDiretamenteNoBanco("editorB");

        $campos = $this->camposRascunho("Tentativa indevida");
        $campos["obra_id"] = (string) $obraId;

        $resposta = $this->requisitarSalvar(
            $campos,
            $cookie
        );

        $this->assertSame(403, $resposta["status"]);

        $obra = $this->consultarObra($obraId);

        $this->assertNotNull($obra);

        $this->assertSame(
            "Obra de propriedade de editorB",
            $obra["titulo"]
        );

        $this->assertSame(
            $this->usuarios["editorB"]["id"],
            (int) $obra["criada_por"]
        );
    }

    public function testAdministradorPodeEditarObraDeOutroEditor(): void
    {
        $cookie = $this->criarSessao("admin");

        $obraId = $this->criarObraDiretamenteNoBanco("editorB");

        $campos = $this->camposRascunho("Edição administrativa");
        $campos["obra_id"] = (string) $obraId;

        $resposta = $this->requisitarSalvar(
            $campos,
            $cookie
        );

        $dados = $this->decodificarResposta($resposta);

        $this->assertSame(200, $resposta["status"]);
        $this->assertTrue($dados["sucesso"]);

        $obra = $this->consultarObra($obraId);

        $this->assertSame(
            "Edição administrativa",
            $obra["titulo"]
        );

        $this->assertSame(
            $this->usuarios["editorB"]["id"],
            (int) $obra["criada_por"]
        );
    }

    /*
     * TESTES DE EXCLUIR.PHP
     */

    public function testExcluirRecusaMetodoGet(): void
    {
        $resposta = $this->requisitarHttp(
            "GET",
            "/api/obras/excluir.php"
        );

        $this->assertSame(405, $resposta["status"]);
    }

    public function testExcluirBloqueiaOrigemNaoAutorizada(): void
    {
        $resposta = $this->requisitarExcluir(
            1,
            null,
            "http://site-malicioso.test"
        );

        $this->assertSame(403, $resposta["status"]);
    }

    public function testExcluirExigeSessao(): void
    {
        $resposta = $this->requisitarExcluir(1);

        $this->assertSame(401, $resposta["status"]);
    }

    public function testVisitanteNaoPodeExcluirObra(): void
    {
        $cookie = $this->criarSessao("visitante");

        $resposta = $this->requisitarExcluir(
            1,
            $cookie
        );

        $this->assertSame(403, $resposta["status"]);
    }

    public function testEditorExcluiPropriaObraLogicamente(): void
    {
        $cookie = $this->criarSessao("editorA");

        $obraId = $this->criarObraDiretamenteNoBanco("editorA");

        $resposta = $this->requisitarExcluir(
            $obraId,
            $cookie
        );

        $dados = $this->decodificarResposta($resposta);

        $this->assertSame(200, $resposta["status"]);
        $this->assertTrue($dados["sucesso"]);
        $this->assertSame($obraId, (int) $dados["obra_id"]);

        $obra = $this->consultarObra($obraId);

        $this->assertNotNull($obra);
        $this->assertNotNull($obra["excluida_em"]);
    }

    public function testEditorNaoPodeExcluirObraDeOutroEditor(): void
    {
        $cookie = $this->criarSessao("editorA");

        $obraId = $this->criarObraDiretamenteNoBanco("editorB");

        $resposta = $this->requisitarExcluir(
            $obraId,
            $cookie
        );

        $this->assertSame(404, $resposta["status"]);

        $obra = $this->consultarObra($obraId);

        $this->assertNotNull($obra);
        $this->assertNull($obra["excluida_em"]);

        $this->assertSame(
            $this->usuarios["editorB"]["id"],
            (int) $obra["criada_por"]
        );
    }

    public function testAdministradorPodeExcluirObraDeOutroEditor(): void
    {
        $cookie = $this->criarSessao("admin");

        $obraId = $this->criarObraDiretamenteNoBanco("editorB");

        $resposta = $this->requisitarExcluir(
            $obraId,
            $cookie
        );

        $dados = $this->decodificarResposta($resposta);

        $this->assertSame(200, $resposta["status"]);
        $this->assertTrue($dados["sucesso"]);

        $obra = $this->consultarObra($obraId);

        $this->assertNotNull($obra);
        $this->assertNotNull($obra["excluida_em"]);
    }

    /*
     * TESTES DE DETALHE.PHP
     */

    private function requisitarDetalhe(
        string $id,
        ?string $cookie = null
    ): array {
        return $this->requisitarHttp(
            "GET",
            "/api/obras/detalhe.php?id=" . rawurlencode($id),
            null,
            "application/json",
            self::ORIGEM,
            $cookie
        );
    }

    private function criarEtapaParaObra(
        int $obraId,
        int $ordem,
        string $titulo,
        string $texto,
        float $zoomX,
        float $zoomY,
        float $zoomLargura,
        float $zoomAltura
    ): void {
        $stmt = $this->db->prepare(
            "INSERT INTO etapas_exploracao
            (
                obra_id,
                ordem,
                titulo,
                texto,
                zoom_x,
                zoom_y,
                zoom_largura,
                zoom_altura
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );

        $stmt->bind_param(
            "iissdddd",
            $obraId,
            $ordem,
            $titulo,
            $texto,
            $zoomX,
            $zoomY,
            $zoomLargura,
            $zoomAltura
        );

        $stmt->execute();
        $stmt->close();
    }

    private function obterIdInexistente(): int
    {
        $resultado = $this->db->query(
            "SELECT COALESCE(MAX(id), 0) + 1 AS proximo_id
             FROM obras"
        );

        $linha = $resultado->fetch_assoc();
        $resultado->free();

        return (int) $linha["proximo_id"];
    }

    public function testDetalheExigeSessao(): void
    {
        $resposta = $this->requisitarHttp(
            "GET",
            "/api/obras/detalhe.php?id=1"
        );

        $this->assertSame(401, $resposta["status"]);

        $dados = json_decode(
            $resposta["body"],
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $this->assertFalse($dados["sucesso"]);

        $setCookieEncontrado = false;

        foreach ($resposta["headers"] as $cabecalho) {
            if (stripos($cabecalho, "Set-Cookie:") === 0) {
                $setCookieEncontrado = true;
                break;
            }
        }

        $this->assertFalse(
            $setCookieEncontrado,
            "A API não deve criar uma sessão para visitantes."
        );
    }

    public function testDetalheRecusaMetodoPost(): void
    {
        $resposta = $this->requisitarHttp(
            "POST",
            "/api/obras/detalhe.php",
            "{}",
            "application/json",
            self::ORIGEM
        );

        $this->assertSame(405, $resposta["status"]);
    }

    public function testDetalheRejeitaIdInvalido(): void
    {
        $cookie = $this->criarSessao("editorA");

        $resposta = $this->requisitarDetalhe(
            "abc",
            $cookie
        );

        $this->assertSame(400, $resposta["status"]);
    }

    public function testDetalheRetorna404ParaObraInexistente(): void
    {
        $cookie = $this->criarSessao("editorA");

        $idInexistente = $this->obterIdInexistente();

        $resposta = $this->requisitarDetalhe(
            (string) $idInexistente,
            $cookie
        );

        $this->assertSame(404, $resposta["status"]);
    }

    public function testVisitanteNaoPodeConsultarDetalhe(): void
    {
        $obraId = $this->criarObraDiretamenteNoBanco("editorA");

        $cookie = $this->criarSessao("visitante");

        $resposta = $this->requisitarDetalhe(
            (string) $obraId,
            $cookie
        );

        $this->assertSame(403, $resposta["status"]);
    }

    public function testEditorConsultaPropriaObraComEtapas(): void
    {
        $obraId = $this->criarObraDiretamenteNoBanco("editorA");

        // Inserimos primeiro a etapa 2 para verificar a ordenação da API.
        $this->criarEtapaParaObra(
            $obraId,
            2,
            "Segunda etapa",
            "Texto da segunda etapa.",
            0.654321,
            0.543210,
            0.234567,
            0.345678
        );

        $this->criarEtapaParaObra(
            $obraId,
            1,
            "Primeira etapa",
            "Texto da primeira etapa.",
            0.123456,
            0.234567,
            0.345678,
            0.456789
        );

        $cookie = $this->criarSessao("editorA");

        $resposta = $this->requisitarDetalhe(
            (string) $obraId,
            $cookie
        );

        $this->assertSame(200, $resposta["status"]);

        $dados = json_decode(
            $resposta["body"],
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $this->assertTrue($dados["sucesso"]);
        $this->assertSame($obraId, $dados["obra"]["id"]);
        $this->assertSame(
            "Obra de propriedade de editorA",
            $dados["obra"]["titulo"]
        );
        $this->assertSame("rascunho", $dados["obra"]["status"]);
        $this->assertFalse($dados["obra"]["imagem_salva"]);

        $etapas = $dados["obra"]["etapas"];

        $this->assertCount(2, $etapas);

        // Confirma que a API devolve as etapas na ordem correta.
        $this->assertSame(1, $etapas[0]["ordem"]);
        $this->assertSame(2, $etapas[1]["ordem"]);

        $this->assertSame("Primeira etapa", $etapas[0]["titulo"]);
        $this->assertSame(
            "Texto da primeira etapa.",
            $etapas[0]["texto"]
        );

        $this->assertEqualsWithDelta(
            0.123456,
            $etapas[0]["zoom_x"],
            0.000001
        );

        $this->assertEqualsWithDelta(
            0.456789,
            $etapas[0]["zoom_altura"],
            0.000001
        );

        $this->assertSame("Segunda etapa", $etapas[1]["titulo"]);
        $this->assertEqualsWithDelta(
            0.654321,
            $etapas[1]["zoom_x"],
            0.000001
        );
    }

    public function testEditorNaoPodeConsultarObraDeOutroEditor(): void
    {
        $obraId = $this->criarObraDiretamenteNoBanco("editorB");

        $cookie = $this->criarSessao("editorA");

        $resposta = $this->requisitarDetalhe(
            (string) $obraId,
            $cookie
        );

        $this->assertSame(403, $resposta["status"]);
    }

    public function testAdminPodeConsultarObraDeOutroEditor(): void
    {
        $obraId = $this->criarObraDiretamenteNoBanco("editorB");

        $cookie = $this->criarSessao("admin");

        $resposta = $this->requisitarDetalhe(
            (string) $obraId,
            $cookie
        );

        $this->assertSame(200, $resposta["status"]);

        $dados = json_decode(
            $resposta["body"],
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $this->assertTrue($dados["sucesso"]);
        $this->assertSame($obraId, $dados["obra"]["id"]);
    }

    public function testDetalheNaoRetornaObraExcluida(): void
    {
        $obraId = $this->criarObraDiretamenteNoBanco("editorA");

        $stmt = $this->db->prepare(
            "UPDATE obras
             SET excluida_em = CURRENT_TIMESTAMP
             WHERE id = ?"
        );

        $stmt->bind_param("i", $obraId);
        $stmt->execute();
        $stmt->close();

        $cookie = $this->criarSessao("editorA");

        $resposta = $this->requisitarDetalhe(
            (string) $obraId,
            $cookie
        );

        $this->assertSame(404, $resposta["status"]);
    }

    /*
     * TESTES DE MINHAS.PHP
     */

    public function testMinhasExigeSessao(): void
    {
        $resposta = $this->requisitarHttp(
            "GET",
            "/api/obras/minhas.php"
        );

        $this->assertSame(401, $resposta["status"]);

        $dados = $this->decodificarResposta($resposta);

        $this->assertFalse($dados["sucesso"]);

        foreach ($resposta["headers"] as $cabecalho) {
            $this->assertFalse(
                stripos($cabecalho, "Set-Cookie:") === 0,
                "A API não deve criar uma sessão para visitantes."
            );
        }
    }

    public function testMinhasRecusaMetodoPost(): void
    {
        $resposta = $this->requisitarHttp(
            "POST",
            "/api/obras/minhas.php",
            "{}",
            "application/json",
            self::ORIGEM
        );

        $this->assertSame(405, $resposta["status"]);
    }

    public function testVisitanteNaoPodeConsultarMinhasObras(): void
    {
        $cookie = $this->criarSessao("visitante");

        $resposta = $this->requisitarHttp(
            "GET",
            "/api/obras/minhas.php",
            null,
            "application/json",
            self::ORIGEM,
            $cookie
        );

        $this->assertSame(403, $resposta["status"]);
    }

    public function testEditorConsultaSomentePropriasObras(): void
    {
        $obraPropria = $this->criarObraDiretamenteNoBanco("editorA");

        $obraDeOutroEditor = $this->criarObraDiretamenteNoBanco("editorB");

        $this->criarEtapaParaObra(
            $obraPropria,
            1,
            "Etapa de teste",
            "Texto da etapa.",
            0.2,
            0.3,
            0.4,
            0.5
        );

        $cookie = $this->criarSessao("editorA");

        $resposta = $this->requisitarHttp(
            "GET",
            "/api/obras/minhas.php",
            null,
            "application/json",
            self::ORIGEM,
            $cookie
        );

        $this->assertSame(200, $resposta["status"]);

        $dados = $this->decodificarResposta($resposta);

        $this->assertTrue($dados["sucesso"]);
        $this->assertArrayHasKey("obras", $dados);

        $idsObras = array_column($dados["obras"], "id");

        $this->assertContains($obraPropria, $idsObras);

        $this->assertNotContains(
            $obraDeOutroEditor,
            $idsObras,
            "O editor não pode visualizar obras de outro editor."
        );

        $this->assertCount(1, $dados["obras"]);

        $obra = $dados["obras"][0];

        /* Verifica a identificação da obra. */

        $this->assertSame($obraPropria, $obra["id"]);

        $this->assertSame(
            1,
            $obra["total_etapas"]
        );

        /* Verifica a identificação do editor responsável. */

        $this->assertSame(
            $this->usuarios["editorA"]["id"],
            $obra["criada_por"]
        );

        $this->assertSame(
            $this->consultarNomeCompletoUsuario("editorA"),
            $obra["editor_nome"]
        );

        /* Verifica os dados de curadoria. */

        $this->assertIsBool($obra["destaque"]);

        $this->assertFalse(
            $obra["destaque"],
            "Uma obra recém-criada sem seleção deve retornar destaque falso."
        );

        $this->assertNull(
            $obra["ordem_destaque"],
            "Uma obra sem prioridade definida deve retornar ordem nula."
        );
    }
   public function testAdministradorConsultaObrasDeTodosOsEditores(): void
{
    $obraEditorA = $this->criarObraDiretamenteNoBanco("editorA");
    $obraEditorB = $this->criarObraDiretamenteNoBanco("editorB");

    $cookie = $this->criarSessao("admin");

    $resposta = $this->requisitarHttp(
        "GET",
        "/api/obras/minhas.php",
        null,
        "application/json",
        self::ORIGEM,
        $cookie
    );

    $this->assertSame(200, $resposta["status"]);

    $dados = $this->decodificarResposta($resposta);

    $this->assertTrue($dados["sucesso"]);
    $this->assertArrayHasKey("obras", $dados);

    $obrasPorId = [];

    foreach ($dados["obras"] as $obra) {
        $obrasPorId[$obra["id"]] = $obra;
    }

    /* Confirma acesso às obras dos dois editores. */

    $this->assertArrayHasKey(
        $obraEditorA,
        $obrasPorId,
        "O administrador deve visualizar as obras do editor A."
    );

    $this->assertArrayHasKey(
        $obraEditorB,
        $obrasPorId,
        "O administrador deve visualizar as obras do editor B."
    );

    /* Verifica a autoria da obra do editor A. */

    $obraA = $obrasPorId[$obraEditorA];

    $this->assertSame(
        $this->usuarios["editorA"]["id"],
        $obraA["criada_por"]
    );

    $this->assertSame(
        $this->consultarNomeCompletoUsuario("editorA"),
        $obraA["editor_nome"]
    );

    /* Verifica a autoria da obra do editor B. */

    $obraB = $obrasPorId[$obraEditorB];

    $this->assertSame(
        $this->usuarios["editorB"]["id"],
        $obraB["criada_por"]
    );

    $this->assertSame(
        $this->consultarNomeCompletoUsuario("editorB"),
        $obraB["editor_nome"]
    );

    /* Verifica a tipagem dos dados de curadoria. */

    foreach ([$obraA, $obraB] as $obra) {
        $this->assertIsBool($obra["destaque"]);

        $this->assertFalse(
            $obra["destaque"],
            "As obras de teste não foram selecionadas como destaques."
        );

        $this->assertNull($obra["ordem_destaque"]);
    }
}
    public function testMinhasNaoRetornaObrasExcluidas(): void
    {
        $obraId = $this->criarObraDiretamenteNoBanco("editorA");

        $stmt = $this->db->prepare(
            "UPDATE obras
             SET excluida_em = CURRENT_TIMESTAMP
             WHERE id = ?"
        );

        $stmt->bind_param("i", $obraId);
        $stmt->execute();
        $stmt->close();

        $cookie = $this->criarSessao("editorA");

        $resposta = $this->requisitarHttp(
            "GET",
            "/api/obras/minhas.php",
            null,
            "application/json",
            self::ORIGEM,
            $cookie
        );

        $this->assertSame(200, $resposta["status"]);

        $dados = $this->decodificarResposta($resposta);

        $this->assertTrue($dados["sucesso"]);

        $idsObras = array_column($dados["obras"], "id");

        $this->assertNotContains(
            $obraId,
            $idsObras,
            "Obras excluídas logicamente não devem aparecer."
        );
    }
    /*
     * TESTES DE ATUALIZAR.PHP DA BIBLIOTECA
     */

    private function publicarObraParaBiblioteca(int $obraId): void
    {
        $stmt = $this->db->prepare(
            "UPDATE obras SET status = 'publicada' WHERE id = ?"
        );

        $stmt->bind_param("i", $obraId);
        $stmt->execute();
        $stmt->close();
    }

    private function requisitarAtualizarBiblioteca(
        array $dados,
        ?string $cookie = null,
        ?string $origem = self::ORIGEM
    ): array {
        return $this->requisitarHttp(
            "POST",
            "/api/biblioteca/atualizar.php",
            json_encode($dados, JSON_THROW_ON_ERROR),
            "application/json",
            $origem,
            $cookie
        );
    }

    private function consultarEstadoBiblioteca(
        int $usuarioId,
        int $obraId
    ): ?array {
        $stmt = $this->db->prepare(
            "SELECT favorito, quero_explorar, explorada_em
             FROM biblioteca_usuario
             WHERE usuario_id = ? AND obra_id = ?"
        );

        $stmt->bind_param("ii", $usuarioId, $obraId);
        $stmt->execute();

        $resultado = $stmt->get_result();
        $estado = $resultado->fetch_assoc();

        $resultado->free();
        $stmt->close();

        return $estado ?: null;
    }

    public function testBibliotecaAtualizarRecusaMetodoGet(): void
    {
        $resposta = $this->requisitarHttp(
            "GET",
            "/api/biblioteca/atualizar.php"
        );

        $this->assertSame(405, $resposta["status"]);
    }

    public function testBibliotecaAtualizarExigeSessao(): void
    {
        $resposta = $this->requisitarHttp(
            "POST",
            "/api/biblioteca/atualizar.php",
            "{}",
            "application/json",
            self::ORIGEM
        );

        $this->assertSame(401, $resposta["status"]);

        foreach ($resposta["headers"] as $cabecalho) {
            $this->assertFalse(
                stripos($cabecalho, "Set-Cookie:") === 0,
                "A API não deve criar uma sessão para visitantes."
            );
        }
    }

    public function testBibliotecaAtualizarBloqueiaOrigemNaoAutorizada(): void
    {
        $cookie = $this->criarSessao("editorA");

        $resposta = $this->requisitarAtualizarBiblioteca(
            [
                "obra_id" => 1,
                "acao" => "favorito",
                "ativo" => true
            ],
            $cookie,
            "http://site-malicioso.test"
        );

        $this->assertSame(403, $resposta["status"]);
    }

    public function testBibliotecaAtualizarRecusaJsonInvalido(): void
    {
        $cookie = $this->criarSessao("editorA");

        $resposta = $this->requisitarHttp(
            "POST",
            "/api/biblioteca/atualizar.php",
            "{json inválido",
            "application/json",
            self::ORIGEM,
            $cookie
        );

        $this->assertSame(400, $resposta["status"]);
    }

    public function testBibliotecaAtualizarValidaCampos(): void
    {
        $cookie = $this->criarSessao("editorA");

        // Identificador inválido.
        $respostaId = $this->requisitarAtualizarBiblioteca(
            [
                "obra_id" => "abc",
                "acao" => "favorito",
                "ativo" => true
            ],
            $cookie
        );

        $this->assertSame(400, $respostaId["status"]);

        // Ação inválida.
        $respostaAcao = $this->requisitarAtualizarBiblioteca(
            [
                "obra_id" => 1,
                "acao" => ["favorito"],
                "ativo" => true
            ],
            $cookie
        );

        $this->assertSame(400, $respostaAcao["status"]);

        // Estado precisa ser booleano.
        $respostaAtivo = $this->requisitarAtualizarBiblioteca(
            [
                "obra_id" => 1,
                "acao" => "favorito",
                "ativo" => "true"
            ],
            $cookie
        );

        $this->assertSame(400, $respostaAtivo["status"]);
    }

    public function testBibliotecaNaoAceitaObraNaoPublicada(): void
    {
        $obraId = $this->criarObraDiretamenteNoBanco("editorA");

        $cookie = $this->criarSessao("editorA");

        $resposta = $this->requisitarAtualizarBiblioteca(
            [
                "obra_id" => $obraId,
                "acao" => "favorito",
                "ativo" => true
            ],
            $cookie
        );

        $this->assertSame(404, $resposta["status"]);

        $estado = $this->consultarEstadoBiblioteca(
            $this->usuarios["editorA"]["id"],
            $obraId
        );

        $this->assertNull($estado);
    }

    public function testBibliotecaAdicionaFavoritoEmObraPublicada(): void
    {
        $obraId = $this->criarObraDiretamenteNoBanco("editorA");

        $this->publicarObraParaBiblioteca($obraId);

        $cookie = $this->criarSessao("editorA");

        $resposta = $this->requisitarAtualizarBiblioteca(
            [
                "obra_id" => $obraId,
                "acao" => "favorito",
                "ativo" => true
            ],
            $cookie
        );

        $this->assertSame(200, $resposta["status"]);

        $dados = $this->decodificarResposta($resposta);

        $this->assertTrue($dados["sucesso"]);
        $this->assertSame($obraId, $dados["obra_id"]);
        $this->assertSame("favorito", $dados["acao"]);
        $this->assertTrue($dados["estado"]["favorito"]);
        $this->assertFalse($dados["estado"]["quero_explorar"]);
        $this->assertFalse($dados["estado"]["explorada"]);

        $estadoBanco = $this->consultarEstadoBiblioteca(
            $this->usuarios["editorA"]["id"],
            $obraId
        );

        $this->assertNotNull($estadoBanco);
        $this->assertSame(1, (int) $estadoBanco["favorito"]);
    }

    public function testBibliotecaPreservaOutrosEstadosAoAtualizarFavorito(): void
    {
        $obraId = $this->criarObraDiretamenteNoBanco("editorA");

        $this->publicarObraParaBiblioteca($obraId);

        $cookie = $this->criarSessao("editorA");

        // Primeiro adiciona a obra à lista de exploração.
        $explorar = $this->requisitarAtualizarBiblioteca(
            [
                "obra_id" => $obraId,
                "acao" => "quero_explorar",
                "ativo" => true
            ],
            $cookie
        );

        $this->assertSame(200, $explorar["status"]);

        // Depois adiciona aos favoritos.
        $favoritar = $this->requisitarAtualizarBiblioteca(
            [
                "obra_id" => $obraId,
                "acao" => "favorito",
                "ativo" => true
            ],
            $cookie
        );

        $this->assertSame(200, $favoritar["status"]);

        $dadosFavorito = $this->decodificarResposta($favoritar);

        $this->assertTrue($dadosFavorito["estado"]["favorito"]);
        $this->assertTrue($dadosFavorito["estado"]["quero_explorar"]);

        // Remove somente o favorito.
        $desfavoritar = $this->requisitarAtualizarBiblioteca(
            [
                "obra_id" => $obraId,
                "acao" => "favorito",
                "ativo" => false
            ],
            $cookie
        );

        $this->assertSame(200, $desfavoritar["status"]);

        $dadosDesfavorito = $this->decodificarResposta($desfavoritar);

        $this->assertFalse($dadosDesfavorito["estado"]["favorito"]);
        $this->assertTrue($dadosDesfavorito["estado"]["quero_explorar"]);

        $estadoBanco = $this->consultarEstadoBiblioteca(
            $this->usuarios["editorA"]["id"],
            $obraId
        );

        $this->assertNotNull($estadoBanco);
        $this->assertSame(0, (int) $estadoBanco["favorito"]);
        $this->assertSame(1, (int) $estadoBanco["quero_explorar"]);
    }

    public function testBibliotecaRemoveRegistroSemEstadosAtivos(): void
    {
        $obraId = $this->criarObraDiretamenteNoBanco("editorA");

        $this->publicarObraParaBiblioteca($obraId);

        $cookie = $this->criarSessao("editorA");

        // Adiciona o favorito.
        $this->requisitarAtualizarBiblioteca(
            [
                "obra_id" => $obraId,
                "acao" => "favorito",
                "ativo" => true
            ],
            $cookie
        );

        // Remove o favorito.
        $resposta = $this->requisitarAtualizarBiblioteca(
            [
                "obra_id" => $obraId,
                "acao" => "favorito",
                "ativo" => false
            ],
            $cookie
        );

        $this->assertSame(200, $resposta["status"]);

        $estadoBanco = $this->consultarEstadoBiblioteca(
            $this->usuarios["editorA"]["id"],
            $obraId
        );

        $this->assertNull(
            $estadoBanco,
            "O registro vazio deveria ter sido removido."
        );
    }

    public function testBibliotecaMantemEstadoSeparadoPorUsuario(): void
    {
        $obraId = $this->criarObraDiretamenteNoBanco("editorA");

        $this->publicarObraParaBiblioteca($obraId);

        $cookieA = $this->criarSessao("editorA");
        $cookieB = $this->criarSessao("editorB");

        $favorito = $this->requisitarAtualizarBiblioteca(
            [
                "obra_id" => $obraId,
                "acao" => "favorito",
                "ativo" => true
            ],
            $cookieA
        );

        $exploracao = $this->requisitarAtualizarBiblioteca(
            [
                "obra_id" => $obraId,
                "acao" => "quero_explorar",
                "ativo" => true
            ],
            $cookieB
        );

        $this->assertSame(200, $favorito["status"]);
        $this->assertSame(200, $exploracao["status"]);

        $estadoA = $this->consultarEstadoBiblioteca(
            $this->usuarios["editorA"]["id"],
            $obraId
        );

        $estadoB = $this->consultarEstadoBiblioteca(
            $this->usuarios["editorB"]["id"],
            $obraId
        );

        $this->assertNotNull($estadoA);
        $this->assertNotNull($estadoB);

        $this->assertSame(1, (int) $estadoA["favorito"]);
        $this->assertSame(0, (int) $estadoA["quero_explorar"]);

        $this->assertSame(0, (int) $estadoB["favorito"]);
        $this->assertSame(1, (int) $estadoB["quero_explorar"]);
    }

    public function testBibliotecaPreservaExploracaoJaConcluida(): void
    {
        $obraId = $this->criarObraDiretamenteNoBanco("editorA");

        $this->publicarObraParaBiblioteca($obraId);

        $usuarioId = $this->usuarios["editorA"]["id"];

        // Simula uma obra que já foi explorada.
        $stmt = $this->db->prepare(
            "INSERT INTO biblioteca_usuario
                (usuario_id, obra_id, favorito, quero_explorar, explorada_em)
             VALUES (?, ?, 0, 1, CURRENT_TIMESTAMP)"
        );

        $stmt->bind_param("ii", $usuarioId, $obraId);
        $stmt->execute();
        $stmt->close();

        $cookie = $this->criarSessao("editorA");

        // Desativa somente a opção quero_explorar.
        $resposta = $this->requisitarAtualizarBiblioteca(
            [
                "obra_id" => $obraId,
                "acao" => "quero_explorar",
                "ativo" => false
            ],
            $cookie
        );

        $this->assertSame(200, $resposta["status"]);

        $dados = $this->decodificarResposta($resposta);

        $this->assertFalse($dados["estado"]["favorito"]);
        $this->assertFalse($dados["estado"]["quero_explorar"]);
        $this->assertTrue($dados["estado"]["explorada"]);
        $this->assertNotNull($dados["estado"]["explorada_em"]);

        $estadoBanco = $this->consultarEstadoBiblioteca(
            $usuarioId,
            $obraId
        );

        $this->assertNotNull($estadoBanco);
        $this->assertNotNull($estadoBanco["explorada_em"]);
    }

    public function testBibliotecaNaoAtualizaObraExcluida(): void
    {
        $obraId = $this->criarObraDiretamenteNoBanco("editorA");

        $this->publicarObraParaBiblioteca($obraId);

        $stmt = $this->db->prepare(
            "UPDATE obras
             SET excluida_em = CURRENT_TIMESTAMP
             WHERE id = ?"
        );

        $stmt->bind_param("i", $obraId);
        $stmt->execute();
        $stmt->close();

        $cookie = $this->criarSessao("editorA");

        $resposta = $this->requisitarAtualizarBiblioteca(
            [
                "obra_id" => $obraId,
                "acao" => "favorito",
                "ativo" => true
            ],
            $cookie
        );

        $this->assertSame(404, $resposta["status"]);
    }
    /*
     * TESTES DE CONCLUIR.PHP DA BIBLIOTECA
     */

    private function requisitarConcluirBiblioteca(
        array $dados,
        ?string $cookie = null,
        ?string $origem = self::ORIGEM
    ): array {
        return $this->requisitarHttp(
            "POST",
            "/api/biblioteca/concluir.php",
            json_encode($dados, JSON_THROW_ON_ERROR),
            "application/json",
            $origem,
            $cookie
        );
    }

    public function testConcluirRecusaMetodoGet(): void
    {
        $resposta = $this->requisitarHttp(
            "GET",
            "/api/biblioteca/concluir.php"
        );

        $this->assertSame(405, $resposta["status"]);
    }

    public function testConcluirExigeSessao(): void
    {
        $resposta = $this->requisitarHttp(
            "POST",
            "/api/biblioteca/concluir.php",
            '{"obra_id":1,"etapas_concluidas":1}',
            "application/json",
            self::ORIGEM
        );

        $this->assertSame(401, $resposta["status"]);

        foreach ($resposta["headers"] as $cabecalho) {
            $this->assertFalse(
                stripos($cabecalho, "Set-Cookie:") === 0,
                "A API não deve criar uma sessão para visitantes."
            );
        }
    }

    public function testConcluirBloqueiaOrigemNaoAutorizada(): void
    {
        $cookie = $this->criarSessao("editorA");

        $resposta = $this->requisitarConcluirBiblioteca(
            [
                "obra_id" => 1,
                "etapas_concluidas" => 1
            ],
            $cookie,
            "http://site-malicioso.test"
        );

        $this->assertSame(403, $resposta["status"]);
    }

    public function testConcluirRecusaJsonInvalido(): void
    {
        $cookie = $this->criarSessao("editorA");

        $resposta = $this->requisitarHttp(
            "POST",
            "/api/biblioteca/concluir.php",
            "{json inválido",
            "application/json",
            self::ORIGEM,
            $cookie
        );

        $this->assertSame(400, $resposta["status"]);
    }

    public function testConcluirValidaCampos(): void
    {
        $cookie = $this->criarSessao("editorA");

        $casosInvalidos = [
            [
                "obra_id" => 0,
                "etapas_concluidas" => 1
            ],
            [
                "obra_id" => "abc",
                "etapas_concluidas" => 1
            ],
            [
                "obra_id" => 1,
                "etapas_concluidas" => 0
            ],
            [
                "obra_id" => 1,
                "etapas_concluidas" => "abc"
            ],
            [
                "obra_id" => 1,
                "etapas_concluidas" => []
            ]
        ];

        foreach ($casosInvalidos as $dados) {
            $resposta = $this->requisitarConcluirBiblioteca(
                $dados,
                $cookie
            );

            $this->assertSame(
                400,
                $resposta["status"],
                "A API aceitou dados inválidos."
            );
        }
    }

    public function testConcluirNaoAceitaObraNaoPublicada(): void
    {
        $obraId = $this->criarObraDiretamenteNoBanco("editorA");

        $cookie = $this->criarSessao("editorA");

        $resposta = $this->requisitarConcluirBiblioteca(
            [
                "obra_id" => $obraId,
                "etapas_concluidas" => 1
            ],
            $cookie
        );

        $this->assertSame(404, $resposta["status"]);

        $estado = $this->consultarEstadoBiblioteca(
            $this->usuarios["editorA"]["id"],
            $obraId
        );

        $this->assertNull($estado);
    }

    public function testConcluirNaoAceitaObraSemEtapas(): void
    {
        $obraId = $this->criarObraDiretamenteNoBanco("editorA");

        $this->publicarObraParaBiblioteca($obraId);

        $cookie = $this->criarSessao("editorA");

        $resposta = $this->requisitarConcluirBiblioteca(
            [
                "obra_id" => $obraId,
                "etapas_concluidas" => 1
            ],
            $cookie
        );

        $this->assertSame(409, $resposta["status"]);

        $estado = $this->consultarEstadoBiblioteca(
            $this->usuarios["editorA"]["id"],
            $obraId
        );

        $this->assertNull($estado);
    }

    public function testConcluirRejeitaQuantidadeDiferenteDeEtapas(): void
    {
        $obraId = $this->criarObraDiretamenteNoBanco("editorA");

        $this->publicarObraParaBiblioteca($obraId);

        $this->criarEtapaParaObra(
            $obraId,
            1,
            "Primeira etapa",
            "Texto da primeira etapa.",
            0.1,
            0.2,
            0.3,
            0.4
        );

        $this->criarEtapaParaObra(
            $obraId,
            2,
            "Segunda etapa",
            "Texto da segunda etapa.",
            0.2,
            0.3,
            0.4,
            0.5
        );

        $cookie = $this->criarSessao("editorA");

        // A obra tem duas etapas, mas o cliente informa apenas uma.
        $resposta = $this->requisitarConcluirBiblioteca(
            [
                "obra_id" => $obraId,
                "etapas_concluidas" => 1
            ],
            $cookie
        );

        $this->assertSame(409, $resposta["status"]);

        $estado = $this->consultarEstadoBiblioteca(
            $this->usuarios["editorA"]["id"],
            $obraId
        );

        $this->assertNull($estado);
    }

    public function testConcluirRegistraExploracaoComTodasEtapas(): void
    {
        $obraId = $this->criarObraDiretamenteNoBanco("editorA");

        $this->publicarObraParaBiblioteca($obraId);

        $this->criarEtapaParaObra(
            $obraId,
            1,
            "Primeira etapa",
            "Texto da primeira etapa.",
            0.1,
            0.2,
            0.3,
            0.4
        );

        $this->criarEtapaParaObra(
            $obraId,
            2,
            "Segunda etapa",
            "Texto da segunda etapa.",
            0.2,
            0.3,
            0.4,
            0.5
        );

        $cookie = $this->criarSessao("editorA");

        $resposta = $this->requisitarConcluirBiblioteca(
            [
                "obra_id" => $obraId,
                "etapas_concluidas" => 2
            ],
            $cookie
        );

        $this->assertSame(200, $resposta["status"]);

        $dados = $this->decodificarResposta($resposta);

        $this->assertTrue($dados["sucesso"]);
        $this->assertSame($obraId, $dados["obra_id"]);
        $this->assertTrue($dados["explorada"]);

        $this->assertSame(
            "Obra registrada em Já explorei.",
            $dados["mensagem"]
        );

        $estado = $this->consultarEstadoBiblioteca(
            $this->usuarios["editorA"]["id"],
            $obraId
        );

        $this->assertNotNull($estado);
        $this->assertNotNull($estado["explorada_em"]);
    }

    public function testConcluirPreservaFavoritoEQueroExplorar(): void
    {
        $obraId = $this->criarObraDiretamenteNoBanco("editorA");

        $this->publicarObraParaBiblioteca($obraId);

        $this->criarEtapaParaObra(
            $obraId,
            1,
            "Etapa de teste",
            "Texto da etapa.",
            0.1,
            0.2,
            0.3,
            0.4
        );

        $usuarioId = $this->usuarios["editorA"]["id"];

        // Prepara um registro que já possui os dois estados ativos.
        $stmt = $this->db->prepare(
            "INSERT INTO biblioteca_usuario
                (usuario_id, obra_id, favorito, quero_explorar)
             VALUES (?, ?, 1, 1)"
        );

        $stmt->bind_param("ii", $usuarioId, $obraId);
        $stmt->execute();
        $stmt->close();

        $cookie = $this->criarSessao("editorA");

        $resposta = $this->requisitarConcluirBiblioteca(
            [
                "obra_id" => $obraId,
                "etapas_concluidas" => 1
            ],
            $cookie
        );

        $this->assertSame(200, $resposta["status"]);

        $estado = $this->consultarEstadoBiblioteca(
            $usuarioId,
            $obraId
        );

        $this->assertNotNull($estado);
        $this->assertSame(1, (int) $estado["favorito"]);
        $this->assertSame(1, (int) $estado["quero_explorar"]);
        $this->assertNotNull($estado["explorada_em"]);
    }

    public function testConcluirMantemDataDaPrimeiraConclusao(): void
    {
        $obraId = $this->criarObraDiretamenteNoBanco("editorA");

        $this->publicarObraParaBiblioteca($obraId);

        $this->criarEtapaParaObra(
            $obraId,
            1,
            "Etapa de teste",
            "Texto da etapa.",
            0.1,
            0.2,
            0.3,
            0.4
        );

        $cookie = $this->criarSessao("editorA");

        $dados = [
            "obra_id" => $obraId,
            "etapas_concluidas" => 1
        ];

        $primeiraResposta = $this->requisitarConcluirBiblioteca(
            $dados,
            $cookie
        );

        $this->assertSame(200, $primeiraResposta["status"]);

        $estadoInicial = $this->consultarEstadoBiblioteca(
            $this->usuarios["editorA"]["id"],
            $obraId
        );

        $this->assertNotNull($estadoInicial);
        $this->assertNotNull($estadoInicial["explorada_em"]);

        $dataOriginal = $estadoInicial["explorada_em"];

        // Registra a conclusão novamente.
        $segundaResposta = $this->requisitarConcluirBiblioteca(
            $dados,
            $cookie
        );

        $this->assertSame(200, $segundaResposta["status"]);

        $estadoFinal = $this->consultarEstadoBiblioteca(
            $this->usuarios["editorA"]["id"],
            $obraId
        );

        $this->assertNotNull($estadoFinal);

        $this->assertSame(
            $dataOriginal,
            $estadoFinal["explorada_em"],
            "A repetição não deve substituir a data da primeira conclusão."
        );
    }

    public function testConcluirNaoAceitaObraExcluida(): void
    {
        $obraId = $this->criarObraDiretamenteNoBanco("editorA");

        $this->publicarObraParaBiblioteca($obraId);

        $stmt = $this->db->prepare(
            "UPDATE obras
             SET excluida_em = CURRENT_TIMESTAMP
             WHERE id = ?"
        );

        $stmt->bind_param("i", $obraId);
        $stmt->execute();
        $stmt->close();

        $cookie = $this->criarSessao("editorA");

        $resposta = $this->requisitarConcluirBiblioteca(
            [
                "obra_id" => $obraId,
                "etapas_concluidas" => 1
            ],
            $cookie
        );

        $this->assertSame(404, $resposta["status"]);
    }
    /*
     * TESTES DE ESTADO.PHP DA BIBLIOTECA
     */

    private function requisitarEstadoBiblioteca(
        string $consulta,
        ?string $cookie = null
    ): array {
        return $this->requisitarHttp(
            "GET",
            "/api/biblioteca/estado.php?" . $consulta,
            null,
            "application/json",
            self::ORIGEM,
            $cookie
        );
    }

    public function testEstadoBibliotecaExigeSessao(): void
    {
        $resposta = $this->requisitarEstadoBiblioteca("obra_id=1");

        $this->assertSame(401, $resposta["status"]);

        $dados = $this->decodificarResposta($resposta);

        $this->assertFalse($dados["sucesso"]);

        foreach ($resposta["headers"] as $cabecalho) {
            $this->assertFalse(
                stripos($cabecalho, "Set-Cookie:") === 0,
                "A API não deve criar uma sessão para visitantes."
            );
        }
    }

    public function testEstadoBibliotecaRecusaMetodoPost(): void
    {
        $resposta = $this->requisitarHttp(
            "POST",
            "/api/biblioteca/estado.php?obra_id=1",
            "{}",
            "application/json",
            self::ORIGEM
        );

        $this->assertSame(405, $resposta["status"]);
    }

    public function testEstadoBibliotecaRejeitaIdentificadorInvalido(): void
    {
        $cookie = $this->criarSessao("editorA");

        $respostaTexto = $this->requisitarEstadoBiblioteca(
            "obra_id=abc",
            $cookie
        );

        $this->assertSame(400, $respostaTexto["status"]);

        $respostaArray = $this->requisitarEstadoBiblioteca(
            "obra_id%5B%5D=1",
            $cookie
        );

        $this->assertSame(400, $respostaArray["status"]);
    }

    public function testEstadoBibliotecaNaoRetornaObraNaoPublicada(): void
    {
        $obraId = $this->criarObraDiretamenteNoBanco("editorA");

        $cookie = $this->criarSessao("editorA");

        $resposta = $this->requisitarEstadoBiblioteca(
            "obra_id=" . $obraId,
            $cookie
        );

        $this->assertSame(404, $resposta["status"]);
    }

    public function testEstadoBibliotecaRetornaEstadoInicialSemRegistro(): void
    {
        $obraId = $this->criarObraDiretamenteNoBanco("editorA");

        $this->publicarObraParaBiblioteca($obraId);

        $cookie = $this->criarSessao("editorA");

        $resposta = $this->requisitarEstadoBiblioteca(
            "obra_id=" . $obraId,
            $cookie
        );

        $this->assertSame(200, $resposta["status"]);

        $dados = $this->decodificarResposta($resposta);

        $this->assertTrue($dados["sucesso"]);
        $this->assertSame($obraId, $dados["obra_id"]);

        $this->assertFalse($dados["estado"]["favorito"]);
        $this->assertFalse($dados["estado"]["quero_explorar"]);
        $this->assertFalse($dados["estado"]["explorada"]);
        $this->assertNull($dados["estado"]["explorada_em"]);
    }

    public function testEstadoBibliotecaRetornaEstadosRegistrados(): void
    {
        $obraId = $this->criarObraDiretamenteNoBanco("editorA");

        $this->publicarObraParaBiblioteca($obraId);

        $usuarioId = $this->usuarios["editorA"]["id"];

        $stmt = $this->db->prepare(
            "INSERT INTO biblioteca_usuario
                (usuario_id, obra_id, favorito, quero_explorar, explorada_em)
             VALUES (?, ?, 1, 1, CURRENT_TIMESTAMP)"
        );

        $stmt->bind_param("ii", $usuarioId, $obraId);
        $stmt->execute();
        $stmt->close();

        $cookie = $this->criarSessao("editorA");

        $resposta = $this->requisitarEstadoBiblioteca(
            "obra_id=" . $obraId,
            $cookie
        );

        $this->assertSame(200, $resposta["status"]);

        $dados = $this->decodificarResposta($resposta);

        $this->assertTrue($dados["sucesso"]);
        $this->assertTrue($dados["estado"]["favorito"]);
        $this->assertTrue($dados["estado"]["quero_explorar"]);
        $this->assertTrue($dados["estado"]["explorada"]);
        $this->assertNotNull($dados["estado"]["explorada_em"]);
    }

    public function testEstadoBibliotecaMantemDadosSeparadosPorUsuario(): void
    {
        $obraId = $this->criarObraDiretamenteNoBanco("editorA");

        $this->publicarObraParaBiblioteca($obraId);

        $usuarioA = $this->usuarios["editorA"]["id"];

        // Somente o editor A possui a obra como favorita.
        $stmt = $this->db->prepare(
            "INSERT INTO biblioteca_usuario
                (usuario_id, obra_id, favorito, quero_explorar)
             VALUES (?, ?, 1, 0)"
        );

        $stmt->bind_param("ii", $usuarioA, $obraId);
        $stmt->execute();
        $stmt->close();

        $cookieA = $this->criarSessao("editorA");
        $cookieB = $this->criarSessao("editorB");

        // Editor B consulta a mesma obra.
        $respostaB = $this->requisitarEstadoBiblioteca(
            "obra_id=" . $obraId,
            $cookieB
        );

        $this->assertSame(200, $respostaB["status"]);

        $dadosB = $this->decodificarResposta($respostaB);

        $this->assertFalse($dadosB["estado"]["favorito"]);
        $this->assertFalse($dadosB["estado"]["quero_explorar"]);
        $this->assertFalse($dadosB["estado"]["explorada"]);

        // Editor A consulta a obra.
        $respostaA = $this->requisitarEstadoBiblioteca(
            "obra_id=" . $obraId,
            $cookieA
        );

        $this->assertSame(200, $respostaA["status"]);

        $dadosA = $this->decodificarResposta($respostaA);

        $this->assertTrue($dadosA["estado"]["favorito"]);
        $this->assertFalse($dadosA["estado"]["quero_explorar"]);
    }

    public function testVisitanteAutenticadoPodeConsultarEstado(): void
    {
        $obraId = $this->criarObraDiretamenteNoBanco("editorA");

        $this->publicarObraParaBiblioteca($obraId);

        $cookie = $this->criarSessao("visitante");

        $resposta = $this->requisitarEstadoBiblioteca(
            "obra_id=" . $obraId,
            $cookie
        );

        $this->assertSame(200, $resposta["status"]);

        $dados = $this->decodificarResposta($resposta);

        $this->assertTrue($dados["sucesso"]);
        $this->assertFalse($dados["estado"]["favorito"]);
    }

    public function testEstadoBibliotecaNaoRetornaObraExcluida(): void
    {
        $obraId = $this->criarObraDiretamenteNoBanco("editorA");

        $this->publicarObraParaBiblioteca($obraId);

        $stmt = $this->db->prepare(
            "UPDATE obras
             SET excluida_em = CURRENT_TIMESTAMP
             WHERE id = ?"
        );

        $stmt->bind_param("i", $obraId);
        $stmt->execute();
        $stmt->close();

        $cookie = $this->criarSessao("editorA");

        $resposta = $this->requisitarEstadoBiblioteca(
            "obra_id=" . $obraId,
            $cookie
        );

        $this->assertSame(404, $resposta["status"]);
    }
    /*
     * TESTES DE LISTAR.PHP DA BIBLIOTECA
     */

    private function requisitarListarBiblioteca(
        ?string $consulta = null,
        ?string $cookie = null
    ): array {
        $caminho = "/api/biblioteca/listar.php";

        if ($consulta !== null && $consulta !== "") {
            $caminho .= "?" . $consulta;
        }

        return $this->requisitarHttp(
            "GET",
            $caminho,
            null,
            "application/json",
            self::ORIGEM,
            $cookie
        );
    }

    private function criarRegistroBibliotecaParaListagem(
        int $usuarioId,
        int $obraId,
        bool $favorito,
        bool $queroExplorar,
        bool $explorada = false
    ): void {
        $valorFavorito = $favorito ? 1 : 0;
        $valorExplorar = $queroExplorar ? 1 : 0;
        $valorExplorada = $explorada ? 1 : 0;

        $stmt = $this->db->prepare(
            "INSERT INTO biblioteca_usuario
                (
                    usuario_id,
                    obra_id,
                    favorito,
                    quero_explorar,
                    explorada_em
                )
             VALUES (
                ?, ?, ?, ?,
                IF(? = 1, CURRENT_TIMESTAMP, NULL)
             )"
        );

        $stmt->bind_param(
            "iiiii",
            $usuarioId,
            $obraId,
            $valorFavorito,
            $valorExplorar,
            $valorExplorada
        );

        $stmt->execute();
        $stmt->close();
    }

    public function testListarBibliotecaExigeSessao(): void
    {
        $resposta = $this->requisitarListarBiblioteca();

        $this->assertSame(401, $resposta["status"]);

        $dados = $this->decodificarResposta($resposta);

        $this->assertFalse($dados["sucesso"]);

        foreach ($resposta["headers"] as $cabecalho) {
            $this->assertFalse(
                stripos($cabecalho, "Set-Cookie:") === 0,
                "A API não deve criar uma sessão para visitantes."
            );
        }
    }

    public function testListarBibliotecaRecusaMetodoPost(): void
    {
        $resposta = $this->requisitarHttp(
            "POST",
            "/api/biblioteca/listar.php",
            "{}",
            "application/json",
            self::ORIGEM
        );

        $this->assertSame(405, $resposta["status"]);
    }

    public function testListarBibliotecaRejeitaColecaoInvalida(): void
    {
        $cookie = $this->criarSessao("editorA");

        $resposta = $this->requisitarListarBiblioteca(
            "colecao=desconhecida",
            $cookie
        );

        $this->assertSame(400, $resposta["status"]);

        // O parâmetro também não pode ser enviado como array.
        $respostaArray = $this->requisitarListarBiblioteca(
            "colecao%5B%5D=favoritos",
            $cookie
        );

        $this->assertSame(400, $respostaArray["status"]);
    }

    public function testListarBibliotecaRetornaFavoritosDoUsuario(): void
    {
        $obraFavorita = $this->criarObraDiretamenteNoBanco("editorA");
        $obraNaoFavorita = $this->criarObraDiretamenteNoBanco("editorA");

        $this->publicarObraParaBiblioteca($obraFavorita);
        $this->publicarObraParaBiblioteca($obraNaoFavorita);

        $this->criarEtapaParaObra(
            $obraFavorita,
            1,
            "Primeira etapa",
            "Texto da primeira etapa.",
            0.1,
            0.2,
            0.3,
            0.4
        );

        $usuarioId = $this->usuarios["editorA"]["id"];

        $this->criarRegistroBibliotecaParaListagem(
            $usuarioId,
            $obraFavorita,
            true,
            false
        );

        $this->criarRegistroBibliotecaParaListagem(
            $usuarioId,
            $obraNaoFavorita,
            false,
            true
        );

        $cookie = $this->criarSessao("editorA");

        // Sem informar colecao, a API deve usar favoritos.
        $resposta = $this->requisitarListarBiblioteca(
            null,
            $cookie
        );

        $this->assertSame(200, $resposta["status"]);

        $dados = $this->decodificarResposta($resposta);

        $this->assertTrue($dados["sucesso"]);
        $this->assertSame("favoritos", $dados["colecao"]);
        $this->assertCount(1, $dados["obras"]);

        $obra = $dados["obras"][0];

        $this->assertSame($obraFavorita, $obra["id"]);
        $this->assertTrue($obra["favorito"]);
        $this->assertFalse($obra["quero_explorar"]);
        $this->assertNull($obra["explorada_em"]);

        $this->assertSame(
            1,
            $obra["total_etapas"]
        );
        $this->assertSame(
            "../../api/obras/imagem-publica.php?id=" . $obraFavorita,
            $obra["imagem_url"]
        );

        $this->assertArrayNotHasKey("url_exploracao", $obra);
    }

    public function testListarBibliotecaRetornaQueroExplorar(): void
    {
        $obraSelecionada = $this->criarObraDiretamenteNoBanco("editorA");
        $obraNaoSelecionada = $this->criarObraDiretamenteNoBanco("editorA");

        $this->publicarObraParaBiblioteca($obraSelecionada);
        $this->publicarObraParaBiblioteca($obraNaoSelecionada);

        $usuarioId = $this->usuarios["editorA"]["id"];

        $this->criarRegistroBibliotecaParaListagem(
            $usuarioId,
            $obraSelecionada,
            false,
            true
        );

        $this->criarRegistroBibliotecaParaListagem(
            $usuarioId,
            $obraNaoSelecionada,
            true,
            false
        );

        $cookie = $this->criarSessao("editorA");

        $resposta = $this->requisitarListarBiblioteca(
            "colecao=quero_explorar",
            $cookie
        );

        $this->assertSame(200, $resposta["status"]);

        $dados = $this->decodificarResposta($resposta);

        $this->assertTrue($dados["sucesso"]);
        $this->assertSame("quero_explorar", $dados["colecao"]);
        $this->assertCount(1, $dados["obras"]);

        $this->assertSame(
            $obraSelecionada,
            $dados["obras"][0]["id"]
        );

        $this->assertTrue(
            $dados["obras"][0]["quero_explorar"]
        );

        $this->assertFalse(
            $dados["obras"][0]["favorito"]
        );
    }

    public function testListarBibliotecaRetornaExploradas(): void
    {
        $obraExplorada = $this->criarObraDiretamenteNoBanco("editorA");
        $obraPendente = $this->criarObraDiretamenteNoBanco("editorA");

        $this->publicarObraParaBiblioteca($obraExplorada);
        $this->publicarObraParaBiblioteca($obraPendente);

        $usuarioId = $this->usuarios["editorA"]["id"];

        $this->criarRegistroBibliotecaParaListagem(
            $usuarioId,
            $obraExplorada,
            false,
            false,
            true
        );

        $this->criarRegistroBibliotecaParaListagem(
            $usuarioId,
            $obraPendente,
            false,
            true,
            false
        );

        $cookie = $this->criarSessao("editorA");

        $resposta = $this->requisitarListarBiblioteca(
            "colecao=exploradas",
            $cookie
        );

        $this->assertSame(200, $resposta["status"]);

        $dados = $this->decodificarResposta($resposta);

        $this->assertTrue($dados["sucesso"]);
        $this->assertSame("exploradas", $dados["colecao"]);
        $this->assertCount(1, $dados["obras"]);

        $obra = $dados["obras"][0];

        $this->assertSame($obraExplorada, $obra["id"]);
        $this->assertNotNull($obra["explorada_em"]);
    }

    public function testListarBibliotecaMantemDadosSeparadosPorUsuario(): void
    {
        $obraId = $this->criarObraDiretamenteNoBanco("editorA");

        $this->publicarObraParaBiblioteca($obraId);

        $usuarioA = $this->usuarios["editorA"]["id"];
        $usuarioB = $this->usuarios["editorB"]["id"];

        $this->criarRegistroBibliotecaParaListagem(
            $usuarioA,
            $obraId,
            true,
            false
        );

        $this->criarRegistroBibliotecaParaListagem(
            $usuarioB,
            $obraId,
            false,
            true
        );

        $cookieA = $this->criarSessao("editorA");
        $cookieB = $this->criarSessao("editorB");

        // Editor A consulta favoritos.
        $respostaA = $this->requisitarListarBiblioteca(
            "colecao=favoritos",
            $cookieA
        );

        $this->assertSame(200, $respostaA["status"]);

        $dadosA = $this->decodificarResposta($respostaA);

        $this->assertCount(1, $dadosA["obras"]);
        $this->assertSame($obraId, $dadosA["obras"][0]["id"]);

        // Editor B não deve encontrar a mesma obra nos favoritos.
        $respostaBFavoritos = $this->requisitarListarBiblioteca(
            "colecao=favoritos",
            $cookieB
        );

        $this->assertSame(200, $respostaBFavoritos["status"]);

        $dadosBFavoritos = $this->decodificarResposta(
            $respostaBFavoritos
        );

        $this->assertCount(0, $dadosBFavoritos["obras"]);

        // Mas deve encontrá-la em sua própria lista de exploração.
        $respostaBExplorar = $this->requisitarListarBiblioteca(
            "colecao=quero_explorar",
            $cookieB
        );

        $this->assertSame(200, $respostaBExplorar["status"]);

        $dadosBExplorar = $this->decodificarResposta(
            $respostaBExplorar
        );

        $this->assertCount(1, $dadosBExplorar["obras"]);
        $this->assertSame($obraId, $dadosBExplorar["obras"][0]["id"]);
    }

    public function testListarBibliotecaNaoRetornaObrasIndisponiveis(): void
    {
        $obraExcluida = $this->criarObraDiretamenteNoBanco("editorA");
        $obraNaoPublicada = $this->criarObraDiretamenteNoBanco("editorA");
        $obraDisponivel = $this->criarObraDiretamenteNoBanco("editorA");

        $this->publicarObraParaBiblioteca($obraExcluida);
        $this->publicarObraParaBiblioteca($obraDisponivel);

        $usuarioId = $this->usuarios["editorA"]["id"];

        $this->criarRegistroBibliotecaParaListagem(
            $usuarioId,
            $obraExcluida,
            true,
            false
        );

        $this->criarRegistroBibliotecaParaListagem(
            $usuarioId,
            $obraNaoPublicada,
            true,
            false
        );

        $this->criarRegistroBibliotecaParaListagem(
            $usuarioId,
            $obraDisponivel,
            true,
            false
        );

        // Exclui logicamente uma obra que estava publicada.
        $stmt = $this->db->prepare(
            "UPDATE obras
             SET excluida_em = CURRENT_TIMESTAMP
             WHERE id = ?"
        );

        $stmt->bind_param("i", $obraExcluida);
        $stmt->execute();
        $stmt->close();

        $cookie = $this->criarSessao("editorA");

        $resposta = $this->requisitarListarBiblioteca(
            "colecao=favoritos",
            $cookie
        );

        $this->assertSame(200, $resposta["status"]);

        $dados = $this->decodificarResposta($resposta);

        $this->assertTrue($dados["sucesso"]);
        $this->assertCount(1, $dados["obras"]);

        $this->assertSame(
            $obraDisponivel,
            $dados["obras"][0]["id"]
        );

        $idsRetornados = array_column($dados["obras"], "id");

        $this->assertNotContains($obraExcluida, $idsRetornados);
        $this->assertNotContains($obraNaoPublicada, $idsRetornados);
    }
    /*
     * AUXILIARES PARA TESTES DE IMAGEM DA BIBLIOTECA
     */

    private function imagemPngValidaParaTeste(): string
    {
        $imagem = base64_decode(
            "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADUlEQVR4nGP4z8AAAAMBAQDJ/pLvAAAAAElFTkSuQmCC",
            true
        );

        $this->assertIsString($imagem);

        return $imagem;
    }

    private function imagemJpegValidaParaTeste(): string
    {
        $base64 =
            "/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAYEBQYFBAYGBwYHBwYIChAKCgkJChQODwwQFxQYGBcUFhYaHSUfGhsjHBYWICwgIyYnKSopGR8tMC0oMCUoKSj/"
            . "2wBDAQcHBwoIChMKChMoGhYaKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCj/wAARCAABAAEDASIAAhEBAxEB/"
            . "8QAHwAAAQUBAQEBAQEAAAAAAAAAAAECAwQFBgcICQoL/8QAtRAAAgEDAwIEAwUFBAQAAAF9AQIDAAQRBRIhMUEGE1FhByJxFDKBkaEII0KxwRVS0fAkM2Jygg"
            . "kKFhcYGRolJicoKSo0NTY3ODk6Q0RFRkdISUpTVFVWV1hZWmNkZWZnaGlqc3R1dnd4eXqDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uHi4+Tl5ufo6erx8vP09fb3+Pn6/8QAHwEAAwEBAQEBAQEAAAAAAAAECAwQFBgcICQoL/8QAtREAAgECBAQDBAcFBAQAAQJ3AAECAxEEBSExBhJBUQdhcRMiMoEIFEKRobHBCSMzUvAVYnLRChYkNOEl8RcYGRomJygpKjU2Nzg5OkNERUZHSElKU1RVFVWV1hZWmNkZWZnaGlqc3R1dnd4eXqDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uHi4+Tl5ufo6erx8vP09fb3+Pn6/9oADAMBAAIRAxEAPwDzqiiivjj+kT//2Q==";

        $imagem = base64_decode($base64, true);

        $this->assertIsString($imagem);

        return $imagem;
    }
    private function imagemWebpValidaParaTeste(): string
    {
        $imagem = base64_decode(
            "UklGRjwAAABXRUJQVlA4IDAAAADQAQCdASoBAAEAAUAmJaACdLoB+AADsAD+8ut//NgVzXPv9//S4P0uD9Lg/9KQAAA=",
            true
        );

        $this->assertIsString($imagem);

        return $imagem;
    }

    private function gravarImagemParaTeste(
        int $obraId,
        string $imagem,
        string $mime
    ): void {
        $stmt = $this->db->prepare(
            "UPDATE obras
             SET imagem = ?, imagem_mime = ?
             WHERE id = ?"
        );

        $stmt->bind_param(
            "ssi",
            $imagem,
            $mime,
            $obraId
        );

        $stmt->execute();
        $stmt->close();
    }

    private function obterCabecalhoImagem(
        array $resposta,
        string $nome
    ): ?string {
        foreach ($resposta["headers"] as $cabecalho) {
            if (stripos($cabecalho, $nome . ":") === 0) {
                return trim(
                    substr($cabecalho, strlen($nome) + 1)
                );
            }
        }

        return null;
    }

    private function requisitarImagemBiblioteca(
        string $consulta
    ): array {
        return $this->requisitarHttp(
            "GET",
            "/api/obras/imagem-publica.php?" . $consulta
        );
    }
    /*
     * TESTES DE IMAGEM.PHP DA BIBLIOTECA
     */
    public function testImagemBibliotecaEntregaJpegPublicoSemSessao(): void
    {
        $obraId = $this->criarObraDiretamenteNoBanco("editorA");

        $this->publicarObraParaBiblioteca($obraId);

        $imagem = $this->imagemJpegValidaParaTeste();

        $this->gravarImagemParaTeste(
            $obraId,
            $imagem,
            "image/jpeg"
        );

        $resposta = $this->requisitarImagemBiblioteca(
            "id=" . $obraId
        );

        $this->assertSame(200, $resposta["status"]);
        $this->assertSame($imagem, $resposta["body"]);

        $this->assertSame(
            "image/jpeg",
            $this->obterCabecalhoImagem($resposta, "Content-Type")
        );
    }

    public function testImagemBibliotecaEntregaWebpPublicoSemSessao(): void
    {
        $obraId = $this->criarObraDiretamenteNoBanco("editorA");

        $this->publicarObraParaBiblioteca($obraId);

        $imagem = $this->imagemWebpValidaParaTeste();

        $this->gravarImagemParaTeste(
            $obraId,
            $imagem,
            "image/webp"
        );

        $resposta = $this->requisitarImagemBiblioteca(
            "id=" . $obraId
        );

        $this->assertSame(200, $resposta["status"]);
        $this->assertSame($imagem, $resposta["body"]);

        $this->assertSame(
            "image/webp",
            $this->obterCabecalhoImagem($resposta, "Content-Type")
        );
    }
    public function testImagemBibliotecaRecusaMetodoPost(): void
    {
        $resposta = $this->requisitarHttp(
            "POST",
            "/api/obras/imagem-publica.php",
            "{}",
            "application/json",
            self::ORIGEM
        );

        $this->assertSame(405, $resposta["status"]);
    }

    public function testImagemBibliotecaRejeitaIdInvalido(): void
    {
        $respostaTexto = $this->requisitarImagemBiblioteca(
            "id=abc"
        );

        $this->assertSame(400, $respostaTexto["status"]);

        $respostaArray = $this->requisitarImagemBiblioteca(
            "id%5B%5D=1"
        );

        $this->assertSame(400, $respostaArray["status"]);
    }

    public function testImagemBibliotecaRetorna404ParaIdInexistente(): void
    {
        $idInexistente = $this->obterIdInexistente();

        $resposta = $this->requisitarImagemBiblioteca(
            "id=" . $idInexistente
        );

        $this->assertSame(404, $resposta["status"]);
    }

    public function testImagemBibliotecaNaoEntregaObraNaoPublicada(): void
    {
        $obraId = $this->criarObraDiretamenteNoBanco("editorA");

        $this->gravarImagemParaTeste(
            $obraId,
            $this->imagemPngValidaParaTeste(),
            "image/png"
        );

        $resposta = $this->requisitarImagemBiblioteca(
            "id=" . $obraId
        );

        $this->assertSame(404, $resposta["status"]);
    }

    public function testImagemBibliotecaNaoEntregaObraExcluida(): void
    {
        $obraId = $this->criarObraDiretamenteNoBanco("editorA");

        $this->publicarObraParaBiblioteca($obraId);

        $this->gravarImagemParaTeste(
            $obraId,
            $this->imagemPngValidaParaTeste(),
            "image/png"
        );

        $stmt = $this->db->prepare(
            "UPDATE obras
             SET excluida_em = CURRENT_TIMESTAMP
             WHERE id = ?"
        );

        $stmt->bind_param("i", $obraId);
        $stmt->execute();
        $stmt->close();

        $resposta = $this->requisitarImagemBiblioteca(
            "id=" . $obraId
        );

        $this->assertSame(404, $resposta["status"]);
    }

    public function testImagemBibliotecaRetorna404SemImagem(): void
    {
        $obraId = $this->criarObraDiretamenteNoBanco("editorA");

        $this->publicarObraParaBiblioteca($obraId);

        $resposta = $this->requisitarImagemBiblioteca(
            "id=" . $obraId
        );

        $this->assertSame(404, $resposta["status"]);
    }

    public function testImagemBibliotecaEntregaPngPublicoSemSessao(): void
    {
        $obraId = $this->criarObraDiretamenteNoBanco("editorA");

        $this->publicarObraParaBiblioteca($obraId);

        $imagem = $this->imagemPngValidaParaTeste();

        $this->gravarImagemParaTeste(
            $obraId,
            $imagem,
            "image/png"
        );

        // A requisição não envia cookie de autenticação.
        $resposta = $this->requisitarImagemBiblioteca(
            "id=" . $obraId
        );

        $this->assertSame(200, $resposta["status"]);

        $this->assertSame(
            $imagem,
            $resposta["body"]
        );

        $this->assertSame(
            "image/png",
            $this->obterCabecalhoImagem(
                $resposta,
                "Content-Type"
            )
        );

        $this->assertSame(
            (string) strlen($imagem),
            $this->obterCabecalhoImagem(
                $resposta,
                "Content-Length"
            )
        );

        $this->assertSame(
            "nosniff",
            $this->obterCabecalhoImagem(
                $resposta,
                "X-Content-Type-Options"
            )
        );

        $this->assertSame(
            "inline",
            $this->obterCabecalhoImagem(
                $resposta,
                "Content-Disposition"
            )
        );

        $this->assertSame(
            "no-store",
            $this->obterCabecalhoImagem(
                $resposta,
                "Cache-Control"
            )
        );

        foreach ($resposta["headers"] as $cabecalho) {
            $this->assertFalse(
                stripos($cabecalho, "Set-Cookie:") === 0,
                "A imagem pública não deve criar uma sessão."
            );
        }
    }

    public function testImagemBibliotecaRejeitaMimeNaoPermitido(): void
    {
        $obraId = $this->criarObraDiretamenteNoBanco("editorA");

        $this->publicarObraParaBiblioteca($obraId);

        $this->gravarImagemParaTeste(
            $obraId,
            $this->imagemPngValidaParaTeste(),
            "image/gif"
        );

        $resposta = $this->requisitarImagemBiblioteca(
            "id=" . $obraId
        );

        $this->assertSame(415, $resposta["status"]);
    }

    public function testImagemBibliotecaRejeitaMimeIncompativel(): void
    {
        $obraId = $this->criarObraDiretamenteNoBanco("editorA");

        $this->publicarObraParaBiblioteca($obraId);

        // Conteúdo PNG, mas MIME declarado como JPEG.
        $this->gravarImagemParaTeste(
            $obraId,
            $this->imagemPngValidaParaTeste(),
            "image/jpeg"
        );

        $resposta = $this->requisitarImagemBiblioteca(
            "id=" . $obraId
        );

        $this->assertSame(415, $resposta["status"]);
    }

    /*
     * TESTES DO ENDPOINT DE IMAGEM DAS OBRAS
     */

    public function testImagemObraRecusaMetodoPost(): void
    {
        $resposta = $this->requisitarHttp(
            "POST",
            "/api/obras/imagem.php",
            "{}",
            "application/json",
            self::ORIGEM
        );

        $this->assertSame(405, $resposta["status"]);
    }

    public function testImagemObraRejeitaIdInvalido(): void
    {
        $respostaTexto = $this->requisitarImagemObra(
            "id=abc"
        );

        $this->assertSame(400, $respostaTexto["status"]);

        $respostaArray = $this->requisitarImagemObra(
            "id%5B%5D=1"
        );

        $this->assertSame(400, $respostaArray["status"]);
    }

    public function testImagemObraRetorna404ParaIdInexistente(): void
    {
        $idInexistente = $this->obterIdInexistente();

        $resposta = $this->requisitarImagemObra(
            "id=" . $idInexistente
        );

        $this->assertSame(404, $resposta["status"]);
    }

    public function testImagemObraNaoEntregaObraExcluida(): void
    {
        $obraId = $this->criarObraDiretamenteNoBanco("editorA");

        $this->gravarImagemParaTeste(
            $obraId,
            $this->imagemPngValidaParaTeste(),
            "image/png"
        );

        $stmt = $this->db->prepare(
            "UPDATE obras
             SET excluida_em = CURRENT_TIMESTAMP
             WHERE id = ?"
        );

        $stmt->bind_param("i", $obraId);
        $stmt->execute();
        $stmt->close();

        $resposta = $this->requisitarImagemObra(
            "id=" . $obraId
        );

        $this->assertSame(404, $resposta["status"]);
    }

    public function testImagemObraRetorna404SemImagem(): void
    {
        $obraId = $this->criarObraDiretamenteNoBanco("editorA");

        $this->publicarObraParaBiblioteca($obraId);

        $resposta = $this->requisitarImagemObra(
            "id=" . $obraId
        );

        $this->assertSame(404, $resposta["status"]);
    }

    public function testImagemObraEntregaImagemPublicadaSemSessao(): void
    {
        $obraId = $this->criarObraDiretamenteNoBanco("editorA");

        $this->publicarObraParaBiblioteca($obraId);

        $imagem = $this->imagemPngValidaParaTeste();

        $this->gravarImagemParaTeste(
            $obraId,
            $imagem,
            "image/png"
        );

        $resposta = $this->requisitarImagemObra(
            "id=" . $obraId
        );

        $this->assertSame(200, $resposta["status"]);

        $this->assertSame(
            $imagem,
            $resposta["body"]
        );

        $this->assertSame(
            "image/png",
            $this->obterCabecalhoImagem(
                $resposta,
                "Content-Type"
            )
        );

        $this->assertSame(
            (string) strlen($imagem),
            $this->obterCabecalhoImagem(
                $resposta,
                "Content-Length"
            )
        );

        $this->assertSame(
            "inline",
            $this->obterCabecalhoImagem(
                $resposta,
                "Content-Disposition"
            )
        );

        $this->assertSame(
            "nosniff",
            $this->obterCabecalhoImagem(
                $resposta,
                "X-Content-Type-Options"
            )
        );

        $this->assertSame(
            "public, max-age=3600",
            $this->obterCabecalhoImagem(
                $resposta,
                "Cache-Control"
            )
        );

        foreach ($resposta["headers"] as $cabecalho) {
            $this->assertFalse(
                stripos($cabecalho, "Set-Cookie:") === 0,
                "Obra publicada sem login nao deve criar sessao."
            );
        }
    }

    public function testImagemObraExigeSessaoParaRascunho(): void
    {
        $obraId = $this->criarObraDiretamenteNoBanco("editorA");

        $this->gravarImagemParaTeste(
            $obraId,
            $this->imagemPngValidaParaTeste(),
            "image/png"
        );

        $resposta = $this->requisitarImagemObra(
            "id=" . $obraId
        );

        $this->assertSame(401, $resposta["status"]);
    }

    public function testImagemObraBloqueiaVisitanteNoRascunho(): void
    {
        $obraId = $this->criarObraDiretamenteNoBanco("editorA");

        $this->gravarImagemParaTeste(
            $obraId,
            $this->imagemPngValidaParaTeste(),
            "image/png"
        );

        $cookie = $this->criarSessao("visitante");

        $resposta = $this->requisitarImagemObra(
            "id=" . $obraId,
            $cookie
        );

        $this->assertSame(403, $resposta["status"]);
    }

    public function testImagemObraEditorAcessaProprioRascunho(): void
    {
        $obraId = $this->criarObraDiretamenteNoBanco("editorA");

        $imagem = $this->imagemPngValidaParaTeste();

        $this->gravarImagemParaTeste(
            $obraId,
            $imagem,
            "image/png"
        );

        $cookie = $this->criarSessao("editorA");

        $resposta = $this->requisitarImagemObra(
            "id=" . $obraId,
            $cookie
        );

        $this->assertSame(200, $resposta["status"]);

        $this->assertSame($imagem, $resposta["body"]);

        $this->assertSame(
            "private, no-store, max-age=0",
            $this->obterCabecalhoImagem(
                $resposta,
                "Cache-Control"
            )
        );
    }

    public function testImagemObraBloqueiaOutroEditorNoRascunho(): void
    {
        $obraId = $this->criarObraDiretamenteNoBanco("editorB");

        $this->gravarImagemParaTeste(
            $obraId,
            $this->imagemPngValidaParaTeste(),
            "image/png"
        );

        $cookie = $this->criarSessao("editorA");

        $resposta = $this->requisitarImagemObra(
            "id=" . $obraId,
            $cookie
        );

        $this->assertSame(403, $resposta["status"]);
    }

    public function testImagemObraAdministradorAcessaRascunhoDeOutroEditor(): void
    {
        $obraId = $this->criarObraDiretamenteNoBanco("editorB");

        $imagem = $this->imagemPngValidaParaTeste();

        $this->gravarImagemParaTeste(
            $obraId,
            $imagem,
            "image/png"
        );

        $cookie = $this->criarSessao("admin");

        $resposta = $this->requisitarImagemObra(
            "id=" . $obraId,
            $cookie
        );

        $this->assertSame(200, $resposta["status"]);

        $this->assertSame($imagem, $resposta["body"]);
    }
    public function testImagemObraEditorEntregaJpegNoRascunho(): void
    {
        $obraId = $this->criarObraDiretamenteNoBanco("editorA");

        $imagem = $this->imagemJpegValidaParaTeste();

        $this->gravarImagemParaTeste(
            $obraId,
            $imagem,
            "image/jpeg"
        );

        $cookie = $this->criarSessao("editorA");

        $resposta = $this->requisitarImagemObra(
            "id=" . $obraId,
            $cookie
        );

        $this->assertSame(200, $resposta["status"]);
        $this->assertSame($imagem, $resposta["body"]);

        $this->assertSame(
            "image/jpeg",
            $this->obterCabecalhoImagem($resposta, "Content-Type")
        );
    }

    public function testImagemObraEditorEntregaWebpNoRascunho(): void
    {
        $obraId = $this->criarObraDiretamenteNoBanco("editorA");

        $imagem = $this->imagemWebpValidaParaTeste();

        $this->gravarImagemParaTeste(
            $obraId,
            $imagem,
            "image/webp"
        );

        $cookie = $this->criarSessao("editorA");

        $resposta = $this->requisitarImagemObra(
            "id=" . $obraId,
            $cookie
        );

        $this->assertSame(200, $resposta["status"]);
        $this->assertSame($imagem, $resposta["body"]);

        $this->assertSame(
            "image/webp",
            $this->obterCabecalhoImagem($resposta, "Content-Type")
        );
    }
    public function testImagemObraRejeitaMimeNaoPermitido(): void
    {
        $obraId = $this->criarObraDiretamenteNoBanco("editorA");

        $this->publicarObraParaBiblioteca($obraId);

        $this->gravarImagemParaTeste(
            $obraId,
            $this->imagemPngValidaParaTeste(),
            "image/gif"
        );

        $resposta = $this->requisitarImagemObra(
            "id=" . $obraId
        );

        $this->assertSame(415, $resposta["status"]);
    }

    public function testImagemObraRejeitaMimeIncompativel(): void
    {
        $obraId = $this->criarObraDiretamenteNoBanco("editorA");

        $this->publicarObraParaBiblioteca($obraId);

        // Conteudo PNG declarado incorretamente como JPEG.
        $this->gravarImagemParaTeste(
            $obraId,
            $this->imagemPngValidaParaTeste(),
            "image/jpeg"
        );

        $resposta = $this->requisitarImagemObra(
            "id=" . $obraId
        );

        $this->assertSame(415, $resposta["status"]);
    }

    /*
     * AUXILIARES PARA TESTES DE PUBLICADAS.PHP
     */

    private function prepararObraPublicadaCompleta(
        string $chave
    ): int {
        $obraId = $this->criarObraDiretamenteNoBanco($chave);

        $this->publicarObraParaBiblioteca($obraId);

        $this->gravarImagemParaTeste(
            $obraId,
            $this->imagemPngValidaParaTeste(),
            "image/png"
        );

        $this->criarEtapaParaObra(
            $obraId,
            1,
            "Primeira etapa",
            "Texto da primeira etapa.",
            0.1,
            0.2,
            0.3,
            0.4
        );

        return $obraId;
    }

    private function definirDataPublicacaoTeste(
        int $obraId,
        string $data
    ): void {
        $stmt = $this->db->prepare(
            "UPDATE obras
             SET publicada_em = ?
             WHERE id = ?"
        );

        $stmt->bind_param("si", $data, $obraId);
        $stmt->execute();
        $stmt->close();
    }

    private function requisitarObrasPublicadas(): array
    {
        return $this->requisitarHttp(
            "GET",
            "/api/obras/publicadas.php"
        );
    }

    /*
     * TESTES DE PUBLICADAS.PHP
     */

    public function testPublicadasRecusaMetodoPost(): void
    {
        $resposta = $this->requisitarHttp(
            "POST",
            "/api/obras/publicadas.php",
            "{}",
            "application/json",
            self::ORIGEM
        );

        $this->assertSame(405, $resposta["status"]);
    }

    public function testPublicadasPermiteAcessoSemSessao(): void
    {
        $resposta = $this->requisitarObrasPublicadas();

        $this->assertSame(200, $resposta["status"]);

        $dados = $this->decodificarResposta($resposta);

        $this->assertTrue($dados["sucesso"]);
        $this->assertIsInt($dados["total"]);
        $this->assertIsArray($dados["obras"]);

        $this->assertSame(
            $dados["total"],
            count($dados["obras"])
        );

        foreach ($resposta["headers"] as $cabecalho) {
            $this->assertFalse(
                stripos($cabecalho, "Set-Cookie:") === 0,
                "A API pública não deve criar uma sessão."
            );
        }
    }

    public function testPublicadasRetornaSomenteObrasCompletas(): void
    {
        // Obra válida: publicada, com imagem e etapa.
        $obraValida = $this->prepararObraPublicadaCompleta("editorA");

        // Obra em rascunho, mesmo possuindo imagem e etapa.
        $obraRascunho = $this->criarObraDiretamenteNoBanco("editorA");

        $this->gravarImagemParaTeste(
            $obraRascunho,
            $this->imagemPngValidaParaTeste(),
            "image/png"
        );

        $this->criarEtapaParaObra(
            $obraRascunho,
            1,
            "Etapa",
            "Texto da etapa.",
            0.1,
            0.2,
            0.3,
            0.4
        );

        // Obra publicada, mas sem imagem.
        $obraSemImagem = $this->criarObraDiretamenteNoBanco("editorA");

        $this->publicarObraParaBiblioteca($obraSemImagem);

        $this->criarEtapaParaObra(
            $obraSemImagem,
            1,
            "Etapa",
            "Texto da etapa.",
            0.1,
            0.2,
            0.3,
            0.4
        );

        // Obra publicada e com imagem, mas sem etapas.
        $obraSemEtapas = $this->criarObraDiretamenteNoBanco("editorA");

        $this->publicarObraParaBiblioteca($obraSemEtapas);

        $this->gravarImagemParaTeste(
            $obraSemEtapas,
            $this->imagemPngValidaParaTeste(),
            "image/png"
        );

        // Obra completa, mas excluída logicamente.
        $obraExcluida = $this->prepararObraPublicadaCompleta("editorA");

        $stmt = $this->db->prepare(
            "UPDATE obras
             SET excluida_em = CURRENT_TIMESTAMP
             WHERE id = ?"
        );

        $stmt->bind_param("i", $obraExcluida);
        $stmt->execute();
        $stmt->close();

        $resposta = $this->requisitarObrasPublicadas();

        $this->assertSame(200, $resposta["status"]);

        $dados = $this->decodificarResposta($resposta);

        $this->assertTrue($dados["sucesso"]);

        $idsRetornados = array_column($dados["obras"], "id");

        $this->assertContains($obraValida, $idsRetornados);
        $this->assertNotContains($obraRascunho, $idsRetornados);
        $this->assertNotContains($obraSemImagem, $idsRetornados);
        $this->assertNotContains($obraSemEtapas, $idsRetornados);
        $this->assertNotContains($obraExcluida, $idsRetornados);
    }

    public function testPublicadasRetornaDadosCorretosDaObra(): void
    {
        $obraId = $this->prepararObraPublicadaCompleta("editorA");

        $titulo = "Obra para teste do catálogo";
        $autor = "Autor de teste";
        $textoAlternativo = "Descrição acessível da obra.";
        $dataPublicacao = "2026-05-10 14:30:00";

        $stmt = $this->db->prepare(
            "UPDATE obras
             SET titulo = ?,
                 autor = ?,
                 texto_alternativo = ?,
                 publicada_em = ?
             WHERE id = ?"
        );

        $stmt->bind_param(
            "ssssi",
            $titulo,
            $autor,
            $textoAlternativo,
            $dataPublicacao,
            $obraId
        );

        $stmt->execute();
        $stmt->close();

        $resposta = $this->requisitarObrasPublicadas();

        $this->assertSame(200, $resposta["status"]);

        $dados = $this->decodificarResposta($resposta);

        $this->assertTrue($dados["sucesso"]);
        $this->assertSame(count($dados["obras"]), $dados["total"]);

        $obrasEncontradas = array_values(
            array_filter(
                $dados["obras"],
                fn(array $obra): bool => $obra["id"] === $obraId
            )
        );

        $this->assertCount(1, $obrasEncontradas);

        $obra = $obrasEncontradas[0];

        $this->assertSame($titulo, $obra["titulo"]);
        $this->assertSame($autor, $obra["autor"]);
        $this->assertSame($textoAlternativo, $obra["texto_alternativo"]);
        $this->assertSame($dataPublicacao, $obra["publicada_em"]);
        $this->assertSame(1, $obra["total_etapas"]);
    }

    public function testPublicadasOrdenaObrasMaisRecentesPrimeiro(): void
    {
        $obraAntiga = $this->prepararObraPublicadaCompleta("editorA");
        $obraMaisRecente = $this->prepararObraPublicadaCompleta("editorA");

        $dataAntiga = "2026-03-01 10:00:00";
        $dataRecente = "2026-08-01 10:00:00";

        $this->definirDataPublicacaoTeste(
            $obraAntiga,
            $dataAntiga
        );

        $this->definirDataPublicacaoTeste(
            $obraMaisRecente,
            $dataRecente
        );

        $resposta = $this->requisitarObrasPublicadas();

        $this->assertSame(200, $resposta["status"]);

        $dados = $this->decodificarResposta($resposta);

        $ids = array_column($dados["obras"], "id");

        $posicaoAntiga = array_search($obraAntiga, $ids, true);
        $posicaoRecente = array_search($obraMaisRecente, $ids, true);

        $this->assertNotFalse($posicaoAntiga);
        $this->assertNotFalse($posicaoRecente);

        $this->assertLessThan(
            $posicaoAntiga,
            $posicaoRecente,
            "A obra mais recente deve aparecer antes da mais antiga."
        );
    }
    /*
     * TESTES DE VISUALIZAR.PHP
     */

    private function requisitarVisualizarObra(string $id): array
    {
        return $this->requisitarHttp(
            "GET",
            "/api/obras/visualizar.php?id=" . rawurlencode($id)
        );
    }

    public function testVisualizarRecusaMetodoPost(): void
    {
        $resposta = $this->requisitarHttp(
            "POST",
            "/api/obras/visualizar.php?id=1",
            "{}",
            "application/json",
            self::ORIGEM
        );

        $this->assertSame(405, $resposta["status"]);
    }

    public function testVisualizarPermiteAcessoSemSessao(): void
    {
        $obraId = $this->criarObraDiretamenteNoBanco("editorA");

        $this->publicarObraParaBiblioteca($obraId);

        $resposta = $this->requisitarVisualizarObra(
            (string) $obraId
        );

        $this->assertSame(200, $resposta["status"]);

        $dados = $this->decodificarResposta($resposta);

        $this->assertTrue($dados["sucesso"]);
        $this->assertSame($obraId, $dados["obra"]["id"]);

        foreach ($resposta["headers"] as $cabecalho) {
            $this->assertFalse(
                stripos($cabecalho, "Set-Cookie:") === 0,
                "A API pública não deve criar uma sessão."
            );
        }
    }

    public function testVisualizarRejeitaIdInvalido(): void
    {
        $respostaTexto = $this->requisitarVisualizarObra("abc");

        $this->assertSame(400, $respostaTexto["status"]);

        $respostaArray = $this->requisitarHttp(
            "GET",
            "/api/obras/visualizar.php?id%5B%5D=1"
        );

        $this->assertSame(400, $respostaArray["status"]);
    }

    public function testVisualizarRetorna404ParaObraInexistente(): void
    {
        $idInexistente = $this->obterIdInexistente();

        $resposta = $this->requisitarVisualizarObra(
            (string) $idInexistente
        );

        $this->assertSame(404, $resposta["status"]);
    }

    public function testVisualizarNaoRetornaObraNaoPublicada(): void
    {
        $obraId = $this->criarObraDiretamenteNoBanco("editorA");

        // A obra permanece como rascunho.
        $resposta = $this->requisitarVisualizarObra(
            (string) $obraId
        );

        $this->assertSame(404, $resposta["status"]);
    }

    public function testVisualizarNaoRetornaObraExcluida(): void
    {
        $obraId = $this->criarObraDiretamenteNoBanco("editorA");

        $this->publicarObraParaBiblioteca($obraId);

        $stmt = $this->db->prepare(
            "UPDATE obras
             SET excluida_em = CURRENT_TIMESTAMP
             WHERE id = ?"
        );

        $stmt->bind_param("i", $obraId);
        $stmt->execute();
        $stmt->close();

        $resposta = $this->requisitarVisualizarObra(
            (string) $obraId
        );

        $this->assertSame(404, $resposta["status"]);
    }

    public function testVisualizarRetornaObraPublicadaSemEtapas(): void
    {
        $obraId = $this->criarObraDiretamenteNoBanco("editorA");

        $this->publicarObraParaBiblioteca($obraId);

        $resposta = $this->requisitarVisualizarObra(
            (string) $obraId
        );

        $this->assertSame(200, $resposta["status"]);

        $dados = $this->decodificarResposta($resposta);

        $this->assertTrue($dados["sucesso"]);
        $this->assertSame($obraId, $dados["obra"]["id"]);
        $this->assertSame([], $dados["obra"]["etapas"]);
        $this->assertFalse($dados["obra"]["imagem_salva"]);
    }

    public function testVisualizarRetornaEtapasOrdenadasEZoomCorreto(): void
    {
        $obraId = $this->criarObraDiretamenteNoBanco("editorA");

        $this->publicarObraParaBiblioteca($obraId);

        // Inserimos a segunda etapa primeiro.
        $this->criarEtapaParaObra(
            $obraId,
            2,
            "Segunda etapa",
            "Texto da segunda etapa.",
            0.654321,
            0.543210,
            0.234567,
            0.345678
        );

        $this->criarEtapaParaObra(
            $obraId,
            1,
            "Primeira etapa",
            "Texto da primeira etapa.",
            0.123456,
            0.234567,
            0.345678,
            0.456789
        );

        $resposta = $this->requisitarVisualizarObra(
            (string) $obraId
        );

        $this->assertSame(200, $resposta["status"]);

        $dados = $this->decodificarResposta($resposta);

        $this->assertTrue($dados["sucesso"]);

        $obra = $dados["obra"];

        $this->assertSame($obraId, $obra["id"]);
        $this->assertCount(2, $obra["etapas"]);

        $this->assertSame(1, $obra["etapas"][0]["ordem"]);
        $this->assertSame("Primeira etapa", $obra["etapas"][0]["titulo"]);

        $this->assertSame(2, $obra["etapas"][1]["ordem"]);
        $this->assertSame("Segunda etapa", $obra["etapas"][1]["titulo"]);

        $this->assertEqualsWithDelta(
            0.123456,
            $obra["etapas"][0]["zoom_x"],
            0.000001
        );

        $this->assertEqualsWithDelta(
            0.456789,
            $obra["etapas"][0]["zoom_altura"],
            0.000001
        );

        $this->assertEqualsWithDelta(
            0.654321,
            $obra["etapas"][1]["zoom_x"],
            0.000001
        );
    }
    private function requisitarImagemObra(
        string $consulta,
        ?string $cookie = null
    ): array {
        return $this->requisitarHttp(
            "GET",
            "/api/obras/imagem.php?" . $consulta,
            null,
            "application/json",
            self::ORIGEM,
            $cookie
        );
    }
}