(() => {
    "use strict";

    const scriptUrl = new URL(document.currentScript.src);
    const apiUrl = (arquivo) => new URL(`../../api/biblioteca/${arquivo}.php`, scriptUrl).href;
    const acessoUrl = new URL("../../views/acesso.html", scriptUrl).href;
    const galeriaUrl = new URL("../../views/galeria.html", scriptUrl).href;
    const lista = document.getElementById("listaBiblioteca");
    const feedback = document.getElementById("bibliotecaFeedback");
    const abas = Array.from(document.querySelectorAll("[data-colecao]"));
    let colecaoAtual = "favoritos";
    let requestId = 0;

    const textosColecao = {
        favoritos: { titulo: "Você ainda não salvou favoritos", texto: "Use o botão de favoritos durante a exploração para guardar as obras que mais chamaram sua atenção." },
        quero_explorar: { titulo: "Sua lista Quero ver está vazia", texto: "Adicione obras que deseja explorar depois. Elas ficarão guardadas nesta lista." },
        exploradas: { titulo: "Nenhuma obra concluída por enquanto", texto: "Quando você chegar ao final de todas as etapas de uma obra, ela aparecerá automaticamente aqui." }
    };

    function setFeedback(mensagem = "") {
        if (feedback) feedback.textContent = mensagem;
    }

    function criar(tag, classe, texto) {
        const elemento = document.createElement(tag);
        if (classe) elemento.className = classe;
        if (texto !== undefined) elemento.textContent = texto;
        return elemento;
    }

    function exibirLoginNecessario(mensagem) {
        lista.replaceChildren();
        const aviso = criar("div", "biblioteca-vazio");
        aviso.appendChild(criar("h2", "", "Entre na sua conta para usar sua biblioteca"));
        aviso.appendChild(criar("p", "", mensagem || "Sua biblioteca é pessoal e fica vinculada à sua conta do Arte Guiada."));
        const acoes = criar("div", "biblioteca-card-acoes");
        const link = criar("a", "", "Entrar ou criar conta");
        link.href = acessoUrl;
        acoes.appendChild(link);
        aviso.appendChild(acoes);
        lista.appendChild(aviso);
    }

    function criarCartao(obra) {
        const artigo = criar("article", "biblioteca-card");
        const moldura = criar("div", "biblioteca-card-imagem");
        const imagem = document.createElement("img");
        imagem.src = new URL(`../../api/biblioteca/imagem.php?id=${encodeURIComponent(obra.id)}`, scriptUrl).href;
        imagem.alt = obra.texto_alternativo || `Imagem da obra ${obra.titulo}`;
        imagem.loading = "lazy";
        imagem.onerror = () => { moldura.replaceChildren(criar("span", "biblioteca-imagem-indisponivel", "Imagem indisponível")); };
        moldura.appendChild(imagem);
        artigo.appendChild(moldura);

        const conteudo = criar("div", "biblioteca-card-conteudo");
        conteudo.appendChild(criar("p", "biblioteca-card-artista", obra.autor || "Autor não informado"));
        conteudo.appendChild(criar("h2", "", obra.titulo || "Obra sem título"));
        if (colecaoAtual === "exploradas" && obra.explorada_em) {
            const data = new Date(String(obra.explorada_em).replace(" ", "T"));
            const dataTexto = Number.isNaN(data.getTime()) ? obra.explorada_em : data.toLocaleDateString("pt-BR");
            conteudo.appendChild(criar("p", "biblioteca-card-data", `Explorada em ${dataTexto}`));
        }

        const acoes = criar("div", "biblioteca-card-acoes");
        if (Number(obra.id) === 3) {
            const explorar = criar("a", "", colecaoAtual === "exploradas" ? "Explorar novamente" : "Explorar obra");
            const destino = new URL(galeriaUrl.href);
            destino.searchParams.set("obra_id", String(obra.id));
            explorar.href = destino.href;
            acoes.appendChild(explorar);
        } else {
            acoes.appendChild(criar("span", "biblioteca-card-data", "A experiência guiada desta obra ainda não está disponível."));
        }

        if (colecaoAtual === "favoritos" || colecaoAtual === "quero_explorar") {
            const botao = criar("button", "secundario", colecaoAtual === "favoritos" ? "Remover dos favoritos" : "Remover de Quero ver");
            botao.type = "button";
            botao.addEventListener("click", async () => {
                botao.disabled = true;
                try {
                    const resposta = await fetch(apiUrl("atualizar"), {
                        method: "POST",
                        credentials: "same-origin",
                        cache: "no-store",
                        headers: { "Content-Type": "application/json", "Accept": "application/json" },
                        body: JSON.stringify({ obra_id: Number(obra.id), acao: colecaoAtual === "favoritos" ? "favorito" : "quero_explorar", ativo: false })
                    });
                    const dados = await resposta.json().catch(() => null);
                    if (resposta.status === 401) { exibirLoginNecessario("Entre na sua conta novamente para alterar sua biblioteca."); return; }
                    if (!resposta.ok || !dados?.sucesso) throw new Error(dados?.mensagem || "Não foi possível atualizar a coleção.");
                    await carregarColecao(colecaoAtual);
                } catch (erro) {
                    setFeedback(erro.message || "Não foi possível atualizar a coleção.");
                    botao.disabled = false;
                }
            });
            acoes.appendChild(botao);
        }

        conteudo.appendChild(acoes);
        artigo.appendChild(conteudo);
        return artigo;
    }

    async function carregarColecao(colecao) {
        colecaoAtual = colecao;
        requestId += 1;
        const idDaRequisicao = requestId;
        abas.forEach((aba) => {
            const ativa = aba.dataset.colecao === colecao;
            aba.classList.toggle("ativa", ativa);
            aba.setAttribute("aria-selected", String(ativa));
            if (ativa) lista.setAttribute("aria-labelledby", aba.id);
        });
        setFeedback("");
        lista.replaceChildren(criar("div", "biblioteca-carregando", "Carregando sua biblioteca…"));

        try {
            const resposta = await fetch(`${apiUrl("listar")}?colecao=${encodeURIComponent(colecao)}`, {
                method: "GET", credentials: "same-origin", cache: "no-store", headers: { "Accept": "application/json" }
            });
            const dados = await resposta.json().catch(() => null);
            if (idDaRequisicao !== requestId) return;
            if (resposta.status === 401) { exibirLoginNecessario("Entre na sua conta para consultar e salvar suas coleções."); return; }
            if (!resposta.ok || !dados?.sucesso || !Array.isArray(dados.obras)) throw new Error(dados?.mensagem || "Não foi possível carregar sua biblioteca.");

            if (dados.obras.length === 0) {
                const vazio = criar("div", "biblioteca-vazio");
                vazio.appendChild(criar("h2", "", textosColecao[colecao].titulo));
                vazio.appendChild(criar("p", "", textosColecao[colecao].texto));
                lista.replaceChildren(vazio);
                return;
            }
            lista.replaceChildren(...dados.obras.map(criarCartao));
        } catch (erro) {
            if (idDaRequisicao !== requestId) return;
            lista.replaceChildren(criar("div", "biblioteca-erro", erro.message || "Não foi possível carregar sua biblioteca."));
        }
    }

    abas.forEach((aba) => aba.addEventListener("click", () => carregarColecao(aba.dataset.colecao)));
    carregarColecao(colecaoAtual);

    document.addEventListener("arteguiada:estado-sessao", (evento) => {
        if (evento.detail?.autenticado) carregarColecao(colecaoAtual);
    });
})();
