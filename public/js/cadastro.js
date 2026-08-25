// Elementos do formulário
const cadastroForm = document.getElementById('cadastroForm');
const nomeInput = document.getElementById('nome');
const emailInput = document.getElementById('email');
const mensagemInput = document.getElementById('mensagem');

const passwordInput = document.getElementById('senha');
const passwordCheckInput = document.getElementById('confirmaSenha');

const strengthBar = document.getElementById('strength-bar');
const strengthText = document.getElementById('strength-text');
const passwordError = document.getElementById('password-error');
const formFeedback = document.getElementById('form-feedback');

// --- FEATURE 1: Validação da Força de Senha ---
passwordInput.addEventListener('input', () => {
    const senha = passwordInput.value;
    let forca = 0;

    if (senha.length >= 8) forca += 25;
    if (/[A-Z]/.test(senha)) forca += 25;
    if (/[0-9]/.test(senha)) forca += 25;
    if (/[^A-Za-z0-9]/.test(senha)) forca += 25;

    strengthBar.style.width = `${forca}%`;

    if (forca <= 25) {
        strengthBar.style.backgroundColor = '#e74c3c';
        strengthText.textContent = 'Força: Fraca';
    } else if (forca <= 75) {
        strengthBar.style.backgroundColor = '#f1c40f';
        strengthText.textContent = 'Força: Média';
    } else {
        strengthBar.style.backgroundColor = '#2ecc71';
        strengthText.textContent = 'Força: Forte';
    }
});

// --- FEATURE 2: Validação dos Campos e do Formulário ---
function validarEmail(email) {
    const regex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    return regex.test(email);
}

cadastroForm.addEventListener('submit', (event) => {
    event.preventDefault();
    let formValido = true;

    // Limpa mensagens anteriores
    document.querySelectorAll('.error-msg').forEach(el => el.textContent = '');
    formFeedback.textContent = '';

    // Validação Nome (Aceita nomes compostos: letras, acentos e espaços, sem números)
    const nomeValor = nomeInput.value.trim();
    const regexApenasLetrasESpacos = /^[a-zA-ZÀ-ÿ\s]+$/;

    if (nomeValor.length < 2 || !regexApenasLetrasESpacos.test(nomeValor)) {
        document.getElementById('nome-error').textContent = 'Digite um nome válido (apenas letras e espaços).';
        formValido = false;
    }

    // Validação Email
    if (!validarEmail(emailInput.value)) {
        document.getElementById('email-error').textContent = 'Informe um e-mail válido.';
        formValido = false;
    }

    // Validação Perfil (Radio Button)
    const perfilSelecionado = document.querySelector('input[name="loginTipo"]:checked');
    if (!perfilSelecionado) {
        document.getElementById('tipo-error').textContent = 'Por favor, selecione um perfil no sistema.';
        formValido = false;
    }

    // Validação Mensagem
    if (mensagemInput.value.trim().length < 5) {
        document.getElementById('mensagem-error').textContent = 'A mensagem deve ter pelo menos 5 caracteres.';
        formValido = false;
    }

    // Validação Senha
    if (passwordInput.value.length < 8) {
        passwordError.textContent = 'A senha precisa ter no mínimo 8 caracteres.';
        formValido = false;
    } else if (passwordInput.value !== passwordCheckInput.value) {
        passwordError.textContent = 'As senhas não coincidem.';
        formValido = false;
    }

    if (formValido) {
        formFeedback.textContent = 'Cadastro enviado com sucesso!';
        formFeedback.className = 'feedback-box success';
        cadastroForm.reset();
        strengthBar.style.width = '0%';
        strengthText.textContent = '';
    } else {
        formFeedback.textContent = 'Por favor, corrija os erros sinalizados no formulário.';
        formFeedback.className = 'feedback-box error';
    }
});