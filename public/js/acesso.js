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

    if (stepNumber === 2) {
        const perfilSelecionado =
            document.querySelector(
                'input[name="loginTipo"]:checked'
            );

        const mensagemValor =
            mensagemInput.value.trim();

        if (!perfilSelecionado) {
            const erro =
                document.getElementById(
                    "tipo-error"
                );

            if (erro) {
                erro.textContent =
                    "Por favor, selecione um perfil no sistema.";
            }

            const primeiroRadio =
                document.querySelector(
                    'input[name="loginTipo"]'
                );

            if (
                primeiroRadio &&
                !primeiroCampoInvalido
            ) {
                primeiroCampoInvalido =
                    primeiroRadio;
            }

            valido = false;
        }

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


function cadastrarUsuario(event) {
    event.preventDefault();

    limparErrosCadastro();

    formFeedback.textContent =
        "";

    formFeedback.className =
        "feedback-box";


    const primeiraEtapaInvalida =
        encontrarPrimeiraEtapaInvalida();


    if (
        primeiraEtapaInvalida > 0
    ) {
        mostrarEtapa(
            primeiraEtapaInvalida
        );

        return;
    }


    formFeedback.textContent =
        "Cadastro realizado com sucesso!";

    formFeedback.className =
        "feedback-box success";


    loginEmailInput.value =
        normalizarEmail(
            emailInput.value
        );


    cadastroForm.reset();


    strengthBar.style.width =
        "0%";

    strengthBar.style.backgroundColor =
        "transparent";


    strengthBar.parentElement.setAttribute(
        "aria-valuenow",
        "0"
    );


    strengthText.textContent =
        "";


    setTimeout(() => {
        formFeedback.textContent =
            "";

        mostrarTelaLogin();
    }, 900);
}


/* =========================================================
   LOGIN
   ========================================================= */

loginForm.addEventListener(
    "submit",
    realizarLogin
);


function realizarLogin(event) {
    event.preventDefault();

    limparErrosLogin();

    loginFeedback.textContent =
        "";

    loginFeedback.className =
        "feedback-box";


    let formValido = true;


    const email =
        normalizarEmail(
            loginEmailInput.value
        );


    const senha =
        loginSenhaInput.value;


    if (
        !validarEmail(email)
    ) {
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


    loginFeedback.textContent =
        "Login realizado com sucesso!";

    loginFeedback.className =
        "feedback-box success";
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