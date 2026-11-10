/*
 * Arte Guiada
 * Galeria dinâmica de obras publicadas.
 *
 * Carrega a obra pelo ID da URL e utiliza as etapas e regiões
 * de zoom salvas pelo editor. O botão de tela cheia é opcional,
 * pois sessao-ui.js pode reconstruir o cabeçalho e removê-lo.
 */

(() => {
    "use strict";

    /* =====================================================
       CONFIGURAÇÃO DAS APIs
    ===================================================== */

    const API_OBRAS = "../api/obras/publicadas.php";
    const API_DETALHE = "../api/obras/visualizar.php";
    const API_IMAGEM = "../api/obras/imagem-publica.php";

    /* =====================================================
       ELEMENTOS DA PÁGINA
    ===================================================== */

    const frame = document.querySelector(".artwork-frame");

    const camadaInicial = document.getElementById("artworkCurrent");
    const camadaSeguinte = document.getElementById("artworkNext");

    const artistaElemento = document.getElementById("artistName");
    const tituloObraElemento = document.getElementById("artworkTitle");

    const secaoNarrativa = document.querySelector(".narrative-section");
    const contadorDetalhe = document.getElementById("slideCounter");
    const numeroDetalhe = document.getElementById("detailNumber");
    const tituloDetalhe = document.getElementById("detailTitle");
    const textoDetalhe = document.getElementById("detailDescription");

    const botaoFavorito = document.getElementById("botaoFavorito");
    const botaoQueroVer = document.getElementById("botaoQueroVer");

    const linkLoginBiblioteca = document.getElementById(
        "linkLoginBiblioteca"
    );

    const feedbackBibliotecaGaleria = document.getElementById(
        "feedbackBibliotecaGaleria"
    );

    const botaoRelatarProblema = document.getElementById(
        "abrirRelatoProblema"
    );

    const botaoAnterior = document.getElementById("previousButton");
    const botaoProximo = document.getElementById("nextButton");

    const barraProgresso = document.getElementById("progressBar");
    const preenchimentoProgresso = document.getElementById("progressFill");
    const textoProgresso = document.getElementById("progressText");

    let botaoTelaCheia = document.getElementById("fullscreenButton");

    const painelCompleto = document.getElementById("completeArtwork");
    const botaoVerCompleta = document.getElementById("viewCompleteButton");
    const botaoFecharCompleta = document.getElementById("closeCompleteButton");

    const imagemCompleta = document.getElementById("completeImage");
    const artistaCompleta = document.getElementById("completeArtist");
    const tituloCompleto = document.getElementById("completeTitle");

    /* =====================================================
       VALIDAÇÃO DA ESTRUTURA HTML
    ===================================================== */

    const elementosObrigatorios = [
        [frame, ".artwork-frame"],
        [camadaInicial, "#artworkCurrent"],
        [camadaSeguinte, "#artworkNext"],
        [artistaElemento, "#artistName"],
        [tituloObraElemento, "#artworkTitle"],
        [secaoNarrativa, ".narrative-section"],
        [contadorDetalhe, "#slideCounter"],
        [numeroDetalhe, "#detailNumber"],
        [tituloDetalhe, "#detailTitle"],
        [textoDetalhe, "#detailDescription"],
        [botaoAnterior, "#previousButton"],
        [botaoProximo, "#nextButton"],
        [barraProgresso, "#progressBar"],
        [preenchimentoProgresso, "#progressFill"],
        [textoProgresso, "#progressText"],
        [painelCompleto, "#completeArtwork"],
        [botaoVerCompleta, "#viewCompleteButton"],
        [botaoFecharCompleta, "#closeCompleteButton"],
        [imagemCompleta, "#completeImage"],
        [artistaCompleta, "#completeArtist"],
        [tituloCompleto, "#completeTitle"]
    ];

    const elementosAusentes = elementosObrigatorios
        .filter(([elemento]) => !elemento)
        .map(([, seletor]) => seletor);

    if (elementosAusentes.length > 0) {
        console.error(
            "Galeria: faltam elementos obrigatórios em views/galeria.html:",
            elementosAusentes.join(", ")
        );

        return;
    }

    /* =====================================================
       ESTADO DA EXPERIÊNCIA
    ===================================================== */

    let obraAtual = null;
    let obraIdAtual = null;

    let etapas = [];
    let indiceAtual = 0;

    let camadaAtual = camadaInicial;
    let camadaProxima = camadaSeguinte;

    let emTransicao = false;
    let temporizadorTransicao = null;

    let focoAnteriorModal = null;
    let overflowAnterior = "";

    /* =====================================================
       ESTADO DA BIBLIOTECA
    ===================================================== */

    let estadoBiblioteca = {
        favorito: false,
        quero_explorar: false,
        explorada: false
    };

    let bibliotecaAutenticada = false;
    let atualizandoBiblioteca = false;

    let conclusaoEmAndamento = false;
    let conclusaoRegistrada = false;

    /* =====================================================
       RELATO DE PROBLEMAS
    ===================================================== */

    function atualizarDisponibilidadeBotaoRelato(disponivel) {
        if (!botaoRelatarProblema) return;

        botaoRelatarProblema.disabled = !disponivel;

        botaoRelatarProblema.setAttribute(
            "aria-disabled",
            String(!disponivel)
        );
    }

    function abrirPaginaRelato() {
        if (
            !Number.isSafeInteger(obraIdAtual) ||
            obraIdAtual < 1
        ) {
            return;
        }

        const destino = new URL(
            "relatar-problema.html",
            document.baseURI
        );

        destino.searchParams.set(
            "obra_id",
            String(obraIdAtual)
        );

        window.location.assign(destino.href);
    }

    /* =====================================================
       FUNÇÕES AUXILIARES
    ===================================================== */

    function urlApiBiblioteca(arquivo) {
        return new URL(
            `../api/biblioteca/${arquivo}.php`,
            document.baseURI
        );
    }

    function mostrarFeedbackBiblioteca(mensagem = "") {
        if (!feedbackBibliotecaGaleria) return;

        feedbackBibliotecaGaleria.textContent = mensagem;
        feedbackBibliotecaGaleria.hidden = !mensagem;
    }

    function mostrarLoginBiblioteca() {
        bibliotecaAutenticada = false;

        if (botaoFavorito) botaoFavorito.hidden = true;
        if (botaoQueroVer) botaoQueroVer.hidden = true;

        if (linkLoginBiblioteca) {
            linkLoginBiblioteca.hidden = false;
        }

        mostrarFeedbackBiblioteca(
            "Entre na sua conta para salvar e acompanhar suas obras."
        );
    }

    function atualizarBotoesBiblioteca() {
        if (!bibliotecaAutenticada) return;

        if (botaoFavorito) {
            botaoFavorito.hidden = false;
            botaoFavorito.disabled = false;

            botaoFavorito.textContent = estadoBiblioteca.favorito
                ? "♥ Nos favoritos"
                : "♡ Favoritar";

            botaoFavorito.setAttribute(
                "aria-pressed",
                String(estadoBiblioteca.favorito)
            );
        }

        if (botaoQueroVer) {
            botaoQueroVer.hidden = false;
            botaoQueroVer.disabled = false;

            botaoQueroVer.textContent = estadoBiblioteca.quero_explorar
                ? "✓ Na lista Quero ver"
                : "+ Quero ver";

            botaoQueroVer.setAttribute(
                "aria-pressed",
                String(estadoBiblioteca.quero_explorar)
            );
        }

        if (linkLoginBiblioteca) {
            linkLoginBiblioteca.hidden = true;
        }
    }

    /*
     * Consulta os estados já salvos para a obra atual.
     */
    async function carregarEstadoBiblioteca() {
        if (!obraAtual) return;

        try {
            const destino = urlApiBiblioteca("estado");

            destino.searchParams.set(
                "obra_id",
                String(obraAtual.id)
            );

            const resposta = await fetch(destino.href, {
                method: "GET",
                credentials: "same-origin",
                cache: "no-store",
                headers: {
                    "Accept": "application/json"
                }
            });

            const dados = await resposta.json().catch(() => null);

            if (resposta.status === 401) {
                mostrarLoginBiblioteca();
                return;
            }

            if (!resposta.ok || !dados?.sucesso || !dados.estado) {
                throw new Error(
                    dados?.mensagem ||
                    "Não foi possível consultar sua biblioteca."
                );
            }

            estadoBiblioteca = {
                favorito: Boolean(dados.estado.favorito),
                quero_explorar: Boolean(dados.estado.quero_explorar),
                explorada: Boolean(dados.estado.explorada)
            };

            bibliotecaAutenticada = true;

            if (estadoBiblioteca.explorada) {
                conclusaoRegistrada = true;
            }

            atualizarBotoesBiblioteca();
            mostrarFeedbackBiblioteca("");

        } catch (erro) {
            console.error(
                "Erro ao consultar o estado da biblioteca:",
                erro
            );

            mostrarFeedbackBiblioteca(
                erro.message ||
                "Não foi possível consultar sua biblioteca."
            );
        }
    }

    /*
     * Adiciona ou remove a obra de Favoritos ou Quero ver.
     */
    async function alterarEstadoBiblioteca(acao) {
        if (
            !obraAtual ||
            !bibliotecaAutenticada ||
            atualizandoBiblioteca
        ) {
            return;
        }

        atualizandoBiblioteca = true;

        const botao = acao === "favorito"
            ? botaoFavorito
            : botaoQueroVer;

        if (botao) botao.disabled = true;

        mostrarFeedbackBiblioteca("Atualizando sua biblioteca...");

        try {
            const resposta = await fetch(
                urlApiBiblioteca("atualizar").href,
                {
                    method: "POST",
                    credentials: "same-origin",
                    cache: "no-store",
                    headers: {
                        "Content-Type": "application/json",
                        "Accept": "application/json"
                    },
                    body: JSON.stringify({
                        obra_id: Number(obraAtual.id),
                        acao,
                        ativo: !estadoBiblioteca[acao]
                    })
                }
            );

            const dados = await resposta.json().catch(() => null);

            if (resposta.status === 401) {
                mostrarLoginBiblioteca();
                return;
            }

            if (!resposta.ok || !dados?.sucesso || !dados.estado) {
                throw new Error(
                    dados?.mensagem ||
                    "Não foi possível atualizar sua biblioteca."
                );
            }

            estadoBiblioteca = {
                favorito: Boolean(dados.estado.favorito),
                quero_explorar: Boolean(dados.estado.quero_explorar),
                explorada: Boolean(dados.estado.explorada)
            };

            atualizarBotoesBiblioteca();

            const adicionado = Boolean(dados.estado[acao]);

            mostrarFeedbackBiblioteca(
                adicionado
                    ? "Obra adicionada à sua biblioteca."
                    : "Obra removida da coleção."
            );

        } catch (erro) {
            console.error(
                "Erro ao atualizar a biblioteca:",
                erro
            );

            mostrarFeedbackBiblioteca(
                erro.message ||
                "Não foi possível atualizar sua biblioteca."
            );

        } finally {
            atualizandoBiblioteca = false;

            if (bibliotecaAutenticada) {
                if (botaoFavorito) botaoFavorito.disabled = false;
                if (botaoQueroVer) botaoQueroVer.disabled = false;
            }
        }
    }

    /*
     * Registra automaticamente a conclusão quando a última etapa
     * da experiência é apresentada.
     */
    async function registrarConclusaoAutomaticamente() {
        if (
            !obraAtual ||
            etapas.length === 0 ||
            indiceAtual !== etapas.length - 1 ||
            conclusaoRegistrada ||
            conclusaoEmAndamento
        ) {
            return;
        }

        conclusaoEmAndamento = true;

        try {
            const resposta = await fetch(
                urlApiBiblioteca("concluir").href,
                {
                    method: "POST",
                    credentials: "same-origin",
                    cache: "no-store",
                    headers: {
                        "Content-Type": "application/json",
                        "Accept": "application/json"
                    },
                    body: JSON.stringify({
                        obra_id: Number(obraAtual.id),
                        etapas_concluidas: etapas.length
                    })
                }
            );

            const dados = await resposta.json().catch(() => null);

            if (resposta.status === 401) {
                mostrarLoginBiblioteca();
                return;
            }

            if (!resposta.ok || !dados?.sucesso) {
                throw new Error(
                    dados?.mensagem ||
                    "Não foi possível registrar a conclusão da experiência."
                );
            }

            conclusaoRegistrada = true;
            estadoBiblioteca.explorada = true;

            mostrarFeedbackBiblioteca(
                "Experiência concluída! A obra foi registrada em Já explorei."
            );

        } catch (erro) {
            console.error(
                "Erro ao registrar conclusão:",
                erro
            );

            mostrarFeedbackBiblioteca(
                erro.message ||
                "Não foi possível registrar sua conclusão."
            );

        } finally {
            conclusaoEmAndamento = false;
        }
    }

    /*
     * Consulta JSON e valida a resposta da API.
     */
    async function consultarJson(url) {
        const resposta = await fetch(url, {
            method: "GET",
            cache: "no-store",
            headers: {
                Accept: "application/json"
            }
        });

        const dados = await resposta.json().catch(() => null);

        if (!resposta.ok || !dados || dados.sucesso !== true) {
            throw new Error(
                dados?.mensagem ||
                `Não foi possível consultar os dados da obra (HTTP ${resposta.status}).`
            );
        }

        return dados;
    }

    function normalizarTexto(texto) {
        return String(texto ?? "")
            .normalize("NFD")
            .replace(/[\u0300-\u036f]/g, "")
            .toLocaleLowerCase("pt-BR")
            .trim();
    }

    /*
     * Se houver ID na URL, ele sempre tem prioridade.
     * A Boba só é escolhida quando não há ID na URL.
     */
    async function descobrirIdInicial() {
        const parametro = new URLSearchParams(
            window.location.search
        ).get("id");

        if (parametro !== null) {
            if (!/^[1-9]\d*$/.test(parametro)) {
                throw new Error(
                    "O identificador da obra é inválido."
                );
            }

            return Number(parametro);
        }

        const resultado = await consultarJson(API_OBRAS);
        const obras = resultado.obras;

        if (!Array.isArray(obras) || obras.length === 0) {
            throw new Error(
                "Ainda não existem obras publicadas."
            );
        }

        const boba = obras.find(
            (obra) =>
                normalizarTexto(obra.titulo) === "a boba"
        );

        return Number((boba || obras[0]).id);
    }

    /*
     * Carrega uma imagem e também trata imagens já presentes no cache.
     */
    function carregarImagem(imagem, url, textoAlternativo) {
        imagem.alt = textoAlternativo || "";

        return new Promise((resolve, reject) => {
            let finalizado = false;

            const limparEventos = () => {
                imagem.removeEventListener("load", carregada);
                imagem.removeEventListener("error", falhou);
            };

            const finalizar = (callback, valor) => {
                if (finalizado) {
                    return;
                }

                finalizado = true;
                limparEventos();
                callback(valor);
            };

            const carregada = () => finalizar(resolve);

            const falhou = () => finalizar(
                reject,
                new Error(
                    "Não foi possível carregar a imagem da obra."
                )
            );

            imagem.addEventListener("load", carregada);
            imagem.addEventListener("error", falhou);

            imagem.src = url;

            if (imagem.complete) {
                if (imagem.naturalWidth > 0) {
                    finalizar(resolve);
                } else {
                    finalizar(
                        reject,
                        new Error(
                            "Não foi possível carregar a imagem da obra."
                        )
                    );
                }
            }
        });
    }

    /*
     * Retorna o retângulo de zoom somente quando todos os valores
     * foram salvos e são válidos. Valores NULL não viram zero.
     */
    function obterRetanguloZoom(etapa) {
        if (!etapa) {
            return null;
        }

        const valores = [
            etapa.zoom_x,
            etapa.zoom_y,
            etapa.zoom_largura,
            etapa.zoom_altura
        ];

        if (
            valores.some(
                (valor) =>
                    valor === null ||
                    valor === undefined ||
                    valor === ""
            )
        ) {
            return null;
        }

        const [x, y, largura, altura] = valores.map(Number);

        if (
            ![x, y, largura, altura].every(Number.isFinite) ||
            x < 0 ||
            y < 0 ||
            largura <= 0 ||
            altura <= 0 ||
            x + largura > 1.000001 ||
            y + altura > 1.000001
        ) {
            return null;
        }

        return {
            x,
            y,
            largura,
            altura
        };
    }

    /*
     * Ajusta a área da galeria à proporção do detalhe selecionado no editor.
     */
    function aplicarEnquadramento(imagem, etapa, animar = true) {
        if (!imagem || !imagem.naturalWidth || !imagem.naturalHeight) {
            return;
        }

        const areaObra = frame.parentElement;

        const larguraDisponivel = Math.max(
            1,
            areaObra?.clientWidth || frame.clientWidth || window.innerWidth
        );

        const alturaDisponivel = Math.max(
            1,
            window.innerHeight * 0.88
        );

        const retangulo = obterRetanguloZoom(etapa);

        const proporcao = retangulo
            ? (
                retangulo.largura * imagem.naturalWidth
            ) / (
                retangulo.altura * imagem.naturalHeight
            )
            : imagem.naturalWidth / imagem.naturalHeight;

        const larguraFrame = Math.max(
            1,
            Math.min(larguraDisponivel, alturaDisponivel * proporcao)
        );

        const alturaFrame = larguraFrame / proporcao;

        Object.assign(frame.style, {
            width: `${larguraFrame}px`,
            height: `${alturaFrame}px`,
            aspectRatio: "auto",
            maxWidth: "100%",
            maxHeight: "none",
            flex: "0 0 auto"
        });

        const escalaBase = Math.max(
            larguraFrame / imagem.naturalWidth,
            alturaFrame / imagem.naturalHeight
        );

        const larguraImagem = imagem.naturalWidth * escalaBase;
        const alturaImagem = imagem.naturalHeight * escalaBase;

        const baseX = (larguraFrame - larguraImagem) / 2;
        const baseY = (alturaFrame - alturaImagem) / 2;

        let escala = 1;
        let deslocamentoX = baseX;
        let deslocamentoY = baseY;

        if (retangulo) {
            const centroX =
                retangulo.x + retangulo.largura / 2;

            const centroY =
                retangulo.y + retangulo.altura / 2;

            const escalaHorizontal =
                larguraFrame / (retangulo.largura * larguraImagem);

            const escalaVertical =
                alturaFrame / (retangulo.altura * alturaImagem);

            escala = Math.min(
                escalaHorizontal,
                escalaVertical
            );

            deslocamentoX =
                larguraFrame / 2 -
                centroX * larguraImagem * escala;

            deslocamentoY =
                alturaFrame / 2 -
                centroY * alturaImagem * escala;
        }

        if (!animar) {
            imagem.style.transition = "none";
        }

        Object.assign(imagem.style, {
            position: "absolute",
            inset: "auto",
            left: "0",
            top: "0",
            right: "auto",
            bottom: "auto",

            width: `${larguraImagem}px`,
            height: `${alturaImagem}px`,

            maxWidth: "none",
            maxHeight: "none",

            objectFit: "fill",
            objectPosition: "0 0",

            transformOrigin: "top left",
            transform:
                `translate(${deslocamentoX}px, ${deslocamentoY}px) scale(${escala})`
        });

        if (!animar) {
            requestAnimationFrame(() => {
                imagem.style.removeProperty("transition");
            });
        }
    }

    /*
     * Atualiza o texto, o contador e a barra de progresso.
     */
    function atualizarNarrativa(etapa, indice) {
        const total = etapas.length;

        numeroDetalhe.textContent =
            String(indice + 1).padStart(2, "0");

        tituloDetalhe.textContent =
            etapa.titulo || `Etapa ${indice + 1}`;

        textoDetalhe.textContent =
            etapa.texto ||
            "Esta etapa ainda não possui uma explicação.";

        contadorDetalhe.textContent =
            `${String(indice + 1).padStart(2, "0")} / ${String(total).padStart(2, "0")}`;

        textoProgresso.textContent =
            `${indice + 1} de ${total} ${total === 1 ? "detalhe" : "detalhes"}`;

        preenchimentoProgresso.style.width =
            `${((indice + 1) / total) * 100}%`;

        barraProgresso.setAttribute("aria-valuemin", "1");
        barraProgresso.setAttribute(
            "aria-valuemax",
            String(total)
        );
        barraProgresso.setAttribute(
            "aria-valuenow",
            String(indice + 1)
        );

        botaoAnterior.disabled = indice === 0;
        botaoProximo.disabled = indice === total - 1;
    }

    function mostrarNarrativa(etapa, indice, animar = true) {
        if (!animar) {
            atualizarNarrativa(etapa, indice);

            if (indice === etapas.length - 1) {
                registrarConclusaoAutomaticamente();
            }

            return;
        }

        secaoNarrativa.classList.remove("is-entering");
        secaoNarrativa.classList.add("is-changing");

        window.setTimeout(() => {
            atualizarNarrativa(etapa, indice);

            if (indice === etapas.length - 1) {
                registrarConclusaoAutomaticamente();
            }

            secaoNarrativa.classList.remove("is-changing");
            secaoNarrativa.classList.add("is-entering");

            window.setTimeout(() => {
                secaoNarrativa.classList.remove("is-entering");
            }, 650);
        }, 220);
    }

    /*
     * Estado para obras sem etapas cadastradas.
     */
    function mostrarSemEtapas() {
        indiceAtual = 0;

        numeroDetalhe.textContent = "—";

        tituloDetalhe.textContent =
            "Esta obra ainda não tem etapas";

        textoDetalhe.textContent =
            "A imagem está publicada, mas nenhuma etapa de exploração foi cadastrada.";

        contadorDetalhe.textContent = "00 / 00";
        textoProgresso.textContent = "Nenhuma etapa cadastrada";

        preenchimentoProgresso.style.width = "0%";

        barraProgresso.setAttribute("aria-valuemin", "0");
        barraProgresso.setAttribute("aria-valuemax", "0");
        barraProgresso.setAttribute("aria-valuenow", "0");

        botaoAnterior.disabled = true;
        botaoProximo.disabled = true;

        aplicarEnquadramento(
            camadaAtual.querySelector("img"),
            null,
            false
        );

        aplicarEnquadramento(
            camadaProxima.querySelector("img"),
            null,
            false
        );
    }

    /*
     * Transição visual entre as etapas.
     */
    function navegarParaEtapa(novoIndice) {
        if (
            emTransicao ||
            novoIndice < 0 ||
            novoIndice >= etapas.length ||
            novoIndice === indiceAtual
        ) {
            return;
        }

        emTransicao = true;

        const imagemNova = camadaProxima.querySelector("img");

        aplicarEnquadramento(
            imagemNova,
            etapas[novoIndice],
            true
        );

        indiceAtual = novoIndice;

        mostrarNarrativa(
            etapas[indiceAtual],
            indiceAtual,
            true
        );

        frame.classList.add("is-transitioning");

        if (temporizadorTransicao !== null) {
            clearTimeout(temporizadorTransicao);
        }

        temporizadorTransicao = window.setTimeout(() => {
            frame.classList.remove("is-transitioning");

            camadaAtual.classList.remove("artwork-layer-current");
            camadaAtual.classList.add("artwork-layer-next");

            camadaProxima.classList.remove("artwork-layer-next");
            camadaProxima.classList.add("artwork-layer-current");

            const temporaria = camadaAtual;

            camadaAtual = camadaProxima;
            camadaProxima = temporaria;

            aplicarEnquadramento(
                camadaAtual.querySelector("img"),
                etapas[indiceAtual],
                false
            );

            emTransicao = false;
            temporizadorTransicao = null;
        }, 750);
    }

    /*
     * Abre e fecha a visualização da obra completa.
     */
    function abrirObraCompleta() {
        focoAnteriorModal = document.activeElement;
        overflowAnterior = document.body.style.overflow;

        painelCompleto.classList.add("is-open");
        painelCompleto.setAttribute("aria-hidden", "false");

        document.body.style.overflow = "hidden";

        botaoFecharCompleta.focus();
    }

    function fecharObraCompleta() {
        painelCompleto.classList.remove("is-open");
        painelCompleto.setAttribute("aria-hidden", "true");

        document.body.style.overflow = overflowAnterior;

        if (focoAnteriorModal instanceof HTMLElement) {
            focoAnteriorModal.focus();
        }
    }

    /*
     * Controle de tela cheia.
     */
    async function alternarTelaCheia() {
        try {
            if (!document.fullscreenElement) {
                await frame.requestFullscreen();
            } else {
                await document.exitFullscreen();
            }
        } catch (erro) {
            console.error(
                "Não foi possível alternar a tela cheia:",
                erro
            );
        }
    }

    function atualizarBotaoTelaCheia() {
        botaoTelaCheia =
            document.getElementById("fullscreenButton");

        if (!botaoTelaCheia) {
            return;
        }

        const ativo = Boolean(document.fullscreenElement);

        botaoTelaCheia.setAttribute(
            "aria-label",
            ativo ? "Sair da tela cheia" : "Entrar em tela cheia"
        );

        const texto =
            botaoTelaCheia.querySelector(".fullscreen-text");

        if (texto) {
            texto.textContent = ativo
                ? "Sair da tela cheia"
                : "Tela cheia";
        }
    }

    /*
     * Recria o botão de tela cheia caso ele tenha sido removido
     * do cabeçalho por outro script.
     */
    function instalarControleTelaCheia() {
        const cabecalho = document.querySelector(".site-header");

        if (!cabecalho) {
            return;
        }

        let botao = document.getElementById("fullscreenButton");

        if (!botao) {
            botao = document.createElement("button");

            botao.type = "button";
            botao.id = "fullscreenButton";
            botao.className = "fullscreen-button";

            botao.setAttribute(
                "aria-label",
                "Entrar em tela cheia"
            );

            const icone = document.createElement("span");
            icone.setAttribute("aria-hidden", "true");
            icone.textContent = "⛶";

            const texto = document.createElement("span");
            texto.className = "fullscreen-text";
            texto.textContent = "Tela cheia";

            botao.append(icone, texto);
            cabecalho.appendChild(botao);
        }

        botaoTelaCheia = botao;

        if (botao.dataset.galeriaEvento !== "instalado") {
            botao.addEventListener(
                "click",
                alternarTelaCheia
            );

            botao.dataset.galeriaEvento = "instalado";
        }

        atualizarBotaoTelaCheia();
    }

    /*
     * Apresenta uma mensagem quando a obra não puder ser carregada.
     */
    function mostrarErro(mensagem) {
        artistaElemento.textContent = "";

        tituloObraElemento.textContent =
            "Não foi possível abrir a obra";

        numeroDetalhe.textContent = "—";
        tituloDetalhe.textContent = "Ocorreu um problema";

        textoDetalhe.textContent = mensagem;

        contadorDetalhe.textContent = "—";
        textoProgresso.textContent = "";

        preenchimentoProgresso.style.width = "0%";

        botaoAnterior.disabled = true;
        botaoProximo.disabled = true;

        [
            camadaInicial.querySelector("img"),
            camadaSeguinte.querySelector("img"),
            imagemCompleta
        ].forEach((imagem) => {
            if (!imagem) return;

            imagem.removeAttribute("src");
            imagem.alt = "";
        });

        console.error("Galeria:", mensagem);
    }

    /*
     * Carrega a obra selecionada, sua imagem e suas etapas.
     */
    async function iniciar() {
        try {
            const id = await descobrirIdInicial();

            /*
             * Guarda o ID assim que ele é identificado.
             * O botão de relato é liberado antes do carregamento
             * da imagem, permitindo relatar falhas na própria imagem.
             */
            obraIdAtual = id;
            atualizarDisponibilidadeBotaoRelato(true);

            const resultado = await consultarJson(
                `${API_DETALHE}?id=${encodeURIComponent(id)}`
            );

            obraAtual = resultado.obra;

            if (
                !obraAtual ||
                Number(obraAtual.id) !== Number(id)
            ) {
                throw new Error(
                    "A API não retornou a obra solicitada."
                );
            }

            carregarEstadoBiblioteca();

            if (!obraAtual.imagem_salva) {
                throw new Error(
                    "Esta obra ainda não possui uma imagem salva."
                );
            }

            etapas = Array.isArray(obraAtual.etapas)
                ? obraAtual.etapas
                : [];

            const titulo =
                obraAtual.titulo || "Obra sem título";

            const autor =
                obraAtual.autor || "Autoria não informada";

            artistaElemento.textContent = autor;
            tituloObraElemento.textContent = titulo;

            artistaCompleta.textContent = autor;
            tituloCompleto.textContent = titulo;

            document.title = `Arte Guiada | ${titulo}`;

            const descricaoImagem =
                obraAtual.texto_alternativo ||
                `${titulo}, de ${autor}.`;

            const urlImagem =
                `${API_IMAGEM}?id=${encodeURIComponent(obraAtual.id)}&v=${Date.now()}`;

            const imagens = [
                camadaAtual.querySelector("img"),
                camadaProxima.querySelector("img"),
                imagemCompleta
            ];

            await Promise.all(
                imagens.map((imagem) =>
                    carregarImagem(
                        imagem,
                        urlImagem,
                        descricaoImagem
                    )
                )
            );

            if (etapas.length === 0) {
                mostrarSemEtapas();
                return;
            }

            indiceAtual = 0;

            aplicarEnquadramento(
                camadaAtual.querySelector("img"),
                etapas[0],
                false
            );

            aplicarEnquadramento(
                camadaProxima.querySelector("img"),
                etapas[0],
                false
            );

            mostrarNarrativa(
                etapas[0],
                0,
                false
            );

        } catch (erro) {
            mostrarErro(
                erro?.message ||
                "Não foi possível carregar esta obra."
            );

            /*
             * Não desabilita o botão de relato aqui.
             * Se o ID já foi identificado, ainda é possível
             * relatar que a obra ou a imagem não carregou.
             */
        }
    }

    /* =====================================================
       EVENTOS
    ===================================================== */

    if (botaoRelatarProblema) {
        botaoRelatarProblema.disabled = true;

        botaoRelatarProblema.addEventListener("click", (evento) => {
            evento.preventDefault();

            abrirPaginaRelato();
        });
    }

    if (botaoFavorito) {
        botaoFavorito.addEventListener("click", () => {
            alterarEstadoBiblioteca("favorito");
        });
    }

    if (botaoQueroVer) {
        botaoQueroVer.addEventListener("click", () => {
            alterarEstadoBiblioteca("quero_explorar");
        });
    }

    botaoAnterior.addEventListener("click", () => {
        navegarParaEtapa(indiceAtual - 1);
    });

    botaoProximo.addEventListener("click", () => {
        navegarParaEtapa(indiceAtual + 1);
    });

    botaoVerCompleta.addEventListener(
        "click",
        abrirObraCompleta
    );

    botaoFecharCompleta.addEventListener(
        "click",
        fecharObraCompleta
    );

    document.addEventListener(
        "fullscreenchange",
        atualizarBotaoTelaCheia
    );

    /*
     * Atalhos de teclado.
     */
    document.addEventListener("keydown", (evento) => {
        if (evento.key === "Escape") {
            if (painelCompleto.classList.contains("is-open")) {
                fecharObraCompleta();
            }

            return;
        }

        if (painelCompleto.classList.contains("is-open")) {
            return;
        }

        if (
            evento.target instanceof HTMLElement &&
            evento.target.closest(
                "input, textarea, select, [contenteditable='true']"
            )
        ) {
            return;
        }

        if (evento.key === "ArrowRight") {
            navegarParaEtapa(indiceAtual + 1);
        }

        if (evento.key === "ArrowLeft") {
            navegarParaEtapa(indiceAtual - 1);
        }
    });

    /*
     * Recalcula o enquadramento quando a janela muda de tamanho.
     */
    window.addEventListener("resize", () => {
        if (!obraAtual || !etapas.length || emTransicao) {
            return;
        }

        aplicarEnquadramento(
            camadaAtual.querySelector("img"),
            etapas[indiceAtual],
            false
        );

        aplicarEnquadramento(
            camadaProxima.querySelector("img"),
            etapas[indiceAtual],
            false
        );
    });

    /* =====================================================
       INICIALIZAÇÃO
    ===================================================== */

    iniciar();

    /*
     * Restaura o botão depois que os scripts do cabeçalho forem executados.
     */
    if (document.readyState === "complete") {
        instalarControleTelaCheia();
    } else {
        window.addEventListener(
            "load",
            instalarControleTelaCheia,
            { once: true }
        );
    }

})();