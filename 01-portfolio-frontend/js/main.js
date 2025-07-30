// Script para modo oscuro (jQuery)
$(function () {
  const KEY = 'modo';

  const setModo = (dark) => {
    $('body').toggleClass('modo-oscuro', dark);
    $('#modo-btn').text(dark ? '☀️ Modo Claro' : '🌙 Modo Oscuro');
    localStorage.setItem(KEY, dark ? 'oscuro' : 'claro');
  };

  // Estado inicial
  setModo(localStorage.getItem(KEY) === 'oscuro');

  // Click del botón
  $('#modo-btn').on('click', () =>
    setModo(!$('body').hasClass('modo-oscuro'))
  );
});

// Script para validación del formulario
$(document).ready(function () {
  $('#contacto-form').on('submit', function (e) {
    e.preventDefault();
    $('.error').text(''); // Limpiar mensajes anteriores

    // Capturar valores
    const nombre = $('#nombre').val().trim();
    const apellido = $('#apellido').val().trim();
    const email = $('#email').val().trim();
    const telefono = $('#tel').val().trim();
    const ciudad = $('#ciudad').val().trim();

    // Expresiones regulares
    const valN = /^[a-zA-ZáéíóúÁÉÍÓÚñÑ\s]+$/;
    const valE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    const valT = /^09\d{8}$/; // Teléfonos Ecuador

    let error = false;

    // Validar nombre
    if (nombre.length < 3 || !valN.test(nombre)) {
      $('#errorN').text('El nombre debe tener mínimo 3 letras y solo texto.');
      error = true;
    }

    // Validar apellido
    if (apellido.length < 3 || !valN.test(apellido)) {
      $('#errorA').text('El apellido debe tener mínimo 3 letras y solo texto.');
      error = true;
    }

    // Validar email
    if (!valE.test(email)) {
      $('#errorE').text('El email no es válido (ejemplo: correo@dominio.com).');
      error = true;
    }

    // Validar ciudad
    if (ciudad.length < 3 || !valN.test(ciudad)) {
      $('#errorC').text('La ciudad debe ser correcta.');
      error = true;
    }

    // Validar teléfono
    if (!valT.test(telefono)) {
      $('#errorT').text('El teléfono debe comenzar con 09 y tener 10 dígitos.');
      error = true;
    }

    // Si no hay errores, puedes enviar el formulario
    if (!error) {
      alert('Formulario enviado correctamente ✅');
      this.submit();
    }
  });
});
