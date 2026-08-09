/**
 * Comportamento del combobox `x-ui.combobox` (Design System §5.6 — ADR-008/022).
 *
 * Fa TRE cose e nessun'altra: sposta l'evidenziazione, su Invio clicca il
 * bottone già evidenziato, e chiude la lista con Esc o col click fuori.
 *
 * **Non decide cosa si vede.** La lista esiste se e solo se il server ha dei
 * suggerimenti, e il valore lo scrive sempre il `wire:click` renderizzato dal
 * server: così esiste un solo percorso — testabile con Livewire — invece di una
 * seconda implementazione in JS che nessun test vedrebbe. Senza JavaScript il
 * campo resta usabile a click.
 *
 * ⚠️ La prima stesura teneva la visibilità in uno stato Alpine `aperto`, e non
 * funzionava: digitando, Livewire rifà il render dopo il debounce, il nodo
 * viene rimpiazzato e `x-data` si reinizializza — il flag tornava `false` e il
 * dropdown non compariva mai. Da qui la regola: **lo stato Alpine può solo
 * NASCONDERE ciò che il server ha deciso di mostrare, mai il contrario.**
 * `chiuso` riparte da `false` a ogni render, ed è voluto: chi sta digitando
 * vuole rivedere i suggerimenti nuovi.
 *
 * Si registra su `alpine:init` perché Alpine arriva dal bundle di Livewire:
 * `@vite` è nel <head> come modulo (esegue dopo il parsing ma prima di
 * DOMContentLoaded), `@livewireScripts` è a fine <body> e avvia Alpine su
 * DOMContentLoaded — quindi il listener è già registrato quando serve.
 */
document.addEventListener('alpine:init', () => {
    window.Alpine.data('uiCombobox', () => ({
        chiuso: false,
        attivo: null,

        riapri() {
            this.chiuso = false
            this.attivo = null
        },

        chiudi() {
            this.chiuso = true
            this.attivo = null
        },

        giu(totale) {
            if (totale === 0) return
            this.chiuso = false
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
