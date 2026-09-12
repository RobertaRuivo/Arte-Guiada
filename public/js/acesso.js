/* =========================================================
   ARTE GUIADA
   SISTEMA DE ACESSO
   LOGIN + CADASTRO
   ========================================================= */


/* =========================================================
   ELEMENTOS DO DOM
   ========================================================= */

const loginWrapper = document.getElementById("loginWrapper");
const cadastroWrapper = document.getElementById("cadastroWrapper");

const painelLogin = document.getElementById("painelLogin");
const painelCadastro = document.getElementById("painelCadastro");

const mostrarCadastro = document.getElementById("mostrarCadastro");
const mostrarLogin = document.getElementById("mostrarLogin");

const loginForm = document.getElementById("loginForm");
const cadastroForm = document.getElementById("cadastroForm");


/* =========================================================
   CAMPOS DO CADASTRO
   ========================================================= */

const nomeInput = document.getElementById("nome");
const sobrenomeInput = document.getElementById("sobrenome");
const emailInput = document.getElementById("email");
const mensagemInput = document.getElementById("mensagem");

const passwordInput = document.getElementById("senha");
const passwordCheckInput = document.getElementById("confirmaSenha");

const strengthBar = document.getElementById("strength-bar");
const strengthText = document.getElementById("strength-text");

const formFeedback = document.getElementById("form-feedback");


/* =========================================================
   CAMPOS DO LOGIN
   ========================================================= */

const loginEmailInput = document.getElementById("loginEmail");
const loginSenhaInput = document.getElementById("loginSenha");

const loginFeedback = document.getElementById("login-feedback");


/* =========================================================
   FUNÇÕES AUXILIARES
   ========================================================= */

/*
 * Normaliza o e-mail para evitar diferenças
 * entre letras maiúsculas e minúsculas.
 */
function normalizarEmail(email) {
    return email
        .trim()
        .toLowerCase();
}


/*
 * Validação de e-mail.
 */
function validarEmail(email) {
    const regex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

    return regex.test(email);
}


/*
 * Validação de nomes.
 *
 * Aceita:
 * - letras;
 * - acentos;
 * - espaços.
 *
 * Não aceita números ou símbolos.
 */
function validarNome(nome) {
    const regexApenasLetrasESpacos =
        /^[a-zA-ZÀ-ÿ\s]+$/;

    return (
        nome.length >= 2 &&
        regexApenasLetrasESpacos.test(nome)
    );
}


/*
 * Remove mensagens de erro do cadastro.
 */
function limparErrosCadastro() {
    document
        .querySelectorAll("#cadastroForm .error-msg")
        .forEach((elemento) => {
            elemento.textContent = "";
        });

    document
        .querySelectorAll("#cadastroForm input, #cadastroForm textarea")
        .forEach((elemento) => {
            elemento.removeAttribute("aria-invalid");
        });
}


/*
 * Remove mensagens de erro do login.
 */
function limparErrosLogin() {
    document
        .querySelectorAll("#loginForm .error-msg")
        .forEach((elemento) => {
            elemento.textContent = "";
        });

    loginEmailInput.removeAttribute("aria-invalid");
    loginSenhaInput.removeAttribute("aria-invalid");
}


/* =========================================================
   ALTERNÂNCIA ENTRE LOGIN E CADASTRO
   ========================================================= */

function mostrarTelaCadastro() {
    loginWrapper.classList.remove("ativo");

    cadastroWrapper.classList.add("ativo");

    painelLogin.classList.remove("ativo");

    painelCadastro.classList.add("ativo");

    loginWrapper.setAttribute(
        "aria-hidden",
        "true"
    );

    cadastroWrapper.setAttribute(
        "aria-hidden",
        "false"
    );


    /*
     * Coloca o foco no primeiro campo do cadastro.
     * Isso melhora a navegação por teclado.
     */
    setTimeout(() => {
        nomeInput.focus();
    }, 350);
}


function mostrarTelaLogin() {
    cadastroWrapper.classList.remove("ativo");

    loginWrapper.classList.add("ativo");

    painelCadastro.classList.remove("ativo");

    painelLogin.classList.add("ativo");

    cadastroWrapper.setAttribute(
        "aria-hidden",
        "true"
    );

    loginWrapper.setAttribute(
        "aria-hidden",
        "false"
    );

    setTimeout(() => {
        loginEmailInput.focus();
    }, 350);
}


/* =========================================================
   BOTÕES DE TROCA
   ========================================================= */

mostrarCadastro.addEventListener(
    "click",
    mostrarTelaCadastro
);

mostrarLogin.addEventListener(
    "click",
    mostrarTelaLogin
);


/* =========================================================
   FORÇA DA SENHA
   ========================================================= */

passwordInput.addEventListener(
    "input",
    atualizarForcaSenha
);


function atualizarForcaSenha() {
    const senha = passwordInput.value;

    let forca = 0;


    /*
     * Cada requisito vale 25%.
     */

    if (senha.length >= 8) {
        forca += 25;
    }

    if (/[A-Z]/.test(senha)) {
        forca += 25;
    }

    if (/[0-9]/.test(senha)) {
        forca += 25;
    }

    if (/[^A-Za-z0-9]/.test(senha)) {
        forca += 25;
    }

    strengthBar.style.width =
        `${forca}%`;


    /*
     * Senha vazia.
     */

    if (senha.length === 0) {
        strengthBar.style.width = "0%";

        strengthBar.style.backgroundColor =
            "transparent";

        strengthText.textContent = "";

        return;
    }


    /*
     * Senha fraca.
     */

    if (forca <= 25) {
        strengthBar.style.backgroundColor =
            "#a33e32";

        strengthText.textContent =
            "Força: Fraca";

        return;
    }


    /*
     * Senha média.
     */

    if (forca <= 75) {
        strengthBar.style.backgroundColor =
            "#c28a32";

        strengthText.textContent =
            "Força: Média";

        return;
    }


    /*
     * Senha forte.
     */

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

    formFeedback.textContent = "";

    formFeedback.className =
        "feedback-box";

    let formValido = true;


    /* -----------------------------------------------------
       NOME
       ----------------------------------------------------- */

    const nomeValor =
        nomeInput.value.trim();

    if (!validarNome(nomeValor)) {
        document.getElementById(
            "nome-error"
        ).textContent =
            "Digite um nome válido, usando apenas letras e espaços.";

        nomeInput.setAttribute(
            "aria-invalid",
            "true"
        );

        formValido = false;
    }


    /* -----------------------------------------------------
       SOBRENOME
       ----------------------------------------------------- */

    const sobrenomeValor =
        sobrenomeInput.value.trim();

    if (!validarNome(sobrenomeValor)) {
        document.getElementById(
            "sobrenome-error"
        ).textContent =
            "Digite um sobrenome válido, usando apenas letras e espaços.";

        sobrenomeInput.setAttribute(
            "aria-invalid",
            "true"
        );

        formValido = false;
    }


    /* -----------------------------------------------------
       E-MAIL
       ----------------------------------------------------- */

    const emailValor =
        normalizarEmail(emailInput.value);

    if (!validarEmail(emailValor)) {
        document.getElementById(
            "email-error"
        ).textContent =
            "Informe um e-mail válido.";

        emailInput.setAttribute(
            "aria-invalid",
            "true"
        );

        formValido = false;
    }


    /* -----------------------------------------------------
       PERFIL
       ----------------------------------------------------- */

    const perfilSelecionado =
        document.querySelector(
            'input[name="loginTipo"]:checked'
        );

    if (!perfilSelecionado) {
        document.getElementById(
            "tipo-error"
        ).textContent =
            "Por favor, selecione um perfil no sistema.";

        formValido = false;
    }


    /* -----------------------------------------------------
       MENSAGEM
       ----------------------------------------------------- */

    const mensagemValor =
        mensagemInput.value.trim();

    if (mensagemValor.length < 5) {
        document.getElementById(
            "mensagem-error"
        ).textContent =
            "A mensagem deve ter pelo menos 5 caracteres.";

        mensagemInput.setAttribute(
            "aria-invalid",
            "true"
        );

        formValido = false;
    }


    /* -----------------------------------------------------
       SENHA
       ----------------------------------------------------- */

    const senhaValor =
        passwordInput.value;

    const confirmaSenhaValor =
        passwordCheckInput.value;

    if (senhaValor.length < 8) {
        document.getElementById(
            "password-error"
        ).textContent =
            "A senha precisa ter no mínimo 8 caracteres.";

        passwordInput.setAttribute(
            "aria-invalid",
            "true"
        );

        formValido = false;

    } else if (
        senhaValor !== confirmaSenhaValor
    ) {
        document.getElementById(
            "password-error"
        ).textContent =
            "As senhas não coincidem.";

        passwordCheckInput.setAttribute(
            "aria-invalid",
            "true"
        );

        formValido = false;
    }


    /* -----------------------------------------------------
       FORMULÁRIO INVÁLIDO
       ----------------------------------------------------- */

    if (!formValido) {
        formFeedback.textContent =
            "Por favor, corrija os erros sinalizados no formulário.";

        formFeedback.className =
            "feedback-box error";

        return;
    }


    /* -----------------------------------------------------
       SUCESSO
       ----------------------------------------------------- */

    formFeedback.textContent =
        "Cadastro realizado com sucesso!";

    formFeedback.className =
        "feedback-box success";


    /*
     * Preenche o e-mail no campo de login
     * para facilitar o acesso após o cadastro.
     */
    loginEmailInput.value =
        emailValor;


    /*
     * Limpa o formulário.
     */
    cadastroForm.reset();

    strengthBar.style.width =
        "0%";

    strengthBar.style.backgroundColor =
        "transparent";

    strengthText.textContent = "";


    /*
     * Depois de um pequeno intervalo,
     * retorna para a tela de login.
     */
    setTimeout(() => {
        formFeedback.textContent = "";

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

    loginFeedback.textContent = "";

    loginFeedback.className =
        "feedback-box";

    let formValido = true;


    /* -----------------------------------------------------
       E-MAIL
       ----------------------------------------------------- */

    const email =
        normalizarEmail(
            loginEmailInput.value
        );

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


    /* -----------------------------------------------------
       SENHA
       ----------------------------------------------------- */

    const senha =
        loginSenhaInput.value;

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


    /* -----------------------------------------------------
       FORMULÁRIO INVÁLIDO
       ----------------------------------------------------- */

    if (!formValido) {
        loginFeedback.textContent =
            "Verifique os dados informados.";

        loginFeedback.className =
            "feedback-box error";

        return;
    }


    /* -----------------------------------------------------
       LOGIN REALIZADO
       ----------------------------------------------------- */

    loginFeedback.textContent =
        "Login realizado com sucesso!";

    loginFeedback.className =
        "feedback-box success";
}


/* =========================================================
   NAVEGAÇÃO POR TECLADO
   ========================================================= */

document.addEventListener(
    "keydown",
    (event) => {

        /*
         * ESC retorna para o login.
         */

        if (
            event.key === "Escape" &&
            cadastroWrapper.classList.contains("ativo")
        ) {
            mostrarTelaLogin();
        }
    }
);