/**
 * Comportamento del combobox `x-ui.combobox` (Design System §5.6 — ADR-008/022).
 *
 * Fa TRE cose e nessun'altra: apre/chiude, sposta l'evidenziazione, e su Invio
 * clicca il bottone già evidenziato. **Il valore non lo scrive mai**: la
 * selezione passa sempre dal `wire:click` renderizzato dal server, così esiste
 * un solo percorso — testabile con Livewire — invece di una seconda
 * implementazione in JS che nessun test vedrebbe. Senza JavaScript il campo
 * resta usabile a click.
 *
 * Si registra su `alpine:init` perché Alpine arriva dal bundle di Livewire:
 * `@vite` è nel <head> come modulo (esegue dopo il parsing ma prima di
 * DOMContentLoaded), `@livewireScripts` è a fine <body> e avvia Alpine su
 * DOMContentLoaded — quindi il listener è già registrato quando serve.
 */
document.addEventListener('alpine:init', () => {
    window.Alpine.data('uiCombobox', () => ({
        aperto: false,
        attivo: null,

        apri() {
            this.aperto = true
        },

        chiudi() {
            this.aperto = false
            this.attivo = null
        },

        giu(totale) {
            if (totale === 0) return
            this.aperto = true
            this.attivo = this.attivo === null ? 0 : Math.min(this.attivo + 1, totale - 1)
        },

        su() {
            if (this.attivo === null) return
            this.attivo = this.attivo === 0 ? null : this.attivo - 1
        },

        // Invio = click sul bottone evidenziato. Non c'è una via alternativa
        // per selezionare: se il bottone non c'è, non succede nulla e l'utente
        // resta con il testo che ha digitato — che è il caso "creo al volo".
        scegli(lista) {
            if (this.attivo === null || !lista) return
            lista.querySelectorAll('button')[this.attivo]?.click()
            this.chiudi()
        },
    }))
})
