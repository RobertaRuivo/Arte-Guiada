// Seleciona os elementos do HTML pelo ID
const passwordInput = document.getElementById('senha');
const passwordError = document.getElementById('password-error');
const cadastroForm = document.getElementById('cadastroForm');
const passwordCheckInput = document.getElementById('confirmaSenha');

// Adiciona um "ouvinte de evento" (event listener) que detecta quando o usuário digita
passwordInput.addEventListener('keyup', () => {
    const senha = passwordInput.value;
    const confirmaSenha = passwordCheckInput.value;

    if (senha.length < 8 || senha !== confirmaSenha) {
        // mostrar erro
        passwordError.style.display = 'block';
    } else {
        // esconder erro
        passwordError.style.display = 'none';
    }


});

// Impede o envio do formulário e exibe um alerta
cadastroForm.addEventListener('submit', (event) => {
    // Prevê o comportamento padrão de envio do formulário
    event.preventDefault();

    // Valida a senha uma última vez antes de enviar
    if (passwordInput.value.length >= 8 && passwordInput.value === passwordCheckInput.value) {
        alert('Formulário enviado com sucesso!');
    } else {
        alert('Por favor, corrija a senha antes de enviar.');
    }
});
