/*
 * Gerenciamento administrativo de obras do Arte Guiada.
 * Utiliza os endpoints compartilhados de obras, sem duplicar regras no PHP.
 */
(() => {
    "use strict";

    const API = {
        listar: "../api/obras/minhas.php",
        detalhe: "../api/obras/detalhe.php",
        imagem: "../api/obras/imagem.php",
        salvar: "../api/obras/salvar.php",
        excluir: "../api/obras/excluir.php"
    };

    const TAMANHO_MAXIMO_IMAGEM = 7 * 1024 * 1024;
    const TIPOS_IMAGEM = new Set([
        "image/jpeg",
        "image/png",
        "image/webp"
    ]);

    const lista = document.getElementById("listaObrasAdmin");
    const feedback = document.getElementById("feedbackObrasAdmin");
    const botaoAtualizar = document.querySelector(
        "[data-atualizar-obras-admin]"
    );
    const botaoNovaObra = document.querySelector(
        "[data-nova-obra-admin]"
    );
    const botoesFiltro = Array.from(
        document.querySelectorAll("[data-filtro-obra-admin]")
    );
    const painelFormulario = document.getElementById("painelFormularioObraAdmin");
    const painelLista = document.getElementById("painelListaObrasAdmin");

    if (!lista || !feedback || !botaoAtualizar || !painelLista || !painelFormulario) {
        console.error(
            "A seção administrativa de obras não contém os elementos necessários."
        );
        return;
    }

    let obras = [];
    let filtroAtual = "todas";
    let carregando = false;
    let salvando = false;
    let excluindo = false;
    let abrindoFormulario = false;
    let formulario = null;
    let mensagemFormulario = null;
    let campoImagem = null;
    let previaImagem = null;
    let campoObraId = null;
    let campoStatus = null;
    let listaEtapas = null;
    let urlImagemTemporaria = null;
    let fonteImagemAtual = null;
    let focoAnterior = null;

    function criar(tag, classe = "", texto = null) {
        const elemento = document.createElement(tag);

        if (classe) elemento.className = classe;
        if (texto !== null) elemento.textContent = texto;

        return elemento;
    }

    function mostrarFeedback(mensagem = "", tipo = "info") {
        feedback.textContent = mensagem;
        feedback.dataset.tipo = tipo;
        feedback.classList.toggle("sucesso", tipo === "sucesso");
        feedback.classList.toggle("erro", tipo === "erro");
    }

    feedback.tabIndex = -1;

    function mostrarFeedbackFormulario(mensagem = "", tipo = "info") {
        if (!mensagemFormulario) return;

        mensagemFormulario.textContent = mensagem;
        mensagemFormulario.dataset.tipo = tipo;
        mensagemFormulario.classList.toggle("sucesso", tipo === "sucesso");
        mensagemFormulario.classList.toggle("erro", tipo === "erro");
    }

    function obterStatus(obra) {
        return String(obra?.status || "").trim().toLowerCase();
    }

    function ehPublicada(obra) {
        return ["publicada", "publicado"].includes(obterStatus(obra));
    }

    function formatarData(valor) {
        if (!valor) return "Data não informada";

        const data = new Date(String(valor).replace(" ", "T"));
        if (Number.isNaN(data.getTime())) return "Data não informada";

        return new Intl.DateTimeFormat("pt-BR", {
            dateStyle: "short",
            timeStyle: "short"
        }).format(data);
    }

    function atualizarContagens() {
        const quantidades = {
            todas: obras.length,
            publicadas: obras.filter(ehPublicada).length,
            rascunhos: obras.filter((obra) => !ehPublicada(obra)).length
        };

        Object.entries(quantidades).forEach(([filtro, quantidade]) => {
            const elemento = document.querySelector(
                `[data-contagem-obras-admin="${filtro}"]`
            );

            if (elemento) elemento.textContent = String(quantidade);
        });
    }

    function atualizarFiltros() {
        botoesFiltro.forEach((botao) => {
            const ativo = botao.dataset.filtroObraAdmin === filtroAtual;
            botao.classList.toggle("ativa", ativo);
            botao.setAttribute("aria-pressed", String(ativo));
        });
    }

    function obrasFiltradas() {
        return obras.filter((obra) => {
            if (filtroAtual === "publicadas") return ehPublicada(obra);
            if (filtroAtual === "rascunhos") return !ehPublicada(obra);
            return true;
        });
    }

    function criarEstadoVazio(titulo, descricao) {
        const estado = criar("div", "estado-vazio");
        estado.append(
            criar("span", "estado-vazio-simbolo", "▧"),
            criar("h3", "", titulo),
            criar("p", "", descricao)
        );
        return estado;
    }

    function criarCartao(obra) {
        const cartao = criar("article", "admin-obra-card");
        const cabecalho = criar("div", "admin-obra-cabecalho");
        const titulo = criar("h3", "", obra.titulo || `Obra sem título #${obra.id}`);
        const statusPublicada = ehPublicada(obra);
        const status = criar(
            "span",
            `admin-obra-status ${statusPublicada ? "publicada" : "rascunho"}`,
            statusPublicada ? "Publicada" : "Rascunho"
        );

        cabecalho.append(titulo, status);

        const autor = criar(
            "p",
            "admin-obra-autor",
            `Autor: ${obra.autor || "Não informado"}`
        );

        const responsavel = criar(
            "p",
            "admin-obra-responsavel",
            `Editor responsável: ${obra.editor_nome || "Não identificado"}`
        );

        const quantidadeEtapas = Number(obra.total_etapas) || 0;
        const meta = criar(
            "p",
            "admin-obra-meta",
            `Atualizada em ${formatarData(obra.atualizada_em)} · ${quantidadeEtapas} ${quantidadeEtapas === 1 ? "etapa" : "etapas"}`
        );

        const acoes = criar("div", "admin-obra-acoes");
        const editar = criar("button", "botao botao-secundario", "Editar obra");
        editar.type = "button";
        editar.dataset.editarObraAdmin = String(obra.id);

        const excluir = criar("button", "botao botao-excluir-obra", "Excluir obra");
        excluir.type = "button";
        excluir.dataset.excluirObraAdmin = String(obra.id);

        const idConfirmacao = `confirmacao-exclusao-admin-${obra.id}`;
        excluir.setAttribute("aria-controls", idConfirmacao);
        excluir.setAttribute("aria-expanded", "false");

        const confirmacao = criar("div", "admin-obra-confirmacao");
        confirmacao.id = idConfirmacao;
        confirmacao.hidden = true;
        confirmacao.setAttribute("role", "group");
        confirmacao.appendChild(criar(
            "p",
            "",
            `Deseja excluir "${obra.titulo || `obra #${obra.id}`}"?`
        ));
        confirmacao.appendChild(criar(
            "p",
            "",
            "A exclusão será lógica. A obra deixará de aparecer nas listagens, mas seu registro não será removido fisicamente."
        ));

        const acoesConfirmacao = criar("div", "admin-obra-confirmacao-acoes");
        const cancelar = criar("button", "botao botao-secundario", "Cancelar");
        cancelar.type = "button";
        cancelar.dataset.cancelarExclusaoAdmin = String(obra.id);

        const confirmar = criar("button", "botao botao-excluir-obra", "Confirmar exclusão");
        confirmar.type = "button";
        confirmar.dataset.confirmarExclusaoAdmin = String(obra.id);

        acoesConfirmacao.append(cancelar, confirmar);
        confirmacao.appendChild(acoesConfirmacao);

        acoes.append(editar, excluir);
        cartao.append(cabecalho, autor, responsavel, meta);

        if (obra.destaque) {
            cartao.appendChild(
                criar("p", "admin-obra-destaque", "Obra em destaque")
            );
        }

        cartao.append(acoes, confirmacao);
        return cartao;
    }

    function renderizarObras() {
        atualizarContagens();
        atualizarFiltros();
        lista.replaceChildren();

        const filtradas = obrasFiltradas();

        if (filtradas.length === 0) {
            const estados = {
                todas: [
                    "Nenhuma obra cadastrada",
                    "Ainda não existem obras disponíveis para gerenciamento."
                ],
                publicadas: [
                    "Nenhuma obra publicada",
                    "Não há publicações cadastradas."
                ],
                rascunhos: [
                    "Nenhum rascunho encontrado",
                    "Não há rascunhos cadastrados."
                ]
            };

            const [titulo, descricao] = estados[filtroAtual];
            lista.appendChild(criarEstadoVazio(titulo, descricao));
            return;
        }

        const fragmento = document.createDocumentFragment();
        filtradas.forEach((obra) => fragmento.appendChild(criarCartao(obra)));
        lista.appendChild(fragmento);
    }

    async function carregarObras({ manterFeedback = false } = {}) {
        if (carregando) return;

        carregando = true;
        if (!manterFeedback) mostrarFeedback("");
        lista.setAttribute("aria-busy", "true");
        botaoAtualizar.disabled = true;
        if (botaoNovaObra) botaoNovaObra.disabled = true;

        if (obras.length === 0) {
            lista.replaceChildren(
                criarEstadoVazio("Carregando obras", "Consultando as publicações e os rascunhos.")
            );
        }

        try {
            const resposta = await fetch(API.listar, {
                method: "GET",
                credentials: "same-origin",
                cache: "no-store",
                headers: { Accept: "application/json" }
            });

            const dados = await resposta.json().catch(() => null);

            if (!resposta.ok || !dados?.sucesso || !Array.isArray(dados.obras)) {
                throw new Error(
                    dados?.mensagem || `Não foi possível carregar as obras (HTTP ${resposta.status}).`
                );
            }

            obras = dados.obras;
            renderizarObras();
            mostrarFeedback(
                `${obras.length} ${obras.length === 1 ? "obra encontrada." : "obras encontradas."}`,
                "sucesso"
            );
        } catch (erro) {
            console.error("Erro ao carregar obras administrativas:", erro);
            lista.replaceChildren(
                criarEstadoVazio(
                    "Não foi possível carregar as obras",
                    "Verifique sua conexão e tente atualizar a lista novamente."
                )
            );
            mostrarFeedback(erro.message, "erro");
        } finally {
            carregando = false;
            lista.setAttribute("aria-busy", "false");
            botaoAtualizar.disabled = false;
            if (botaoNovaObra) botaoNovaObra.disabled = false;
        }
    }

    function criarCampoRotulado(rotulo, controle, classe = "admin-form-campo") {
        const grupo = criar("div", classe);
        const label = criar("label", "", rotulo);
        label.htmlFor = controle.id;
        grupo.append(label, controle);
        return grupo;
    }

    function criarEntrada({ id, nome, valor = "", tipo = "text", classe = "", placeholder = "" }) {
        const campo = criar("input", classe);
        campo.type = tipo;
        campo.id = id;
        campo.name = nome;
        campo.value = valor ?? "";
        if (placeholder) campo.placeholder = placeholder;
        return campo;
    }

    function criarAreaTexto({ id, nome, valor = "", linhas = 4, placeholder = "" }) {
        const campo = criar("textarea", "");
        campo.id = id;
        campo.name = nome;
        campo.rows = linhas;
        campo.value = valor ?? "";
        if (placeholder) campo.placeholder = placeholder;
        return campo;
    }

    function valorCoordenada(valor) {
        if (valor === null || valor === undefined || valor === "") return "";
        const numero = Number(valor);
        return Number.isFinite(numero) ? numero.toFixed(6) : "";
    }

    function criarEtapa(etapa = {}, indice = 0) {
        const cartao = criar("fieldset", "admin-etapa-card");
        cartao.dataset.adminEtapa = "";

        const legenda = criar("legend", "admin-etapa-legenda", `Etapa ${indice + 1}`);
        cartao.appendChild(legenda);

        const cabecalho = criar("div", "admin-etapa-cabecalho");
        const remover = criar("button", "botao botao-secundario admin-remover-etapa", "Remover etapa");
        remover.type = "button";
        remover.dataset.removerEtapaAdmin = "";
        cabecalho.appendChild(remover);
        cartao.appendChild(cabecalho);

        const titulo = criarEntrada({
            id: `adminEtapaTitulo${indice}`,
            nome: `etapas[${indice}][titulo]`,
            valor: etapa.titulo || "",
            classe: ""
        });
        titulo.dataset.campoEtapa = "titulo";
        cartao.appendChild(criarCampoRotulado("Título da etapa", titulo));

        const texto = criarAreaTexto({
            id: `adminEtapaTexto${indice}`,
            nome: `etapas[${indice}][texto]`,
            valor: etapa.texto || "",
            linhas: 4,
            placeholder: "Explique o detalhe da obra que o visitante deverá observar."
        });
        texto.dataset.campoEtapa = "texto";
        cartao.appendChild(criarCampoRotulado("Texto explicativo", texto));

        const orientacao = criar(
            "p",
            "admin-zoom-instrucao",
            "Desenhe sobre a imagem para selecionar a região que será ampliada nesta etapa. Uma nova seleção substitui a anterior."
        );
        cartao.appendChild(orientacao);

        const area = criar("div", "admin-zoom-area");
        area.dataset.adminZoomArea = "";
        area.setAttribute("role", "group");
        area.setAttribute("aria-label", `Seleção da região de zoom da etapa ${indice + 1}`);

        const imagem = criar("img", "admin-zoom-imagem");
        imagem.alt = "Imagem da obra para selecionar a região de zoom";
        imagem.hidden = true;

        const placeholder = criar(
            "div",
            "admin-zoom-placeholder",
            "A imagem da obra será exibida aqui."
        );

        const selecao = criar("div", "admin-zoom-selecao");
        selecao.dataset.adminZoomSelecao = "";
        selecao.hidden = true;

        area.append(imagem, placeholder, selecao);
        cartao.appendChild(area);

        const camposZoom = criar("div", "admin-zoom-valores");
        const definicoesZoom = [
            ["x", "zoom_x"],
            ["y", "zoom_y"],
            ["largura", "zoom_largura"],
            ["altura", "zoom_altura"]
        ];

        definicoesZoom.forEach(([chave, nome]) => {
            const campo = criarEntrada({
                id: `adminEtapa${indice}Zoom${chave}`,
                nome: `etapas[${indice}][${nome}]`,
                valor: valorCoordenada(etapa[nome]),
                tipo: "hidden"
            });
            campo.dataset.zoomAdminValor = chave;
            camposZoom.appendChild(campo);
        });

        const limpar = criar("button", "botao botao-secundario admin-limpar-zoom", "Limpar região de zoom");
        limpar.type = "button";
        limpar.dataset.limparZoomAdmin = "";

        cartao.append(camposZoom, limpar);
        return cartao;
    }

    function reindexarEtapas() {
        if (!listaEtapas) return;

        const etapas = Array.from(listaEtapas.querySelectorAll("[data-admin-etapa]"));

        etapas.forEach((etapa, indice) => {
            const legenda = etapa.querySelector(".admin-etapa-legenda");
            if (legenda) legenda.textContent = `Etapa ${indice + 1}`;

            const campos = [
                ["titulo", "titulo"],
                ["texto", "texto"],
                ["x", "zoom_x"],
                ["y", "zoom_y"],
                ["largura", "zoom_largura"],
                ["altura", "zoom_altura"]
            ];

            campos.forEach(([chave, nome]) => {
                const seletor = ["x", "y", "largura", "altura"].includes(chave)
                    ? `[data-zoom-admin-valor="${chave}"]`
                    : `[data-campo-etapa="${chave}"]`;

                const campo = etapa.querySelector(seletor);
                if (!campo) return;

                const idAnterior = campo.id;
                campo.name = `etapas[${indice}][${nome}]`;
                campo.id = `adminEtapa${indice}${chave}`;

                if (idAnterior) {
                    etapa.querySelectorAll("label").forEach((label) => {
                        if (label.htmlFor === idAnterior) {
                            label.htmlFor = campo.id;
                        }
                    });
                }
            });

            const remover = etapa.querySelector("[data-remover-etapa-admin]");
            if (remover) remover.hidden = etapas.length <= 1;

            const area = etapa.querySelector("[data-admin-zoom-area]");
            if (area) area.setAttribute("aria-label", `Seleção da região de zoom da etapa ${indice + 1}`);
        });
    }

    function escreverZoom(etapa, retangulo) {
        const x = Math.max(0, Math.min(1, retangulo.x));
        const y = Math.max(0, Math.min(1, retangulo.y));
        const largura = Math.max(0, Math.min(1 - x, retangulo.largura));
        const altura = Math.max(0, Math.min(1 - y, retangulo.altura));

        const valores = { x, y, largura, altura };
        Object.entries(valores).forEach(([chave, valor]) => {
            const campo = etapa.querySelector(`[data-zoom-admin-valor="${chave}"]`);
            if (campo) campo.value = valor.toFixed(6);
        });

        const selecao = etapa.querySelector("[data-admin-zoom-selecao]");
        if (selecao) {
            selecao.hidden = false;
            selecao.style.left = `${x * 100}%`;
            selecao.style.top = `${y * 100}%`;
            selecao.style.width = `${largura * 100}%`;
            selecao.style.height = `${altura * 100}%`;
        }
    }

    function limparZoom(etapa) {
        etapa.querySelectorAll("[data-zoom-admin-valor]").forEach((campo) => {
            campo.value = "";
        });

        const selecao = etapa.querySelector("[data-admin-zoom-selecao]");
        if (selecao) selecao.hidden = true;
    }

    function aplicarImagemNasEtapas(url) {
        if (!listaEtapas) return;

        listaEtapas.querySelectorAll("[data-admin-etapa]").forEach((etapa) => {
            const imagem = etapa.querySelector(".admin-zoom-imagem");
            const placeholder = etapa.querySelector(".admin-zoom-placeholder");

            if (!imagem || !placeholder) return;

            if (url) {
                imagem.onload = () => {
                    imagem.hidden = false;
                    placeholder.hidden = true;
                };
                imagem.onerror = () => {
                    imagem.hidden = true;
                    placeholder.hidden = false;
                    placeholder.textContent = "Não foi possível carregar a imagem. Verifique o acesso ao arquivo.";
                };
                imagem.src = url;
            } else {
                imagem.removeAttribute("src");
                imagem.hidden = true;
                placeholder.hidden = false;
                placeholder.textContent = "Selecione uma imagem para configurar as regiões de zoom.";
            }
        });
    }

    function selecionarImagem(url) {
        fonteImagemAtual = url || null;

        if (previaImagem) {
            if (url) {
                previaImagem.src = url;
                previaImagem.hidden = false;
            } else {
                previaImagem.removeAttribute("src");
                previaImagem.hidden = true;
            }
        }

        aplicarImagemNasEtapas(fonteImagemAtual);
    }

    function liberarImagemTemporaria() {
        if (urlImagemTemporaria) {
            URL.revokeObjectURL(urlImagemTemporaria);
            urlImagemTemporaria = null;
        }
    }

    function adicionarEtapa(etapa = {}) {
        if (!listaEtapas) return;

        const indice = listaEtapas.querySelectorAll("[data-admin-etapa]").length;
        const cartao = criarEtapa(etapa, indice);
        listaEtapas.appendChild(cartao);
        reindexarEtapas();

        if (fonteImagemAtual) {
            const imagem = cartao.querySelector(".admin-zoom-imagem");
            const placeholder = cartao.querySelector(".admin-zoom-placeholder");

            imagem.onload = () => {
                imagem.hidden = false;
                placeholder.hidden = true;
            };
            imagem.onerror = () => {
                imagem.hidden = true;
                placeholder.hidden = false;
                placeholder.textContent = "Não foi possível carregar a imagem.";
            };
            imagem.src = fonteImagemAtual;
        }

        const zoom = {
            x: etapa.zoom_x,
            y: etapa.zoom_y,
            largura: etapa.zoom_largura,
            altura: etapa.zoom_altura
        };

        if (Object.values(zoom).every((valor) => valor !== null && valor !== undefined && valor !== "")) {
            escreverZoom(cartao, {
                x: Number(zoom.x),
                y: Number(zoom.y),
                largura: Number(zoom.largura),
                altura: Number(zoom.altura)
            });
        }
    }

    function prepararFormularioNaPagina() {
        if (formulario) return;

        const conteudo = criarConteudoFormulario();
        painelFormulario.replaceChildren(conteudo);

        formulario = painelFormulario.querySelector("#formularioObraAdmin");
        mensagemFormulario = painelFormulario.querySelector("#feedbackFormularioObraAdmin");
        campoImagem = painelFormulario.querySelector("#imagemObraAdmin");
        previaImagem = painelFormulario.querySelector("#previaImagemObraAdmin");
        campoObraId = painelFormulario.querySelector("#obraIdAdmin");
        campoStatus = painelFormulario.querySelector("#statusObraAdmin");
        listaEtapas = painelFormulario.querySelector("#etapasObraAdmin");

        formulario.addEventListener("submit", salvarFormulario);
        formulario.querySelectorAll("[data-cancelar-edicao-admin]")
            .forEach((botao) => {
                botao.addEventListener("click", voltarParaLista);
            });

        formulario.querySelector("[data-adicionar-etapa-admin]")
            .addEventListener("click", () => adicionarEtapa());

        campoImagem.addEventListener("change", tratarImagemSelecionada);

        listaEtapas.addEventListener("click", (evento) => {
            const alvo = evento.target;
            if (!(alvo instanceof Element)) return;

            const remover = alvo.closest("[data-remover-etapa-admin]");
            if (remover) {
                const etapa = remover.closest("[data-admin-etapa]");
                if (etapa && listaEtapas.querySelectorAll("[data-admin-etapa]").length > 1) {
                    etapa.remove();
                    reindexarEtapas();
                }
                return;
            }

            const limpar = alvo.closest("[data-limpar-zoom-admin]");
            if (limpar) {
                const etapa = limpar.closest("[data-admin-etapa]");
                if (etapa) limparZoom(etapa);
            }
        });

        listaEtapas.addEventListener("pointerdown", iniciarSelecaoZoom);
    }

    function criarConteudoFormulario() {
        const wrapper = criar("div", "admin-obras-formulario-pagina");
        wrapper.innerHTML = `
            <div class="admin-obras-formulario-cabecalho">
                <div>
                    <span class="painel-etiqueta">GESTÃO DE CONTEÚDO</span>
                    <h2 id="tituloFormularioObraAdmin">Editar obra</h2>
                    <p>As alterações serão salvas na obra original, sem mudar seu editor responsável.</p>
                </div>
                <button type="button" class="botao botao-secundario" data-cancelar-edicao-admin>
                    ← Voltar para obras
                </button>
            </div>
            <form id="formularioObraAdmin" enctype="multipart/form-data">
                <input type="hidden" id="obraIdAdmin" name="obra_id" value="">
                <div class="admin-form-grade">
                    <div class="admin-form-campo">
                        <label for="tituloObraAdmin">Título da obra</label>
                        <input id="tituloObraAdmin" name="titulo" type="text" maxlength="200">
                    </div>
                    <div class="admin-form-campo">
                        <label for="autorObraAdmin">Autor</label>
                        <input id="autorObraAdmin" name="autor" type="text" maxlength="200">
                    </div>
                    <div class="admin-form-campo admin-form-campo-largo">
                        <label for="altObraAdmin">Descrição da imagem (acessibilidade)</label>
                        <input id="altObraAdmin" name="texto_alternativo" type="text" maxlength="255">
                    </div>
                    <div class="admin-form-campo">
                        <label for="statusObraAdmin">Status da obra</label>
                        <select id="statusObraAdmin" name="status">
                            <option value="rascunho">Rascunho</option>
                            <option value="publicada">Publicada</option>
                        </select>
                    </div>
                    <div class="admin-form-campo admin-form-imagem">
                        <label for="imagemObraAdmin">Substituir imagem principal (opcional)</label>
                        <input id="imagemObraAdmin" name="imagem" type="file" accept="image/jpeg,image/png,image/webp">
                        <small>JPEG, PNG ou WebP. Limite de 7 MB. Sem selecionar arquivo, a imagem atual será preservada.</small>
                    </div>
                </div>
                <div class="admin-form-previa">
                    <img id="previaImagemObraAdmin" alt="Prévia da imagem principal" hidden>
                </div>
                <div class="admin-etapas-topo">
                    <div>
                        <h3>Etapas de exploração</h3>
                        <p>Edite os textos e ajuste visualmente a região de zoom de cada etapa.</p>
                    </div>
                    <button type="button" class="botao botao-secundario" data-adicionar-etapa-admin>＋ Adicionar etapa</button>
                </div>
                <div id="etapasObraAdmin" class="admin-etapas-lista"></div>
                <p id="feedbackFormularioObraAdmin" class="admin-form-feedback" role="status" aria-live="polite"></p>
                <div class="admin-form-acoes">
                    <button type="button" class="botao botao-secundario" data-cancelar-edicao-admin>Cancelar</button>
                    <button type="submit" class="botao botao-principal" data-salvar-obra-admin>Salvar alterações</button>
                </div>
            </form>
        `;
        return wrapper;
    }

    function exibirFormulario() {
        painelLista.hidden = true;
        painelFormulario.hidden = false;
        requestAnimationFrame(() => {
            painelFormulario.querySelector("#tituloObraAdmin")?.focus();
        });
    }

    function voltarParaLista(forcar = false) {
        if (salvando && !forcar) return;

        liberarImagemTemporaria();
        fonteImagemAtual = null;
        formulario?.reset();
        listaEtapas?.replaceChildren();

        painelFormulario.hidden = true;
        painelLista.hidden = false;

        if (focoAnterior?.isConnected) focoAnterior.focus();
        focoAnterior = null;
    }

    function iniciarSelecaoZoom(evento) {
        const alvo = evento.target;
        if (!(alvo instanceof Element)) return;

        const area = alvo.closest("[data-admin-zoom-area]");
        if (!area || (evento.pointerType === "mouse" && evento.button !== 0)) return;

        const etapa = area.closest("[data-admin-etapa]");
        const imagem = area.querySelector(".admin-zoom-imagem");
        if (!etapa || !imagem || imagem.hidden || !imagem.complete || !imagem.naturalWidth) {
            mostrarFeedbackFormulario("A imagem ainda não está disponível para selecionar o zoom.", "erro");
            return;
        }

        const limites = imagem.getBoundingClientRect();
        if (limites.width <= 0 || limites.height <= 0) return;

        const ponto = (movimento) => ({
            x: Math.max(0, Math.min(1, (movimento.clientX - limites.left) / limites.width)),
            y: Math.max(0, Math.min(1, (movimento.clientY - limites.top) / limites.height))
        });

        const inicio = ponto(evento);
        let atual = inicio;
        const selecao = area.querySelector("[data-admin-zoom-selecao]");
        const valoresAnteriores = {};

        ["x", "y", "largura", "altura"].forEach((chave) => {
            const campo = etapa.querySelector(`[data-zoom-admin-valor="${chave}"]`);
            valoresAnteriores[chave] = campo?.value ?? "";
        });

        const tinhaZoomAnterior = Object.values(valoresAnteriores).every(
            (valor) => valor !== "" && Number.isFinite(Number(valor))
        );

        const restaurarZoomAnterior = () => {
            if (tinhaZoomAnterior) {
                escreverZoom(etapa, {
                    x: Number(valoresAnteriores.x),
                    y: Number(valoresAnteriores.y),
                    largura: Number(valoresAnteriores.largura),
                    altura: Number(valoresAnteriores.altura)
                });
            } else {
                limparZoom(etapa);
            }
        };

        const atualizar = () => {
            const x = Math.min(inicio.x, atual.x);
            const y = Math.min(inicio.y, atual.y);
            const largura = Math.abs(atual.x - inicio.x);
            const altura = Math.abs(atual.y - inicio.y);
            selecao.hidden = false;
            selecao.style.left = `${x * 100}%`;
            selecao.style.top = `${y * 100}%`;
            selecao.style.width = `${largura * 100}%`;
            selecao.style.height = `${altura * 100}%`;
        };

        evento.preventDefault();

        try {
            area.setPointerCapture(evento.pointerId);
        } catch (erro) {
            // A captura evita perder o fim do gesto, mas não é indispensável.
        }

        const mover = (movimento) => {
            atual = ponto(movimento);
            atualizar();
        };

        const finalizar = () => {
            area.removeEventListener("pointermove", mover);
            area.removeEventListener("pointerup", finalizar);
            area.removeEventListener("pointercancel", cancelar);

            try {
                if (area.hasPointerCapture(evento.pointerId)) {
                    area.releasePointerCapture(evento.pointerId);
                }
            } catch (erro) {
                // A captura pode já ter sido liberada pelo navegador.
            }

            const x = Math.min(inicio.x, atual.x);
            const y = Math.min(inicio.y, atual.y);
            const largura = Math.abs(atual.x - inicio.x);
            const altura = Math.abs(atual.y - inicio.y);

            if (largura < 0.02 || altura < 0.02) {
                restaurarZoomAnterior();
                mostrarFeedbackFormulario("A região ficou pequena demais. A seleção anterior foi preservada.", "erro");
                return;
            }

            escreverZoom(etapa, { x, y, largura, altura });
            mostrarFeedbackFormulario("Região de zoom atualizada.", "sucesso");
        };

        const cancelar = () => {
            area.removeEventListener("pointermove", mover);
            area.removeEventListener("pointerup", finalizar);
            area.removeEventListener("pointercancel", cancelar);
            restaurarZoomAnterior();
        };

        area.addEventListener("pointermove", mover);
        area.addEventListener("pointerup", finalizar, { once: true });
        area.addEventListener("pointercancel", cancelar, { once: true });
    }

    function tratarImagemSelecionada() {
        const arquivo = campoImagem.files?.[0] || null;
        if (!arquivo) return;

        if (!TIPOS_IMAGEM.has(arquivo.type)) {
            campoImagem.value = "";
            mostrarFeedbackFormulario("Escolha uma imagem JPEG, PNG ou WebP.", "erro");
            return;
        }

        if (arquivo.size <= 0 || arquivo.size > TAMANHO_MAXIMO_IMAGEM) {
            campoImagem.value = "";
            mostrarFeedbackFormulario("A imagem deve ter tamanho maior que zero e no máximo 7 MB.", "erro");
            return;
        }

        liberarImagemTemporaria();
        urlImagemTemporaria = URL.createObjectURL(arquivo);
        selecionarImagem(urlImagemTemporaria);
        mostrarFeedbackFormulario("Nova imagem selecionada. As regiões de zoom podem ser ajustadas sobre ela.", "info");
    }

    function prepararFormulario(obra = null) {
        formulario.reset();
        listaEtapas.replaceChildren();
        liberarImagemTemporaria();
        fonteImagemAtual = null;

        campoObraId.value = obra?.id ? String(obra.id) : "";
        formulario.querySelector("#tituloObraAdmin").value = obra?.titulo || "";
        formulario.querySelector("#autorObraAdmin").value = obra?.autor || "";
        formulario.querySelector("#altObraAdmin").value = obra?.texto_alternativo || "";
        campoStatus.value = obra?.status === "publicada" ? "publicada" : "rascunho";
        campoImagem.value = "";

        const tituloFormulario = painelFormulario.querySelector("#tituloFormularioObraAdmin");
        tituloFormulario.textContent = obra ? "Editar obra" : "Criar nova obra";
        formulario.querySelector("[data-salvar-obra-admin]").textContent = obra
            ? "Salvar alterações"
            : "Criar obra";

        const etapas = Array.isArray(obra?.etapas) && obra.etapas.length
            ? obra.etapas
            : [{}];

        etapas.forEach((etapa) => adicionarEtapa(etapa));

        if (obra?.imagem_salva) {
            selecionarImagem(`${API.imagem}?id=${encodeURIComponent(obra.id)}&t=${Date.now()}`);
        } else {
            selecionarImagem(null);
        }

        mostrarFeedbackFormulario("");
    }

    async function abrirFormulario(obraId = null, botaoOrigem = null) {
        if (abrindoFormulario || !painelFormulario.hidden || salvando || excluindo) return;

        abrindoFormulario = true;
        if (botaoOrigem) botaoOrigem.disabled = true;

        try {
            prepararFormularioNaPagina();
            focoAnterior = botaoOrigem || document.activeElement;

            if (obraId === null) {
                prepararFormulario(null);
                exibirFormulario();
                return;
            }

            mostrarFeedback("Carregando os dados da obra...", "info");

            const resposta = await fetch(
                `${API.detalhe}?id=${encodeURIComponent(obraId)}`,
                {
                    method: "GET",
                    credentials: "same-origin",
                    cache: "no-store",
                    headers: { Accept: "application/json" }
                }
            );

            const dados = await resposta.json().catch(() => null);

            if (!resposta.ok || !dados?.sucesso || !dados.obra) {
                throw new Error(
                    dados?.mensagem || `Não foi possível carregar a obra (HTTP ${resposta.status}).`
                );
            }

            prepararFormulario(dados.obra);
            exibirFormulario();
            mostrarFeedback("");
        } catch (erro) {
            console.error("Erro ao abrir obra para edição:", erro);
            mostrarFeedback(erro.message, "erro");
            focoAnterior = null;
        } finally {
            abrindoFormulario = false;
            if (botaoOrigem?.isConnected) botaoOrigem.disabled = false;
        }
    }

    async function salvarFormulario(evento) {
        evento.preventDefault();
        if (salvando || excluindo) return;

        const dados = new FormData(formulario);
        dados.set("obra_id", campoObraId.value || "");
        dados.set("status", campoStatus.value);

        salvando = true;
        const botoes = Array.from(formulario.querySelectorAll("button"));
        botoes.forEach((botao) => { botao.disabled = true; });
        mostrarFeedbackFormulario(
            campoStatus.value === "publicada" ? "Salvando publicação..." : "Salvando rascunho...",
            "info"
        );

        try {
            const resposta = await fetch(API.salvar, {
                method: "POST",
                credentials: "same-origin",
                cache: "no-store",
                headers: { Accept: "application/json" },
                body: dados
            });

            const resultado = await resposta.json().catch(() => null);

            if (!resposta.ok || !resultado?.sucesso) {
                throw new Error(
                    resultado?.mensagem || `Não foi possível salvar a obra (HTTP ${resposta.status}).`
                );
            }

            const mensagemBase = resultado.mensagem || "Obra salva com sucesso.";
            const mensagem = campoObraId.value
                ? `${mensagemBase} Quando a obra pertence a outro editor, ele recebe um aviso no próprio painel.`
                : mensagemBase;
            voltarParaLista(true);
            await carregarObras({ manterFeedback: true });
            mostrarFeedback(mensagem, "sucesso");
        } catch (erro) {
            console.error("Erro ao salvar obra administrativa:", erro);
            mostrarFeedbackFormulario(erro.message, "erro");
        } finally {
            salvando = false;
            botoes.forEach((botao) => { botao.disabled = false; });
        }
    }

    async function excluirObra(obraId, botaoConfirmar) {
        if (excluindo || salvando) return;

        const obra = obras.find((item) => String(item.id) === String(obraId));
        const cartao = botaoConfirmar?.closest(".admin-obra-card");
        if (!obra || !cartao) return;

        excluindo = true;
        cartao.querySelectorAll("button").forEach((botao) => {
            botao.disabled = true;
        });
        mostrarFeedback(`Excluindo "${obra.titulo || `obra #${obraId}`}"...`, "info");

        try {
            const resposta = await fetch(API.excluir, {
                method: "POST",
                credentials: "same-origin",
                cache: "no-store",
                headers: {
                    Accept: "application/json",
                    "Content-Type": "application/json"
                },
                body: JSON.stringify({ obra_id: Number(obraId) })
            });

            const resultado = await resposta.json().catch(() => null);

            if (!resposta.ok || !resultado?.sucesso) {
                throw new Error(
                    resultado?.mensagem || `Não foi possível excluir a obra (HTTP ${resposta.status}).`
                );
            }

            obras = obras.filter((item) => String(item.id) !== String(obraId));
            renderizarObras();
            mostrarFeedback(
                `${resultado.mensagem || "Obra excluída com sucesso."} O editor responsável recebe um aviso no próprio painel, quando a obra pertence a outra conta.`,
                "sucesso"
            );
            feedback.focus?.();
        } catch (erro) {
            console.error("Erro ao excluir obra administrativa:", erro);
            mostrarFeedback(erro.message, "erro");
            if (cartao.isConnected) {
                cartao.querySelectorAll("button").forEach((botao) => {
                    botao.disabled = false;
                });
            }
        } finally {
            excluindo = false;
        }
    }

    botoesFiltro.forEach((botao) => {
        botao.addEventListener("click", () => {
            filtroAtual = botao.dataset.filtroObraAdmin;
            renderizarObras();
        });
    });

    botaoAtualizar.addEventListener("click", () => carregarObras());

    if (botaoNovaObra) {
        botaoNovaObra.addEventListener("click", () => abrirFormulario());
    }

    lista.addEventListener("click", (evento) => {
        const alvo = evento.target;
        if (!(alvo instanceof Element)) return;

        const botaoEditar = alvo.closest("[data-editar-obra-admin]");
        if (botaoEditar) {
            abrirFormulario(botaoEditar.dataset.editarObraAdmin, botaoEditar);
            return;
        }

        const botaoCancelar = alvo.closest("[data-cancelar-exclusao-admin]");
        if (botaoCancelar) {
            const cartao = botaoCancelar.closest(".admin-obra-card");
            const confirmacao = cartao?.querySelector(".admin-obra-confirmacao");
            const botaoExcluir = cartao?.querySelector("[data-excluir-obra-admin]");

            if (confirmacao) confirmacao.hidden = true;
            if (botaoExcluir) {
                botaoExcluir.hidden = false;
                botaoExcluir.setAttribute("aria-expanded", "false");
                botaoExcluir.focus();
            }
            return;
        }

        const botaoConfirmar = alvo.closest("[data-confirmar-exclusao-admin]");
        if (botaoConfirmar) {
            excluirObra(botaoConfirmar.dataset.confirmarExclusaoAdmin, botaoConfirmar);
            return;
        }

        const botaoExcluir = alvo.closest("[data-excluir-obra-admin]");
        if (botaoExcluir) {
            if (salvando || excluindo) return;

            const cartao = botaoExcluir.closest(".admin-obra-card");
            const confirmacao = cartao?.querySelector(".admin-obra-confirmacao");
            if (!confirmacao) return;

            confirmacao.hidden = false;
            botaoExcluir.hidden = true;
            botaoExcluir.setAttribute("aria-expanded", "true");
            confirmacao.querySelector("[data-confirmar-exclusao-admin]")?.focus();
        }
    });

    document.addEventListener("painel:secao-aberta", (evento) => {
        if (evento.detail?.nome === "obras") {
            carregarObras();
        }
    });

    carregarObras();
})();
