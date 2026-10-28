// Pequenos ajustes de interface, sem dependencias.
(function () {
  // Imagens externas que falharem somem em vez de mostrar icone quebrado.
  document.querySelectorAll('img').forEach(function (img) {
    img.addEventListener('error', function () { img.style.visibility = 'hidden'; }, { once: true });
  });

  // Botao "Rodar agora" mostra que esta trabalhando.
  document.querySelectorAll('button[value="rodar"]').forEach(function (b) {
    b.form && b.form.addEventListener('submit', function () {
      // Desativar so depois do envio: um botao desativado na hora nao manda acao=rodar.
      setTimeout(function () {
        b.disabled = true;
        b.textContent = 'Rodando, aguarde até 25 s...';
      }, 0);
    });
  });
})();
