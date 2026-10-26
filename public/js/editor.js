/*
 * Editor de obras do Arte Guiado.
 * Gerencia a prévia local, as etapas, a seleção de zoom e o envio
 * de rascunhos/publicações para a API PHP.
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

    /*
     * Limites visuais da imagem na seleção de zoom.
     * A imagem mantém sua proporção original e nunca é cortada.
     */
    const ALTURA_MAXIMA_ZOOM = 0.48;
    const LARGURA_MAXIMA_ZOOM = 760;

    let urlImagemAtual = null;
    let imagemFonteAtual = null;
    let proximoIdEtapa = 2;
    let imagemPersistida = false;
    let enviando = false;
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
     * Dimensiona o canvas e a imagem usando as dimensões naturais
     * da obra como referência.
     *
     * A mesma escala é aplicada à largura e à altura. Assim, a imagem
     * permanece inteira, sem cortes e sem distorção.
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

        /*
         * clientWidth inclui o padding, mas não inclui as bordas.
         * Subtraímos o padding para obter a largura disponível à imagem.
         */
        let larguraDisponivel = area.clientWidth - paddingHorizontal;

        /*
         * Quando a seção do editor ainda está oculta, clientWidth pode
         * ser zero. Nesse caso usamos uma largura provisória, que será
         * recalculada quando o componente ficar visível.
         */
        if (!Number.isFinite(larguraDisponivel) || larguraDisponivel <= 0) {
            larguraDisponivel = Math.min(
                LARGURA_MAXIMA_ZOOM,
                window.innerWidth * 0.8
            );
        }

        /*
         * Limita também a altura para que a imagem não ocupe
         * um espaço exagerado na tela.
         */
        const alturaDisponivel = Math.max(
            1,
            window.innerHeight * ALTURA_MAXIMA_ZOOM
        );

        /*
         * Nunca amplia a imagem acima do tamanho natural.
         * A escala respeita simultaneamente a largura e a altura.
         */
        const escala = Math.min(
            1,
            larguraDisponivel / imagem.naturalWidth,
            alturaDisponivel / imagem.naturalHeight
        );

        const larguraFinal = imagem.naturalWidth * escala;
        const alturaFinal = imagem.naturalHeight * escala;

        /*
         * Canvas e imagem recebem exatamente as mesmas dimensões.
         * O uso de !important evita que regras antigas do CSS imponham
         * width: 100% ou outras dimensões incompatíveis.
         */
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
     * Recalcula o tamanho de todas as imagens utilizadas nas etapas.
     * As coordenadas dos retângulos são percentuais, por isso continuam
     * proporcionais quando a imagem muda de tamanho.
     */
    function redimensionarTodasAsImagensZoom() {
        etapasContainer
            .querySelectorAll("[data-zoom-canvas]")
            .forEach((canvas) => {
                const imagem = canvas.querySelector("[data-zoom-imagem]");

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
     * Observa mudanças de tamanho nas áreas de seleção.
     * Isso permite recalcular a imagem quando a seção do editor,
     * inicialmente oculta, passa a ser exibida.
     */
    let observadorZoom = null;

    if ("ResizeObserver" in window) {
        observadorZoom = new ResizeObserver((entradas) => {
            entradas.forEach((entrada) => {
                const area = entrada.target;
                const canvas = area.querySelector("[data-zoom-canvas]");
                const imagem = canvas?.querySelector("[data-zoom-imagem]");

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

    /*
     * Recalcula quando a janela muda de tamanho.
     */
    window.addEventListener(
        "resize",
        redimensionarTodasAsImagensZoom
    );

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
     * Aplica a imagem à etapa e ajusta seu tamanho antes da seleção.
     * Também trata imagens que já estejam carregadas no navegador.
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
            /*
             * O redimensionamento acontece após o carregamento.
             * Atribuir o manipulador antes do src evita perder o evento.
             */
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

            /*
             * Registra a área para recalcular a imagem quando ela
             * se tornar visível ou mudar de tamanho.
             */
            if (observadorZoom) {
                const area = canvas.closest(".imagem-selecao-zoom");

                if (area) {
                    observadorZoom.observe(area);
                }
            }

            imagem.src = url;

            /*
             * Se o navegador já tiver a imagem em cache, onload pode
             * não precisar ocorrer novamente. Por isso verificamos
             * também se ela já terminou de carregar.
             */
            if (imagem.complete && imagem.naturalWidth > 0) {
                dimensionarCanvasZoom(canvas, imagem);
            }
        } else {
            imagem.onload = null;
            imagem.onerror = null;

            imagem.removeAttribute("src");

            /*
             * Remove os tamanhos inline para não reaproveitar
             * as dimensões da imagem anterior.
             */
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

    function liberarImagemLocal() {
        if (urlImagemAtual) {
            URL.revokeObjectURL(urlImagemAtual);
            urlImagemAtual = null;
        }
    }

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

    function esperarImagem(imagem) {
        if (imagem.complete && imagem.naturalWidth > 0) {
            return Promise.resolve();
        }

        return new Promise((resolve, reject) => {
            const carregada = () => {
                limpar();
                resolve();
            };

            const falhou = () => {
                limpar();
                reject(
                    new Error("Não foi possível carregar a imagem da obra.")
                );
            };

            const limpar = () => {
                imagem.removeEventListener("load", carregada);
                imagem.removeEventListener("error", falhou);
            };

            imagem.addEventListener("load", carregada, { once: true });
            imagem.addEventListener("error", falhou, { once: true });
        });
    }

    entradaImagem.addEventListener("change", () => {
        const arquivo = entradaImagem.files?.[0] || null;

        if (!arquivo) {
            atualizarPreviaImagem(null);
            return;
        }

        if (!TIPOS_IMAGEM.has(arquivo.type)) {
            entradaImagem.value = "";
            atualizarPreviaImagem(null);
            mostrarFeedback("Escolha uma imagem JPEG, PNG ou WebP.");
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

    function reindexarEtapas() {
        const etapas = Array.from(
            etapasContainer.querySelectorAll(".etapa-editor")
        );

        etapas.forEach((etapa, indice) => {
            etapa.dataset.etapaEditor = String(indice + 1);

            const numero = etapa.querySelector(".etapa-editor-numero");
            const titulo = etapa.querySelector(".etapa-editor-cabecalho h3");

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

            const remover = etapa.querySelector("[data-remover-etapa]");

            if (remover) {
                remover.hidden = etapas.length === 1;
            }
        });
    }

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

    etapasContainer.addEventListener("click", (evento) => {
        const botao = evento.target.closest("[data-remover-etapa]");

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

    function lerRetangulo(caixa) {
        return {
            x: parseFloat(caixa.style.left) / 100,
            y: parseFloat(caixa.style.top) / 100,
            w: parseFloat(caixa.style.width) / 100,
            h: parseFloat(caixa.style.height) / 100
        };
    }

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
     * Seleção de zoom:
     * - só permite iniciar a seleção sobre a imagem;
     * - calcula as coordenadas em relação à imagem realmente exibida;
     * - mantém a caixa de seleção proporcional ao canvas;
     * - permite desenhar, mover e redimensionar o retângulo.
     */
    etapasContainer.addEventListener("pointerdown", (evento) => {
        const canvas = evento.target.closest("[data-zoom-canvas]");

        if (
            !canvas ||
            canvas.hidden ||
            (evento.pointerType === "mouse" && evento.button !== 0)
        ) {
            return;
        }

        if (evento.target.closest("[data-limpar-zoom]")) {
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

        /*
         * O canvas e a imagem recebem as mesmas dimensões na função
         * dimensionarCanvasZoom. A caixa usa percentuais do canvas,
         * portanto a referência geométrica deve coincidir.
         */
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
         * Não permite desenhar fora da imagem.
         */
        if (
            evento.clientX < limitesImagem.left ||
            evento.clientX > limitesImagem.right ||
            evento.clientY < limitesImagem.top ||
            evento.clientY > limitesImagem.bottom
        ) {
            return;
        }

        /*
         * Converte a posição do mouse ou toque em coordenadas
         * normalizadas entre 0 e 1.
         */
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
        const alca = evento.target.closest("[data-zoom-alca]");
        const clicouNaCaixa = evento.target.closest("[data-zoom-caixa]");
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
            /* A captura do ponteiro é opcional. */
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
                /* A captura já pode ter sido liberada pelo navegador. */
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

    etapasContainer.addEventListener("click", (evento) => {
        const botao = evento.target.closest("[data-limpar-zoom]");

        if (!botao) {
            return;
        }

        const etapa = botao.closest(".etapa-editor");

        if (etapa) {
            limparSelecaoZoom(etapa);
        }
    });

    function coletarErros(status) {
        const erros = [];

        const titulo = document.getElementById("tituloObra").value.trim();
        const autor = document.getElementById("autorObra").value.trim();
        const textoAlternativo = document.getElementById("altObra").value.trim();
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

    async function salvarObra(status) {
        if (enviando) {
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
                if (
                    resposta.status === 401 ||
                    resposta.status === 403
                ) {
                    mostrarFeedback(
                        resultado?.mensagem ||
                        "Sua sessão não permite esta operação."
                    );
                } else {
                    mostrarFeedback(
                        resultado?.mensagem ||
                        "Não foi possível salvar a obra. Verifique os dados e tente novamente."
                    );
                }

                return;
            }

            if (resultado.obra_id) {
                campoObraId.value = String(resultado.obra_id);
            }

            imagemPersistida = Boolean(resultado.imagem_salva);

            definirFiltroObras(
                status === "rascunho" ? "rascunhos" : "publicadas"
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
                mostrarFeedback(mensagemListaPendente, "sucesso");
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

    function definirFiltroObras(filtro) {
        filtroObrasAtual = filtro;

        document.querySelectorAll("[data-obras-filtro]").forEach((botao) => {
            const ativo = botao.dataset.obrasFiltro === filtro;

            botao.classList.toggle("ativa", ativo);
            botao.setAttribute("aria-selected", String(ativo));
        });

        renderizarMinhasObras();
    }

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

    function criarBotao(texto, classe, atributo, valor) {
        const botao = document.createElement("button");

        botao.type = "button";
        botao.className = classe;
        botao.textContent = texto;
        botao.dataset[atributo] = String(valor);

        return botao;
    }

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

        if (filtradas.length === 0) {
            const estado = document.createElement("div");
            estado.className = "estado-vazio";

            const simbolo = document.createElement("span");
            simbolo.className = "estado-vazio-simbolo";
            simbolo.setAttribute("aria-hidden", "true");
            simbolo.textContent =
                filtroObrasAtual === "rascunhos" ? "✎" : "▧";

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

        filtradas.forEach((obra) => {
            const cartao = document.createElement("article");
            cartao.className = "obra-editor-card";

            const cabecalho = document.createElement("div");
            cabecalho.className = "obra-editor-cabecalho";

            const nome = document.createElement("h3");
            nome.textContent =
                obra.titulo || `Obra sem título · rascunho #${obra.id}`;

            const etiqueta = document.createElement("span");
            etiqueta.className =
                `estado-obra ${obra.status === "rascunho"
                    ? "estado-obra-rascunho"
                    : "estado-obra-publicada"
                }`;

            etiqueta.textContent =
                obra.status === "rascunho" ? "Rascunho" : "Publicada";

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

            acoes.appendChild(
                criarBotao(
                    obra.status === "rascunho"
                        ? "Continuar editando"
                        : "Editar obra",
                    "botao botao-secundario",
                    "editarObra",
                    obra.id
                )
            );

            cartao.append(cabecalho, autoria, meta, acoes);

            listaMinhasObras.appendChild(cartao);
        });
    }

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
                if (resposta.status === 401) {
                    mostrarFeedbackObras(
                        resultado?.mensagem ||
                        "Sua sessão expirou. Entre novamente.",
                        "erro"
                    );
                } else {
                    mostrarFeedbackObras(
                        resultado?.mensagem ||
                        "Não foi possível carregar suas obras.",
                        "erro"
                    );
                }

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

    function iniciarNovaObra() {
        if (enviando) {
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

            /*
             * Recalcula as dimensões depois que a seção é exibida.
             */
            requestAnimationFrame(() => {
                redimensionarTodasAsImagensZoom();
            });
        }
    }

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

            while (
                etapasContainer.querySelectorAll(".etapa-editor").length >
                etapas.length
            ) {
                const ultima = etapasContainer.lastElementChild;
                const area = ultima?.querySelector(".imagem-selecao-zoom");

                if (area && observadorZoom) {
                    observadorZoom.unobserve(area);
                }

                ultima?.remove();
            }

            reindexarEtapas();

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
                        etapasContainer.querySelectorAll("[data-zoom-imagem]")
                    )
                ];

                await Promise.all(imagens.map(esperarImagem));

                /*
                 * Garante que o canvas seja dimensionado antes de restaurar
                 * os retângulos de zoom salvos.
                 */
                redimensionarTodasAsImagensZoom();

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

                /*
                 * A área pode ter estado oculta enquanto a imagem carregava.
                 * Recalcula depois de abrir a seção de publicação.
                 */
                requestAnimationFrame(() => {
                    redimensionarTodasAsImagensZoom();
                });
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

    document.addEventListener("painel:secao-aberta", (evento) => {
        if (evento.detail?.nome === "minhas-obras") {
            const mensagem = mensagemListaPendente;
            mensagemListaPendente = "";

            carregarMinhasObras(mensagem);
        }

        if (evento.detail?.nome === "publicar") {
            requestAnimationFrame(() => {
                redimensionarTodasAsImagensZoom();
            });
        }
    });

    document.querySelectorAll("[data-obras-filtro]").forEach((botao) => {
        botao.addEventListener("click", () => {
            definirFiltroObras(botao.dataset.obrasFiltro);
        });
    });

    document.addEventListener("click", (evento) => {
        const botaoNova = evento.target.closest("[data-nova-obra]");

        if (botaoNova) {
            evento.preventDefault();
            iniciarNovaObra();
            return;
        }

        const botaoEditar = evento.target.closest("[data-editar-obra]");

        if (botaoEditar) {
            evento.preventDefault();
            carregarObraParaEdicao(botaoEditar.dataset.editarObra);
        }
    });

    botaoRascunho.addEventListener("click", () => {
        salvarObra("rascunho");
    });

    formulario.addEventListener("submit", (evento) => {
        evento.preventDefault();
        salvarObra("publicada");
    });

    /*
     * Carrega a lista para preencher as contagens do painel.
     * Ao abrir "Minhas obras", a lista é atualizada novamente.
     */
    carregarMinhasObras();

    window.addEventListener("beforeunload", () => {
        liberarImagemLocal();
    });
})();