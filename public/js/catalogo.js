/*
 * Arte Guiada
 * Catálogo público de obras.
 */

(() => {
    "use strict";

    const listaObras = document.getElementById("catalogoObras");
    const feedback = document.getElementById("feedbackCatalogo");

    const botaoBusca = document.getElementById("toggleBuscaCatalogo");
    const painelBusca = document.getElementById("painelBuscaCatalogo");
    const campoBusca = document.getElementById("buscaObra");
    const botaoFecharBusca = document.getElementById("fecharBuscaCatalogo");

    const API_OBRAS = "../api/obras/publicadas.php";
    const API_IMAGEM = "../api/obras/imagem-publica.php";

    let obrasPublicadas = [];

    if (!listaObras) {
        console.error("Catálogo: elemento #catalogoObras não encontrado.");
        return;
    }

    function informar(mensagem = "") {
        if (feedback) {
            feedback.textContent = mensagem;
        }
    }

    function normalizarTexto(texto) {
        return String(texto ?? "")
            .normalize("NFD")
            .replace(/[\u0300-\u036f]/g, "")
            .toLocaleLowerCase("pt-BR")
            .trim();
    }

    function criarEstadoVazio(tituloTexto, descricaoTexto) {
        const estado = document.createElement("div");
        estado.className = "catalogo-estado-vazio";

        const titulo = document.createElement("h2");
        titulo.textContent = tituloTexto;

        const descricao = document.createElement("p");
        descricao.textContent = descricaoTexto;

        estado.append(titulo, descricao);

        return estado;
    }

    function criarCartaoObra(obra) {
        const id = Number(obra?.id);

        if (!Number.isInteger(id) || id < 1) {
            console.error("Catálogo: identificador inválido.", obra);
            return null;
        }

        const tituloTexto = String(obra.titulo || "Obra sem título");
        const autorTexto = String(obra.autor || "Autoria não informada");

        /*
         * Cada cartão utiliza o ID da respectiva obra.
         */

        const urlGaleria = `galeria.html?id=${encodeURIComponent(id)}`;
        const urlImagem = `${API_IMAGEM}?id=${encodeURIComponent(id)}`;

        const cartao = document.createElement("article");
        cartao.className = "obra-card";
        cartao.dataset.obraId = String(id);

        const areaImagem = document.createElement("div");
        areaImagem.className = "obra-card-imagem";

        const linkImagem = document.createElement("a");
        linkImagem.href = urlGaleria;
        linkImagem.setAttribute("aria-label", `Explorar ${tituloTexto}`);

        const imagem = document.createElement("img");
        imagem.src = urlImagem;
        imagem.alt = String(
            obra.texto_alternativo || `${tituloTexto}, de ${autorTexto}.`
        );
        imagem.loading = "lazy";
        imagem.decoding = "async";

        imagem.addEventListener("error", () => {
            console.error(`Não foi possível carregar a imagem da obra ${id}.`);

            imagem.removeAttribute("src");
            imagem.alt = `Imagem indisponível: ${tituloTexto}.`;
            areaImagem.classList.add("obra-card-imagem-indisponivel");
        }, { once: true });

        linkImagem.appendChild(imagem);
        areaImagem.appendChild(linkImagem);

        const conteudo = document.createElement("div");
        conteudo.className = "obra-card-conteudo";

        const artista = document.createElement("span");
        artista.className = "obra-artista";
        artista.textContent = autorTexto;

        const titulo = document.createElement("h2");
        titulo.className = "obra-card-titulo";
        titulo.textContent = tituloTexto;

        const etapas = document.createElement("p");
        etapas.className = "obra-card-etapas";

        const quantidadeEtapas = Number(obra.total_etapas);

        etapas.textContent =
            Number.isInteger(quantidadeEtapas) && quantidadeEtapas >= 0
                ? `${quantidadeEtapas} ${quantidadeEtapas === 1 ? "etapa" : "etapas"
                } para explorar`
                : "Explore os detalhes desta obra";

        const linkExplorar = document.createElement("a");
        linkExplorar.href = urlGaleria;
        linkExplorar.className = "obra-link";
        linkExplorar.textContent = "Explorar obra →";

        conteudo.append(artista, titulo, etapas, linkExplorar);
        cartao.append(areaImagem, conteudo);

        return cartao;
    }

    function renderizarObras() {
        const termo = normalizarTexto(campoBusca?.value ?? "");

        const filtradas = obrasPublicadas.filter((obra) => {
            return normalizarTexto(obra.titulo).includes(termo) ||
                normalizarTexto(obra.autor).includes(termo);
        });

        listaObras.replaceChildren();

        if (filtradas.length === 0) {
            const semPublicacoes =
                obrasPublicadas.length === 0 && termo === "";

            listaObras.appendChild(
                criarEstadoVazio(
                    semPublicacoes
                        ? "Nenhuma obra publicada"
                        : "Nenhuma obra encontrada",
                    semPublicacoes
                        ? "As obras publicadas aparecerão nesta galeria."
                        : "Tente pesquisar por outro título ou artista."
                )
            );

            informar("");
            return;
        }

        const fragmento = document.createDocumentFragment();

        filtradas.forEach((obra) => {
            const cartao = criarCartaoObra(obra);

            if (cartao) {
                fragmento.appendChild(cartao);
            }
        });

        if (fragmento.childElementCount === 0) {
            listaObras.appendChild(
                criarEstadoVazio(
                    "Não foi possível exibir as obras",
                    "Os dados recebidos não contêm identificadores válidos."
                )
            );

            informar("Não foi possível montar os cartões.");
            return;
        }

        listaObras.appendChild(fragmento);
        informar("");
    }

    async function carregarObrasPublicadas() {
        listaObras.setAttribute("aria-busy", "true");
        informar("");

        try {
            const resposta = await fetch(API_OBRAS, {
                method: "GET",
                cache: "no-store",
                headers: {
                    Accept: "application/json"
                }
            });

            const dados = await resposta.json().catch(() => null);

            if (
                !resposta.ok ||
                !dados?.sucesso ||
                !Array.isArray(dados.obras)
            ) {
                throw new Error(
                    dados?.mensagem ||
                    "Não foi possível carregar as obras publicadas."
                );
            }

            obrasPublicadas = dados.obras.filter((obra) => {
                const id = Number(obra?.id);
                return Number.isInteger(id) && id > 0;
            });

            renderizarObras();

        } catch (erro) {
            console.error("Erro ao carregar o catálogo:", erro);

            listaObras.replaceChildren();

            const estado = criarEstadoVazio(
                "Não foi possível carregar a galeria",
                erro.message ||
                "Verifique a conexão com o servidor e tente novamente."
            );

            const tentarNovamente = document.createElement("button");
            tentarNovamente.type = "button";
            tentarNovamente.className = "catalogo-tentar-novamente";
            tentarNovamente.textContent = "Tentar novamente";
            tentarNovamente.addEventListener(
                "click",
                carregarObrasPublicadas
            );

            estado.appendChild(tentarNovamente);
            listaObras.appendChild(estado);

            informar("Ocorreu um erro ao consultar o acervo.");

        } finally {
            listaObras.setAttribute("aria-busy", "false");
        }
    }

    /*
     * Busca por título ou artista.
     */

    campoBusca?.addEventListener("input", renderizarObras);

    /*
     * Abre e fecha a busca.
     */

    function fecharBusca(devolverFoco = false) {
        if (!painelBusca || !botaoBusca) {
            return;
        }

        painelBusca.hidden = true;
        botaoBusca.setAttribute("aria-expanded", "false");
        botaoBusca.setAttribute("aria-label", "Abrir busca de obras");

        if (devolverFoco) {
            botaoBusca.focus();
        }
    }

    if (botaoBusca && painelBusca) {
        painelBusca.hidden = true;
        botaoBusca.setAttribute("aria-expanded", "false");

        botaoBusca.addEventListener("click", () => {
            if (painelBusca.hidden) {
                painelBusca.hidden = false;
                botaoBusca.setAttribute("aria-expanded", "true");
                botaoBusca.setAttribute("aria-label", "Fechar busca de obras");
                campoBusca?.focus();
            } else {
                fecharBusca();
            }
        });

        botaoFecharBusca?.addEventListener("click", () => {
            fecharBusca(true);
        });

        painelBusca.addEventListener("keydown", (evento) => {
            if (evento.key === "Escape") {
                fecharBusca(true);
            }
        });
    }

    carregarObrasPublicadas();

})();