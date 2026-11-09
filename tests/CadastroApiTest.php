<?php

use PHPUnit\Framework\TestCase;

final class CadastroApiTest extends TestCase
{
    private const BASE_URL = 'http://127.0.0.1:8001';
    private const ORIGEM = 'http://127.0.0.1:8001';

    private mysqli $conn;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame(
            'arte_guiada_test',
            getenv('ARTE_GUIADA_DB_NAME')
        );

        require __DIR__ . '/../config/banco.php';

        $resultado = $conn->query('SELECT DATABASE() AS banco');
        $linha = $resultado->fetch_assoc();
        $resultado->free();

        if (($linha['banco'] ?? null) !== 'arte_guiada_test') {
            $conn->close();
            $this->fail('Conexao fora do banco de testes.');
        }

        $this->conn = $conn;
    }

    protected function tearDown(): void
    {
        if (isset($this->conn)) {
            $this->conn->close();
        }

        parent::tearDown();
    }

    private function requisitarCadastro(
        string $metodo,
        string $corpo = '',
        string $origem = self::ORIGEM
    ): array {
        $opcoes = [
            'method' => $metodo,
            'header' =>
                "Origin: {$origem}\r\n" .
                "Content-Type: application/json\r\n",
            'ignore_errors' => true,
            'timeout' => 10
        ];

        if ($corpo !== '') {
            $opcoes['content'] = $corpo;
        }

        $contexto = stream_context_create([
            'http' => $opcoes
        ]);

        $resposta = @file_get_contents(
            self::BASE_URL . '/api/cadastro/cadastrar.php',
            false,
            $contexto
        );

        $cabecalhos = $http_response_header ?? [];
        $status = 0;

        if (
            isset($cabecalhos[0]) &&
            preg_match('/\s(\d{3})\s/', $cabecalhos[0], $match)
        ) {
            $status = (int) $match[1];
        }

        return [
            'status' => $status,
            'body' => $resposta === false ? '' : $resposta
        ];
    }

    private function novoEmail(): string
    {
        return 'teste.' . bin2hex(random_bytes(6)) . '@example.com';
    }

    private function dadosValidos(string $email): array
    {
        return [
            'nome' => 'Teste PHPUnit',
            'sobrenome' => 'Cadastro automatizado',
            'email' => $email,
            'senha' => 'SenhaTeste#2026',
            'mensagem' => 'Cadastro utilizado em teste automatizado.'
        ];
    }

    private function codificar(array $dados): string
    {
        return json_encode(
            $dados,
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
    }

    private function buscarUsuario(string $email): ?array
    {
        $stmt = $this->conn->prepare(
            'SELECT nome, senha, perfil
             FROM usuarios
             WHERE email = ?'
        );

        $stmt->bind_param('s', $email);
        $stmt->execute();

        $stmt->bind_result($nome, $senha, $perfil);

        $encontrou = $stmt->fetch();
        $stmt->close();

        if (!$encontrou) {
            return null;
        }

        return [
            'nome' => $nome,
            'senha' => $senha,
            'perfil' => $perfil
        ];
    }

    private function removerUsuario(string $email): void
    {
        $stmt = $this->conn->prepare(
            'DELETE FROM usuarios WHERE email = ?'
        );

        $stmt->bind_param('s', $email);
        $stmt->execute();
        $stmt->close();
    }

    public function testMetodoGetEhRecusado(): void
    {
        $resposta = $this->requisitarCadastro('GET');

        $this->assertSame(405, $resposta['status']);
    }

    public function testOrigemNaoConfiavelEhBloqueada(): void
    {
        $resposta = $this->requisitarCadastro(
            'POST',
            '{}',
            'http://site-malicioso.test'
        );

        $this->assertSame(403, $resposta['status']);
    }

    public function testJsonInvalidoEhRecusado(): void
    {
        $resposta = $this->requisitarCadastro('POST', '{');

        $this->assertSame(400, $resposta['status']);
    }

    public function testCamposObrigatoriosAusentesSaoRecusados(): void
    {
        $resposta = $this->requisitarCadastro('POST', '{}');

        $this->assertSame(400, $resposta['status']);
    }

    public function testSenhaMuitoCurtaEhRecusada(): void
    {
        $email = $this->novoEmail();
        $dados = $this->dadosValidos($email);
        $dados['senha'] = '1234567';

        $resposta = $this->requisitarCadastro(
            'POST',
            $this->codificar($dados)
        );

        $this->assertSame(422, $resposta['status']);
    }

    public function testCadastroValidoArmazenaSenhaHash(): void
    {
        $email = $this->novoEmail();
        $dados = $this->dadosValidos($email);

        try {
            $resposta = $this->requisitarCadastro(
                'POST',
                $this->codificar($dados)
            );

            $this->assertSame(201, $resposta['status']);

            $usuario = $this->buscarUsuario($email);

            $this->assertNotNull($usuario);
            $this->assertSame('visitante', $usuario['perfil']);
            $this->assertNotSame($dados['senha'], $usuario['senha']);
            $this->assertTrue(
                password_verify($dados['senha'], $usuario['senha'])
            );

        } finally {
            $this->removerUsuario($email);
        }
    }

    public function testEmailDuplicadoEhRecusado(): void
    {
        $email = $this->novoEmail();
        $dados = $this->dadosValidos($email);
        $corpo = $this->codificar($dados);

        try {
            $primeira = $this->requisitarCadastro('POST', $corpo);

            $this->assertSame(201, $primeira['status']);

            $segunda = $this->requisitarCadastro('POST', $corpo);

            $this->assertSame(409, $segunda['status']);

        } finally {
            $this->removerUsuario($email);
        }
    }

    public function testClienteNaoConsegueCriarAdministrador(): void
    {
        $email = $this->novoEmail();
        $dados = $this->dadosValidos($email);

        $dados['perfil'] = 'admin';

        try {
            $resposta = $this->requisitarCadastro(
                'POST',
                $this->codificar($dados)
            );

            $this->assertSame(201, $resposta['status']);

            $usuario = $this->buscarUsuario($email);

            $this->assertNotNull($usuario);
            $this->assertSame('visitante', $usuario['perfil']);

        } finally {
            $this->removerUsuario($email);
        }
    }

    public function testEntradaSqlInjectionEhTratadaComoTexto(): void
    {
        $email = $this->novoEmail();
        $dados = $this->dadosValidos($email);

        $dados['nome'] = "'; DROP TABLE usuarios; --";

        try {
            $resposta = $this->requisitarCadastro(
                'POST',
                $this->codificar($dados)
            );

            $this->assertSame(201, $resposta['status']);

            $usuario = $this->buscarUsuario($email);

            $this->assertNotNull($usuario);
            $this->assertSame($dados['nome'], $usuario['nome']);

        } finally {
            $this->removerUsuario($email);
        }
    }
}