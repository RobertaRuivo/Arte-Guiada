/*
 * Arte Guiada | Atendimento administrativo dos relatos.
 * A API valida o perfil admin no servidor; a interface não é a proteção.
 */
(() => {
    "use strict";

    const script = document.currentScript;
    if (!script?.src) return;

    const raizAplicacao = new URL("../../", new URL(script.src));
    const urlListar = new URL("api/relatos/listar-admin.php", raizAplicacao);
    const urlAtualizar = new URL("api/relatos/atualizar-status.php", raizAplicacao);

    const lista = document.getElementById("listaRelatosAdmin");
    const feedback = document.getElementById("feedbackRelatosAdmin");
    const botoesFiltro = Array.from(
        document.querySelectorAll("[data-filtro-relato]")
    );
    const botaoAtualizar = document.querySelector("[data-atualizar-relatos]");

    if (!lista) {
        console.error("[ADMIN/RELATOS] Lista de relatos não encontrada.");
        return;
    }

    const rotulosStatus = {
        pendente: "Pendente",
        em_analise: "Em análise",
        resolvido: "Resolvido"
    };

    const rotulosTipo = {
        imagem: "Imagem",
        informacoes: "Informações da obra",
        texto: "Texto explicativo",
        etapa_zoom: "Etapa ou zoom",
        navegacao: "Navegação",
        acessibilidade: "Acessibilidade",
        outro: "Outro"
    };

    let relatos = [];
    let filtroAtivo = "pendente";
    let carregando = false;

    function elemento(tag, classe, texto) {
        const no = document.createElement(tag);
        if (classe) no.className = classe;
        if (texto !== undefined && texto !== null) {
            no.textContent = String(texto);
        }
        return no;
    }

    function informar(mensagem, tipo = "") {
        if (!feedback) return;
        feedback.textContent = mensagem;
        feedback.classList.remove("erro", "sucesso");
        if (tipo) feedback.classList.add(tipo);
    }

    function formatarData(valor) {
        if (!valor) return "Data não informada";

        const data = new Date(String(valor).replace(" ", "T"));
        if (Number.isNaN(data.getTime())) return String(valor);

        return new Intl.DateTimeFormat("pt-BR", {
            dateStyle: "short",
            timeStyle: "short"
        }).format(data);
    }

    function atualizarContagens() {
        const contagens = {
            pendente: 0,
            em_analise: 0,
            resolvido: 0
        };

        relatos.forEach((relato) => {
            if (Object.hasOwn(contagens, relato.status)) {
                contagens[relato.status] += 1;
            }
        });

        Object.entries(contagens).forEach(([status, quantidade]) => {
            const contador = document.querySelector(
                `[data-contagem-relato="${status}"]`
            );
            if (contador) contador.textContent = String(quantidade);
        });

        const total = document.querySelector("[data-total-relatos]");
        if (total) total.textContent = String(relatos.length);
    }

    function criarEstadoVazio(titulo, texto) {
        const estado = elemento("div", "estado-vazio relato-admin-vazio");
        estado.append(
            elemento("span", "estado-vazio-simbolo", "✓"),
            elemento("h3", "", titulo),
            elemento("p", "", texto)
        );
        return estado;
    }

    function criarCategorias(tipos) {
        const grupo = elemento("div", "relato-admin-categorias");
        const categorias = Array.isArray(tipos) ? tipos : [];

        categorias.forEach((tipo) => {
            grupo.appendChild(
                elemento("span", "relato-admin-categoria", rotulosTipo[tipo] || tipo)
            );
        });

        return grupo;
    }

    function criarCampoStatus(statusAtual) {
        const rotulo = elemento("label", "relato-admin-campo", "Estado do atendimento");
        const seletor = elemento("select", "");
        seletor.name = "status";
        seletor.required = true;

        Object.entries(rotulosStatus).forEach(([valor, texto]) => {
            const opcao = elemento("option", "", texto);
            opcao.value = valor;
            opcao.selected = valor === statusAtual;
            seletor.appendChild(opcao);
        });

        rotulo.appendChild(seletor);
        return rotulo;
    }

    function criarCampoObservacao() {
        const rotulo = elemento(
            "label",
            "relato-admin-campo relato-admin-campo-largo",
            "Observação do atendimento (opcional)"
        );
        const campo = elemento("textarea", "");
        campo.name = "observacao";
        campo.rows = 2;
        campo.maxLength = 1000;
        campo.placeholder = "Registre uma providência ou informação sobre a análise.";
        rotulo.appendChild(campo);
        return rotulo;
    }

    function criarCartao(relato) {
        const cartao = elemento("article", "relato-admin-card");

        const cabecalho = elemento("div", "relato-admin-cabecalho");
        const titulo = elemento(
            "h3",
            "relato-admin-titulo",
            `Relato #${relato.id}`
        );
        const estado = elemento(
            "span",
            `relato-admin-estado estado-${relato.status}`,
            rotulosStatus[relato.status] || relato.status
        );
        cabecalho.append(titulo, estado);

        const obra = elemento("p", "relato-admin-meta");
        obra.append(
            elemento("strong", "", "Obra: "),
            document.createTextNode(relato.obra_titulo || `Obra #${relato.obra_id}`)
        );
        if (relato.obra_autor) {
            obra.append(
                document.createTextNode(" · "),
                elemento("span", "", relato.obra_autor)
            );
        }

        const origem = elemento("p", "relato-admin-meta");
        origem.append(
            elemento("strong", "", "Enviado por: "),
            document.createTextNode(relato.relator_nome || "Visitante anônimo"),
            document.createTextNode(" · "),
            elemento("strong", "", "Data: "),
            document.createTextNode(formatarData(relato.enviado_em))
        );

        const categoriasTitulo = elemento("p", "relato-admin-legenda", "Categorias selecionadas");
        const categorias = criarCategorias(relato.tipos);

        const descricaoTitulo = elemento("p", "relato-admin-legenda", "Descrição");
        const descricao = elemento(
            "p",
            "relato-admin-descricao",
            relato.descricao?.trim() || "Nenhuma descrição adicional foi informada."
        );

        const responsavel = elemento(
            "p",
            "relato-admin-meta relato-admin-responsavel",
            relato.atendente_nome
                ? `Responsável atual: ${relato.atendente_nome}`
                : "Ainda não há um administrador responsável registrado."
        );

        const formulario = elemento("form", "relato-admin-form");
        formulario.dataset.formRelato = "";
        formulario.dataset.relatoId = String(relato.id);
        formulario.append(
            criarCampoStatus(relato.status),
            criarCampoObservacao()
        );

        const acoes = elemento("div", "relato-admin-acoes");
        const botaoSalvar = elemento("button", "botao botao-principal", "Salvar atualização");
        botaoSalvar.type = "submit";
        acoes.appendChild(botaoSalvar);
        formulario.appendChild(acoes);

        cartao.append(
            cabecalho,
            obra,
            origem,
            categoriasTitulo,
            categorias,
            descricaoTitulo,
            descricao,
            responsavel,
            formulario
        );

        return cartao;
    }

    function renderizarRelatos() {
        lista.replaceChildren();

        const filtrados = filtroAtivo === "todos"
            ? relatos
            : relatos.filter((relato) => relato.status === filtroAtivo);

        if (filtrados.length === 0) {
            const mensagens = {
                todos: ["Nenhum relato registrado", "Quando alguém enviar um relato, ele aparecerá nesta área."],
                pendente: ["Nenhum relato pendente", "Não há relatos aguardando atendimento."],
                em_analise: ["Nenhum relato em análise", "Não há relatos sendo analisados no momento."],
                resolvido: ["Nenhum relato resolvido", "Os relatos concluídos aparecerão nesta lista."]
            };
            const [titulo, texto] = mensagens[filtroAtivo] || mensagens.todos;
            lista.appendChild(criarEstadoVazio(titulo, texto));
            return;
        }

        const fragmento = document.createDocumentFragment();
        filtrados.forEach((relato) => fragmento.appendChild(criarCartao(relato)));
        lista.appendChild(fragmento);
    }

    async function carregarRelatos() {
        if (carregando) return;
        carregando = true;
        lista.setAttribute("aria-busy", "true");

        if (botaoAtualizar) botaoAtualizar.disabled = true;
        informar("Carregando relatos...");

        try {
            const resposta = await fetch(urlListar.href, {
                method: "GET",
                credentials: "same-origin",
                cache: "no-store",
                headers: { Accept: "application/json" }
            });

            const dados = await resposta.json().catch(() => null);

            if (!resposta.ok || dados?.sucesso !== true || !Array.isArray(dados.relatos)) {
                throw new Error(
                    dados?.mensagem ||
                    `Não foi possível carregar os relatos (HTTP ${resposta.status}).`
                );
            }

            relatos = dados.relatos;
            atualizarContagens();
            renderizarRelatos();
            informar(`${relatos.length} relato(s) carregado(s).`, "sucesso");

        } catch (erro) {
            console.error("[ADMIN/RELATOS] Erro ao carregar:", erro);
            lista.replaceChildren(
                criarEstadoVazio(
                    "Não foi possível carregar os relatos",
                    erro.message || "Verifique sua conexão e tente novamente."
                )
            );
            informar(erro.message || "Falha ao consultar os relatos.", "erro");

        } finally {
            carregando = false;
            lista.setAttribute("aria-busy", "false");
            if (botaoAtualizar) botaoAtualizar.disabled = false;
        }
    }

    function selecionarFiltro(botao) {
        filtroAtivo = botao.dataset.filtroRelato || "pendente";

        botoesFiltro.forEach((item) => {
            const ativo = item === botao;
            item.classList.toggle("ativa", ativo);
            item.setAttribute("aria-pressed", String(ativo));
        });

        renderizarRelatos();
    }

    botoesFiltro.forEach((botao) => {
        botao.addEventListener("click", () => selecionarFiltro(botao));
    });

    botaoAtualizar?.addEventListener("click", carregarRelatos);

    /* Um único listener atende os formulários de todos os cartões. */
    lista.addEventListener("submit", async (evento) => {
        const formulario = evento.target.closest("form[data-form-relato]");
        if (!formulario) return;

        evento.preventDefault();

        const botao = formulario.querySelector('button[type="submit"]');
        const relatoId = Number(formulario.dataset.relatoId);
        const status = formulario.elements.status?.value;
        const observacao = formulario.elements.observacao?.value?.trim() || "";

        if (!Number.isSafeInteger(relatoId) || relatoId < 1) {
            informar("Identificador do relato inválido.", "erro");
            return;
        }

        if (!Object.hasOwn(rotulosStatus, status)) {
            informar("Selecione um estado válido.", "erro");
            return;
        }

        if (botao) {
            botao.disabled = true;
            botao.textContent = "Salvando...";
        }

        informar(`Atualizando o relato #${relatoId}...`);

        try {
            const resposta = await fetch(urlAtualizar.href, {
                method: "POST",
                credentials: "same-origin",
                cache: "no-store",
                headers: {
                    "Content-Type": "application/json",
                    Accept: "application/json"
                },
                body: JSON.stringify({
                    relato_id: relatoId,
                    status,
                    observacao
                })
            });

            const dados = await resposta.json().catch(() => null);

            if (!resposta.ok || dados?.sucesso !== true) {
                throw new Error(
                    dados?.mensagem ||
                    `Não foi possível atualizar o relato (HTTP ${resposta.status}).`
                );
            }

            informar(dados.mensagem || "Atendimento atualizado.", "sucesso");
            await carregarRelatos();

        } catch (erro) {
            console.error("[ADMIN/RELATOS] Erro ao atualizar:", erro);
            informar(erro.message || "Não foi possível atualizar o atendimento.", "erro");

        } finally {
            if (botao) {
                botao.disabled = false;
                botao.textContent = "Salvar atualização";
            }
        }
    });

    /* A página só é protegida no servidor; esta tela apenas consulta a API. */
    carregarRelatos();
})();
