
/*
 * Arte Guiada | Biblioteca pessoal compartilhada.
 * Funciona na página independente e na área pessoal do visitante.
 */
(() => {
    "use strict";

    const lista =
        document.querySelector("[data-biblioteca-lista]") ||
        document.getElementById("listaBiblioteca") ||
        document.getElementById("colecaoConteudo");

    const feedback =
        document.getElementById("bibliotecaFeedback") ||
        document.querySelector("[data-status-biblioteca]");

    const abas = Array.from(
        document.querySelectorAll("[data-colecao]")
    );

    const contagens = Array.from(
        document.querySelectorAll("[data-contagem]")
    );

    // Evita executar o script em páginas que não possuem biblioteca.
    if (!lista || abas.length === 0) {
        return;
    }

    /*
     * As páginas da biblioteca ficam dentro de /views/.
     * Os caminhos são resolvidos a partir da página atual.
     */
    const urlApi = (arquivo) =>
        new URL(`../api/biblioteca/${arquivo}.php`, document.baseURI);

    const urlAcesso = new URL(
        "acesso.html",
        document.baseURI
    );

    const urlGaleria = new URL(
        "galeria.html",
        document.baseURI
    );

    const nomesColecoes = {
        favoritos: {
            titulo: "Você ainda não salvou favoritos",
            texto: "Use o botão de favoritos durante a exploração para guardar as obras que mais chamaram sua atenção."
        },

        quero_explorar: {
            titulo: "Sua lista Quero ver está vazia",
            texto: "Adicione obras que deseja explorar depois. Elas ficarão guardadas nesta lista."
        },

        exploradas: {
            titulo: "Nenhuma obra concluída por enquanto",
            texto: "Quando você chegar ao final de todas as etapas de uma obra, ela aparecerá automaticamente aqui."
        }
    };

    const colecoesContagem = [
        "favoritos",
        "quero_explorar",
        "exploradas"
    ];

    function normalizarColecao(valor) {
        return String(valor || "")
            .trim()
            .replace(/-/g, "_");
    }

    function setFeedback(mensagem = "") {
        if (!feedback) return;

        feedback.textContent = mensagem;
        feedback.hidden = !mensagem;
    }

    function criar(tag, classe, texto) {
        const elemento = document.createElement(tag);

        if (classe) {
            elemento.className = classe;
        }

        if (texto !== undefined) {
            elemento.textContent = texto;
        }

        return elemento;
    }

    function urlDaGaleria(obraId) {
        const destino = new URL(urlGaleria.href);

        destino.searchParams.set(
            "id",
            String(obraId)
        );

        return destino.href;
    }

    function urlDaImagem(obraId) {
        const destino = urlApi("imagem");

        destino.searchParams.set(
            "id",
            String(obraId)
        );

        return destino.href;
    }

    function atualizarAbas(colecao) {
        abas.forEach((aba) => {
            const ativa =
                normalizarColecao(aba.dataset.colecao) === colecao;

            aba.classList.toggle("ativa", ativa);

            aba.setAttribute(
                "aria-selected",
                String(ativa)
            );

            if (ativa && aba.id) {
                lista.setAttribute(
                    "aria-labelledby",
                    aba.id
                );
            }
        });
    }

    function exibirLoginNecessario(mensagem) {
        lista.replaceChildren();

        const aviso = criar(
            "div",
            "biblioteca-vazio"
        );

        aviso.appendChild(
            criar(
                "h2",
                "",
                "Entre na sua conta para usar sua biblioteca"
            )
        );

        aviso.appendChild(
            criar(
                "p",
                "",
                mensagem ||
                "Sua biblioteca é pessoal e fica vinculada à sua conta do Arte Guiada."
            )
        );

        const acoes = criar(
            "div",
            "biblioteca-card-acoes"
        );

        const link = criar(
            "a",
            "",
            "Entrar ou criar conta"
        );

        link.href = urlAcesso.href;

        acoes.appendChild(link);
        aviso.appendChild(acoes);
        lista.appendChild(aviso);
    }

    /*
     * Consulta uma coleção e valida a resposta da API.
     */
    async function consultarColecao(colecao) {
        const destino = urlApi("listar");

        destino.searchParams.set(
            "colecao",
            colecao
        );

        const resposta = await fetch(destino.href, {
            method: "GET",
            credentials: "same-origin",
            cache: "no-store",
            headers: {
                "Accept": "application/json"
            }
        });

        const dados = await resposta
            .json()
            .catch(() => null);

        if (resposta.status === 401) {
            const erro = new Error(
                dados?.mensagem ||
                "Entre na sua conta para consultar sua biblioteca."
            );

            erro.status = 401;
            throw erro;
        }

        if (
            !resposta.ok ||
            !dados?.sucesso ||
            !Array.isArray(dados.obras)
        ) {
            throw new Error(
                dados?.mensagem ||
                "Não foi possível carregar sua biblioteca."
            );
        }

        return dados.obras;
    }

    /*
     * Atualiza os números apresentados nos cartões de resumo.
     * Cada coleção é consultada separadamente porque a API atual
     * recebe uma coleção por requisição.
     */
    async function atualizarContagens() {
        if (contagens.length === 0) return;

        const resultados = await Promise.allSettled(
            colecoesContagem.map(
                (colecao) => consultarColecao(colecao)
            )
        );

        contagens.forEach((elemento) => {
            const colecao = normalizarColecao(
                elemento.dataset.contagem
            );

            const indice = colecoesContagem.indexOf(colecao);
            const resultado = resultados[indice];

            elemento.textContent =
                resultado?.status === "fulfilled"
                    ? String(resultado.value.length)
                    : "—";
        });
    }

    /*
     * Cria o cartão de uma obra.
     */
    function criarCartao(obra, colecao) {
        const artigo = criar(
            "article",
            "biblioteca-card"
        );

        const moldura = criar(
            "div",
            "biblioteca-card-imagem"
        );

        const imagem = document.createElement("img");

        imagem.src = urlDaImagem(obra.id);

        imagem.alt =
            obra.texto_alternativo ||
            `Imagem da obra ${obra.titulo || "sem título"}`;

        imagem.loading = "lazy";

        imagem.onerror = () => {
            moldura.replaceChildren(
                criar(
                    "span",
                    "biblioteca-imagem-indisponivel",
                    "Imagem indisponível"
                )
            );
        };

        moldura.appendChild(imagem);
        artigo.appendChild(moldura);

        const conteudo = criar(
            "div",
            "biblioteca-card-conteudo"
        );

        conteudo.appendChild(
            criar(
                "p",
                "biblioteca-card-artista",
                obra.autor || "Autor não informado"
            )
        );

        conteudo.appendChild(
            criar(
                "h2",
                "",
                obra.titulo || "Obra sem título"
            )
        );

        if (colecao === "exploradas" && obra.explorada_em) {
            const data = new Date(
                String(obra.explorada_em).replace(" ", "T")
            );

            const dataTexto = Number.isNaN(data.getTime())
                ? obra.explorada_em
                : data.toLocaleDateString("pt-BR");

            conteudo.appendChild(
                criar(
                    "p",
                    "biblioteca-card-data",
                    `Explorada em ${dataTexto}`
                )
            );
        }

        const acoes = criar(
            "div",
            "biblioteca-card-acoes"
        );

        /*
         * A página da galeria recebe o ID da obra.
         * Não há mais restrição artificial ao ID 3.
         */
        const explorar = criar(
            "a",
            "",
            colecao === "exploradas"
                ? "Explorar novamente"
                : "Explorar obra"
        );

        explorar.href = urlDaGaleria(obra.id);

        acoes.appendChild(explorar);

        /*
         * Favoritos e Quero explorar podem ser removidos.
         * A coleção de experiências concluídas é preservada.
         */
        if (
            colecao === "favoritos" ||
            colecao === "quero_explorar"
        ) {
            const acao = colecao === "favoritos"
                ? "favorito"
                : "quero_explorar";

            const rotulo = colecao === "favoritos"
                ? "Remover dos favoritos"
                : "Remover de Quero ver";

            const botao = criar(
                "button",
                "secundario",
                rotulo
            );

            botao.type = "button";

            botao.addEventListener("click", async () => {
                botao.disabled = true;
                setFeedback("");

                try {
                    const resposta = await fetch(
                        urlApi("atualizar").href,
                        {
                            method: "POST",
                            credentials: "same-origin",
                            cache: "no-store",
                            headers: {
                                "Content-Type": "application/json",
                                "Accept": "application/json"
                            },
                            body: JSON.stringify({
                                obra_id: Number(obra.id),
                                acao,
                                ativo: false
                            })
                        }
                    );

                    const dados = await resposta
                        .json()
                        .catch(() => null);

                    if (resposta.status === 401) {
                        exibirLoginNecessario(
                            "Entre na sua conta novamente para alterar sua biblioteca."
                        );

                        return;
                    }

                    if (!resposta.ok || !dados?.sucesso) {
                        throw new Error(
                            dados?.mensagem ||
                            "Não foi possível atualizar a coleção."
                        );
                    }

                    await carregarColecao(colecao);
                    atualizarContagens();

                } catch (erro) {
                    setFeedback(
                        erro.message ||
                        "Não foi possível atualizar a coleção."
                    );

                    botao.disabled = false;
                }
            });

            acoes.appendChild(botao);
        }

        conteudo.appendChild(acoes);
        artigo.appendChild(conteudo);

        return artigo;
    }

    let colecaoAtual = normalizarColecao(
        abas.find(
            (aba) =>
                aba.getAttribute("aria-selected") === "true"
        )?.dataset.colecao ||
        abas[0]?.dataset.colecao ||
        "favoritos"
    );

    let requestId = 0;

    /*
     * Carrega e apresenta a coleção selecionada.
     */
    async function carregarColecao(colecao) {
        colecao = normalizarColecao(colecao);

        if (!nomesColecoes[colecao]) return;

        colecaoAtual = colecao;
        requestId += 1;

        const idDaRequisicao = requestId;

        atualizarAbas(colecao);
        setFeedback("");

        lista.replaceChildren(
            criar(
                "div",
                "biblioteca-carregando",
                "Carregando sua biblioteca…"
            )
        );

        try {
            const obras = await consultarColecao(colecao);

            // Ignora respostas de cliques anteriores.
            if (idDaRequisicao !== requestId) return;

            if (obras.length === 0) {
                const vazio = criar(
                    "div",
                    "biblioteca-vazio"
                );

                vazio.appendChild(
                    criar(
                        "h2",
                        "",
                        nomesColecoes[colecao].titulo
                    )
                );

                vazio.appendChild(
                    criar(
                        "p",
                        "",
                        nomesColecoes[colecao].texto
                    )
                );

                const acoes = criar(
                    "div",
                    "biblioteca-card-acoes"
                );

                const link = criar(
                    "a",
                    "",
                    "Encontrar uma obra"
                );

                link.href = new URL(
                    "catalogo.html",
                    document.baseURI
                ).href;

                acoes.appendChild(link);
                vazio.appendChild(acoes);

                lista.replaceChildren(vazio);
                return;
            }

            lista.replaceChildren(
                ...obras.map(
                    (obra) => criarCartao(obra, colecao)
                )
            );

        } catch (erro) {
            if (idDaRequisicao !== requestId) return;

            if (erro.status === 401) {
                exibirLoginNecessario(erro.message);
                return;
            }

            lista.replaceChildren(
                criar(
                    "div",
                    "biblioteca-erro",
                    erro.message ||
                    "Não foi possível carregar sua biblioteca."
                )
            );
        }
    }

    /*
     * Eventos das abas.
     */
    abas.forEach((aba) => {
        aba.addEventListener("click", () => {
            carregarColecao(aba.dataset.colecao);
        });
    });

    /*
     * Carregamento inicial.
     */
    carregarColecao(colecaoAtual);
    atualizarContagens();

    /*
     * Atualiza os dados quando a sessão é confirmada.
     * Isso também trata a inicialização assíncrona do cabeçalho global.
     */
    document.addEventListener(
        "arteguiada:estado-sessao",
        (evento) => {
            if (evento.detail?.autenticado) {
                carregarColecao(colecaoAtual);
                atualizarContagens();
            }
        }
    );

})();
