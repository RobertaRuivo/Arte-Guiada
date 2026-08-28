javascript
// ============================================================
// ARTE GUIADA — ACESSO
// Login e cadastro
// ============================================================

document.addEventListener("DOMContentLoaded", () => {

    const formLogin = document.getElementById("form-login");
    const formCadastro = document.getElementById("form-cadastro");

    const loginForm = document.getElementById("login-form");
    const cadastroForm = document.getElementById("cadastro-form");

    const btnMostrarCadastro =
        document.getElementById("mostrar-cadastro");

    const btnMostrarLogin =
        document.getElementById("mostrar-login");

    const loginMensagem =
        document.getElementById("login-mensagem");

    const cadastroMensagem =
        document.getElementById("cadastro-mensagem");


    // ========================================================
    // ALTERNA PARA CADASTRO
    // ========================================================

    btnMostrarCadastro.addEventListener("click", () => {

        formLogin.classList.remove("formulario-ativo");
        formLogin.setAttribute("aria-hidden", "true");

        formCadastro.classList.add("formulario-ativo");
        formCadastro.setAttribute("aria-hidden", "false");

        cadastroMensagem.textContent = "";

        document
            .getElementById("cadastro-nome")
            .focus();

    });


    // ========================================================
    // ALTERNA PARA LOGIN
    // ========================================================

    btnMostrarLogin.addEventListener("click", () => {

        formCadastro.classList.remove("formulario-ativo");
        formCadastro.setAttribute("aria-hidden", "true");

        formLogin.classList.add("formulario-ativo");
        formLogin.setAttribute("aria-hidden", "false");

        loginMensagem.textContent = "";

        document
            .getElementById("login-email")
            .focus();

    });


    // ========================================================
    // CADASTRO
    // ========================================================

    cadastroForm.addEventListener("submit", (event) => {

        event.preventDefault();

        const nome =
            document
                .getElementById("cadastro-nome")
                .value
                .trim();

        const email =
            document
                .getElementById("cadastro-email")
                .value
                .trim()
                .toLowerCase();

        const senha =
            document
                .getElementById("cadastro-senha")
                .value;


        if (!nome || !email || !senha) {

            cadastroMensagem.textContent =
                "Preencha todos os campos.";

            return;
        }


        if (senha.length < 6) {

            cadastroMensagem.textContent =
                "A senha deve possuir pelo menos 6 caracteres.";

            return;
        }


        const usuarioExistente =
            localStorage.getItem("arteGuiadaUsuario");


        if (usuarioExistente) {

            const usuario =
                JSON.parse(usuarioExistente);


            if (usuario.email === email) {

                cadastroMensagem.textContent =
                    "Este email já possui uma conta.";

                return;
            }

        }


        const usuario = {
            nome,
            email,
            senha
        };


        localStorage.setItem(
            "arteGuiadaUsuario",
            JSON.stringify(usuario)
        );


        cadastroMensagem.textContent =
            "Conta criada. Você já pode entrar.";


        cadastroForm.reset();


        setTimeout(() => {

            btnMostrarLogin.click();

            document
                .getElementById("login-email")
                .value = email;

        }, 1000);

    });


    // ========================================================
    // LOGIN
    // ========================================================

    loginForm.addEventListener("submit", (event) => {

        event.preventDefault();


        const email =
            document
                .getElementById("login-email")
                .value
                .trim()
                .toLowerCase();

        const senha =
            document
                .getElementById("login-senha")
                .value;


        const usuarioSalvo =
            localStorage.getItem("arteGuiadaUsuario");


        if (!usuarioSalvo) {

            loginMensagem.textContent =
                "Você ainda não possui uma conta. Crie seu cadastro primeiro.";

            return;
        }


        const usuario =
            JSON.parse(usuarioSalvo);


        if (
            usuario.email === email &&
            usuario.senha === senha
        ) {

            localStorage.setItem(
                "arteGuiadaLogado",
                "true"
            );


            loginMensagem.textContent =
                `Bem-vinda, ${usuario.nome}.`;


            setTimeout(() => {

                window.location.href =
                    "../index.html";

            }, 800);


            return;
        }


        loginMensagem.textContent =
            "Email ou senha incorretos.";

    });

});

