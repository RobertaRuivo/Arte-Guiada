<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Infraestrutura compartilhada dos testes de API. */
abstract class ApiTestCase extends TestCase
{
    protected const BASE_URL = "http://127.0.0.1:8001";
    protected const ORIGEM = "http://127.0.0.1:8001";
    protected const SENHA = "SenhaObras#2026";

    protected ?mysqli $db = null;
    protected array $usuarios = [];
    protected array $cookies = [];

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

    protected function conectarBancoTeste(): mysqli
    {
        $this->assertSame(
            "arte_guiada_test",
            getenv("ARTE_GUIADA_DB_NAME")
        );

        require __DIR__ . "/../../config/banco.php";

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

    protected function criarUsuario(string $chave, string $perfil): void
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

    protected function criarSessao(string $chave): string
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

    protected function requisitarHttp(
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

    protected function montarMultipart(
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

    protected function requisitarSalvar(
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

    protected function requisitarExcluir(
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

    protected function decodificarResposta(array $resposta): array
    {
        $dados = json_decode($resposta["body"], true);

        $this->assertIsArray(
            $dados,
            "A resposta da API não contém JSON válido. Corpo recebido: " .
            $resposta["body"]
        );

        return $dados;
    }

    protected function camposRascunho(string $titulo = "Obra de teste"): array
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

    protected function consultarNomeCompletoUsuario(string $chave): string
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

    protected function criarObraDiretamenteNoBanco(string $chave): int
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

    protected function consultarObra(int $obraId): ?array
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

    protected function requisitarDetalhe(
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

    protected function criarEtapaParaObra(
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

    protected function obterIdInexistente(): int
    {
        $resultado = $this->db->query(
            "SELECT COALESCE(MAX(id), 0) + 1 AS proximo_id
             FROM obras"
        );

        $linha = $resultado->fetch_assoc();
        $resultado->free();

        return (int) $linha["proximo_id"];
    }

    protected function publicarObraParaBiblioteca(int $obraId): void
    {
        $stmt = $this->db->prepare(
            "UPDATE obras SET status = 'publicada' WHERE id = ?"
        );

        $stmt->bind_param("i", $obraId);
        $stmt->execute();
        $stmt->close();
    }

    protected function requisitarAtualizarBiblioteca(
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

    protected function consultarEstadoBiblioteca(
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

    protected function requisitarConcluirBiblioteca(
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

    protected function requisitarEstadoBiblioteca(
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

    protected function requisitarListarBiblioteca(
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

    protected function criarRegistroBibliotecaParaListagem(
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

    protected function imagemPngValidaParaTeste(): string
    {
        $imagem = base64_decode(
            "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADUlEQVR4nGP4z8AAAAMBAQDJ/pLvAAAAAElFTkSuQmCC",
            true
        );

        $this->assertIsString($imagem);

        return $imagem;
    }

    protected function imagemJpegValidaParaTeste(): string
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

    protected function imagemWebpValidaParaTeste(): string
    {
        $imagem = base64_decode(
            "UklGRjwAAABXRUJQVlA4IDAAAADQAQCdASoBAAEAAUAmJaACdLoB+AADsAD+8ut//NgVzXPv9//S4P0uD9Lg/9KQAAA=",
            true
        );

        $this->assertIsString($imagem);

        return $imagem;
    }

    protected function gravarImagemParaTeste(
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

    protected function obterCabecalhoImagem(
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

    protected function requisitarImagemBiblioteca(
        string $consulta
    ): array {
        return $this->requisitarHttp(
            "GET",
            "/api/obras/imagem-publica.php?" . $consulta
        );
    }

    protected function prepararObraPublicadaCompleta(
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

    protected function definirDataPublicacaoTeste(
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

    protected function requisitarObrasPublicadas(): array
    {
        return $this->requisitarHttp(
            "GET",
            "/api/obras/publicadas.php"
        );
    }

    protected function requisitarVisualizarObra(string $id): array
    {
        return $this->requisitarHttp(
            "GET",
            "/api/obras/visualizar.php?id=" . rawurlencode($id)
        );
    }

    protected function requisitarImagemObra(
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
