<?php

use PHPUnit\Framework\TestCase;

final class ConexaoBancoTest extends TestCase
{
    public function testPhpunitUtilizaBancoDeTestes(): void
    {
        $this->assertSame(
            'arte_guiada_test',
            getenv('ARTE_GUIADA_DB_NAME')
        );

        require __DIR__ . '/../config/banco.php';

        $this->assertInstanceOf(mysqli::class, $conn);

        try {
            $resultado = $conn->query('SELECT DATABASE() AS banco');
            $linha = $resultado->fetch_assoc();

            $this->assertSame(
                'arte_guiada_test',
                $linha['banco']
            );
        } finally {
            $conn->close();
        }
    }
}