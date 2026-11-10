/* Lixeira compartilhada dos painéis de editor e administrador. */
(() => {
    "use strict";

    const API = {
        listar: "../api/obras/excluidas.php",
        restaurar: "../api/obras/restaurar.php"
    };

    const secao = document.querySelector('[data-painel-secao="lixeira-obras"]');
    if (secao && !secao.id) secao.id = "secaoLixeiraObras";
    const lista = document.getElementById("listaLixeiraObras");
    const feedback = document.getElementById("feedbackLixeiraObras");
    const atualizar = document.querySelector("[data-atualizar-lixeira-obras]");

    if (!secao || !lista || !feedback || !atualizar) {
        return;
    }

    let obrasExcluidas = [];
    let carregando = false;
    let restaurando = false;

    const perfisPermitidos = String(document.body.dataset.perfisPermitidos || "")
        .split(",")
        .map((valor) => valor.trim().toLowerCase());

    let perfil = String(
        document.body.dataset.usuarioPerfil ||
        (perfisPermitidos.includes("admin") ? "admin" : "editor")
    ).trim().toLowerCase();

    document.addEventListener("arteguiada:acesso-verificado", (evento) => {
        perfil = String(evento.detail?.perfil || perfil).trim().toLowerCase();
    });

    function criar(tag, classe = "", texto = null) {
        const elemento = document.createElement(tag);
        if (classe) elemento.className = classe;
        if (texto !== null) elemento.textContent = texto;
        return elemento;
    }

    function exibirFeedback(mensagem = "", tipo = "info") {
        feedback.textContent = mensagem;
        feedback.dataset.tipo = tipo;
        feedback.classList.toggle("sucesso", tipo === "sucesso");
        feedback.classList.toggle("erro", tipo === "erro");
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
        const cartao = criar("article", "lixeira-obra-card");
        const topo = criar("div", "lixeira-obra-topo");
        const titulo = criar("h3", "", obra.titulo || `Obra sem título #${obra.id}`);
        const status = criar(
            "span",
            `lixeira-obra-status ${obra.status === "publicada" ? "publicada" : "rascunho"}`,
            obra.status === "publicada" ? "Publicada antes da exclusão" : "Rascunho antes da exclusão"
        );
        topo.append(titulo, status);

        const autor = criar(
            "p",
            "lixeira-obra-meta",
            `Autor: ${obra.autor || "Não informado"}`
        );

        const detalhes = criar(
            "p",
            "lixeira-obra-meta",
            `Excluída em ${formatarData(obra.excluida_em)} · ${Number(obra.total_etapas) || 0} ${(Number(obra.total_etapas) || 0) === 1 ? "etapa preservada" : "etapas preservadas"}`
        );

        cartao.append(topo, autor);

        if (perfil === "admin" && obra.editor_nome) {
            cartao.appendChild(
                criar("p", "lixeira-obra-meta", `Editor responsável: ${obra.editor_nome}`)
            );
        }

        cartao.appendChild(detalhes);

        const aviso = criar(
            "p",
            "lixeira-obra-aviso",
            "Restaurar devolve a obra à listagem normal, preservando seu status, imagem e etapas."
        );

        const acoes = criar("div", "lixeira-obra-acoes");
        const botaoRestaurar = criar("button", "botao botao-principal", "Restaurar obra");
        botaoRestaurar.type = "button";
        botaoRestaurar.dataset.restaurarObra = String(obra.id);
        acoes.appendChild(botaoRestaurar);

        cartao.append(aviso, acoes);
        return cartao;
    }

    function renderizar() {
        lista.replaceChildren();

        if (obrasExcluidas.length === 0) {
            lista.appendChild(
                criarEstadoVazio(
                    "A lixeira está vazia",
                    perfil === "admin"
                        ? "As obras excluídas pelos editores e administradores aparecerão aqui."
                        : "As obras que você excluir aparecerão aqui e poderão ser restauradas."
                )
            );
            return;
        }

        const fragmento = document.createDocumentFragment();
        obrasExcluidas.forEach((obra) => {
            fragmento.appendChild(criarCartao(obra));
        });
        lista.appendChild(fragmento);
    }

    async function carregar({ mensagem = "", tipo = "info" } = {}) {
        if (carregando || restaurando) return;

        carregando = true;
        atualizar.disabled = true;
        lista.setAttribute("aria-busy", "true");
        if (mensagem) exibirFeedback(mensagem, tipo);
        else exibirFeedback("");

        if (obrasExcluidas.length === 0) {
            lista.replaceChildren(
                criarEstadoVazio("Carregando lixeira", "Consultando as obras excluídas.")
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
                    dados?.mensagem || `Não foi possível carregar a lixeira (HTTP ${resposta.status}).`
                );
            }

            obrasExcluidas = dados.obras;
            renderizar();
            exibirFeedback(
                `${obrasExcluidas.length} ${obrasExcluidas.length === 1 ? "obra encontrada" : "obras encontradas"} na lixeira.`,
                "sucesso"
            );
        } catch (erro) {
            console.error("Erro ao carregar a lixeira:", erro);
            lista.replaceChildren(
                criarEstadoVazio(
                    "Não foi possível carregar a lixeira",
                    "Verifique a conexão e tente atualizar novamente."
                )
            );
            exibirFeedback(erro.message, "erro");
        } finally {
            carregando = false;
            atualizar.disabled = false;
            lista.setAttribute("aria-busy", "false");
        }
    }

    async function restaurar(id, botao) {
        if (restaurando || carregando) return;

        const obra = obrasExcluidas.find((item) => String(item.id) === String(id));
        if (!obra) {
            exibirFeedback("A obra não está mais disponível na lixeira. Atualize a lista.", "erro");
            return;
        }

        restaurando = true;
        botao.disabled = true;
        atualizar.disabled = true;
        exibirFeedback(`Restaurando "${obra.titulo || `obra #${id}`}"...`);

        try {
            const resposta = await fetch(API.restaurar, {
                method: "POST",
                credentials: "same-origin",
                cache: "no-store",
                headers: {
                    Accept: "application/json",
                    "Content-Type": "application/json"
                },
                body: JSON.stringify({ obra_id: Number(id) })
            });

            const dados = await resposta.json().catch(() => null);
            if (!resposta.ok || !dados?.sucesso) {
                throw new Error(
                    dados?.mensagem || `Não foi possível restaurar a obra (HTTP ${resposta.status}).`
                );
            }

            obrasExcluidas = obrasExcluidas.filter(
                (item) => String(item.id) !== String(id)
            );
            renderizar();
            exibirFeedback(
                `${dados.mensagem || "Obra restaurada com sucesso."} O status, a imagem e as etapas foram preservados.`,
                "sucesso"
            );
            feedback.focus();
        } catch (erro) {
            console.error("Erro ao restaurar obra:", erro);
            exibirFeedback(erro.message, "erro");
            if (botao.isConnected) botao.disabled = false;
        } finally {
            restaurando = false;
            atualizar.disabled = false;
        }
    }

    atualizar.addEventListener("click", () => carregar());

    lista.addEventListener("click", (evento) => {
        const alvo = evento.target;
        if (!(alvo instanceof Element)) return;

        const botao = alvo.closest("[data-restaurar-obra]");
        if (!botao) return;

        evento.preventDefault();
        restaurar(botao.dataset.restaurarObra, botao);
    });

    document.addEventListener("painel:secao-aberta", (evento) => {
        if (evento.detail?.nome === "lixeira-obras") {
            carregar();
        }
    });

    // A seção não abre automaticamente, mas mantém estado inicial acessível.
    lista.setAttribute("aria-busy", "false");
    feedback.tabIndex = -1;
})();
