/* Gerenciamento administrativo de promoção e revogação de editores. */
(() => {
    "use strict";

    const API = "../api/admin/gerenciar-editores.php";
    const formulario = document.getElementById("formBuscaUsuariosEditor");
    const campoBusca = document.getElementById("buscaUsuarioEditor");
    const resultadosBusca = document.getElementById("resultadosBuscaEditores");
    const listaEditores = document.getElementById("listaEditoresAtuais");
    const feedback = document.getElementById("feedbackGerenciarEditores");
    const botaoAtualizar = document.getElementById("atualizarListaEditores");

    if (!formulario || !campoBusca || !resultadosBusca || !listaEditores || !feedback) return;

    let termoAtual = "";
    let carregando = false;

    function mostrarFeedback(mensagem, tipo = "info") {
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

    function criarElemento(tag, classe, texto) {
        const elemento = document.createElement(tag);
        if (classe) elemento.className = classe;
        if (texto !== undefined) elemento.textContent = texto;
        return elemento;
    }

    function criarConfirmacao(usuario, acao, botaoOriginal) {
        const area = criarElemento("div", "gerenciar-editor-confirmacao");
        const nome = usuario.nome_completo || usuario.email || `usuário #${usuario.id}`;
        const revogar = acao === "revogar";
        area.appendChild(criarElemento(
            "p",
            "",
            revogar
                ? `Revogar o perfil Editor de ${nome}? A conta e as obras serão preservadas, mas os acessos delegados serão desativados.`
                : `Confirmar a promoção de ${nome} para Editor?`
        ));
        if (revogar) {
            area.appendChild(criarElemento(
                "p",
                "gerenciar-editor-aviso",
                "A pessoa precisará sair da conta e entrar novamente para carregar o perfil atualizado."
            ));
        }

        const acoes = criarElemento("div", "gerenciar-editor-acoes-confirmacao");
        const confirmar = criarElemento(
            "button",
            revogar ? "botao botao-perigo" : "botao botao-principal",
            revogar ? "Confirmar revogação" : "Confirmar promoção"
        );
        confirmar.type = "button";
        const cancelar = criarElemento("button", "botao botao-secundario", "Cancelar");
        cancelar.type = "button";
        cancelar.addEventListener("click", () => {
            area.remove();
            botaoOriginal.disabled = false;
        });
        confirmar.addEventListener("click", async () => {
            confirmar.disabled = true;
            cancelar.disabled = true;
            confirmar.textContent = revogar ? "Revogando…" : "Promovendo…";
            try {
                const dados = await requisicao(API, {
                    method: "POST",
                    headers: { "Content-Type": "application/json" },
                    body: JSON.stringify({ acao, usuario_id: usuario.id })
                });
                await carregarDados(termoAtual);
                mostrarFeedback(
                    `${dados.mensagem}${dados.relogin_necessario ? " A pessoa deverá sair e entrar novamente." : ""}`,
                    "sucesso"
                );
            } catch (erro) {
                mostrarFeedback(erro.message, "erro");
                confirmar.disabled = false;
                cancelar.disabled = false;
                confirmar.textContent = revogar ? "Confirmar revogação" : "Confirmar promoção";
            }
        });
        acoes.append(confirmar, cancelar);
        area.appendChild(acoes);
        return area;
    }

    function renderizarEditores(editores) {
        listaEditores.replaceChildren();
        if (!Array.isArray(editores) || editores.length === 0) {
            listaEditores.appendChild(criarElemento(
                "p", "gerenciar-editores-vazio", "Ainda não há contas com perfil de editor."
            ));
            return;
        }

        const fragmento = document.createDocumentFragment();
        for (const editor of editores) {
            const cartao = criarElemento("article", "gerenciar-editor-card");
            const identificacao = criarElemento("div", "gerenciar-editor-identificacao");
            identificacao.append(
                criarElemento("strong", "", editor.nome_completo || `Editor #${editor.id}`),
                criarElemento("span", "", editor.email || "E-mail não informado")
            );

            const colunaAcoes = criarElemento("div", "gerenciar-editor-lista-acoes");
            colunaAcoes.appendChild(criarElemento("span", "gerenciar-editor-perfil", "Editor"));
            const pendencias = Number(editor.relatos_abertos || 0);
            if (pendencias > 0) {
                const aviso = criarElemento(
                    "p",
                    "gerenciar-editor-pendencias",
                    `${pendencias} relato(s) ainda atribuído(s). Reencaminhe ou conclua esses relatos antes de revogar o perfil.`
                );
                colunaAcoes.appendChild(aviso);
                const bloqueado = criarElemento("button", "botao botao-secundario", "Revogação bloqueada");
                bloqueado.type = "button";
                bloqueado.disabled = true;
                bloqueado.title = "Há relatos abertos atribuídos a este editor.";
                colunaAcoes.appendChild(bloqueado);
            } else {
                const botao = criarElemento("button", "botao botao-perigo", "Revogar perfil Editor");
                botao.type = "button";
                botao.addEventListener("click", () => {
                    if (cartao.querySelector(".gerenciar-editor-confirmacao")) return;
                    botao.disabled = true;
                    cartao.appendChild(criarConfirmacao(editor, "revogar", botao));
                });
                colunaAcoes.appendChild(botao);
            }

            cartao.append(identificacao, colunaAcoes);
            fragmento.appendChild(cartao);
        }
        listaEditores.appendChild(fragmento);
    }

    function renderizarResultados(usuarios) {
        resultadosBusca.replaceChildren();
        if (!Array.isArray(usuarios) || usuarios.length === 0) {
            resultadosBusca.appendChild(criarElemento(
                "p", "gerenciar-editores-vazio", "Nenhuma conta correspondente foi encontrada. Confira o nome ou o e-mail."
            ));
            return;
        }

        const fragmento = document.createDocumentFragment();
        for (const usuario of usuarios) {
            const cartao = criarElemento("article", "gerenciar-editor-resultado");
            const identificacao = criarElemento("div", "gerenciar-editor-identificacao");
            identificacao.append(
                criarElemento("strong", "", usuario.nome_completo || `Usuário #${usuario.id}`),
                criarElemento("span", "", usuario.email || "E-mail não informado")
            );
            const rodape = criarElemento("div", "gerenciar-editor-resultado-acoes");
            const classePerfil = usuario.perfil === "Editor"
                ? "gerenciar-editor-perfil"
                : usuario.perfil === "Visitante"
                    ? "gerenciar-editor-perfil visitante"
                    : "gerenciar-editor-perfil outro";
            rodape.appendChild(criarElemento("span", classePerfil, usuario.perfil || "Sem perfil"));

            if (usuario.pode_promover) {
                const botao = criarElemento("button", "botao botao-principal", "Promover a editor");
                botao.type = "button";
                botao.addEventListener("click", () => {
                    if (cartao.querySelector(".gerenciar-editor-confirmacao")) return;
                    botao.disabled = true;
                    cartao.appendChild(criarConfirmacao(usuario, "promover", botao));
                });
                rodape.appendChild(botao);
            } else if (usuario.perfil === "Editor") {
                rodape.appendChild(criarElemento("span", "gerenciar-editor-ja-ativo", "Esta conta já é editora"));
            }
            cartao.append(identificacao, rodape);
            fragmento.appendChild(cartao);
        }
        resultadosBusca.appendChild(fragmento);
    }

    async function carregarDados(termo = "") {
        if (carregando) return;
        carregando = true;
        if (botaoAtualizar) botaoAtualizar.disabled = true;
        resultadosBusca.setAttribute("aria-busy", "true");
        listaEditores.setAttribute("aria-busy", "true");
        try {
            const url = new URL(API, window.location.href);
            if (termo) url.searchParams.set("q", termo);
            const dados = await requisicao(url.toString());
            renderizarResultados(dados.resultados || []);
            renderizarEditores(dados.editores || []);
            termoAtual = termo;
            mostrarFeedback(termo
                ? `${dados.total_resultados} conta(s) encontrada(s).`
                : "Pesquise por nome ou e-mail para encontrar uma conta existente.", "info");
        } catch (erro) {
            resultadosBusca.replaceChildren(criarElemento("p", "gerenciar-editores-vazio", "Não foi possível consultar as contas."));
            listaEditores.replaceChildren(criarElemento("p", "gerenciar-editores-vazio", "Não foi possível carregar a lista de editores."));
            mostrarFeedback(erro.message, "erro");
        } finally {
            carregando = false;
            resultadosBusca.setAttribute("aria-busy", "false");
            listaEditores.setAttribute("aria-busy", "false");
            if (botaoAtualizar) botaoAtualizar.disabled = false;
        }
    }

    formulario.addEventListener("submit", (evento) => {
        evento.preventDefault();
        const termo = campoBusca.value.trim();
        if (termo.length < 2) {
            mostrarFeedback("Digite ao menos dois caracteres para pesquisar.", "erro");
            campoBusca.focus();
            return;
        }
        carregarDados(termo);
    });
    if (botaoAtualizar) botaoAtualizar.addEventListener("click", () => carregarDados(termoAtual));
    document.addEventListener("painel:secao-aberta", (evento) => {
        if (evento.detail?.nome === "editores") carregarDados(termoAtual);
    });
    carregarDados();
})();
