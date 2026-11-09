/* Fila de correções atribuídas ao editor. */
(() => {
    "use strict";

    const script = document.currentScript;
    if (!script?.src) return;
    const raiz = new URL("../../", new URL(script.src));
    const api = (arquivo) => new URL(`api/relatos/${arquivo}`, raiz).href;
    const lista = document.getElementById("listaRelatosEditor");
    const feedback = document.getElementById("feedbackRelatosEditor");
    const atualizar = document.querySelector("[data-atualizar-relatos-editor]");
    if (!lista) return;

    const status = {
        pendente: "Aguardando sua análise",
        em_analise: "Em análise",
        atribuido: "Atribuído a você",
        em_correcao: "Correção em andamento",
        aguardando_verificacao: "Aguardando verificação do administrador",
        reaberto: "Devolvido para novo ajuste"
    };
    const prioridades = { baixa: "Baixa", media: "Média", alta: "Alta", critica: "Crítica" };
    const tipos = {
        imagem: "Imagem", informacoes: "Informações da obra", texto: "Texto explicativo",
        etapa_zoom: "Etapa ou zoom", navegacao: "Navegação", acessibilidade: "Acessibilidade", outro: "Outro"
    };
    let carregando = false;

    function node(tag, classe = "", texto = null) {
        const item = document.createElement(tag);
        if (classe) item.className = classe;
        if (texto !== null && texto !== undefined) item.textContent = String(texto);
        return item;
    }
    function informar(texto, tipo = "") {
        if (!feedback) return;
        feedback.textContent = texto;
        feedback.classList.remove("erro", "sucesso");
        if (tipo) feedback.classList.add(tipo);
    }
    function dataPt(valor) {
        if (!valor) return "Data não informada";
        const data = new Date(String(valor).replace(" ", "T"));
        if (Number.isNaN(data.getTime())) return String(valor);
        return new Intl.DateTimeFormat("pt-BR", { dateStyle: "short", timeStyle: "short" }).format(data);
    }

    function criarCartao(relato) {
        const card = node("article", "relato-editor-card");
        card.dataset.relatoId = String(relato.id);
        const cabecalho = node("div", "relato-editor-cabecalho");
        cabecalho.append(node("h3", "", `Relato #${relato.id} · ${relato.obra_titulo || `Obra #${relato.obra_id}`}`));
        cabecalho.append(node("span", `relato-editor-estado estado-${relato.status}`, status[relato.status] || relato.status));
        cabecalho.append(node("span", `relato-editor-prioridade prioridade-${relato.prioridade}`, `Prioridade ${prioridades[relato.prioridade] || "Média"}`));
        card.append(cabecalho);

        if (relato.obra_autor) card.appendChild(node("p", "relato-editor-meta", `Artista: ${relato.obra_autor}`));
        card.appendChild(node("p", "relato-editor-meta", `Relatado em ${dataPt(relato.enviado_em)}`));
        const categorias = node("div", "relato-editor-categorias");
        (relato.tipos || []).forEach((tipo) => categorias.appendChild(node("span", "", tipos[tipo] || tipo)));
        card.append(categorias, node("p", "relato-editor-descricao", relato.descricao || "Sem descrição adicional."));

        if (relato.orientacao_admin) {
            const orientacao = node("div", "relato-editor-orientacao");
            orientacao.append(node("strong", "", "Orientação do administrador"), node("p", "", relato.orientacao_admin));
            card.appendChild(orientacao);
        }
        if (relato.obra_excluida) {
            card.appendChild(node("p", "relato-editor-alerta", "A obra está na Lixeira. Peça ao administrador que restaure o registro antes de editar."));
            return card;
        }
        if (relato.status === "aguardando_verificacao") {
            card.appendChild(node("p", "relato-editor-aguardando", "Sua resposta foi enviada. O administrador foi avisado e fará a verificação."));
            if (relato.resposta_editor) card.appendChild(node("p", "relato-editor-descricao", relato.resposta_editor));
            return card;
        }

        const acoes = node("div", "relato-editor-acoes");
        const iniciar = node("button", "botao botao-secundario", relato.status === "em_correcao" ? "Correção iniciada" : "Iniciar correção");
        iniciar.type = "button";
        iniciar.dataset.editorRelatoAction = "iniciar";
        iniciar.disabled = relato.status === "em_correcao";
        const editar = node("button", "botao botao-principal", "Abrir obra para editar");
        editar.type = "button";
        editar.dataset.editarObra = String(relato.obra_id);
        acoes.append(iniciar, editar);
        card.appendChild(acoes);

        const formulario = node("form", "relato-editor-resposta-form");
        formulario.dataset.formRespostaRelato = "";
        const label = node("label", "", "O que foi corrigido?");
        const textarea = node("textarea");
        textarea.name = "observacao";
        textarea.rows = 3;
        textarea.maxLength = 1000;
        textarea.minLength = 5;
        textarea.required = true;
        textarea.placeholder = "Descreva a alteração feita na obra e como o problema foi resolvido.";
        label.appendChild(textarea);
        const enviar = node("button", "botao botao-principal", "Enviar para verificação do administrador");
        enviar.type = "submit";
        formulario.append(label, enviar);
        card.appendChild(formulario);
        return card;
    }

    async function carregar() {
        if (carregando) return;
        carregando = true;
        if (atualizar) atualizar.disabled = true;
        lista.setAttribute("aria-busy", "true");
        try {
            const resposta = await fetch(api("minhas-atribuicoes.php"), {
                credentials: "same-origin", cache: "no-store", headers: { Accept: "application/json" }
            });
            const dados = await resposta.json().catch(() => null);
            if (!resposta.ok || dados?.sucesso !== true || !Array.isArray(dados.relatos)) {
                throw new Error(dados?.mensagem || `Não foi possível carregar suas pendências (HTTP ${resposta.status}).`);
            }
            lista.replaceChildren();
            if (!dados.relatos.length) {
                const vazio = node("div", "estado-vazio");
                vazio.append(node("span", "estado-vazio-simbolo", "✓"), node("h3", "", "Nenhum problema atribuído a você"), node("p", "", "Quando uma obra sua receber um relato ou o administrador encaminhar uma pendência, ela aparecerá aqui."));
                lista.appendChild(vazio);
            } else {
                dados.relatos.forEach((relato) => lista.appendChild(criarCartao(relato)));
            }
            informar(`${dados.total} pendência(s) atribuída(s) a você.`, "sucesso");
        } catch (erro) {
            console.error("[RELATOS/EDITOR]", erro);
            lista.replaceChildren(node("div", "estado-vazio", erro.message || "Não foi possível carregar as pendências."));
            informar(erro.message || "Falha ao consultar pendências.", "erro");
        } finally {
            carregando = false;
            lista.setAttribute("aria-busy", "false");
            if (atualizar) atualizar.disabled = false;
        }
    }

    async function enviarAcao(relatoId, acao, observacao = "", botao = null) {
        if (botao) botao.disabled = true;
        try {
            const resposta = await fetch(api("responder-editor.php"), {
                method: "POST", credentials: "same-origin", cache: "no-store",
                headers: { "Content-Type": "application/json", Accept: "application/json" },
                body: JSON.stringify({ relato_id: relatoId, acao, observacao })
            });
            const dados = await resposta.json().catch(() => null);
            if (!resposta.ok || dados?.sucesso !== true) throw new Error(dados?.mensagem || `Falha ao atualizar relato (HTTP ${resposta.status}).`);
            informar(dados.mensagem || "Pendência atualizada.", "sucesso");
            await carregar();
        } catch (erro) {
            informar(erro.message || "Não foi possível atualizar a pendência.", "erro");
        } finally {
            if (botao?.isConnected) botao.disabled = false;
        }
    }

    lista.addEventListener("click", (evento) => {
        const botao = evento.target.closest("button[data-editor-relato-action]");
        if (!botao) return;
        const card = botao.closest("[data-relato-id]");
        if (!card) return;
        enviarAcao(Number(card.dataset.relatoId), botao.dataset.editorRelatoAction, "", botao);
    });

    lista.addEventListener("submit", (evento) => {
        const formulario = evento.target.closest("form[data-form-resposta-relato]");
        if (!formulario) return;
        evento.preventDefault();
        const card = formulario.closest("[data-relato-id]");
        const campo = formulario.elements.observacao;
        const observacao = campo?.value?.trim() || "";
        if (observacao.length < 5) {
            informar("Descreva a correção com pelo menos 5 caracteres.", "erro");
            campo?.focus();
            return;
        }
        enviarAcao(Number(card.dataset.relatoId), "enviar_verificacao", observacao, formulario.querySelector("button[type=submit]"));
    });

    atualizar?.addEventListener("click", carregar);
    document.addEventListener("painel:secao-aberta", (evento) => {
        if (evento.detail?.nome === "relatos-editor") carregar();
    });
    carregar();
    window.setInterval(carregar, 90000);
})();
