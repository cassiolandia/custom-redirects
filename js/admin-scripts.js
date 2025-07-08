jQuery(document).ready(function ($) {

    // --- LÓGICA DO BOTÃO "USAR ESTE" (CORRIGIDA E FOCADA) ---
    // Este código lida com o clique no botão que está ao lado do slug sugerido.
    // Ele só será executado se o botão existir na página (ou seja, em um novo post).
    $('#cr_use_suggested_slug_btn').on('click', function (e) {
        // Previne o comportamento padrão do botão, se houver algum.
        e.preventDefault();

        // 1. Pega o valor do texto que está dentro do span com a classe 'cr-suggested-slug'.
        // O método .trim() remove espaços em branco extras no início e no fim.
        var suggestedSlug = $('.cr-suggested-slug').text().trim();

        // 2. Encontra o campo de input do slug personalizado pelo seu ID e define o seu valor.
        $('#cr_custom_slug').val(suggestedSlug);
        
        // (Opcional) BÔNUS: Foca no campo de input após o clique para melhorar a experiência do usuário.
        $('#cr_custom_slug').focus(); 
    });
    // --- FIM DA LÓGICA DO BOTÃO ---


    // --- LÓGICA DO BOTÃO DE COPIAR URL (EXISTENTE E FUNCIONAL) ---
    // Esta parte do seu código já estava correta e foi mantida.
    // Apenas um pequeno ajuste para salvar e restaurar o título original do botão.
    $(document.body).on('click', '.cr-copy-button', function (e) {
        e.preventDefault();
        const button = $(this);
        const textToCopy = button.data('copy-text');

        if (!navigator.clipboard) {
            alert('A função de copiar não é suportada neste navegador.');
            return;
        }

        navigator.clipboard.writeText(textToCopy).then(function () {
            const originalIcon = 'dashicons-admin-page';
            // Salva o título original se ainda não foi salvo
            if (!button.data('original-title')) {
                button.data('original-title', button.attr('title'));
            }

            button.removeClass(originalIcon).addClass('dashicons-yes-alt copied');
            button.attr('title', 'Copiado!');

            setTimeout(function () {
                button.removeClass('dashicons-yes-alt copied').addClass(originalIcon);
                // Restaura o título original
                button.attr('title', button.data('original-title'));
            }, 2000);
        }).catch(function (err) {
            // Usar console.error é melhor para depuração do que alert.
            console.error('Não foi possível copiar o texto: ', err);
            alert('Ocorreu um erro ao tentar copiar a URL.');
        });
    });
    // --- FIM DA LÓGICA DE COPIAR ---

});