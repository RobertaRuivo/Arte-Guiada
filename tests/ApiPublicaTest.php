<?php

declare(strict_types=1);

require_once __DIR__ . '/Support/ApiTestCase.php';

final class ApiPublicaTest extends ApiTestCase
{
    public function testImagemPublicaEntregaJpegPublicoSemSessao(): void
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

    public function testImagemPublicaNaoEntregaObraNaoPublicada(): void
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



    public function testImagemPublicaRejeitaMimeIncompativel(): void
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




















    /**
     * Verifica a proteção contra XSS armazenado no catálogo.
     *
     * Simula uma obra que já possui conteúdo malicioso armazenado
     * e verifica se o catálogo utiliza inserção segura de texto.
     */
    public function testTituloMaliciosoEhTratadoComoTextoNoCatalogo(): void
    {
        $obraId = $this->prepararObraPublicadaCompleta("editorA");

        $tituloMalicioso = '<img src=x onerror=alert("XSS")>';

        // Simula conteúdo malicioso já persistido no banco.
        $stmt = $this->db->prepare(
            "UPDATE obras SET titulo = ? WHERE id = ?"
        );

        $stmt->bind_param("si", $tituloMalicioso, $obraId);
        $stmt->execute();

        $this->assertSame(1, $stmt->affected_rows);

        $stmt->close();

        // Consulta a obra pela API pública do catálogo.
        $resposta = $this->requisitarObrasPublicadas();

        $this->assertSame(200, $resposta["status"]);

        $dados = $this->decodificarResposta($resposta);

        $this->assertTrue($dados["sucesso"]);

        $obrasEncontradas = array_values(array_filter(
            $dados["obras"],
            static fn(array $obra): bool =>
                (int) ($obra["id"] ?? 0) === $obraId
        ));

        $this->assertCount(1, $obrasEncontradas);

        // O conteúdo deve chegar como dado, sem ser alterado.
        $this->assertSame(
            $tituloMalicioso,
            $obrasEncontradas[0]["titulo"]
        );

        // Verifica a forma de inserção utilizada pelo catálogo.
        $caminhoScript = dirname(__DIR__) . "/public/js/catalogo.js";
        $scriptCatalogo = file_get_contents($caminhoScript);

        $this->assertIsString($scriptCatalogo);

        $inicioFuncao = strpos(
            $scriptCatalogo,
            "function criarCartaoObra(obra)"
        );

        $this->assertNotFalse(
            $inicioFuncao,
            "A função de criação dos cartões não foi encontrada."
        );

        if ($inicioFuncao === false) {
            return;
        }

        $fimFuncao = strpos(
            $scriptCatalogo,
            "function renderizarObras()",
            $inicioFuncao
        );

        $this->assertNotFalse(
            $fimFuncao,
            "A função de renderização do catálogo não foi encontrada."
        );

        if ($fimFuncao === false) {
            return;
        }

        $codigoCartao = substr(
            $scriptCatalogo,
            $inicioFuncao,
            $fimFuncao - $inicioFuncao
        );

        $this->assertStringContainsString(
            "titulo.textContent = tituloTexto;",
            $codigoCartao
        );

        $this->assertStringContainsString(
            "artista.textContent = autorTexto;",
            $codigoCartao
        );

        $this->assertStringNotContainsString(
            "innerHTML",
            $codigoCartao,
            "O cartão não deve interpretar dados da obra como HTML."
        );
    }
}
