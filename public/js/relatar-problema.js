
/*
 * Arte Guiada | Formulário independente de relatos.
 * Aceita usuários autenticados e anônimos.
 */

(() => {
    "use strict";

    const formulario = document.getElementById("formRelatoProblema");

    if (!formulario) {
        console.error("[RELATO] Formulário não encontrado.");
        return;
    }

    const campoDescricao = document.getElementById("descricaoRelato");
    const contadorDescricao = document.getElementById("contadorDescricaoRelato");

    const feedback = document.getElementById("feedbackRelatoProblema");
    const sucesso = document.getElementById("sucessoRelato");

    const tituloObra = document.getElementById("tituloObraRelato");
    const autorObra = document.getElementById("autorObraRelato");

    const erroContexto = document.getElementById("erroContextoRelato");
    const contextoObra = document.getElementById("contextoObraRelato");

    const linkVoltar = document.getElementById("voltarGaleriaRelato");
    const linkCancelar = document.getElementById("cancelarRelatoProblema");

    const botaoEnviar = formulario.querySelector('button[type="submit"]');

    if (!campoDescricao || !botaoEnviar) {
        console.error(
            "[RELATO] Campo de descrição ou botão de envio não encontrado."
        );
        return;
    }

    const textoOriginalBotao =
        botaoEnviar.textContent.trim() || "Enviar relato";

    const parametros = new URLSearchParams(window.location.search);

    const identificador =
        parametros.get("obra_id") ||
        parametros.get("id");

    const obraId = /^[1-9]\d*$/.test(identificador || "")
        ? Number(identificador)
        : NaN;

    let enviando = false;

    /*
     * Exibe mensagens para o usuário.
     */
    function mostrarFeedback(mensagem, tipo = "") {
        if (!feedback) {
            console.warn("[RELATO] Elemento de feedback não encontrado.");
            console.log("[RELATO]", mensagem);
            return;
        }

        feedback.textContent = mensagem;

        feedback.classList.remove("erro", "sucesso");

        if (tipo) {
            feedback.classList.add(tipo);
        }
    }

    /*
     * Atualiza os links para retornar à obra na galeria.
     */
    function atualizarLinksDaGaleria() {
        const destino = new URL(
            "galeria.html",
            document.baseURI
        );

        destino.searchParams.set("id", String(obraId));

        if (linkVoltar) {
            linkVoltar.href = destino.href;
        }

        if (linkCancelar) {
            linkCancelar.href = destino.href;
        }
    }

    /*
     * Valida o identificador da obra recebido pela URL.
     */
    if (!Number.isSafeInteger(obraId) || obraId < 1) {
        formulario.hidden = true;

        if (contextoObra) {
            contextoObra.hidden = true;
        }

        if (erroContexto) {
            erroContexto.hidden = false;

            erroContexto.textContent =
                "Não foi possível identificar a obra relacionada ao relato. " +
                "Volte à galeria e abra o formulário pela página da obra.";
        }

        if (tituloObra) {
            tituloObra.textContent = "Obra não identificada";
        }

        console.error(
            "[RELATO] Identificador inválido:",
            identificador
        );

        return;
    }

    atualizarLinksDaGaleria();

    if (erroContexto) {
        erroContexto.hidden = true;
    }

    if (contextoObra) {
        contextoObra.hidden = false;
    }

    /*
     * Carrega o título e o autor da obra.
     *
     * A consulta é independente do envio do formulário.
     * Se a API falhar, o usuário ainda poderá enviar o relato.
     */
    async function carregarContextoObra() {
        const destino = new URL(
            "../api/obras/detalhe-publico.php",
            document.baseURI
        );

        destino.searchParams.set("id", String(obraId));

        try {
            const resposta = await fetch(destino.href, {
                method: "GET",
                credentials: "same-origin",
                cache: "no-store",
                headers: {
                    Accept: "application/json"
                }
            });

            const corpoResposta = await resposta.text();

            let dados = null;

            try {
                dados = JSON.parse(corpoResposta);
            } catch (erroJson) {
                console.error(
                    "[RELATO] Resposta inválida da API de obras:",
                    corpoResposta
                );
            }

            console.log("[RELATO] Consulta da obra:", {
                url: resposta.url,
                status: resposta.status,
                dados
            });

            if (!resposta.ok || dados?.sucesso !== true) {
                throw new Error(
                    dados?.mensagem ||
                    "Não foi possível carregar os dados da obra."
                );
            }

            /*
             * Aceita a obra nos formatos:
             * dados.obra
             * dados.dados.obra
             * dados.dados
             * dados
             */
            const obra =
                dados.obra ||
                dados.dados?.obra ||
                dados.dados ||
                dados;

            const titulo =
                obra.titulo ||
                obra.titulo_obra ||
                obra.nome ||
                obra.nome_obra;

            const autorRecebido =
                obra.autor ||
                obra.nome_autor ||
                obra.autor_nome;

            const autor =
                typeof autorRecebido === "string"
                    ? autorRecebido
                    : autorRecebido?.nome ||
                    autorRecebido?.nome_completo ||
                    "";

            if (tituloObra) {
                tituloObra.textContent =
                    titulo || "Obra selecionada";
            }

            if (autorObra) {
                autorObra.textContent =
                    autor || "Autor não informado";
            }

            console.log(
                "[RELATO] Contexto da obra carregado:",
                { obraId, titulo, autor }
            );

        } catch (erro) {
            console.error(
                "[RELATO] Erro ao carregar a obra:",
                erro
            );

            if (tituloObra) {
                tituloObra.textContent = "Obra selecionada";
            }

            if (autorObra) {
                autorObra.textContent =
                    "Não foi possível carregar os dados do autor.";
            }

            /*
             * A falha desta consulta não bloqueia o formulário.
             * O identificador válido continua disponível para envio.
             */
        }
    }

    /*
     * Torna a descrição obrigatória somente quando
     * a categoria "Outro" estiver selecionada.
     */
    function atualizarObrigatoriedadeDescricao() {
        const outroSelecionado = Boolean(
            formulario.querySelector(
                'input[name="tipos"][value="outro"]:checked'
            )
        );

        campoDescricao.required = outroSelecionado;

        campoDescricao.setAttribute(
            "aria-required",
            String(outroSelecionado)
        );

        const indicacao = document.getElementById(
            "indicacaoDescricaoRelato"
        );

        if (indicacao) {
            indicacao.textContent = outroSelecionado
                ? "Obrigatória para esta opção."
                : "Opcional, mas ajuda na análise.";
        }
    }

    /*
     * Atualiza o contador de caracteres da descrição.
     */
    function atualizarContadorDescricao() {
        if (contadorDescricao) {
            contadorDescricao.textContent =
                String(campoDescricao.value.length);
        }
    }

    /*
     * Valida e envia o relato ao backend.
     */
    async function enviarRelato(evento) {
        evento.preventDefault();

        if (enviando) {
            return;
        }

        const tipos = Array.from(
            formulario.querySelectorAll(
                'input[name="tipos"]:checked'
            )
        ).map((campo) => campo.value);

        const descricao = campoDescricao.value.trim();

        /*
         * É necessário selecionar pelo menos uma categoria.
         */
        if (tipos.length === 0) {
            mostrarFeedback(
                "Selecione pelo menos um tipo de problema.",
                "erro"
            );

            formulario.querySelector(
                'input[name="tipos"]'
            )?.focus();

            return;
        }

        /*
         * A descrição é obrigatória para a categoria Outro.
         */
        if (tipos.includes("outro") && !descricao) {
            mostrarFeedback(
                "Descreva o problema selecionado em Outro.",
                "erro"
            );

            campoDescricao.focus();
            return;
        }

        /*
         * Limite máximo da descrição.
         */
        if (descricao.length > 2000) {
            mostrarFeedback(
                "A descrição ultrapassa o limite de 2.000 caracteres.",
                "erro"
            );

            campoDescricao.focus();
            return;
        }

        enviando = true;

        botaoEnviar.disabled = true;
        botaoEnviar.textContent = "Enviando relato...";

        mostrarFeedback("Registrando seu relato...");

        try {
            const destino = new URL(
                "../api/relatos/criar.php",
                document.baseURI
            );

            const resposta = await fetch(destino.href, {
                method: "POST",
                credentials: "same-origin",
                cache: "no-store",
                headers: {
                    "Content-Type": "application/json",
                    Accept: "application/json"
                },
                body: JSON.stringify({
                    obra_id: obraId,
                    tipos,
                    descricao
                })
            });

            /*
             * Lê a resposta como texto antes de interpretar o JSON.
             * Isso facilita identificar mensagens de erro do PHP.
             */
            const corpoResposta = await resposta.text();

            let dados = null;

            try {
                dados = JSON.parse(corpoResposta);
            } catch (erroJson) {
                console.error(
                    "[RELATO] A API de envio não retornou JSON válido:",
                    corpoResposta
                );
            }

            console.log("[RELATO] Resposta do envio:", {
                url: resposta.url,
                status: resposta.status,
                corpo: corpoResposta,
                dados
            });

            /*
             * O envio só é considerado concluído quando
             * o backend confirma o sucesso.
             */
            if (!resposta.ok || dados?.sucesso !== true) {
                throw new Error(
                    dados?.mensagem ||
                    `Não foi possível enviar o relato (HTTP ${resposta.status}).`
                );
            }

            /*
             * Apresenta a confirmação após o sucesso do backend.
             */
            formulario.hidden = true;

            if (contextoObra) {
                contextoObra.hidden = true;
            }

            if (sucesso) {
                sucesso.hidden = false;

                sucesso.scrollIntoView({
                    behavior: "smooth",
                    block: "center"
                });
            } else {
                mostrarFeedback(
                    "Relato enviado com sucesso!",
                    "sucesso"
                );
            }

            /*
             * Retorna automaticamente à página inicial.
             */
            const paginaInicial = new URL(
                "../index.html",
                document.baseURI
            ).href;

            window.setTimeout(() => {
                window.location.replace(paginaInicial);
            }, 2000);

        } catch (erro) {
            console.error(
                "[RELATO] Erro ao enviar:",
                erro
            );

            mostrarFeedback(
                erro.message ||
                "Não foi possível registrar seu relato. Tente novamente.",
                "erro"
            );

            /*
             * Preserva os campos e libera uma nova tentativa.
             */
            enviando = false;

            botaoEnviar.disabled = false;
            botaoEnviar.textContent = textoOriginalBotao;
        }
    }

    /*
     * Inicialização dos eventos do formulário.
     *
     * Estes eventos são registrados antes da consulta da obra.
     * Portanto, uma falha na API de detalhes não impede o envio.
     */
    formulario.querySelectorAll(
        'input[name="tipos"]'
    ).forEach((campo) => {
        campo.addEventListener(
            "change",
            atualizarObrigatoriedadeDescricao
        );
    });

    campoDescricao.addEventListener(
        "input",
        atualizarContadorDescricao
    );

    formulario.addEventListener(
        "submit",
        enviarRelato
    );

    atualizarObrigatoriedadeDescricao();
    atualizarContadorDescricao();

    console.log(
        "[RELATO] Formulário inicializado.",
        { obraId }
    );

    /*
     * A consulta da obra acontece depois da inicialização.
     */
    carregarContextoObra();

})();
