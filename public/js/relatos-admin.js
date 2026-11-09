/* Supervisão administrativa dos relatos. A correção é responsabilidade do editor atribuído. */
(() => {
    "use strict";

    const script = document.currentScript;
    if (!script?.src) return;
    const raiz = new URL("../../", new URL(script.src));
    const api = (arquivo) => new URL(`api/relatos/${arquivo}`, raiz).href;

    const lista = document.getElementById("listaRelatosAdmin");
    const feedback = document.getElementById("feedbackRelatosAdmin");
    const botaoAtualizar = document.querySelector("[data-atualizar-relatos]");
    let filtros = document.querySelector(".filtros-admin");
    const secao = document.querySelector('[data-painel-secao="problemas"]');

    if (!lista || !secao) {
        console.error("[RELATOS/ADMIN] Estrutura da seção de relatos não encontrada.");
        return;
    }

    const status = {
        pendente: "Pendente de correção",
        em_analise: "Em análise (legado)",
        atribuido: "Atribuído ao editor",
        em_correcao: "Em correção",
        aguardando_verificacao: "Aguardando verificação",
        reaberto: "Devolvido ao editor",
        resolvido: "Resolvido",
        improcedente: "Improcedente"
    };
    const prioridades = {
        baixa: "Baixa",
        media: "Média",
        alta: "Alta",
        critica: "Crítica"
    };
    const tipos = {
        imagem: "Imagem",
        informacoes: "Informações da obra",
        texto: "Texto explicativo",
        etapa_zoom: "Etapa ou zoom",
        navegacao: "Navegação",
        acessibilidade: "Acessibilidade",
        outro: "Outro"
    };
    const filtrosDisponiveis = [
        ["abertos", "Em andamento"],
        ["pendente", "Pendentes"],
        ["em_analise", "Em análise (legado)"],
        ["atribuido", "Atribuídos"],
        ["em_correcao", "Em correção"],
        ["aguardando_verificacao", "Aguardando verificação"],
        ["reaberto", "Devolvidos"],
        ["resolvido", "Resolvidos"],
        ["improcedente", "Improcedentes"],
        ["todos", "Todos"]
    ];

    let relatos = [];
    let editores = [];
    let filtroAtual = "abertos";
    let carregando = false;
    let avisosCarregando = false;

    const avisos = document.createElement("section");
    avisos.className = "relatos-admin-notificacoes";
    avisos.id = "notificacoesRelatosAdmin";
    avisos.hidden = true;
    avisos.setAttribute("aria-live", "polite");
    const tituloAvisos = node("h3", "relatos-admin-notificacoes-titulo", "Avisos para a administração");
    const listaAvisos = node("div", "relatos-admin-notificacoes-lista");
    avisos.append(tituloAvisos, listaAvisos);
    const tituloSecao = secao.querySelector(".secao-titulo-painel");
    if (tituloSecao) tituloSecao.insertAdjacentElement("afterend", avisos);
    else secao.insertBefore(avisos, lista);

    const navProblemas = document.querySelector('[data-painel-target="problemas"]');
    let seloNovos = null;
    if (navProblemas) {
        seloNovos = node("span", "relatos-admin-selo-novos", "0");
        seloNovos.hidden = true;
        seloNovos.setAttribute("aria-label", "Novos avisos de relatos");
        navProblemas.appendChild(seloNovos);
    }

    function node(tag, classe = "", texto = null) {
        const item = document.createElement(tag);
        if (classe) item.className = classe;
        if (texto !== null && texto !== undefined) item.textContent = String(texto);
        return item;
    }

    if (!filtros) {
        filtros = node("div", "filtros-admin");
        filtros.id = "filtrosRelatosAdmin";
        if (tituloSecao) tituloSecao.insertAdjacentElement("afterend", filtros);
        else secao.insertBefore(filtros, lista);
    }

    function informar(mensagem, tipo = "") {
        if (!feedback) return;
        feedback.textContent = mensagem;
        feedback.classList.remove("erro", "sucesso");
        if (tipo) feedback.classList.add(tipo);
    }

    function dataPt(valor) {
        if (!valor) return "Data não informada";
        const data = new Date(String(valor).replace(" ", "T"));
        if (Number.isNaN(data.getTime())) return String(valor);
        return new Intl.DateTimeFormat("pt-BR", { dateStyle: "short", timeStyle: "short" }).format(data);
    }

    function criarCampo(rotulo, controle, classe = "") {
        const label = node("label", `relato-admin-campo ${classe}`.trim(), rotulo);
        label.appendChild(controle);
        return label;
    }

    function criarSelect(opcoes, selecionado, classe = "") {
        const select = node("select", classe);
        opcoes.forEach(([valor, nome]) => {
            const option = node("option", "", nome);
            option.value = valor;
            option.selected = String(valor) === String(selecionado);
            select.appendChild(option);
        });
        return select;
    }

    function montarFiltros() {
        if (!filtros) return;
        const ativoAnterior = filtroAtual;
        filtros.replaceChildren(node("span", "filtro-etiqueta", "Estado do relato"));
        filtrosDisponiveis.forEach(([valor, nome]) => {
            const quantidade = valor === "todos"
                ? relatos.length
                : valor === "abertos"
                    ? relatos.filter((r) => !["resolvido", "improcedente"].includes(r.status)).length
                    : relatos.filter((r) => r.status === valor).length;
            const botao = node("button", `filtro-chip${valor === ativoAnterior ? " ativa" : ""}`, `${nome} (${quantidade})`);
            botao.type = "button";
            botao.dataset.filtroRelato = valor;
            botao.setAttribute("aria-pressed", String(valor === ativoAnterior));
            botao.addEventListener("click", () => {
                filtroAtual = valor;
                montarFiltros();
                renderizar();
            });
            filtros.appendChild(botao);
        });
    }

    function criarCategorias(valores) {
        const grupo = node("div", "relato-admin-categorias");
        (Array.isArray(valores) ? valores : []).forEach((valor) => {
            grupo.appendChild(node("span", "relato-admin-categoria", tipos[valor] || valor));
        });
        if (!grupo.childElementCount) grupo.appendChild(node("span", "relato-admin-categoria", "Categoria não informada"));
        return grupo;
    }

    function criarBotao(texto, acao, variante = "botao-secundario") {
        const botao = node("button", `botao ${variante}`, texto);
        botao.type = "button";
        botao.dataset.relatoAction = acao;
        return botao;
    }

    function criarCartao(relato) {
        const cartao = node("article", "relato-admin-card");
        cartao.dataset.relatoId = String(relato.id);

        const cabecalho = node("div", "relato-admin-cabecalho");
        cabecalho.appendChild(node("h3", "relato-admin-titulo", `Relato #${relato.id}`));
        const selo = node("span", `relato-admin-estado estado-${relato.status}`, status[relato.status] || relato.status);
        cabecalho.appendChild(selo);
        const prioridade = node("span", `relato-admin-prioridade prioridade-${relato.prioridade}`, `Prioridade ${prioridades[relato.prioridade] || "Média"}`);
        cabecalho.appendChild(prioridade);

        const obra = node("p", "relato-admin-meta");
        obra.append(node("strong", "", "Obra: "), document.createTextNode(relato.obra_titulo || `Obra #${relato.obra_id}`));
        if (relato.obra_autor) obra.append(document.createTextNode(` · ${relato.obra_autor}`));
        const contaAutora = node("p", "relato-admin-meta", `Autoria original: ${relato.autor_conta_nome || "não identificada"}`);
        const origem = node("p", "relato-admin-meta", `Relatado por ${relato.relator_nome || "Visitante anônimo"} · ${dataPt(relato.enviado_em)}`);
        const responsavel = node("p", "relato-admin-meta relato-admin-responsavel", `Editor responsável: ${relato.editor_responsavel_nome || "Ainda não atribuído"}`);
        const descricaoTitulo = node("p", "relato-admin-legenda", "Problema relatado");
        const descricao = node("p", "relato-admin-descricao", relato.descricao || "Sem descrição adicional.");
        const categorias = criarCategorias(relato.tipos);

        cartao.append(cabecalho, obra, contaAutora, origem, responsavel, node("p", "relato-admin-legenda", "Categorias"), categorias, descricaoTitulo, descricao);

        if (relato.resposta_editor) {
            const resposta = node("div", "relato-admin-resposta-editor");
            resposta.append(node("strong", "", "Correção informada pelo editor"), node("p", "", relato.resposta_editor));
            if (relato.corrigido_em) resposta.appendChild(node("time", "", `Enviada em ${dataPt(relato.corrigido_em)}`));
            cartao.appendChild(resposta);
        }
        if (relato.ultima_observacao) {
            const ultima = node("p", "relato-admin-ultima-acao", `Última providência: ${relato.ultima_observacao}`);
            cartao.appendChild(ultima);
        }

        const controles = node("div", "relato-admin-controles");
        const editorAtual = relato.editor_responsavel_id || relato.obra_criada_por || "";
        const opcoesEditores = [["", "Selecione um editor"]].concat(
            editores.map((e) => [String(e.id), e.nome])
        );
        const selectEditor = criarSelect(opcoesEditores, editorAtual);
        selectEditor.dataset.campoEditor = "";
        const selectPrioridade = criarSelect(Object.entries(prioridades), relato.prioridade || "media");
        selectPrioridade.dataset.campoPrioridade = "";
        const observacao = node("textarea", "");
        observacao.rows = 2;
        observacao.maxLength = 1000;
        observacao.placeholder = "Orientação ao editor ou justificativa da providência (até 1.000 caracteres).";
        observacao.dataset.campoObservacao = "";

        controles.append(
            criarCampo("Editor responsável", selectEditor),
            criarCampo("Prioridade", selectPrioridade),
            criarCampo("Orientação / observação", observacao, "relato-admin-campo-largo")
        );

        const opcoesAcesso = node("div", "relato-admin-opcoes-acesso");
        const checkAnterior = node("input");
        checkAnterior.type = "checkbox";
        checkAnterior.dataset.revogarAnterior = "";
        opcoesAcesso.appendChild(criarCampo("Revogar o acesso do editor responsável anterior à obra", checkAnterior));
        const checkAutor = node("input");
        checkAutor.type = "checkbox";
        checkAutor.dataset.revogarAutor = "";
        opcoesAcesso.appendChild(criarCampo("Revogar também o acesso do autor original", checkAutor));
        controles.appendChild(opcoesAcesso);

        const acoes = node("div", "relato-admin-acoes");
        acoes.appendChild(criarBotao("Encaminhar / alterar responsável", "atribuir_editor", "botao-principal"));
        if (!["resolvido", "improcedente"].includes(relato.status)) {
            acoes.appendChild(criarBotao("Reforçar aviso ao editor", "reforcar_aviso"));
        }
        if (relato.status === "aguardando_verificacao") {
            acoes.append(
                criarBotao("Aprovar correção", "aprovar_correcao", "botao-principal"),
                criarBotao("Devolver para ajustes", "devolver_correcao", "botao-secundario")
            );
        }
        if (["resolvido", "improcedente"].includes(relato.status)) {
            acoes.appendChild(criarBotao("Reabrir acompanhamento", "reabrir_relato", "botao-secundario"));
        } else {
            acoes.appendChild(criarBotao("Marcar como improcedente", "marcar_improcedente", "botao-secundario"));
        }
        controles.appendChild(acoes);
        cartao.appendChild(controles);

        cartao.appendChild(node("p", "relato-admin-nota-supervisao", "A administração acompanha o caso. A correção é feita pelo editor responsável; o administrador pode orientar, encaminhar e verificar o resultado."));
        return cartao;
    }

    function renderizar() {
        lista.replaceChildren();
        const filtrados = relatos.filter((relato) => {
            if (filtroAtual === "todos") return true;
            if (filtroAtual === "abertos") return !["resolvido", "improcedente"].includes(relato.status);
            return relato.status === filtroAtual;
        });
        if (!filtrados.length) {
            const vazio = node("div", "estado-vazio relato-admin-vazio");
            vazio.append(node("span", "estado-vazio-simbolo", "✓"), node("h3", "", "Nenhum relato nesta categoria"), node("p", "", "Os relatos que correspondem ao filtro selecionado aparecerão aqui."));
            lista.appendChild(vazio);
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
        informar("Carregando relatos e responsáveis...");
        try {
            const resposta = await fetch(api("listar-admin.php"), { credentials: "same-origin", cache: "no-store", headers: { Accept: "application/json" } });
            const dados = await resposta.json().catch(() => null);
            if (!resposta.ok || dados?.sucesso !== true || !Array.isArray(dados.relatos)) {
                throw new Error(dados?.mensagem || `Falha ao consultar relatos (HTTP ${resposta.status}).`);
            }
            relatos = dados.relatos;
            editores = Array.isArray(dados.editores) ? dados.editores : [];
            montarFiltros();
            renderizar();
            informar(`${relatos.length} relato(s) carregado(s).`, "sucesso");
        } catch (erro) {
            console.error("[RELATOS/ADMIN]", erro);
            lista.replaceChildren(node("div", "estado-vazio", erro.message || "Não foi possível carregar os relatos."));
            informar(erro.message || "Não foi possível carregar os relatos.", "erro");
        } finally {
            carregando = false;
            lista.setAttribute("aria-busy", "false");
            if (botaoAtualizar) botaoAtualizar.disabled = false;
        }
    }

    async function executarAcao(botao) {
        const cartao = botao.closest("[data-relato-id]");
        if (!cartao) return;
        const relatoId = Number(cartao.dataset.relatoId);
        const acao = botao.dataset.relatoAction;
        const editorId = Number(cartao.querySelector("[data-campo-editor]")?.value || 0);
        const prioridade = cartao.querySelector("[data-campo-prioridade]")?.value || "media";
        const observacao = cartao.querySelector("[data-campo-observacao]")?.value?.trim() || "";
        const payload = { relato_id: relatoId, acao, prioridade, observacao };

        if (acao === "atribuir_editor") {
            if (!editorId) {
                informar("Selecione o editor que ficará responsável.", "erro");
                return;
            }
            payload.editor_id = editorId;
            payload.revogar_editor_anterior = Boolean(cartao.querySelector("[data-revogar-anterior]")?.checked);
            payload.revogar_autor_original = Boolean(cartao.querySelector("[data-revogar-autor]")?.checked);
        }
        if (["reforcar_aviso", "devolver_correcao", "marcar_improcedente", "reabrir_relato"].includes(acao) && observacao.length < 5) {
            informar("Registre uma orientação ou justificativa com pelo menos 5 caracteres.", "erro");
            cartao.querySelector("[data-campo-observacao]")?.focus();
            return;
        }
        botao.disabled = true;
        const textoOriginal = botao.textContent;
        botao.textContent = "Salvando...";
        informar(`Registrando ação no relato #${relatoId}...`);
        try {
            const resposta = await fetch(api("gerenciar-admin.php"), {
                method: "POST",
                credentials: "same-origin",
                cache: "no-store",
                headers: { "Content-Type": "application/json", Accept: "application/json" },
                body: JSON.stringify(payload)
            });
            const dados = await resposta.json().catch(() => null);
            if (!resposta.ok || dados?.sucesso !== true) {
                throw new Error(dados?.mensagem || `Falha na ação (HTTP ${resposta.status}).`);
            }
            informar(dados.mensagem || "Ação registrada.", "sucesso");
            await carregarRelatos();
            await carregarAvisos();
        } catch (erro) {
            console.error("[RELATOS/ADMIN] Ação", erro);
            informar(erro.message || "Não foi possível concluir a ação.", "erro");
        } finally {
            if (botao.isConnected) {
                botao.disabled = false;
                botao.textContent = textoOriginal;
            }
        }
    }

    async function carregarAvisos() {
        if (avisosCarregando) return;
        avisosCarregando = true;
        try {
            const resposta = await fetch(api("notificacoes-admin.php"), { credentials: "same-origin", cache: "no-store", headers: { Accept: "application/json" } });
            const dados = await resposta.json().catch(() => null);
            if (!resposta.ok || dados?.sucesso !== true || !Array.isArray(dados.avisos)) return;
            listaAvisos.replaceChildren();
            const total = dados.avisos.length;
            avisos.hidden = total === 0;
            if (seloNovos) {
                seloNovos.textContent = String(total);
                seloNovos.hidden = total === 0;
            }
            if (!total) return;
            dados.avisos.forEach((aviso) => {
                const card = node("article", "relato-admin-aviso-card");
                const texto = node("div", "");
                texto.append(node("strong", "", aviso.titulo), node("p", "", aviso.mensagem), node("time", "", dataPt(aviso.criada_em)));
                const marcar = node("button", "botao botao-secundario", "Marcar como lido");
                marcar.type = "button";
                marcar.dataset.marcarAviso = String(aviso.id);
                card.append(texto, marcar);
                listaAvisos.appendChild(card);
            });
        } catch (erro) {
            console.warn("[RELATOS/ADMIN] Não foi possível atualizar os avisos.", erro);
        } finally {
            avisosCarregando = false;
        }
    }

    lista.addEventListener("click", (evento) => {
        const botao = evento.target.closest("button[data-relato-action]");
        if (botao) executarAcao(botao);
    });
    listaAvisos.addEventListener("click", async (evento) => {
        const botao = evento.target.closest("[data-marcar-aviso]");
        if (!botao) return;
        botao.disabled = true;
        try {
            const resposta = await fetch(api("marcar-notificacao-admin-lida.php"), {
                method: "POST", credentials: "same-origin", cache: "no-store",
                headers: { "Content-Type": "application/json", Accept: "application/json" },
                body: JSON.stringify({ id: Number(botao.dataset.marcarAviso) })
            });
            const dados = await resposta.json().catch(() => null);
            if (!resposta.ok || dados?.sucesso !== true) throw new Error(dados?.mensagem || "Não foi possível marcar o aviso.");
            await carregarAvisos();
        } catch (erro) {
            botao.disabled = false;
            informar(erro.message || "Falha ao marcar o aviso.", "erro");
        }
    });

    botaoAtualizar?.addEventListener("click", () => { carregarRelatos(); carregarAvisos(); });
    carregarRelatos();
    carregarAvisos();
    window.setInterval(carregarAvisos, 60000);
    window.setInterval(carregarRelatos, 90000);
})();
