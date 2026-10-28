/* =========================================================
   ARTE GUIADA
   SISTEMA DE ACESSO
   LOGIN + CADASTRO
   ========================================================= */

const loginWrapper = document.getElementById("loginWrapper");
const cadastroWrapper = document.getElementById("cadastroWrapper");

const painelLogin = document.getElementById("painelLogin");
const painelCadastro = document.getElementById("painelCadastro");

const mostrarCadastro = document.getElementById("mostrarCadastro");
const mostrarLogin = document.getElementById("mostrarLogin");

const loginForm = document.getElementById("loginForm");
const cadastroForm = document.getElementById("cadastroForm");

const nomeInput = document.getElementById("nome");
const sobrenomeInput = document.getElementById("sobrenome");
const emailInput = document.getElementById("email");
const mensagemInput = document.getElementById("mensagem");

const passwordInput = document.getElementById("senha");
const passwordCheckInput = document.getElementById("confirmaSenha");

const strengthBar = document.getElementById("strength-bar");
const strengthText = document.getElementById("strength-text");
const formFeedback = document.getElementById("form-feedback");

const loginEmailInput = document.getElementById("loginEmail");
const loginSenhaInput = document.getElementById("loginSenha");
const loginFeedback = document.getElementById("login-feedback");

const loginConteudo = document.getElementById("loginConteudo");
const recuperacaoConteudo = document.getElementById("recuperacaoConteudo");

const abrirRecuperacaoButton = document.getElementById("abrirRecuperacao");
const voltarLoginRecuperacaoButton = document.getElementById("voltarLogin");

const recuperacaoSolicitarForm = document.getElementById("recuperacaoSolicitarForm");
const recuperacaoEmailInput = document.getElementById("recuperacaoEmail");
const recuperacaoSolicitarFeedback = document.getElementById("recuperacaoSolicitarFeedback");

const tokenLocalContainer = document.getElementById("tokenLocalContainer");
const tokenLocalValor = document.getElementById("tokenLocalValor");

const redefinirSenhaForm = document.getElementById("redefinirSenhaForm");
const recuperacaoTokenInput = document.getElementById("recuperacaoToken");
const recuperacaoNovaSenhaInput = document.getElementById("recuperacaoNovaSenha");
const recuperacaoConfirmarSenhaInput = document.getElementById("recuperacaoConfirmarSenha");

const recuperacaoRedefinirFeedback = document.getElementById("recuperacaoRedefinirFeedback");


const wizardProgress = document.getElementById("wizard-progress");
const wizardProgressBar = document.getElementById("wizard-progress-bar");
const wizardStepLabel = document.getElementById("wizard-step-label");
const wizardSteps = Array.from(document.querySelectorAll(".wizard-step"));
const nextButtons = Array.from(document.querySelectorAll(".btn-next"));
const prevButtons = Array.from(document.querySelectorAll(".btn-prev"));

const TOTAL_STEPS = 3;
let currentStep = 1;


/* =========================================================
   UTILITÁRIOS
   ========================================================= */

function atualizarEstadoElemento(elemento, ativo) {
    if (!elemento) {
        return;
    }

    elemento.classList.toggle("ativo", ativo);
    elemento.setAttribute("aria-hidden", String(!ativo));
    elemento.inert = !ativo;
}


function normalizarEmail(email) {
    return email
        .trim()
        .toLowerCase();
}


function validarEmail(email) {
    const regex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

    return regex.test(email);
}


function validarNome(nome) {
    const regexApenasLetrasESpacos =
        /^[a-zA-ZÀ-ÿ\s]+$/;

    return (
        nome.length >= 2 &&
        regexApenasLetrasESpacos.test(nome)
    );
}


function limparErrosCadastro() {
    document
        .querySelectorAll("#cadastroForm .error-msg")
        .forEach((elemento) => {
            elemento.textContent = "";
        });

    document
        .querySelectorAll(
            "#cadastroForm input, #cadastroForm textarea"
        )
        .forEach((elemento) => {
            elemento.removeAttribute("aria-invalid");
        });
}


function limparErrosLogin() {
    document
        .querySelectorAll("#loginForm .error-msg")
        .forEach((elemento) => {
            elemento.textContent = "";
        });

    loginEmailInput.removeAttribute("aria-invalid");
    loginSenhaInput.removeAttribute("aria-invalid");
}


function focarPrimeiroCampo(stepNumber) {
    const step = document.getElementById(
        `step-${stepNumber}`
    );

    if (!step) {
        return;
    }

    const alvo = step.querySelector(
        "input:not([type='radio']), textarea"
    );

    if (alvo) {
        alvo.focus({
            preventScroll: true
        });

        alvo.scrollIntoView({
            behavior: "smooth",
            block: "center"
        });
    }
}


function atualizarProgresso(stepNumber) {
    const percentual =
        (stepNumber / TOTAL_STEPS) * 100;

    if (wizardProgress) {
        wizardProgress.setAttribute(
            "aria-valuenow",
            String(Math.round(percentual))
        );
    }

    if (wizardProgressBar) {
        wizardProgressBar.style.width =
            `${percentual}%`;
    }

    if (wizardStepLabel) {
        wizardStepLabel.textContent =
            `Etapa ${stepNumber} de ${TOTAL_STEPS}`;
    }
}


function mostrarEtapa(stepNumber, foco = true) {
    if (
        stepNumber < 1 ||
        stepNumber > TOTAL_STEPS
    ) {
        return;
    }

    currentStep = stepNumber;

    wizardSteps.forEach((step) => {
        const ativa =
            Number(step.dataset.step) === stepNumber;

        step.classList.toggle(
            "active",
            ativa
        );

        step.hidden = !ativa;

        step.setAttribute(
            "aria-hidden",
            String(!ativa)
        );

        step.inert = !ativa;
    });

    atualizarProgresso(stepNumber);

    if (foco) {
        window.requestAnimationFrame(() => {
            focarPrimeiroCampo(stepNumber);
        });
    }
}


/* =========================================================
   VALIDAÇÃO POR ETAPA
   ========================================================= */

function validarEtapa(stepNumber) {
    let valido = true;
    let primeiroCampoInvalido = null;

    const marcarInvalido = (
        campo,
        mensagemId,
        mensagem
    ) => {
        const elementoErro =
            document.getElementById(
                mensagemId
            );

        if (elementoErro) {
            elementoErro.textContent =
                mensagem;
        }

        campo.setAttribute(
            "aria-invalid",
            "true"
        );

        if (!primeiroCampoInvalido) {
            primeiroCampoInvalido =
                campo;
        }

        valido = false;
    };


    /* -----------------------------------------------------
       ETAPA 1 - DADOS PESSOAIS
       ----------------------------------------------------- */

    if (stepNumber === 1) {
        const nomeValor =
            nomeInput.value.trim();

        const sobrenomeValor =
            sobrenomeInput.value.trim();

        const emailValor =
            normalizarEmail(
                emailInput.value
            );

        if (!validarNome(nomeValor)) {
            marcarInvalido(
                nomeInput,
                "nome-error",
                "Digite um nome válido, usando apenas letras e espaços."
            );
        }

        if (!validarNome(sobrenomeValor)) {
            marcarInvalido(
                sobrenomeInput,
                "sobrenome-error",
                "Digite um sobrenome válido, usando apenas letras e espaços."
            );
        }

        if (!validarEmail(emailValor)) {
            marcarInvalido(
                emailInput,
                "email-error",
                "Informe um e-mail válido."
            );
        }
    }


    /* -----------------------------------------------------
       ETAPA 2 - PERFIL E MENSAGEM
       ----------------------------------------------------- */

    /* ETAPA 2: APRESENTAÇÃO */

    if (stepNumber === 2) {
        const mensagemValor = mensagemInput.value.trim();

        if (mensagemValor.length < 5) {
            marcarInvalido(
                mensagemInput,
                "mensagem-error",
                "A mensagem deve ter pelo menos 5 caracteres."
            );
        }
    }

    /* -----------------------------------------------------
       ETAPA 3 - SEGURANÇA
       ----------------------------------------------------- */

    if (stepNumber === 3) {
        const senhaValor =
            passwordInput.value;

        const confirmaSenhaValor =
            passwordCheckInput.value;

        if (senhaValor.length < 8) {
            marcarInvalido(
                passwordInput,
                "password-error",
                "A senha precisa ter no mínimo 8 caracteres."
            );
        } else if (
            senhaValor !==
            confirmaSenhaValor
        ) {
            marcarInvalido(
                passwordCheckInput,
                "password-error",
                "As senhas não coincidem."
            );
        }
    }


    /* -----------------------------------------------------
       FOCO NO PRIMEIRO ERRO
       ----------------------------------------------------- */

    if (
        !valido &&
        primeiroCampoInvalido
    ) {
        window.requestAnimationFrame(() => {
            primeiroCampoInvalido.focus();

            primeiroCampoInvalido.scrollIntoView({
                behavior: "smooth",
                block: "center"
            });
        });
    }

    return valido;
}


function encontrarPrimeiraEtapaInvalida() {
    for (
        let step = 1;
        step <= TOTAL_STEPS;
        step += 1
    ) {
        if (!validarEtapa(step)) {
            return step;
        }
    }

    return 0;
}


/* =========================================================
   ALTERNÂNCIA LOGIN / CADASTRO
   ========================================================= */

function mostrarTelaCadastro() {
    loginConteudo.hidden = false;
    recuperacaoConteudo.hidden = true;

    atualizarEstadoElemento(
        loginWrapper,
        false
    );

    atualizarEstadoElemento(
        cadastroWrapper,
        true
    );

    atualizarEstadoElemento(
        painelLogin,
        false
    );

    atualizarEstadoElemento(
        painelCadastro,
        true
    );

    limparErrosCadastro();

    formFeedback.textContent = "";

    formFeedback.className =
        "feedback-box";

    mostrarEtapa(
        1,
        false
    );

    setTimeout(() => {
        nomeInput.focus();
    }, 350);
}


function mostrarTelaLogin() {
    loginConteudo.hidden = false;
    recuperacaoConteudo.hidden = true;

    atualizarEstadoElemento(
        cadastroWrapper,
        false
    );

    atualizarEstadoElemento(
        loginWrapper,
        true
    );

    atualizarEstadoElemento(
        painelCadastro,
        false
    );

    atualizarEstadoElemento(
        painelLogin,
        true
    );

    mostrarEtapa(
        1,
        false
    );

    setTimeout(() => {
        loginEmailInput.focus();
    }, 350);
}


mostrarCadastro.addEventListener(
    "click",
    mostrarTelaCadastro
);


mostrarLogin.addEventListener(
    "click",
    mostrarTelaLogin
);


/* =========================================================
   WIZARD
   ========================================================= */

nextButtons.forEach((button) => {
    button.addEventListener(
        "click",
        () => {
            const etapaAtual =
                Number(
                    button
                        .closest(".wizard-step")
                        ?.dataset.step ||
                    currentStep
                );

            if (
                !validarEtapa(
                    etapaAtual
                )
            ) {
                mostrarEtapa(
                    etapaAtual,
                    false
                );

                focarPrimeiroCampo(
                    etapaAtual
                );

                return;
            }

            mostrarEtapa(
                etapaAtual + 1
            );
        }
    );
});


prevButtons.forEach((button) => {
    button.addEventListener(
        "click",
        () => {
            const etapaAtual =
                Number(
                    button
                        .closest(".wizard-step")
                        ?.dataset.step ||
                    currentStep
                );

            mostrarEtapa(
                etapaAtual - 1
            );
        }
    );
});


/* =========================================================
   FORÇA DA SENHA
   ========================================================= */

passwordInput.addEventListener(
    "input",
    atualizarForcaSenha
);


function atualizarForcaSenha() {
    const senha =
        passwordInput.value;

    let forca = 0;


    if (senha.length >= 8) {
        forca += 25;
    }


    if (/[A-Z]/.test(senha)) {
        forca += 25;
    }


    if (/[0-9]/.test(senha)) {
        forca += 25;
    }


    if (
        /[^A-Za-z0-9]/.test(senha)
    ) {
        forca += 25;
    }


    strengthBar.style.width =
        `${forca}%`;


    const meter =
        strengthBar.parentElement;

    meter.setAttribute(
        "aria-valuenow",
        String(forca)
    );


    if (senha.length === 0) {
        strengthBar.style.backgroundColor =
            "transparent";

        strengthText.textContent =
            "";

        return;
    }


    if (forca <= 25) {
        strengthBar.style.backgroundColor =
            "#a33e32";

        strengthText.textContent =
            "Força: Fraca";

        return;
    }


    if (forca <= 75) {
        strengthBar.style.backgroundColor =
            "#c28a32";

        strengthText.textContent =
            "Força: Média";

        return;
    }


    strengthBar.style.backgroundColor =
        "#32704b";

    strengthText.textContent =
        "Força: Forte";
}


/* =========================================================
   CADASTRO
   ========================================================= */

cadastroForm.addEventListener(
    "submit",
    cadastrarUsuario
);


async function cadastrarUsuario(event) {
    event.preventDefault();

    limparErrosCadastro();

    formFeedback.textContent = "";
    formFeedback.className = "feedback-box";

    const primeiraEtapaInvalida =
        encontrarPrimeiraEtapaInvalida();

    if (primeiraEtapaInvalida > 0) {
        mostrarEtapa(primeiraEtapaInvalida);
        return;
    }


    const dados = {
        nome: nomeInput.value.trim(),
        sobrenome: sobrenomeInput.value.trim(),
        email: normalizarEmail(emailInput.value),
        senha: passwordInput.value,
        mensagem: mensagemInput.value.trim()
    };

    try {
        const resposta = await fetch(
            "../api/cadastro/cadastrar.php",
            {
                method: "POST",
                headers: {
                    "Content-Type": "application/json"
                },
                body: JSON.stringify(dados)
            }
        );

        const resultado = await resposta.json();

        if (!resposta.ok) {
            formFeedback.textContent =
                resultado.mensagem ||
                "Não foi possível realizar o cadastro.";

            formFeedback.className =
                "feedback-box error";

            return;
        }

        formFeedback.textContent =
            resultado.mensagem ||
            "Cadastro realizado com sucesso!";

        formFeedback.className =
            "feedback-box success";

        loginEmailInput.value =
            normalizarEmail(emailInput.value);

        cadastroForm.reset();

        strengthBar.style.width = "0%";
        strengthBar.style.backgroundColor = "transparent";

        strengthBar.parentElement.setAttribute(
            "aria-valuenow",
            "0"
        );

        strengthText.textContent = "";

        setTimeout(() => {
            formFeedback.textContent = "";
            mostrarTelaLogin();
        }, 900);

    } catch (erro) {
        formFeedback.textContent =
            "Não foi possível conectar ao servidor.";

        formFeedback.className =
            "feedback-box error";
    }
}
/* =========================================================
   LOGIN
   ========================================================= */

loginForm.addEventListener(
    "submit",
    realizarLogin
);



async function realizarLogin(event) {
    event.preventDefault();

    limparErrosLogin();

    loginFeedback.textContent = "";
    loginFeedback.className = "feedback-box";

    let formValido = true;

    const email = normalizarEmail(
        loginEmailInput.value
    );

    const senha = loginSenhaInput.value;

    if (!validarEmail(email)) {
        document.getElementById(
            "login-email-error"
        ).textContent =
            "Informe um e-mail válido.";

        loginEmailInput.setAttribute(
            "aria-invalid",
            "true"
        );

        formValido = false;
    }

    if (senha.length === 0) {
        document.getElementById(
            "login-senha-error"
        ).textContent =
            "Informe sua senha.";

        loginSenhaInput.setAttribute(
            "aria-invalid",
            "true"
        );

        formValido = false;
    }

    if (!formValido) {
        loginFeedback.textContent =
            "Verifique os dados informados.";

        loginFeedback.className =
            "feedback-box error";

        return;
    }

    const dados = {
        email: email,
        senha: senha
    };

    try {
        const resposta = await fetch(
            "../api/login/login.php",
            {
                method: "POST",
                headers: {
                    "Content-Type": "application/json"
                },
                body: JSON.stringify(dados)
            }
        );

        const resultado = await resposta.json();

        if (!resposta.ok) {
            loginFeedback.textContent =
                resultado.mensagem ||
                "E-mail ou senha incorretos.";

            loginFeedback.className =
                "feedback-box error";

            return;
        }

        const destinosPorPerfil = {
            visitante: "visitante.html",
            editor: "editor.html",
            admin: "admin.html"
        };

        const perfil = String(
            resultado.usuario?.perfil ?? ""
        ).trim().toLowerCase();

        const destino = destinosPorPerfil[perfil];

        if (!destino) {
            loginFeedback.textContent =
                "Sua conta não possui um perfil de acesso válido.";

            loginFeedback.className =
                "feedback-box error";

            return;
        }

        loginFeedback.textContent =
            resultado.mensagem || "Login realizado com sucesso.";

        loginFeedback.className =
            "feedback-box success";

        setTimeout(() => {
            window.location.href = destino;
        }, 500);

    } catch (erro) {
        loginFeedback.textContent =
            "Não foi possível conectar ao servidor.";

        loginFeedback.className =
            "feedback-box error";
    }
}
/* =========================================================
   TECLADO
   ========================================================= */

document.addEventListener(
    "keydown",
    (event) => {
        if (
            event.key === "Escape" &&
            cadastroWrapper.classList.contains(
                "ativo"
            )
        ) {
            mostrarTelaLogin();
        }
    }
);


/* =========================================================
   ESTADO INICIAL
   ========================================================= */

atualizarEstadoElemento(
    loginWrapper,
    true
);


atualizarEstadoElemento(
    cadastroWrapper,
    false
);


atualizarEstadoElemento(
    painelLogin,
    true
);


atualizarEstadoElemento(
    painelCadastro,
    false
);


mostrarEtapa(
    1,
    false
);

/* Mostrar ou ocultar a senha individualmente. */
document.querySelectorAll("[data-alvo-senha]").forEach((botao) => {
    const idCampo = botao.dataset.alvoSenha;
    const campo = document.getElementById(idCampo);

    if (!campo) return;

    botao.addEventListener("click", () => {
        const mostrar = campo.type === "password";

        campo.type = mostrar ? "text" : "password";
        botao.textContent = mostrar ? "Ocultar" : "Mostrar";
        botao.setAttribute(
            "aria-label",
            mostrar ? "Ocultar senha" : "Mostrar senha"
        );
    });
});

/* =========================================================
   RECUPERAÇÃO LOCAL DE SENHA
   Disponível somente para desenvolvimento no XAMPP.
   ========================================================= */

function mostrarTelaRecuperacao(tokenInicial = null) {
    const tokenDaUrl =
        tokenInicial ??
        new URLSearchParams(window.location.search).get("token");

    atualizarEstadoElemento(cadastroWrapper, false);
    atualizarEstadoElemento(loginWrapper, true);
    atualizarEstadoElemento(painelCadastro, false);
    atualizarEstadoElemento(painelLogin, true);

    loginConteudo.hidden = true;
    recuperacaoConteudo.hidden = false;

    recuperacaoSolicitarForm.reset();
    redefinirSenhaForm.reset();

    recuperacaoSolicitarFeedback.textContent = "";
    recuperacaoSolicitarFeedback.className = "feedback-box";

    recuperacaoRedefinirFeedback.textContent = "";
    recuperacaoRedefinirFeedback.className = "feedback-box";

    tokenLocalValor.textContent = "";
    tokenLocalContainer.hidden = true;

    const existeTokenNaUrl =
        typeof tokenDaUrl === "string" &&
        tokenDaUrl.length > 0;

    recuperacaoSolicitarForm.hidden = existeTokenNaUrl;
    redefinirSenhaForm.hidden = !existeTokenNaUrl;

    if (existeTokenNaUrl) {
        recuperacaoTokenInput.value = tokenDaUrl;

        // Remove o token da barra de endereço após carregá-lo.
        window.history.replaceState(
            {},
            document.title,
            window.location.pathname + window.location.hash
        );

        window.setTimeout(() => {
            recuperacaoTokenInput.focus();
        }, 350);
    } else {
        recuperacaoEmailInput.value =
            loginEmailInput.value.trim();

        window.setTimeout(() => {
            recuperacaoEmailInput.focus();
        }, 350);
    }
}


/* Abre a recuperação a partir do login. */
abrirRecuperacaoButton.addEventListener("click", () => {
    mostrarTelaRecuperacao();
});


/* Retorna para o formulário de login. */
voltarLoginRecuperacaoButton.addEventListener("click", () => {
    mostrarTelaLogin();
});


/* =========================================================
   SOLICITAR TOKEN
   ========================================================= */

recuperacaoSolicitarForm.addEventListener("submit", async (evento) => {
    evento.preventDefault();

    recuperacaoSolicitarFeedback.textContent = "";
    recuperacaoSolicitarFeedback.className = "feedback-box";

    const email = normalizarEmail(recuperacaoEmailInput.value);

    if (!validarEmail(email)) {
        recuperacaoSolicitarFeedback.textContent =
            "Informe um e-mail válido.";

        recuperacaoSolicitarFeedback.className =
            "feedback-box error";

        recuperacaoEmailInput.setAttribute("aria-invalid", "true");
        recuperacaoEmailInput.focus();

        return;
    }

    recuperacaoEmailInput.removeAttribute("aria-invalid");

    try {
        const resposta = await fetch("../api/senha/solicitar.php", {
            method: "POST",
            headers: {
                "Content-Type": "application/json"
            },
            body: JSON.stringify({ email })
        });

        const resultado = await resposta.json();

        if (!resposta.ok) {
            recuperacaoSolicitarFeedback.textContent =
                resultado.mensagem ||
                "Não foi possível gerar o token.";

            recuperacaoSolicitarFeedback.className =
                "feedback-box error";

            return;
        }

        /*
         * O endpoint local devolve token_desenvolvimento
         * somente quando encontra o usuário e gera o token.
         */
        if (resultado.token_desenvolvimento) {
            tokenLocalValor.textContent =
                resultado.token_desenvolvimento;

            tokenLocalContainer.hidden = false;

            recuperacaoTokenInput.value =
                resultado.token_desenvolvimento;

            recuperacaoSolicitarForm.hidden = true;
            redefinirSenhaForm.hidden = false;

            recuperacaoSolicitarFeedback.textContent =
                "Token gerado no ambiente local. Ele já foi inserido no formulário abaixo.";

            recuperacaoSolicitarFeedback.className =
                "feedback-box success";

            window.setTimeout(() => {
                recuperacaoNovaSenhaInput.focus();
            }, 100);

            return;
        }

        recuperacaoSolicitarFeedback.textContent =
            resultado.mensagem ||
            "Se o e-mail estiver cadastrado, será gerado um token de recuperação.";

        recuperacaoSolicitarFeedback.className =
            "feedback-box success";

    } catch (erro) {
        console.error("Erro ao solicitar recuperação:", erro);

        recuperacaoSolicitarFeedback.textContent =
            "Não foi possível conectar ao servidor local.";

        recuperacaoSolicitarFeedback.className =
            "feedback-box error";
    }
});


/* =========================================================
   REDEFINIR SENHA
   ========================================================= */

redefinirSenhaForm.addEventListener("submit", async (evento) => {
    evento.preventDefault();

    recuperacaoRedefinirFeedback.textContent = "";
    recuperacaoRedefinirFeedback.className = "feedback-box";

    const token = recuperacaoTokenInput.value.trim();
    const novaSenha = recuperacaoNovaSenhaInput.value;
    const confirmarSenha = recuperacaoConfirmarSenhaInput.value;

    if (!/^[a-f0-9]{64}$/i.test(token)) {
        recuperacaoRedefinirFeedback.textContent =
            "Informe um token válido de 64 caracteres hexadecimais.";

        recuperacaoRedefinirFeedback.className =
            "feedback-box error";

        recuperacaoTokenInput.focus();
        return;
    }

    if (novaSenha.length < 8) {
        recuperacaoRedefinirFeedback.textContent =
            "A nova senha deve ter pelo menos 8 caracteres.";

        recuperacaoRedefinirFeedback.className =
            "feedback-box error";

        recuperacaoNovaSenhaInput.focus();
        return;
    }

    if (novaSenha !== confirmarSenha) {
        recuperacaoRedefinirFeedback.textContent =
            "As senhas não coincidem.";

        recuperacaoRedefinirFeedback.className =
            "feedback-box error";

        recuperacaoConfirmarSenhaInput.focus();
        return;
    }

    try {
        const resposta = await fetch("../api/senha/redefinir.php", {
            method: "POST",
            headers: {
                "Content-Type": "application/json"
            },
            body: JSON.stringify({
                token: token,
                nova_senha: novaSenha,
                confirmar_senha: confirmarSenha
            })
        });

        const resultado = await resposta.json();

        if (!resposta.ok) {
            recuperacaoRedefinirFeedback.textContent =
                resultado.mensagem ||
                "Não foi possível redefinir a senha.";

            recuperacaoRedefinirFeedback.className =
                "feedback-box error";

            return;
        }

        recuperacaoRedefinirFeedback.textContent =
            resultado.mensagem ||
            "Senha redefinida com sucesso. Você já pode entrar com a nova senha.";

        recuperacaoRedefinirFeedback.className =
            "feedback-box success";

        // Remove os dados do token da interface.
        tokenLocalContainer.hidden = true;
        tokenLocalValor.textContent = "";

        redefinirSenhaForm.reset();

    } catch (erro) {
        console.error("Erro ao redefinir senha:", erro);

        recuperacaoRedefinirFeedback.textContent =
            "Não foi possível conectar ao servidor local.";

        recuperacaoRedefinirFeedback.className =
            "feedback-box error";
    }
});


/* Permite abrir diretamente a redefinição com ?token=... */
const tokenInicialRecuperacao =
    new URLSearchParams(window.location.search).get("token");

if (tokenInicialRecuperacao) {
    mostrarTelaRecuperacao(tokenInicialRecuperacao);
}