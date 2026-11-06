// Menu laterale comprimibile (sopra i 1100 px); la scelta resta sul dispositivo.
const radice = document.documentElement;
const bottone = document.querySelector('[data-menu-toggle]');

function imposta(compresso) {
    radice.dataset.menu = compresso ? 'compresso' : 'aperto';
    bottone?.setAttribute('aria-expanded', String(!compresso));
    bottone?.setAttribute('aria-label', compresso ? 'Espandi il menu' : 'Comprimi il menu');
}

if (bottone) {
    imposta(localStorage.getItem('menu') === 'compresso');
    bottone.addEventListener('click', () => {
        const compresso = radice.dataset.menu !== 'compresso';
        localStorage.setItem('menu', compresso ? 'compresso' : 'aperto');
        imposta(compresso);
    });
}
