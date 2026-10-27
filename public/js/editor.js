/*
 * Editor de obras do Arte Guiado.
 * Gerencia prévia de imagem, etapas, seleção de zoom, salvamento e listagem.
 */
(() => {
    "use strict";

    const entradaImagem = document.getElementById("imagemObra");
    const previaImagem = document.getElementById("imagemObraPreview");
    const previaContainer = document.getElementById("previaObra");
    const etapasContainer = document.getElementById("etapasEditor");
    const adicionarEtapa = document.getElementById("adicionarEtapa");
    const formulario = document.getElementById("formPublicacao");
    const feedback = document.getElementById("feedbackPublicacao");
    const botaoRascunho = document.querySelector("[data-salvar-rascunho]");
    const botaoPublicar = formulario?.querySelector('button[type="submit"]');
    const campoObraId = document.getElementById("obraId");
    const listaMinhasObras = document.getElementById("listaMinhasObras");
    const feedbackMinhasObras = document.getElementById("feedbackMinhasObras");
    const tituloFormulario = document.getElementById("titulo-publicar");

    const modeloEtapa = etapasContainer
        ?.querySelector(".etapa-editor")
        ?.cloneNode(true);

    const TIPOS_IMAGEM = new Set([
        "image/jpeg",
        "image/png",
        "image/webp"
    ]);

    const TAMANHO_MAXIMO_IMAGEM = 7 * 1024 * 1024;
    const ALTURA_MAXIMA_ZOOM = 0.48;
    const LARGURA_MAXIMA_ZOOM = 760;

    let urlImagemAtual = null;
    let imagemFonteAtual = null;
    let proximoIdEtapa = 2;
    let imagemPersistida = false;
    let enviando = false;
    let excluindo = false;
    let obrasCache = [];
    let filtroObrasAtual = "publicadas";
    let mensagemListaPendente = "";

    if (
        !entradaImagem ||
        !previaImagem ||
        !previaContainer ||
        !etapasContainer ||
        !adicionarEtapa ||
        !formulario ||
        !feedback ||
        !botaoRascunho ||
        !campoObraId ||
        !listaMinhasObras ||
        !feedbackMinhasObras ||
        !modeloEtapa
    ) {
        console.error(
            "O formulário do editor não contém todos os elementos esperados."
        );
        return;
    }

    /*
     * A região de feedback também recebe o foco após ações importantes.
     * Isso torna os resultados mais perceptíveis para quem usa teclado
     * ou tecnologia assistiva.
     */
    feedbackMinhasObras.setAttribute("role", "status");
    feedbackMinhasObras.setAttribute("aria-live", "polite");
    feedbackMinhasObras.setAttribute("aria-atomic", "true");
    feedbackMinhasObras.tabIndex = -1;

    function mostrarFeedback(mensagem, tipo = "erro") {
        feedback.textContent = mensagem;
        feedback.dataset.tipo = tipo;

        feedback.classList.toggle("sucesso", tipo === "sucesso");
        feedback.classList.toggle("erro", tipo === "erro");
    }

    function limitar(valor, minimo, maximo) {
        return Math.min(maximo, Math.max(minimo, valor));
    }

    /*
     * Dimensiona o canvas e a imagem sem alterar a proporção original.
     */
    function dimensionarCanvasZoom(canvas, imagem) {
        if (
            !canvas ||
            !imagem ||
            !imagem.complete ||
            !imagem.naturalWidth ||
            !imagem.naturalHeight
        ) {
            return;
        }

        const area = canvas.closest(".imagem-selecao-zoom");

        if (!area) {
            return;
        }

        const estilosArea = window.getComputedStyle(area);

        const paddingHorizontal =
            (parseFloat(estilosArea.paddingLeft) || 0) +
            (parseFloat(estilosArea.paddingRight) || 0);

        let larguraDisponivel =
            area.clientWidth - paddingHorizontal;

        /*
         * Quando o editor está oculto, a largura pode ser zero.
         * Nesse caso usamos uma estimativa temporária.
         */
        if (
            !Number.isFinite(larguraDisponivel) ||
            larguraDisponivel <= 0
        ) {
            larguraDisponivel = Math.min(
                LARGURA_MAXIMA_ZOOM,
                window.innerWidth * 0.8
            );
        }

        const alturaDisponivel = Math.max(
            1,
            window.innerHeight * ALTURA_MAXIMA_ZOOM
        );

        const escala = Math.min(
            1,
            larguraDisponivel / imagem.naturalWidth,
            alturaDisponivel / imagem.naturalHeight
        );

        const larguraFinal = imagem.naturalWidth * escala;
        const alturaFinal = imagem.naturalHeight * escala;

        canvas.style.setProperty(
            "width",
            `${larguraFinal}px`,
            "important"
        );

        canvas.style.setProperty(
            "height",
            `${alturaFinal}px`,
            "important"
        );

        imagem.style.setProperty(
            "width",
            `${larguraFinal}px`,
            "important"
        );

        imagem.style.setProperty(
            "height",
            `${alturaFinal}px`,
            "important"
        );

        imagem.style.setProperty(
            "max-width",
            "none",
            "important"
        );

        imagem.style.setProperty(
            "max-height",
            "none",
            "important"
        );

        imagem.style.setProperty(
            "object-fit",
            "fill",
            "important"
        );

        imagem.style.setProperty(
            "object-position",
            "center",
            "important"
        );
    }

    /*
     * Redimensiona as imagens de todas as etapas existentes.
     */
    function redimensionarTodasAsImagensZoom() {
        etapasContainer
            .querySelectorAll("[data-zoom-canvas]")
            .forEach((canvas) => {
                const imagem = canvas.querySelector(
                    "[data-zoom-imagem]"
                );

                if (
                    imagem &&
                    imagem.complete &&
                    imagem.naturalWidth > 0
                ) {
                    dimensionarCanvasZoom(canvas, imagem);
                }
            });
    }

    /*
     * Observa alterações de tamanho das áreas de zoom.
     */
    let observadorZoom = null;

    if ("ResizeObserver" in window) {
        observadorZoom = new ResizeObserver((entradas) => {
            entradas.forEach((entrada) => {
                const area = entrada.target;

                const canvas = area.querySelector(
                    "[data-zoom-canvas]"
                );

                const imagem = canvas?.querySelector(
                    "[data-zoom-imagem]"
                );

                if (
                    canvas &&
                    !canvas.hidden &&
                    imagem &&
                    imagem.complete &&
                    imagem.naturalWidth > 0
                ) {
                    dimensionarCanvasZoom(canvas, imagem);
                }
            });
        });
    }

    window.addEventListener(
        "resize",
        redimensionarTodasAsImagensZoom
    );

    /*
     * Limpa a seleção de zoom e os valores associados.
     */
    function limparSelecaoZoom(etapa) {
        const caixa = etapa.querySelector("[data-zoom-caixa]");

        if (caixa) {
            caixa.hidden = true;
            caixa.style.left = "";
            caixa.style.top = "";
            caixa.style.width = "";
            caixa.style.height = "";
        }

        etapa.querySelectorAll("[data-zoom-valor]").forEach((campo) => {
            campo.value = "";
        });
    }

    /*
     * Aplica uma imagem à etapa.
     */
    function aplicarImagemNaEtapa(etapa, url) {
        const canvas = etapa.querySelector("[data-zoom-canvas]");
        const imagem = etapa.querySelector("[data-zoom-imagem]");
        const texto = etapa.querySelector(".zoom-sem-imagem");

        if (!canvas || !imagem || !texto) {
            return;
        }

        limparSelecaoZoom(etapa);

        if (url) {
            imagem.onload = () => {
                dimensionarCanvasZoom(canvas, imagem);
            };

            imagem.onerror = () => {
                console.error(
                    "Não foi possível carregar a imagem utilizada na etapa."
                );
            };

            imagem.alt =
                "Imagem da obra utilizada para selecionar a região de zoom";

            canvas.hidden = false;
            texto.hidden = true;

            if (observadorZoom) {
                const area = canvas.closest(".imagem-selecao-zoom");

                if (area) {
                    observadorZoom.observe(area);
                }
            }

            imagem.src = url;

            /*
             * Trata imagens que já estejam carregadas em cache.
             */
            if (imagem.complete && imagem.naturalWidth > 0) {
                dimensionarCanvasZoom(canvas, imagem);
            }
        } else {
            imagem.onload = null;
            imagem.onerror = null;

            imagem.removeAttribute("src");

            [
                "width",
                "height",
                "max-width",
                "max-height",
                "object-fit",
                "object-position"
            ].forEach((propriedade) => {
                imagem.style.removeProperty(propriedade);
            });

            canvas.style.removeProperty("width");
            canvas.style.removeProperty("height");

            canvas.hidden = true;
            texto.hidden = false;
        }
    }

    /*
     * Libera a URL temporária de uma imagem local.
     */
    function liberarImagemLocal() {
        if (urlImagemAtual) {
            URL.revokeObjectURL(urlImagemAtual);
            urlImagemAtual = null;
        }
    }

    /*
     * Atualiza a prévia após a escolha de um arquivo.
     */
    function atualizarPreviaImagem(arquivo) {
        liberarImagemLocal();

        imagemPersistida = false;
        imagemFonteAtual = null;

        if (!arquivo) {
            previaImagem.removeAttribute("src");
            previaImagem.hidden = true;

            const texto = previaContainer.querySelector("span");

            if (texto) {
                texto.hidden = false;
            }

            etapasContainer
                .querySelectorAll(".etapa-editor")
                .forEach((etapa) => {
                    aplicarImagemNaEtapa(etapa, null);
                });

            return;
        }

        urlImagemAtual = URL.createObjectURL(arquivo);
        imagemFonteAtual = urlImagemAtual;

        previaImagem.src = imagemFonteAtual;
        previaImagem.hidden = false;

        const texto = previaContainer.querySelector("span");

        if (texto) {
            texto.hidden = true;
        }

        etapasContainer
            .querySelectorAll(".etapa-editor")
            .forEach((etapa) => {
                aplicarImagemNaEtapa(etapa, imagemFonteAtual);
            });
    }

    /*
     * Carrega a imagem de uma obra já salva no servidor.
     */
    function aplicarImagemPersistida(obraId) {
        liberarImagemLocal();

        imagemPersistida = true;

        imagemFonteAtual =
            `../api/obras/imagem.php?id=${encodeURIComponent(obraId)}&t=${Date.now()}`;

        previaImagem.src = imagemFonteAtual;
        previaImagem.hidden = false;

        const texto = previaContainer.querySelector("span");

        if (texto) {
            texto.hidden = true;
        }

        etapasContainer
            .querySelectorAll(".etapa-editor")
            .forEach((etapa) => {
                aplicarImagemNaEtapa(etapa, imagemFonteAtual);
            });
    }

    /*
     * Aguarda uma imagem carregar ou retorna um erro.
     */
    function esperarImagem(imagem) {
        if (imagem.complete && imagem.naturalWidth > 0) {
            return Promise.resolve();
        }

        return new Promise((resolve, reject) => {
            const limpar = () => {
                imagem.removeEventListener("load", carregada);
                imagem.removeEventListener("error", falhou);
            };

            const carregada = () => {
                limpar();
                resolve();
            };

            const falhou = () => {
                limpar();

                reject(
                    new Error(
                        "Não foi possível carregar a imagem da obra."
                    )
                );
            };

            imagem.addEventListener("load", carregada, { once: true });
            imagem.addEventListener("error", falhou, { once: true });
        });
    }

    /*
     * Validação do arquivo escolhido.
     */
    entradaImagem.addEventListener("change", () => {
        const arquivo = entradaImagem.files?.[0] || null;

        if (!arquivo) {
            atualizarPreviaImagem(null);
            return;
        }

        if (!TIPOS_IMAGEM.has(arquivo.type)) {
            entradaImagem.value = "";
            atualizarPreviaImagem(null);

            mostrarFeedback(
                "Escolha uma imagem JPEG, PNG ou WebP."
            );

            return;
        }

        if (arquivo.size > TAMANHO_MAXIMO_IMAGEM) {
            entradaImagem.value = "";
            atualizarPreviaImagem(null);

            mostrarFeedback(
                "A imagem ultrapassa o limite de 7 MB. Escolha um arquivo menor."
            );

            return;
        }

        mostrarFeedback("");
        atualizarPreviaImagem(arquivo);
    });

    /*
     * Atualiza a numeração e os nomes das etapas.
     */
    function reindexarEtapas() {
        const etapas = Array.from(
            etapasContainer.querySelectorAll(".etapa-editor")
        );

        etapas.forEach((etapa, indice) => {
            etapa.dataset.etapaEditor = String(indice + 1);

            const numero = etapa.querySelector(
                ".etapa-editor-numero"
            );

            const titulo = etapa.querySelector(
                ".etapa-editor-cabecalho h3"
            );

            if (numero) {
                numero.textContent = String(indice + 1).padStart(2, "0");
            }

            if (titulo) {
                titulo.textContent = `Etapa ${indice + 1}`;
            }

            etapa.querySelectorAll("[name]").forEach((campo) => {
                campo.name = campo.name.replace(
                    /etapas\[\d+\]/,
                    `etapas[${indice}]`
                );
            });

            const remover = etapa.querySelector(
                "[data-remover-etapa]"
            );

            if (remover) {
                remover.hidden = etapas.length === 1;
            }
        });
    }

    /*
     * Adiciona uma etapa em branco.
     */
    adicionarEtapa.addEventListener("click", () => {
        const base = etapasContainer.querySelector(".etapa-editor");

        if (!base) {
            return;
        }

        const nova = base.cloneNode(true);

        nova.dataset.etapaEditor = String(proximoIdEtapa++);

        nova.querySelectorAll("input, textarea").forEach((campo) => {
            campo.value = "";
        });

        nova.querySelectorAll("[data-zoom-caixa]").forEach((caixa) => {
            caixa.hidden = true;
            caixa.style.left = "";
            caixa.style.top = "";
            caixa.style.width = "";
            caixa.style.height = "";
        });

        nova.querySelectorAll("[data-zoom-valor]").forEach((campo) => {
            campo.value = "";
        });

        aplicarImagemNaEtapa(nova, imagemFonteAtual);

        const remover = nova.querySelector("[data-remover-etapa]");

        if (remover) {
            remover.hidden = false;
        }

        etapasContainer.appendChild(nova);

        reindexarEtapas();

        nova.querySelector('input[name$="[titulo]"]')?.focus();
    });

    /*
     * Remove uma etapa, preservando sempre pelo menos uma.
     */
    etapasContainer.addEventListener("click", (evento) => {
        const alvo = evento.target;

        if (!(alvo instanceof Element)) {
            return;
        }

        const botao = alvo.closest("[data-remover-etapa]");

        if (
            !botao ||
            etapasContainer.querySelectorAll(".etapa-editor").length <= 1
        ) {
            return;
        }

        const etapa = botao.closest(".etapa-editor");

        if (etapa) {
            const area = etapa.querySelector(".imagem-selecao-zoom");

            if (area && observadorZoom) {
                observadorZoom.unobserve(area);
            }

            etapa.remove();
        }

        reindexarEtapas();
    });

    /*
     * Lê as coordenadas normalizadas do retângulo de zoom.
     */
    function lerRetangulo(caixa) {
        return {
            x: parseFloat(caixa.style.left) / 100,
            y: parseFloat(caixa.style.top) / 100,
            w: parseFloat(caixa.style.width) / 100,
            h: parseFloat(caixa.style.height) / 100
        };
    }

    /*
     * Atualiza a representação visual e os campos de coordenadas.
     */
    function escreverRetangulo(etapa, caixa, retangulo) {
        const x = limitar(retangulo.x, 0, 1);
        const y = limitar(retangulo.y, 0, 1);
        const w = limitar(retangulo.w, 0, 1 - x);
        const h = limitar(retangulo.h, 0, 1 - y);

        caixa.style.left = `${x * 100}%`;
        caixa.style.top = `${y * 100}%`;
        caixa.style.width = `${w * 100}%`;
        caixa.style.height = `${h * 100}%`;
        caixa.hidden = false;

        const campoX = etapa.querySelector('[data-zoom-valor="x"]');
        const campoY = etapa.querySelector('[data-zoom-valor="y"]');

        const campoLargura = etapa.querySelector(
            '[data-zoom-valor="largura"]'
        );

        const campoAltura = etapa.querySelector(
            '[data-zoom-valor="altura"]'
        );

        if (campoX) campoX.value = x.toFixed(6);
        if (campoY) campoY.value = y.toFixed(6);
        if (campoLargura) campoLargura.value = w.toFixed(6);
        if (campoAltura) campoAltura.value = h.toFixed(6);
    }

    /*
     * Seleção de zoom por mouse ou toque.
     * Permite desenhar, mover e redimensionar a região.
     */
    etapasContainer.addEventListener("pointerdown", (evento) => {
        const alvo = evento.target;

        if (!(alvo instanceof Element)) {
            return;
        }

        const canvas = alvo.closest("[data-zoom-canvas]");

        if (
            !canvas ||
            canvas.hidden ||
            (evento.pointerType === "mouse" && evento.button !== 0)
        ) {
            return;
        }

        if (alvo.closest("[data-limpar-zoom]")) {
            return;
        }

        const etapa = canvas.closest(".etapa-editor");
        const imagem = canvas.querySelector("[data-zoom-imagem]");
        const caixa = canvas.querySelector("[data-zoom-caixa]");

        if (!etapa || !imagem || !caixa) {
            return;
        }

        if (
            !imagem.complete ||
            !imagem.naturalWidth ||
            !imagem.naturalHeight
        ) {
            mostrarFeedback(
                "A imagem ainda está carregando. Aguarde um instante e tente selecionar a região novamente.",
                "info"
            );

            return;
        }

        const limitesImagem = imagem.getBoundingClientRect();
        const limitesCanvas = canvas.getBoundingClientRect();

        if (
            limitesImagem.width <= 0 ||
            limitesImagem.height <= 0 ||
            limitesCanvas.width <= 0 ||
            limitesCanvas.height <= 0
        ) {
            return;
        }

        /*
         * Impede começar uma seleção fora da área da imagem.
         */
        if (
            evento.clientX < limitesImagem.left ||
            evento.clientX > limitesImagem.right ||
            evento.clientY < limitesImagem.top ||
            evento.clientY > limitesImagem.bottom
        ) {
            return;
        }

        const ponto = (e) => ({
            x: limitar(
                (e.clientX - limitesCanvas.left) / limitesCanvas.width,
                0,
                1
            ),
            y: limitar(
                (e.clientY - limitesCanvas.top) / limitesCanvas.height,
                0,
                1
            )
        });

        const inicio = ponto(evento);
        const alca = alvo.closest("[data-zoom-alca]");
        const clicouNaCaixa = alvo.closest("[data-zoom-caixa]");
        const tinhaSelecao = !caixa.hidden;

        let modo = "desenhar";
        let retanguloInicial = null;

        if (alca && tinhaSelecao) {
            modo = "redimensionar";
            retanguloInicial = lerRetangulo(caixa);
        } else if (clicouNaCaixa && tinhaSelecao) {
            modo = "mover";
            retanguloInicial = lerRetangulo(caixa);
        } else {
            escreverRetangulo(etapa, caixa, {
                x: inicio.x,
                y: inicio.y,
                w: 0,
                h: 0
            });
        }

        evento.preventDefault();

        try {
            canvas.setPointerCapture(evento.pointerId);
        } catch (erro) {
            // A captura do ponteiro é opcional.
        }

        const mover = (movimento) => {
            const atual = ponto(movimento);
            let retangulo;

            if (modo === "desenhar") {
                retangulo = {
                    x: Math.min(inicio.x, atual.x),
                    y: Math.min(inicio.y, atual.y),
                    w: Math.abs(atual.x - inicio.x),
                    h: Math.abs(atual.y - inicio.y)
                };
            } else if (modo === "mover") {
                retangulo = {
                    x: limitar(
                        retanguloInicial.x + atual.x - inicio.x,
                        0,
                        1 - retanguloInicial.w
                    ),
                    y: limitar(
                        retanguloInicial.y + atual.y - inicio.y,
                        0,
                        1 - retanguloInicial.h
                    ),
                    w: retanguloInicial.w,
                    h: retanguloInicial.h
                };
            } else {
                retangulo = {
                    x: retanguloInicial.x,
                    y: retanguloInicial.y,
                    w: limitar(
                        retanguloInicial.w + atual.x - inicio.x,
                        0,
                        1 - retanguloInicial.x
                    ),
                    h: limitar(
                        retanguloInicial.h + atual.y - inicio.y,
                        0,
                        1 - retanguloInicial.y
                    )
                };
            }

            escreverRetangulo(etapa, caixa, retangulo);
        };

        const finalizar = () => {
            canvas.removeEventListener("pointermove", mover);
            canvas.removeEventListener("pointerup", finalizar);
            canvas.removeEventListener("pointercancel", finalizar);

            try {
                if (canvas.hasPointerCapture(evento.pointerId)) {
                    canvas.releasePointerCapture(evento.pointerId);
                }
            } catch (erro) {
                // A captura pode já ter sido liberada.
            }

            const w = parseFloat(caixa.style.width) / 100;
            const h = parseFloat(caixa.style.height) / 100;

            if (
                !Number.isFinite(w) ||
                !Number.isFinite(h) ||
                w < 0.02 ||
                h < 0.02
            ) {
                limparSelecaoZoom(etapa);

                mostrarFeedback(
                    "A região selecionada ficou pequena demais. Desenhe um retângulo um pouco maior.",
                    "info"
                );
            } else {
                mostrarFeedback("");
            }
        };

        canvas.addEventListener("pointermove", mover);
        canvas.addEventListener("pointerup", finalizar, { once: true });
        canvas.addEventListener("pointercancel", finalizar, { once: true });
    });

    /*
     * Limpa a região de zoom de uma etapa.
     */
    etapasContainer.addEventListener("click", (evento) => {
        const alvo = evento.target;

        if (!(alvo instanceof Element)) {
            return;
        }

        const botao = alvo.closest("[data-limpar-zoom]");

        if (!botao) {
            return;
        }

        const etapa = botao.closest(".etapa-editor");

        if (etapa) {
            limparSelecaoZoom(etapa);
        }
    });

    /*
     * Valida os dados antes de salvar ou publicar.
     */
    function coletarErros(status) {
        const erros = [];

        const titulo = document.getElementById("tituloObra").value.trim();
        const autor = document.getElementById("autorObra").value.trim();

        const textoAlternativo =
            document.getElementById("altObra").value.trim();

        const arquivo = entradaImagem.files?.[0] || null;

        const etapas = Array.from(
            etapasContainer.querySelectorAll(".etapa-editor")
        );

        if (arquivo && !TIPOS_IMAGEM.has(arquivo.type)) {
            erros.push("A imagem deve ser JPEG, PNG ou WebP.");
        }

        if (arquivo && arquivo.size > TAMANHO_MAXIMO_IMAGEM) {
            erros.push("A imagem deve ter no máximo 7 MB.");
        }

        /*
         * Os rascunhos podem ser salvos incompletos.
         * As exigências completas são aplicadas ao publicar.
         */
        if (status === "publicada") {
            if (!titulo) {
                erros.push("Informe o nome da obra.");
            }

            if (!autor) {
                erros.push("Informe o autor da obra.");
            }

            if (!textoAlternativo) {
                erros.push(
                    "Preencha a descrição da imagem para acessibilidade."
                );
            }

            if (!arquivo && !imagemPersistida) {
                erros.push(
                    "Selecione a imagem principal antes de publicar."
                );
            }

            let etapasValidas = 0;

            etapas.forEach((etapa, indice) => {
                const etapaTitulo =
                    etapa.querySelector('input[name$="[titulo]"]')
                        ?.value.trim() || "";

                const etapaTexto =
                    etapa.querySelector('textarea[name$="[texto]"]')
                        ?.value.trim() || "";

                const valores = ["x", "y", "largura", "altura"].map(
                    (chave) =>
                        etapa.querySelector(
                            `[data-zoom-valor="${chave}"]`
                        )?.value ?? ""
                );

                const temConteudo =
                    etapaTitulo ||
                    etapaTexto ||
                    valores.some(Boolean);

                if (!temConteudo) {
                    return;
                }

                if (!etapaTitulo) {
                    erros.push(
                        `Informe o título da etapa ${indice + 1}.`
                    );
                }

                if (!etapaTexto) {
                    erros.push(
                        `Preencha o texto explicativo da etapa ${indice + 1}.`
                    );
                }

                if (valores.some((valor) => valor === "")) {
                    erros.push(
                        `Selecione a região de zoom da etapa ${indice + 1}.`
                    );
                } else {
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
                        erros.push(
                            `A região de zoom da etapa ${indice + 1} é inválida.`
                        );
                    }
                }

                if (
                    etapaTitulo &&
                    etapaTexto &&
                    valores.every(Boolean)
                ) {
                    etapasValidas += 1;
                }
            });

            if (etapasValidas === 0) {
                erros.push(
                    "Adicione pelo menos uma etapa completa, com texto e região de zoom."
                );
            }
        }

        return erros;
    }

    /*
     * Envia o formulário à API para salvar rascunho ou publicação.
     */
    async function salvarObra(status) {
        if (enviando || excluindo) {
            return;
        }

        const erros = coletarErros(status);

        if (erros.length) {
            mostrarFeedback(erros.join(" "));

            const primeiroCampo = status === "publicada"
                ? formulario.querySelector(
                    ":invalid, input#tituloObra, input#autorObra"
                )
                : null;

            primeiroCampo?.focus({ preventScroll: true });

            return;
        }

        const dados = new FormData(formulario);

        dados.set("status", status);
        dados.set("obra_id", campoObraId.value || "");

        enviando = true;

        [botaoRascunho, botaoPublicar]
            .filter(Boolean)
            .forEach((botao) => {
                botao.disabled = true;
            });

        mostrarFeedback(
            status === "rascunho"
                ? "Salvando rascunho..."
                : "Publicando obra...",
            "info"
        );

        try {
            const resposta = await fetch("../api/obras/salvar.php", {
                method: "POST",
                credentials: "same-origin",
                cache: "no-store",
                headers: {
                    "Accept": "application/json"
                },
                body: dados
            });

            const resultado = await resposta.json().catch(() => null);

            if (!resposta.ok || !resultado?.sucesso) {
                mostrarFeedback(
                    resultado?.mensagem ||
                    (
                        resposta.status === 401 ||
                            resposta.status === 403
                            ? "Sua sessão não permite esta operação."
                            : "Não foi possível salvar a obra. Verifique os dados e tente novamente."
                    )
                );

                return;
            }

            if (resultado.obra_id) {
                campoObraId.value = String(resultado.obra_id);
            }

            imagemPersistida = Boolean(resultado.imagem_salva);

            definirFiltroObras(
                status === "rascunho"
                    ? "rascunhos"
                    : "publicadas"
            );

            mensagemListaPendente = resultado.mensagem ||
                (
                    status === "rascunho"
                        ? "Rascunho salvo."
                        : "Obra publicada."
                );

            if (typeof window.abrirSecaoPainel === "function") {
                window.abrirSecaoPainel("minhas-obras");
            } else {
                mostrarFeedback(
                    mensagemListaPendente,
                    "sucesso"
                );
            }
        } catch (erro) {
            mostrarFeedback(
                "Não foi possível conectar ao servidor. A obra não foi confirmada como salva."
            );
        } finally {
            enviando = false;

            [botaoRascunho, botaoPublicar]
                .filter(Boolean)
                .forEach((botao) => {
                    botao.disabled = false;
                });
        }
    }

    /*
     * Seleciona qual coleção será exibida.
     */
    function definirFiltroObras(filtro) {
        filtroObrasAtual = filtro;

        document.querySelectorAll("[data-obras-filtro]").forEach((botao) => {
            const ativo = botao.dataset.obrasFiltro === filtro;

            botao.classList.toggle("ativa", ativo);
            botao.setAttribute("aria-selected", String(ativo));
        });

        renderizarMinhasObras();
    }

    /*
     * Atualiza a mensagem da seção Minhas obras.
     */
    function mostrarFeedbackObras(mensagem = "", tipo = "info") {
        feedbackMinhasObras.textContent = mensagem;

        feedbackMinhasObras.classList.toggle(
            "sucesso",
            tipo === "sucesso"
        );

        feedbackMinhasObras.classList.toggle(
            "erro",
            tipo === "erro"
        );

        feedbackMinhasObras.dataset.tipo = tipo;
    }

    function formatarData(valor) {
        if (!valor) {
            return "Data indisponível";
        }

        const data = new Date(String(valor).replace(" ", "T"));

        if (Number.isNaN(data.getTime())) {
            return "Data indisponível";
        }

        return new Intl.DateTimeFormat("pt-BR", {
            dateStyle: "short",
            timeStyle: "short"
        }).format(data);
    }

    /*
     * Cria um botão sem inserir texto diretamente no HTML.
     */
    function criarBotao(texto, classe, atributo, valor) {
        const botao = document.createElement("button");

        botao.type = "button";
        botao.className = classe;
        botao.textContent = texto;
        botao.dataset[atributo] = String(valor);

        return botao;
    }

    /*
     * Monta os cartões das obras.
     *
     * Cada cartão contém apenas um botão de edição e um botão de exclusão.
     * A confirmação fica separada e começa oculta.
     */
    function renderizarMinhasObras() {
        const filtradas = obrasCache.filter((obra) =>
            obra.status === (
                filtroObrasAtual === "rascunhos"
                    ? "rascunho"
                    : "publicada"
            )
        );

        listaMinhasObras.replaceChildren();

        const contadorPublicadas = document.querySelector(
            '[data-contagem-editor="publicadas"]'
        );

        const contadorRascunhos = document.querySelector(
            '[data-contagem-editor="rascunhos"]'
        );

        if (contadorPublicadas) {
            contadorPublicadas.textContent = String(
                obrasCache.filter(
                    (obra) => obra.status === "publicada"
                ).length
            );
        }

        if (contadorRascunhos) {
            contadorRascunhos.textContent = String(
                obrasCache.filter(
                    (obra) => obra.status === "rascunho"
                ).length
            );
        }

        /*
         * Estado vazio para a coleção selecionada.
         */
        if (filtradas.length === 0) {
            const estado = document.createElement("div");
            estado.className = "estado-vazio";

            const simbolo = document.createElement("span");
            simbolo.className = "estado-vazio-simbolo";
            simbolo.setAttribute("aria-hidden", "true");

            simbolo.textContent =
                filtroObrasAtual === "rascunhos"
                    ? "✎"
                    : "▧";

            const titulo = document.createElement("h3");

            titulo.textContent =
                filtroObrasAtual === "rascunhos"
                    ? "Você ainda não tem rascunhos"
                    : "Você ainda não publicou uma obra";

            const texto = document.createElement("p");

            texto.textContent =
                filtroObrasAtual === "rascunhos"
                    ? "Quando salvar uma obra como rascunho, ela aparecerá aqui. Você poderá retomá-la depois ou começar outra obra sem perder este rascunho."
                    : "As obras que você publicar aparecerão aqui e na galeria pública.";

            const botao = criarBotao(
                "＋ Criar nova obra",
                "botao botao-secundario",
                "novaObra",
                "true"
            );

            estado.append(simbolo, titulo, texto, botao);
            listaMinhasObras.appendChild(estado);

            return;
        }

        /*
         * Um único conjunto de ações por cartão.
         * Não repetir os append antigos abaixo deste bloco.
         */
        filtradas.forEach((obra) => {
            const cartao = document.createElement("article");
            cartao.className = "obra-editor-card";

            const cabecalho = document.createElement("div");
            cabecalho.className = "obra-editor-cabecalho";

            const nome = document.createElement("h3");

            nome.textContent =
                obra.titulo ||
                `Obra sem título · rascunho #${obra.id}`;

            const etiqueta = document.createElement("span");

            etiqueta.className =
                `estado-obra ${obra.status === "rascunho"
                    ? "estado-obra-rascunho"
                    : "estado-obra-publicada"
                }`;

            etiqueta.textContent =
                obra.status === "rascunho"
                    ? "Rascunho"
                    : "Publicada";

            cabecalho.append(nome, etiqueta);

            const autoria = document.createElement("p");
            autoria.className = "obra-editor-autor";

            autoria.textContent = obra.autor
                ? `Autor: ${obra.autor}`
                : "Autor ainda não informado";

            const meta = document.createElement("p");
            meta.className = "obra-editor-meta";

            meta.textContent =
                `Atualizada em ${formatarData(obra.atualizada_em)} · ` +
                `${obra.total_etapas} ` +
                `${obra.total_etapas === 1 ? "etapa" : "etapas"}`;

            const acoes = document.createElement("div");
            acoes.className = "obra-editor-acoes";

            const botaoEditar = criarBotao(
                obra.status === "rascunho"
                    ? "Continuar editando"
                    : "Editar obra",
                "botao botao-secundario",
                "editarObra",
                obra.id
            );

            const botaoExcluir = criarBotao(
                "Excluir obra",
                "botao botao-excluir-obra",
                "excluirObra",
                obra.id
            );

            const idConfirmacao =
                `confirmacao-exclusao-${obra.id}`;

            botaoExcluir.setAttribute(
                "aria-controls",
                idConfirmacao
            );

            botaoExcluir.setAttribute(
                "aria-expanded",
                "false"
            );

            const confirmacao = document.createElement("div");

            confirmacao.className = "confirmacao-exclusao-obra";
            confirmacao.id = idConfirmacao;
            confirmacao.hidden = true;
            confirmacao.setAttribute("role", "group");

            confirmacao.setAttribute(
                "aria-labelledby",
                `${idConfirmacao}-titulo`
            );

            const tituloConfirmacao = document.createElement("p");

            tituloConfirmacao.id = `${idConfirmacao}-titulo`;

            tituloConfirmacao.textContent =
                `Deseja excluir "${nome.textContent}"?`;

            const explicacaoConfirmacao = document.createElement("p");

            explicacaoConfirmacao.textContent =
                "A obra deixará de aparecer nas listagens. " +
                "Essa ação não poderá ser desfeita pela interface.";

            const acoesConfirmacao = document.createElement("div");

            acoesConfirmacao.className =
                "confirmacao-exclusao-acoes";

            const botaoCancelar = criarBotao(
                "Cancelar",
                "botao botao-secundario",
                "cancelarExclusao",
                obra.id
            );

            const botaoConfirmar = criarBotao(
                "Confirmar exclusão",
                "botao botao-excluir-obra",
                "confirmarExclusao",
                obra.id
            );

            acoesConfirmacao.append(
                botaoCancelar,
                botaoConfirmar
            );

            confirmacao.append(
                tituloConfirmacao,
                explicacaoConfirmacao,
                acoesConfirmacao
            );

            acoes.append(
                botaoEditar,
                botaoExcluir
            );

            cartao.append(
                cabecalho,
                autoria,
                meta,
                acoes,
                confirmacao
            );

            listaMinhasObras.appendChild(cartao);
        });
    }

    /*
     * Executa a exclusão após a confirmação no próprio cartão.
     */
    async function excluirObra(id, botaoConfirmar) {
        if (enviando || excluindo) {
            return;
        }

        const obra = obrasCache.find(
            (item) => String(item.id) === String(id)
        );

        if (!obra) {
            mostrarFeedbackObras(
                "Não foi possível localizar essa obra na lista atual.",
                "erro"
            );

            feedbackMinhasObras.focus();
            return;
        }

        const cartao = botaoConfirmar?.closest(
            ".obra-editor-card"
        );

        if (!cartao) {
            mostrarFeedbackObras(
                "Não foi possível identificar o cartão da obra.",
                "erro"
            );

            feedbackMinhasObras.focus();
            return;
        }

        const confirmacao = cartao.querySelector(
            ".confirmacao-exclusao-obra"
        );

        excluindo = true;

        /*
         * Desativa os controles do cartão durante a requisição.
         * Isso impede cliques repetidos que enviariam várias solicitações.
         */
        cartao.querySelectorAll("button").forEach((botao) => {
            botao.disabled = true;
        });

        mostrarFeedbackObras(
            "Excluindo obra...",
            "info"
        );

        try {
            const resposta = await fetch("../api/obras/excluir.php", {
                method: "POST",
                credentials: "same-origin",
                cache: "no-store",
                headers: {
                    "Accept": "application/json",
                    "Content-Type": "application/json"
                },
                body: JSON.stringify({
                    obra_id: Number(id)
                })
            });

            const resultado = await resposta.json().catch(() => null);

            if (!resposta.ok || !resultado?.sucesso) {
                mostrarFeedbackObras(
                    resultado?.mensagem ||
                    `Não foi possível excluir a obra. Código HTTP: ${resposta.status}.`,
                    "erro"
                );

                return;
            }

            /*
             * Retira a obra da lista local somente após a confirmação
             * de sucesso enviada pela API.
             */
            obrasCache = obrasCache.filter(
                (item) => String(item.id) !== String(id)
            );

            renderizarMinhasObras();

            mostrarFeedbackObras(
                resultado.mensagem ||
                "Obra excluída com sucesso.",
                "sucesso"
            );

            /*
             * O cartão foi removido. O foco passa para a mensagem,
             * evitando que o usuário perca a referência da interface.
             */
            feedbackMinhasObras.focus();

        } catch (erro) {
            console.error("Erro ao excluir obra:", erro);

            mostrarFeedbackObras(
                "Não foi possível conectar ao servidor. " +
                "Confira a lista antes de tentar novamente.",
                "erro"
            );

        } finally {
            excluindo = false;

            /*
             * Se ocorreu erro, mantém a confirmação aberta e reativa
             * os botões para permitir cancelar ou tentar novamente.
             */
            if (confirmacao?.isConnected) {
                cartao.querySelectorAll("button").forEach((botao) => {
                    botao.disabled = false;
                });
            }
        }
    }

    /*
     * Obtém as obras persistidas pela API.
     */
    async function carregarMinhasObras(mensagem = "") {
        mostrarFeedbackObras(
            mensagem,
            mensagem ? "sucesso" : "info"
        );

        listaMinhasObras.innerHTML =
            '<div class="estado-vazio">' +
            '<span class="estado-vazio-simbolo" aria-hidden="true">…</span>' +
            '<h3>Carregando suas obras</h3>' +
            '<p>Consultando os rascunhos e as publicações salvos.</p>' +
            '</div>';

        try {
            const resposta = await fetch("../api/obras/minhas.php", {
                method: "GET",
                credentials: "same-origin",
                cache: "no-store",
                headers: {
                    "Accept": "application/json"
                }
            });

            const resultado = await resposta.json().catch(() => null);

            if (
                !resposta.ok ||
                !resultado?.sucesso ||
                !Array.isArray(resultado.obras)
            ) {
                mostrarFeedbackObras(
                    resultado?.mensagem ||
                    (
                        resposta.status === 401
                            ? "Sua sessão expirou. Entre novamente."
                            : "Não foi possível carregar suas obras."
                    ),
                    "erro"
                );

                listaMinhasObras.replaceChildren();

                return;
            }

            obrasCache = resultado.obras;

            renderizarMinhasObras();

        } catch (erro) {
            listaMinhasObras.replaceChildren();

            mostrarFeedbackObras(
                "Não foi possível conectar ao servidor para listar suas obras.",
                "erro"
            );
        }
    }

    /*
     * Restaura uma única etapa vazia ao iniciar uma nova obra.
     */
    function redefinirEtapas() {
        etapasContainer.replaceChildren();

        const primeira = modeloEtapa.cloneNode(true);

        primeira.querySelectorAll("input, textarea").forEach((campo) => {
            campo.value = "";
        });

        primeira.querySelectorAll("[data-zoom-caixa]").forEach((caixa) => {
            caixa.hidden = true;
            caixa.style.left = "";
            caixa.style.top = "";
            caixa.style.width = "";
            caixa.style.height = "";
        });

        primeira.querySelectorAll("[data-zoom-valor]").forEach((campo) => {
            campo.value = "";
        });

        aplicarImagemNaEtapa(primeira, null);

        etapasContainer.appendChild(primeira);

        proximoIdEtapa = 2;

        reindexarEtapas();
    }

    /*
     * Limpa o formulário e abre a seção para criar uma obra.
     */
    function iniciarNovaObra() {
        if (enviando || excluindo) {
            return;
        }

        formulario.reset();
        campoObraId.value = "";

        atualizarPreviaImagem(null);
        redefinirEtapas();

        imagemPersistida = false;

        if (tituloFormulario) {
            tituloFormulario.textContent = "Configure sua obra";
        }

        botaoRascunho.textContent = "Salvar rascunho";

        if (botaoPublicar) {
            botaoPublicar.textContent = "Publicar obra";
        }

        mostrarFeedback("", "info");

        if (typeof window.abrirSecaoPainel === "function") {
            window.abrirSecaoPainel("publicar");

            requestAnimationFrame(
                redimensionarTodasAsImagensZoom
            );
        }
    }

    /*
     * Carrega uma obra existente para edição.
     */
    async function carregarObraParaEdicao(id) {
        mostrarFeedbackObras(
            "Carregando a obra para edição...",
            "info"
        );

        try {
            const resposta = await fetch(
                `../api/obras/detalhe.php?id=${encodeURIComponent(id)}`,
                {
                    method: "GET",
                    credentials: "same-origin",
                    cache: "no-store",
                    headers: {
                        "Accept": "application/json"
                    }
                }
            );

            const resultado = await resposta.json().catch(() => null);

            if (
                !resposta.ok ||
                !resultado?.sucesso ||
                !resultado.obra
            ) {
                mostrarFeedbackObras(
                    resultado?.mensagem ||
                    "Não foi possível abrir esta obra.",
                    "erro"
                );

                return;
            }

            const obra = resultado.obra;

            iniciarNovaObraSemNavegar();

            campoObraId.value = String(obra.id);

            document.getElementById("tituloObra").value =
                obra.titulo || "";

            document.getElementById("autorObra").value =
                obra.autor || "";

            document.getElementById("altObra").value =
                obra.texto_alternativo || "";

            const etapas =
                Array.isArray(obra.etapas) && obra.etapas.length
                    ? obra.etapas
                    : [{}];

            /*
             * Cria etapas até alcançar a quantidade salva.
             */
            while (
                etapasContainer.querySelectorAll(".etapa-editor").length <
                etapas.length
            ) {
                const base =
                    etapasContainer.querySelector(".etapa-editor");

                const nova = base.cloneNode(true);

                nova.querySelectorAll("input, textarea").forEach((campo) => {
                    campo.value = "";
                });

                nova.querySelectorAll("[data-zoom-caixa]").forEach((caixa) => {
                    caixa.hidden = true;
                    caixa.style.left = "";
                    caixa.style.top = "";
                    caixa.style.width = "";
                    caixa.style.height = "";
                });

                nova.querySelectorAll("[data-zoom-valor]").forEach((campo) => {
                    campo.value = "";
                });

                aplicarImagemNaEtapa(nova, null);

                etapasContainer.appendChild(nova);
            }

            /*
             * Remove etapas excedentes.
             */
            while (
                etapasContainer.querySelectorAll(".etapa-editor").length >
                etapas.length
            ) {
                const ultima = etapasContainer.lastElementChild;

                const area = ultima?.querySelector(
                    ".imagem-selecao-zoom"
                );

                if (area && observadorZoom) {
                    observadorZoom.unobserve(area);
                }

                ultima?.remove();
            }

            reindexarEtapas();

            /*
             * Restaura títulos, textos e coordenadas salvas.
             */
            Array.from(
                etapasContainer.querySelectorAll(".etapa-editor")
            ).forEach((elemento, indice) => {
                const etapa = etapas[indice] || {};

                const titulo = elemento.querySelector(
                    'input[name$="[titulo]"]'
                );

                const texto = elemento.querySelector(
                    'textarea[name$="[texto]"]'
                );

                if (titulo) {
                    titulo.value = etapa.titulo || "";
                }

                if (texto) {
                    texto.value = etapa.texto || "";
                }

                const pares = [
                    ["x", etapa.zoom_x],
                    ["y", etapa.zoom_y],
                    ["largura", etapa.zoom_largura],
                    ["altura", etapa.zoom_altura]
                ];

                pares.forEach(([chave, valor]) => {
                    const campo = elemento.querySelector(
                        `[data-zoom-valor="${chave}"]`
                    );

                    if (campo) {
                        campo.value =
                            valor === null || valor === undefined
                                ? ""
                                : Number(valor).toFixed(6);
                    }
                });
            });

            if (obra.imagem_salva) {
                aplicarImagemPersistida(obra.id);

                const imagens = [
                    previaImagem,
                    ...Array.from(
                        etapasContainer.querySelectorAll(
                            "[data-zoom-imagem]"
                        )
                    )
                ];

                await Promise.all(
                    imagens.map(esperarImagem)
                );

                redimensionarTodasAsImagensZoom();

                /*
                 * Restaura a seleção visual de cada etapa.
                 */
                Array.from(
                    etapasContainer.querySelectorAll(".etapa-editor")
                ).forEach((elemento, indice) => {
                    const etapaSalva = etapas[indice] || {};

                    const paresZoom = [
                        ["x", etapaSalva.zoom_x],
                        ["y", etapaSalva.zoom_y],
                        ["largura", etapaSalva.zoom_largura],
                        ["altura", etapaSalva.zoom_altura]
                    ];

                    paresZoom.forEach(([chave, valor]) => {
                        const campo = elemento.querySelector(
                            `[data-zoom-valor="${chave}"]`
                        );

                        if (campo) {
                            campo.value =
                                valor === null || valor === undefined
                                    ? ""
                                    : Number(valor).toFixed(6);
                        }
                    });

                    const valores = paresZoom.map(([, valor]) => valor);

                    if (
                        valores.every(
                            (valor) =>
                                valor !== null &&
                                valor !== undefined &&
                                Number.isFinite(Number(valor))
                        )
                    ) {
                        const caixa = elemento.querySelector(
                            "[data-zoom-caixa]"
                        );

                        if (caixa) {
                            escreverRetangulo(elemento, caixa, {
                                x: Number(etapaSalva.zoom_x),
                                y: Number(etapaSalva.zoom_y),
                                w: Number(etapaSalva.zoom_largura),
                                h: Number(etapaSalva.zoom_altura)
                            });
                        }
                    }
                });
            } else {
                atualizarPreviaImagem(null);
            }

            if (tituloFormulario) {
                tituloFormulario.textContent =
                    obra.status === "rascunho"
                        ? "Continuar rascunho"
                        : "Editar obra publicada";
            }

            botaoRascunho.textContent =
                obra.status === "publicada"
                    ? "Salvar como rascunho"
                    : "Salvar rascunho";

            if (botaoPublicar) {
                botaoPublicar.textContent =
                    obra.status === "publicada"
                        ? "Salvar alterações"
                        : "Publicar obra";
            }

            mostrarFeedback(
                obra.status === "rascunho"
                    ? "Você está editando um rascunho salvo."
                    : "Você está editando uma obra publicada.",
                "info"
            );

            if (typeof window.abrirSecaoPainel === "function") {
                window.abrirSecaoPainel("publicar");

                requestAnimationFrame(
                    redimensionarTodasAsImagensZoom
                );
            }

        } catch (erro) {
            console.error(
                "Erro ao carregar a obra para edição:",
                erro
            );

            mostrarFeedbackObras(
                "Não foi possível carregar todos os dados da obra. Confira se a imagem está disponível e tente novamente.",
                "erro"
            );
        }
    }

    /*
     * Prepara o formulário para editar sem navegar entre seções.
     */
    function iniciarNovaObraSemNavegar() {
        formulario.reset();
        campoObraId.value = "";

        atualizarPreviaImagem(null);
        redefinirEtapas();

        imagemPersistida = false;

        if (tituloFormulario) {
            tituloFormulario.textContent = "Configure sua obra";
        }

        botaoRascunho.textContent = "Salvar rascunho";

        if (botaoPublicar) {
            botaoPublicar.textContent = "Publicar obra";
        }

        mostrarFeedback("", "info");
    }

    /*
     * Atualiza os dados quando o painel abre determinada seção.
     */
    document.addEventListener("painel:secao-aberta", (evento) => {
        if (evento.detail?.nome === "minhas-obras") {
            const mensagem = mensagemListaPendente;

            mensagemListaPendente = "";

            carregarMinhasObras(mensagem);
        }

        if (evento.detail?.nome === "publicar") {
            requestAnimationFrame(
                redimensionarTodasAsImagensZoom
            );
        }
    });

    /*
     * Controles de filtro: publicadas e rascunhos.
     */
    document.querySelectorAll("[data-obras-filtro]").forEach((botao) => {
        botao.addEventListener("click", () => {
            definirFiltroObras(botao.dataset.obrasFiltro);
        });
    });

    /*
     * Tratamento centralizado dos controles dos cartões.
     * A delegação de eventos continua funcionando após renderizar
     * novamente a lista, sem adicionar ouvintes repetidos.
     */
    document.addEventListener("click", (evento) => {
        const alvo = evento.target;

        if (!(alvo instanceof Element)) {
            return;
        }

        /*
         * Criar nova obra.
         */
        const botaoNova = alvo.closest("[data-nova-obra]");

        if (botaoNova) {
            evento.preventDefault();
            iniciarNovaObra();
            return;
        }

        /*
         * Confirmar a exclusão.
         */
        const botaoConfirmar = alvo.closest(
            "[data-confirmar-exclusao]"
        );

        if (botaoConfirmar) {
            evento.preventDefault();

            excluirObra(
                botaoConfirmar.dataset.confirmarExclusao,
                botaoConfirmar
            );

            return;
        }

        /*
         * Cancelar a exclusão e devolver o foco ao botão original.
         */
        const botaoCancelar = alvo.closest(
            "[data-cancelar-exclusao]"
        );

        if (botaoCancelar) {
            evento.preventDefault();

            const cartao = botaoCancelar.closest(
                ".obra-editor-card"
            );

            const confirmacao = cartao?.querySelector(
                ".confirmacao-exclusao-obra"
            );

            const botaoExcluir = cartao?.querySelector(
                "[data-excluir-obra]"
            );

            if (confirmacao) {
                confirmacao.hidden = true;
            }

            if (botaoExcluir) {
                botaoExcluir.hidden = false;

                botaoExcluir.setAttribute(
                    "aria-expanded",
                    "false"
                );

                botaoExcluir.focus();
            }

            return;
        }

        /*
         * Abrir a confirmação dentro do cartão.
         * O botão original fica oculto enquanto a confirmação está aberta.
         */
        const botaoExcluir = alvo.closest("[data-excluir-obra]");

        if (botaoExcluir) {
            evento.preventDefault();

            if (enviando || excluindo) {
                return;
            }

            const cartao = botaoExcluir.closest(
                ".obra-editor-card"
            );

            const confirmacao = cartao?.querySelector(
                ".confirmacao-exclusao-obra"
            );

            if (!confirmacao) {
                mostrarFeedbackObras(
                    "Não foi possível abrir a confirmação de exclusão.",
                    "erro"
                );

                return;
            }

            confirmacao.hidden = false;
            botaoExcluir.hidden = true;

            botaoExcluir.setAttribute(
                "aria-expanded",
                "true"
            );

            confirmacao
                .querySelector("[data-confirmar-exclusao]")
                ?.focus();

            return;
        }

        /*
         * Editar uma obra existente.
         */
        const botaoEditar = alvo.closest("[data-editar-obra]");

        if (botaoEditar) {
            evento.preventDefault();

            carregarObraParaEdicao(
                botaoEditar.dataset.editarObra
            );
        }
    });

    /*
     * Salvar rascunho.
     */
    botaoRascunho.addEventListener("click", () => {
        salvarObra("rascunho");
    });

    /*
     * Publicar obra.
     */
    formulario.addEventListener("submit", (evento) => {
        evento.preventDefault();

        salvarObra("publicada");
    });

    /*
     * Carrega a lista inicial para preencher as contagens.
     */
    carregarMinhasObras();

    /*
     * Libera recursos temporários ao sair da página.
     */
    window.addEventListener(
        "beforeunload",
        liberarImagemLocal
    );
})();