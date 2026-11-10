/* Notificações internas dirigidas ao editor responsável pelas obras. */
(() => {
    "use strict";

    const API = {
        listar: "../api/notificacoes/minhas.php",
        marcarLida: "../api/notificacoes/marcar-lida.php"
    };

    const principal = document.getElementById("conteudo-principal");
    const areaConteudo = principal?.querySelector(".dashboard-main");
    const barraSuperior = areaConteudo?.querySelector(".dashboard-topbar");

    /*
     * A versão atual do dashboard não possui .painel-boas-vindas.
     * A área de avisos deve ficar fora das seções alternadas, logo
     * depois da barra superior, para permanecer visível em qualquer aba.
     */
    if (!principal || !areaConteudo || !barraSuperior) {
        console.error(
            "Notificações do editor: não foi possível localizar " +
            "#conteudo-principal .dashboard-main .dashboard-topbar."
        );
        return;
    }

    const painel = document.createElement("section");
    painel.className = "notificacoes-editor";
    painel.setAttribute("aria-labelledby", "titulo-notificacoes-editor");
    painel.setAttribute("aria-live", "polite");
    painel.hidden = true;

    const cabecalho = document.createElement("div");
    cabecalho.className = "notificacoes-editor-cabecalho";

    const titulo = document.createElement("h2");
    titulo.id = "titulo-notificacoes-editor";
    titulo.textContent = "Avisos sobre suas obras";

    const quantidade = document.createElement("span");
    quantidade.className = "notificacoes-editor-contagem";

    cabecalho.append(titulo, quantidade);

    const lista = document.createElement("div");
    lista.className = "notificacoes-editor-lista";

    painel.append(cabecalho, lista);
    barraSuperior.insertAdjacentElement("afterend", painel);

    let consultando = false;
    let idsExibidos = new Set();

    function criar(tag, classe, texto) {
        const elemento = document.createElement(tag);
        if (classe) elemento.className = classe;
        if (texto !== undefined) elemento.textContent = texto;
        return elemento;
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

    function renderizar(notificacoes) {
        const idsNovos = new Set(notificacoes.map((item) => String(item.id)));
        const atuaisPermanecem = [...idsExibidos].some((id) => idsNovos.has(id));

        if (!atuaisPermanecem) {
            lista.replaceChildren();
        }

        notificacoes.forEach((notificacao) => {
            const id = String(notificacao.id);
            if (idsExibidos.has(id) && lista.querySelector(`[data-notificacao-id="${CSS.escape(id)}"]`)) {
                return;
            }

            const cartao = criar("article", "notificacao-editor-card");
            cartao.dataset.notificacaoId = id;

            const conteudo = criar("div", "notificacao-editor-conteudo");
            const tituloNotificacao = criar(
                "h3",
                "",
                notificacao.titulo || "Atualização em uma de suas obras"
            );
            const mensagem = criar("p", "", notificacao.mensagem || "O administrador realizou uma alteração em uma obra de sua autoria.");
            const data = criar("time", "notificacao-editor-data", formatarData(notificacao.criada_em));

            conteudo.append(tituloNotificacao, mensagem, data);

            const marcar = criar("button", "botao botao-secundario", "Marcar como lida");
            marcar.type = "button";
            marcar.dataset.marcarNotificacaoLida = id;

            cartao.append(conteudo, marcar);
            lista.appendChild(cartao);
            idsExibidos.add(id);
        });

        // Remove avisos que já foram marcados como lidos em outra aba.
        [...lista.querySelectorAll("[data-notificacao-id]")].forEach((cartao) => {
            if (!idsNovos.has(cartao.dataset.notificacaoId)) {
                cartao.remove();
                idsExibidos.delete(cartao.dataset.notificacaoId);
            }
        });

        const total = lista.querySelectorAll("[data-notificacao-id]").length;
        painel.hidden = total === 0;
        quantidade.textContent = total === 1 ? "1 aviso não lido" : `${total} avisos não lidos`;
    }

    async function carregarNotificacoes() {
        if (consultando || document.visibilityState === "hidden") return;
        consultando = true;

        try {
            const resposta = await fetch(API.listar, {
                method: "GET",
                credentials: "same-origin",
                cache: "no-store",
                headers: { Accept: "application/json" }
            });
            const dados = await resposta.json().catch(() => null);

            if (resposta.status === 401 || resposta.status === 403) {
                painel.hidden = true;
                return;
            }

            if (!resposta.ok || !dados?.sucesso || !Array.isArray(dados.notificacoes)) {
                throw new Error(dados?.mensagem || "Não foi possível consultar os avisos.");
            }

            renderizar(dados.notificacoes);
        } catch (erro) {
            console.error("Erro ao carregar notificações do editor:", erro);
        } finally {
            consultando = false;
        }
    }

    lista.addEventListener("click", async (evento) => {
        const alvo = evento.target;
        if (!(alvo instanceof Element)) return;

        const botao = alvo.closest("[data-marcar-notificacao-lida]");
        if (!botao || botao.disabled) return;

        const id = botao.dataset.marcarNotificacaoLida;
        botao.disabled = true;

        try {
            const resposta = await fetch(API.marcarLida, {
                method: "POST",
                credentials: "same-origin",
                cache: "no-store",
                headers: {
                    Accept: "application/json",
                    "Content-Type": "application/json"
                },
                body: JSON.stringify({ id: Number(id) })
            });
            const dados = await resposta.json().catch(() => null);

            if (!resposta.ok || !dados?.sucesso) {
                throw new Error(dados?.mensagem || "Não foi possível marcar o aviso como lido.");
            }

            const cartao = botao.closest("[data-notificacao-id]");
            cartao?.remove();
            idsExibidos.delete(String(id));

            const total = lista.querySelectorAll("[data-notificacao-id]").length;
            painel.hidden = total === 0;
            quantidade.textContent = total === 1 ? "1 aviso não lido" : `${total} avisos não lidos`;
        } catch (erro) {
            console.error("Erro ao marcar notificação como lida:", erro);
            botao.disabled = false;
            botao.textContent = erro.message;
        }
    });

    document.addEventListener("visibilitychange", carregarNotificacoes);
    window.addEventListener("focus", carregarNotificacoes);
    window.setInterval(carregarNotificacoes, 60000);

    carregarNotificacoes();
})();
