<?php

use PHPUnit\Framework\TestCase;

final class LoginApiTest extends TestCase
{
    private const BASE_URL = "http://127.0.0.1:8001";
    private const ORIGEM = "http://127.0.0.1:8001";

    private ?mysqli $db = null;
    private ?string $emailTeste = null;
    private string $senhaTeste = "SenhaLogin#2026";

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame(
            "arte_guiada_test",
            getenv("ARTE_GUIADA_DB_NAME")
        );

        require __DIR__ . "/../config/banco.php";

        $this->db = $conn;

        $resultado = $this->db->query(
            "SELECT DATABASE() AS banco"
        );

        $linha = $resultado->fetch_assoc();
        $resultado->free();

        $this->assertSame(
            "arte_guiada_test",
            $linha["banco"]
        );

        /*
         * Cria um usuário exclusivo para este teste.
         */

        $this->emailTeste =
            "login." . bin2hex(random_bytes(8)) . "@example.com";

        $nome = "Teste Login";
        $sobrenome = "PHPUnit";
        $email = $this->emailTeste;
        $senhaHash = password_hash(
            $this->senhaTeste,
            PASSWORD_DEFAULT
        );
        $perfil = "visitante";
        $mensagem = "Usuario temporario para teste de login.";

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
        $stmt->close();
    }

    protected function tearDown(): void
    {
        /*
         * Remove somente o usuário criado por este teste.
         */

        if ($this->db instanceof mysqli) {
            if ($this->emailTeste !== null) {
                $stmt = $this->db->prepare(
                    "DELETE FROM usuarios WHERE email = ?"
                );

                $email = $this->emailTeste;
                $stmt->bind_param("s", $email);
                $stmt->execute();
                $stmt->close();
            }

            $this->db->close();
        }

        parent::tearDown();
    }

    /*
     * Envia uma requisição HTTP ao servidor de testes.
     */

    private function requisitar(
        string $metodo,
        string $endpoint,
        ?string $corpo = null,
        ?string $origem = self::ORIGEM,
        ?string $cookie = null
    ): array {
        $cabecalhos = [
            "Content-Type: application/json"
        ];

        if ($origem !== null) {
            $cabecalhos[] = "Origin: " . $origem;
        }

        if ($cookie !== null) {
            $cabecalhos[] = "Cookie: PHPSESSID=" . $cookie;
        }

        $opcoes = [
            "method" => $metodo,
            "header" => implode("\r\n", $cabecalhos),
            "ignore_errors" => true,
            "timeout" => 10
        ];

        if ($corpo !== null) {
            $opcoes["content"] = $corpo;
        }

        $contexto = stream_context_create([
            "http" => $opcoes
        ]);

        $resposta = @file_get_contents(
            self::BASE_URL . $endpoint,
            false,
            $contexto
        );

        $cabecalhosResposta = $http_response_header ?? [];
        $status = 0;
        $cookieSessao = null;
        $cabecalhoCookie = null;

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
                $cabecalhoCookie = $cabecalho;
            }
        }

        return [
            "status" => $status,
            "body" => $resposta === false ? "" : $resposta,
            "headers" => $cabecalhosResposta,
            "cookie" => $cookieSessao,
            "set_cookie" => $cabecalhoCookie
        ];
    }

    private function codificar(array $dados): string
    {
        return json_encode(
            $dados,
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
    }

    private function decodificar(array $resposta): array
    {
        $dados = json_decode($resposta["body"], true);

        $this->assertIsArray(
            $dados,
            "A resposta da API não contém um JSON válido."
        );

        return $dados;
    }

    private function dadosLogin(?string $senha = null): string
    {
        return $this->codificar([
            "email" => $this->emailTeste,
            "senha" => $senha ?? $this->senhaTeste
        ]);
    }

    /* Bloqueio de origem não autorizada. */

    public function testOrigemNaoAutorizadaEhBloqueada(): void
    {
        $resposta = $this->requisitar(
            "POST",
            "/api/login/login.php",
            "{}",
            "http://site-malicioso.test"
        );

        $this->assertSame(403, $resposta["status"]);
    }

    /*
     * TESTE 3: JSON malformado.
     */



    /*
     * TESTE 4: tipo incorreto de campo.
     */



    /*
     * TESTE 5: e-mail inválido.
     */



    /*
     * TESTE 6: senha incorreta.
     */

    public function testSenhaIncorretaRetorna401(): void
    {
        $resposta = $this->requisitar(
            "POST",
            "/api/login/login.php",
            $this->dadosLogin("SenhaIncorreta#2026")
        );

        $dados = $this->decodificar($resposta);

        $this->assertSame(401, $resposta["status"]);
        $this->assertSame(
            "E-mail ou senha incorretos.",
            $dados["mensagem"]
        );
    }

    /*
     * TESTE 7: usuário inexistente.
     */



    /*
     * TESTE 8: login válido e configuração do cookie.
     */

    public function testLoginValidoCriaCookieDeSessao(): void
    {
        $resposta = $this->requisitar(
            "POST",
            "/api/login/login.php",
            $this->dadosLogin()
        );

        $dados = $this->decodificar($resposta);

        $this->assertSame(200, $resposta["status"]);
        $this->assertTrue($dados["sucesso"]);
        $this->assertSame(
            $this->emailTeste,
            $dados["usuario"]["email"]
        );
        $this->assertSame(
            "visitante",
            $dados["usuario"]["perfil"]
        );

        $this->assertNotNull($resposta["cookie"]);
        $this->assertNotSame("", $resposta["cookie"]);

        $cabecalhoCookie = strtolower(
            (string) $resposta["set_cookie"]
        );

        $this->assertStringContainsString(
            "path=/",
            $cabecalhoCookie
        );

        $this->assertStringContainsString(
            "httponly",
            $cabecalhoCookie
        );

        $this->assertStringContainsString(
            "samesite=lax",
            $cabecalhoCookie
        );

        /*
         * Encerra a sessão criada pelo teste.
         */

        $logout = $this->requisitar(
            "POST",
            "/api/login/logout.php",
            "{}",
            self::ORIGEM,
            $resposta["cookie"]
        );

        $this->assertSame(200, $logout["status"]);
    }

    /*
     * TESTE 9: persistência e encerramento da sessão.
     */

    public function testSessaoPermaneceAtivaELogoutEncerraAcesso(): void
    {
        $login = $this->requisitar(
            "POST",
            "/api/login/login.php",
            $this->dadosLogin()
        );

        $dadosLogin = $this->decodificar($login);

        $this->assertSame(200, $login["status"]);
        $this->assertTrue($dadosLogin["sucesso"]);
        $this->assertNotNull($login["cookie"]);

        $cookie = $login["cookie"];

        /*
         * Consulta a sessão usando o cookie do login.
         */

        $consulta = $this->requisitar(
            "GET",
            "/api/login/sessao.php",
            null,
            null,
            $cookie
        );

        $dadosSessao = $this->decodificar($consulta);

        $this->assertSame(200, $consulta["status"]);
        $this->assertTrue($dadosSessao["autenticado"]);
        $this->assertSame(
            $dadosLogin["usuario"]["id"],
            $dadosSessao["usuario"]["id"]
        );

        /*
         * Encerra a sessão.
         */

        $logout = $this->requisitar(
            "POST",
            "/api/login/logout.php",
            "{}",
            self::ORIGEM,
            $cookie
        );

        $dadosLogout = $this->decodificar($logout);

        $this->assertSame(200, $logout["status"]);
        $this->assertTrue($dadosLogout["sucesso"]);
        $this->assertNotNull($logout["set_cookie"]);

        $this->assertStringContainsString(
            "PHPSESSID=deleted",
            $logout["set_cookie"]
        );

        /*
         * Sem cookie após o logout, o usuário não
         * deve continuar autenticado nem receber
         * uma nova sessão vazia.
         */

        $aposLogout = $this->requisitar(
            "GET",
            "/api/login/sessao.php"
        );

        $dadosAposLogout = $this->decodificar($aposLogout);

        $this->assertSame(401, $aposLogout["status"]);
        $this->assertFalse($dadosAposLogout["autenticado"]);
        $this->assertNull($aposLogout["cookie"]);
    }

    /*
     * TESTE 10: visitante sem cookie.
     */


}