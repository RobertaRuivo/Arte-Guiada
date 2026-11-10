/* Curadoria das obras publicadas exibidas no destaque principal da página inicial. */
(() => {
    "use strict";

    const API = "../api/admin/gerenciar-destaques.php";
    const listaSelecionadas = document.getElementById("listaDestaquesSelecionados");
    const listaDisponiveis = document.getElementById("listaDestaquesDisponiveis");
    const contador = document.getElementById("contadorDestaquesAdmin");
    const feedback = document.getElementById("feedbackDestaquesAdmin");
    const botaoSalvar = document.getElementById("salvarDestaquesAdmin");
    const botaoAtualizar = document.getElementById("atualizarDestaquesAdmin");

    if (!listaSelecionadas || !listaDisponiveis || !contador || !feedback || !botaoSalvar) return;

    let obras = [];
    let selecionadas = [];
    let carregando = false;
    let salvando = false;

    function informar(mensagem, tipo = "info") {
        feedback.textContent = mensagem;
        feedback.dataset.tipo = tipo;
    }

    async function requisicao(url, opcoes = {}) {
        const resposta = await fetch(url, {
            credentials: "same-origin",
            cache: "no-store",
            ...opcoes,
            headers: { Accept: "application/json", ...(opcoes.headers || {}) }
        });
        const texto = await resposta.text();
        let dados;
        try { dados = texto ? JSON.parse(texto) : {}; }
        catch { throw new Error("O servidor devolveu uma resposta inválida."); }
        if (!resposta.ok || dados.sucesso !== true) {
            throw new Error(dados.mensagem || `Falha na requisição (HTTP ${resposta.status}).`);
        }
        return dados;
    }

    function criar(tag, classe, texto) {
        const elemento = document.createElement(tag);
        if (classe) elemento.className = classe;
        if (texto !== undefined) elemento.textContent = texto;
        return elemento;
    }

    function localizarObra(id) {
        return obras.find((obra) => Number(obra.id) === Number(id));
    }

    function criarCartao(obra, selecionada, indice = -1) {
        const cartao = criar("article", "destaque-admin-card");
        const imagem = criar("img", "destaque-admin-imagem");
        imagem.src = `../api/obras/imagem-publica.php?id=${encodeURIComponent(obra.id)}`;
        imagem.alt = obra.texto_alternativo || `${obra.titulo}, de ${obra.autor}.`;
        imagem.loading = "lazy";
        imagem.addEventListener("error", () => {
            imagem.hidden = true;
            cartao.classList.add("sem-imagem");
        }, { once: true });

        const dados = criar("div", "destaque-admin-identificacao");
        dados.append(
            criar("strong", "", obra.titulo || "Obra sem título"),
            criar("span", "", obra.autor || "Autoria não informada"),
            criar("small", "", `${Number(obra.total_etapas) || 0} etapa(s) de exploração`)
        );

        const acoes = criar("div", "destaque-admin-acoes");
        const label = criar("label", "destaque-admin-checkbox");
        const checkbox = criar("input", "");
        checkbox.type = "checkbox";
        checkbox.checked = selecionada;
        checkbox.disabled = salvando;
        checkbox.setAttribute("aria-label", `${selecionada ? "Remover" : "Adicionar"} ${obra.titulo} ${selecionada ? "dos" : "aos"} destaques`);
        checkbox.addEventListener("change", () => {
            if (checkbox.checked) {
                if (selecionadas.length >= 3) {
                    checkbox.checked = false;
                    informar("A página inicial permite até três obras em destaque.", "erro");
                    return;
                }
                selecionadas.push(Number(obra.id));
            } else {
                selecionadas = selecionadas.filter((id) => id !== Number(obra.id));
            }
            renderizar();
            informar("Seleção alterada. Clique em Salvar seleção para publicar a nova ordem.");
        });
        label.append(checkbox, document.createTextNode(selecionada ? "Selecionada" : "Exibir no início"));
        acoes.appendChild(label);

        if (selecionada) {
            const moverCima = criar("button", "botao botao-secundario botao-ordem", "↑");
            moverCima.type = "button";
            moverCima.disabled = salvando || indice <= 0;
            moverCima.setAttribute("aria-label", `Mover ${obra.titulo} para cima`);
            moverCima.addEventListener("click", () => mover(indice, -1));
            const moverBaixo = criar("button", "botao botao-secundario botao-ordem", "↓");
            moverBaixo.type = "button";
            moverBaixo.disabled = salvando || indice >= selecionadas.length - 1;
            moverBaixo.setAttribute("aria-label", `Mover ${obra.titulo} para baixo`);
            moverBaixo.addEventListener("click", () => mover(indice, 1));
            acoes.append(moverCima, moverBaixo);
        }

        cartao.append(imagem, dados, acoes);
        return cartao;
    }

    function mover(indice, deslocamento) {
        const destino = indice + deslocamento;
        if (destino < 0 || destino >= selecionadas.length) return;
        const [id] = selecionadas.splice(indice, 1);
        selecionadas.splice(destino, 0, id);
        renderizar();
        informar("Ordem alterada. Salve a seleção para atualizar a página inicial.");
    }

    function renderizar() {
        listaSelecionadas.replaceChildren();
        listaDisponiveis.replaceChildren();
        contador.textContent = `${selecionadas.length} de 3 selecionadas`;
        botaoSalvar.disabled = salvando || carregando;

        if (selecionadas.length === 0) {
            listaSelecionadas.appendChild(criar("p", "destaque-admin-vazio", "Nenhuma obra selecionada. A composição de destaque ficará vazia até você escolher uma obra."));
        } else {
            selecionadas.forEach((id, indice) => {
                const obra = localizarObra(id);
                if (obra) listaSelecionadas.appendChild(criarCartao(obra, true, indice));
            });
        }

        const disponiveis = obras.filter((obra) => !selecionadas.includes(Number(obra.id)));
        if (disponiveis.length === 0) {
            listaDisponiveis.appendChild(criar("p", "destaque-admin-vazio", "Todas as obras publicadas disponíveis já foram selecionadas."));
        } else {
            disponiveis.forEach((obra) => listaDisponiveis.appendChild(criarCartao(obra, false)));
        }
    }

    async function carregar() {
        if (carregando) return;
        carregando = true;
        botaoSalvar.disabled = true;
        if (botaoAtualizar) botaoAtualizar.disabled = true;
        try {
            const dados = await requisicao(API);
            obras = Array.isArray(dados.obras) ? dados.obras : [];
            selecionadas = Array.isArray(dados.selecionadas)
                ? dados.selecionadas.map(Number).filter((id) => obras.some((obra) => Number(obra.id) === id)).slice(0, 3)
                : [];
            renderizar();
            informar(obras.length
                ? "Escolha até três obras publicadas e ajuste a ordem com as setas."
                : "Não há obras publicadas com imagem e etapas disponíveis para destaque.");
        } catch (erro) {
            listaSelecionadas.replaceChildren(criar("p", "destaque-admin-vazio", "Não foi possível carregar os destaques."));
            listaDisponiveis.replaceChildren();
            informar(erro.message, "erro");
        } finally {
            carregando = false;
            botaoSalvar.disabled = false;
            if (botaoAtualizar) botaoAtualizar.disabled = false;
            renderizar();
        }
    }

    async function salvar() {
        if (salvando || selecionadas.length > 3) return;
        salvando = true;
        botaoSalvar.disabled = true;
        if (botaoAtualizar) botaoAtualizar.disabled = true;
        renderizar();
        try {
            const dados = await requisicao(API, {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify({ ids: selecionadas })
            });
            informar(dados.mensagem, "sucesso");
            await carregar();
        } catch (erro) {
            informar(erro.message, "erro");
        } finally {
            salvando = false;
            botaoSalvar.disabled = false;
            if (botaoAtualizar) botaoAtualizar.disabled = false;
            renderizar();
        }
    }

    botaoSalvar.addEventListener("click", salvar);
    if (botaoAtualizar) botaoAtualizar.addEventListener("click", carregar);
    document.addEventListener("painel:secao-aberta", (evento) => {
        if (evento.detail?.nome === "destaques") carregar();
    });
    carregar();
})();
