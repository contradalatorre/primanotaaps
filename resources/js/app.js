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

// Revisione dei movimenti (#41): vista Tabella o Schede, selezione e tasti. Senza script la pagina funziona lo stesso.
const revisione = document.querySelector('[data-revisione]');

if (revisione) {
    const segnaVista = () => revisione.querySelectorAll('button[data-vista]').forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.vista === radice.dataset.vista)));
    segnaVista();
    // La catena è una riga che si espande sul telefono; dai 900 px si vede tutta.
    revisione.querySelector('[data-catena]').open = matchMedia('(min-width: 900px)').matches;
    revisione.querySelectorAll('button[data-vista]').forEach((b) => b.addEventListener('click', () => {
        radice.dataset.vista = b.dataset.vista;
        try { localStorage.setItem('vista-revisione', b.dataset.vista); } catch (e) { /* senza memoria si perde solo la scelta */ }
        segnaVista();
    }));

    // La barra dei selezionati dice quanti sono e accende i suoi pulsanti solo se ce n'è almeno uno.
    const barra = document.querySelector('[data-selezione]');
    if (barra) {
        const caselle = [...document.querySelectorAll('input[form="selezione"][type="checkbox"]')];
        const aggiorna = () => {
            const n = caselle.filter((c) => c.checked).length;
            barra.querySelector('[data-selezionati]').textContent = n ? `${n} selezionat${n === 1 ? 'o' : 'i'}` : 'Seleziona i movimenti con le caselle per agire su più insieme.';
            barra.querySelectorAll('[data-su-selezionati]').forEach((b) => { b.disabled = n === 0; });
        };
        caselle.forEach((c) => c.addEventListener('change', aggiorna));
        aggiorna();
    }

    // J e K vanno al movimento dopo e prima, Invio conferma quello aperto, Esc chiude il dettaglio.
    // J e K sono scorciatoie di un carattere (WCAG 2.1.4): valgono solo con il focus nell'elenco o nel dettaglio,
    // mai in un campo di testo, e senza distinguere maiuscole (Caps Lock). Per questo il dettaglio prende il focus quando si apre.
    document.querySelector('.dettaglio')?.setAttribute('tabindex', '-1');
    document.querySelector('.dettaglio')?.focus({ preventScroll: true });
    document.addEventListener('keydown', (e) => {
        if (e.ctrlKey || e.metaKey || e.altKey || e.target.closest('input, select, textarea, [contenteditable]')) return;
        const tasto = e.key.length === 1 ? e.key.toLowerCase() : e.key;
        const dentro = e.target.closest('.elenco, .dettaglio');
        // Invio e Esc, su un collegamento o un pulsante, fanno quello che fa il collegamento o il pulsante.
        if (tasto.length === 1 ? ! dentro : e.target.closest('button, a, summary')) return;
        const azione = { j: '[data-successivo]', k: '[data-precedente]', d: '.dettaglio [data-dubbio]', Escape: '[data-chiudi]', Enter: '.dettaglio [data-conferma]' }[tasto];
        // Senza dettaglio aperto, J apre il primo movimento dell'elenco.
        const elemento = azione && (document.querySelector(azione) ?? (tasto === 'j' && !document.querySelector('.dettaglio') ? document.querySelector('.riga-desc a') : null));
        if (elemento) {
            e.preventDefault();
            elemento.click();
        }
    });
}
