const botonModo = document.getElementById('modo-btn');

// Revisar si el usuario ya tiene un modo guardado
if (localStorage.getItem('modo') === 'oscuro') {
    document.body.classList.add('modo-oscuro');
    botonModo.textContent = '☀️ Modo Claro';
}

botonModo.addEventListener('click', () => {
document.body.classList.toggle('modo-oscuro');

    if (document.body.classList.contains('modo-oscuro')) {
    botonModo.textContent = '☀️ Modo Claro';
    localStorage.setItem('modo', 'oscuro');
    } else {
    botonModo.textContent = '🌙 Modo Oscuro';
    localStorage.setItem('modo', 'claro');
    }
});