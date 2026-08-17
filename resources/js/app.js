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

/**
 * Scanner QR della vista di campo (§3 del wireframe — 🔗 ADR-003).
 *
 * Usa `BarcodeDetector`, l'API nativa del browser, e `getUserMedia`: **nessuna
 * dipendenza nuova**, coerente con un progetto in cui questo file era una riga
 * di commento fino al combobox. Dove l'API manca — Firefox, iOS sotto la 17 —
 * non si simula nulla: si dice all'utente di usare la fotocamera del telefono,
 * che funziona comunque perché il QR contiene già un'URL firmata (ADR-003) e
 * l'app fotocamera di qualunque telefono la apre da sola. Il QR non ha bisogno
 * di questa pagina: questa pagina è una comodità.
 *
 * ⚠️ **Si naviga solo verso lo stesso host, e non è una precauzione teorica.**
 * Un QR contiene testo arbitrario: chiunque può stamparne uno e attaccarlo su
 * una macchina. Seguire ciò che si legge porterebbe il tecnico su un sito
 * scelto da un estraneo — con l'aria di essere stato aperto da Easy Lab, che è
 * esattamente ciò che rende credibile una pagina di login falsa. Il codice che
 * non corrisponde a questo host viene quindi mostrato e non seguito.
 *
 * La fotocamera si spegne appena si è finito: `stop()` su ogni traccia, e
 * comunque all'uscita dalla pagina. Una spia accesa addosso a un tecnico che
 * gira per un laboratorio è una cosa che si nota, e giustamente.
 */
document.addEventListener('alpine:init', () => {
    window.Alpine.data('scannerQr', () => ({
        attivo: false,
        errore: null,
        codiceEstraneo: null,
        stream: null,
        rilevatore: null,

        get supportato() {
            return 'BarcodeDetector' in window && !!navigator.mediaDevices?.getUserMedia
        },

        async avvia() {
            this.errore = null
            this.codiceEstraneo = null

            try {
                // `environment` = fotocamera posteriore: è quella puntata sulla
                // macchina, non sul viso di chi la usa.
                this.stream = await navigator.mediaDevices.getUserMedia({
                    video: { facingMode: 'environment' },
                })
            } catch {
                this.errore = 'Non riesco ad accedere alla fotocamera. Controlla il permesso nelle impostazioni del browser.'
                return
            }

            this.attivo = true
            this.$refs.video.srcObject = this.stream
            await this.$refs.video.play()

            this.rilevatore = new window.BarcodeDetector({ formats: ['qr_code'] })
            this.cerca()
        },

        async cerca() {
            if (!this.attivo) return

            try {
                const trovati = await this.rilevatore.detect(this.$refs.video)
                if (trovati.length > 0) return this.segui(trovati[0].rawValue)
            } catch {
                // Un fotogramma illeggibile non è un errore: si riprova col
                // successivo. Interrompere qui renderebbe lo scanner fragile
                // proprio dove la luce è peggiore, cioè in laboratorio.
            }

            requestAnimationFrame(() => this.cerca())
        },

        segui(valore) {
            let url
            try {
                url = new URL(valore, window.location.origin)
            } catch {
                this.codiceEstraneo = valore
                return this.spegni()
            }

            if (url.origin !== window.location.origin) {
                this.codiceEstraneo = valore
                return this.spegni()
            }

            this.spegni()
            window.location.assign(url.href)
        },

        spegni() {
            this.attivo = false
            this.stream?.getTracks().forEach((traccia) => traccia.stop())
            this.stream = null
        },
    }))
})
