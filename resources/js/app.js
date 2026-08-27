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

/**
 * Il selettore di tema `x-ui.selettore-tema` (🔗 ADR-034 — DS §8.3).
 *
 * Fa TRE cose in un click, e nessun'altra: scrive `data-theme` sull'`<html>`,
 * scrive `localStorage`, e **anticipa** `aria-pressed` sui tre bottoni.
 *
 * **Non decide il tema.** La verità è `users.tema`, e a scriverla è il
 * `wire:click` reso dal server, che parte nello stesso click. Qui si compra
 * solo l'immediatezza: un morph di Livewire ridisegna il componente ma **non
 * l'`<html>`**, quindi senza questa riga il colore cambierebbe soltanto al
 * ricaricamento successivo — l'interruttore sembrerebbe rotto pur avendo
 * salvato.
 *
 * ⚠️ **`aria-pressed` è anticipato, non posseduto.** È la regola già imparata
 * dal combobox letta dal lato giusto: lo stato Alpine non può *aggiungere* nulla
 * a ciò che il server ha deciso. Qui non tiene nemmeno uno stato — scrive
 * direttamente sull'attributo, e il morph successivo riporta i tre bottoni a ciò
 * che dice il database. Se il server rifiutasse il valore, l'evidenziazione
 * tornerebbe indietro da sé senza che questo file sappia che è successo.
 *
 * ⚠️ **In `localStorage` va il valore di DOMINIO** (`sistema|chiaro|scuro`), non
 * `light|dark`: «seguo il sistema» dev'essere **scrivibile**, non esprimibile
 * solo cancellando la chiave — cioè con un gesto indistinguibile da una pulizia
 * del browser. La traduzione verso le due stringhe che `app.css` cerca nei
 * propri selettori è la stessa che fa `TemaUtente::attributoHtml()` sul server e
 * lo script d'ospite nel `<head>`: `sistema` **non scrive l'attributo affatto**,
 * perché è l'assenza a far decidere il sistema operativo (ADR-034 punto 2).
 *
 * ⚠️ **La chiave arriva dal Blade**, che la rende da `TemaUtente::CHIAVE_LOCALSTORAGE`:
 * questo file è un asset statico e non può leggere una costante PHP, quindi
 * riscriverla qui vorrebbe dire tenerne allineate due copie a mano.
 *
 * ⚠️ Il `try/catch` non è cautela generica: in navigazione privata, e coi cookie
 * di terze parti bloccati, il solo `localStorage.setItem` **lancia**. Senza
 * cache il tema continua a funzionare — l'autenticato lo riceve dal server —
 * e si perde soltanto l'anticipo sulla pagina di accesso.
 */
document.addEventListener('alpine:init', () => {
    // I tre stati di dominio, e il valore che ciascuno scrive sull'`<html>`.
    // `null` = **nessun attributo**: non esiste un `data-theme="system"`, e un
    // valore terzo inciamperebbe nel `:not([data-theme="light"])` di `app.css`
    // spegnendo in silenzio la media query, cioè proprio la preferenza di
    // sistema che si voleva rispettare.
    const ATTRIBUTO = { chiaro: 'light', scuro: 'dark', sistema: null }

    window.Alpine.data('selettoreTema', (chiave) => ({
        /**
         * Il database ha ragione, e questa è la riga che glielo lascia dire.
         *
         * `data-tema-utente` porta **sempre** il valore di dominio dell'utente
         * autenticato, i tre stati distinti (lo rende il layout). Se
         * `localStorage` dice un'altra cosa — perché il tema è stato cambiato
         * da un altro dispositivo — la copia locale è vecchia: si riallinea, e
         * la prossima pagina di accesso su QUESTO browser nascerà del colore
         * giusto invece che di quello di ieri. È il costo dichiarato di ADR-034
         * («uno schermo già aperto altrove non si aggiorna da solo»), pagato al
         * primo caricamento utile.
         */
        riallinea() {
            const dominio = document.documentElement.dataset.temaUtente

            if (!(dominio in ATTRIBUTO)) return

            try {
                if (localStorage.getItem(chiave) !== dominio) {
                    localStorage.setItem(chiave, dominio)
                }
            } catch (e) {
                // Senza cache leggibile non si perde niente di essenziale.
            }
        },

        applica(tema) {
            // Un valore che non è uno dei tre non scrive NULLA: né attributo né
            // cache. È la stessa postura dello script d'ospite, e vale anche
            // qui dove i tre bottoni li rende il server — perché è l'unico modo
            // in cui questo file non può mettere l'`<html>` in uno stato che
            // `app.css` non sa leggere.
            if (!(tema in ATTRIBUTO)) return

            const radice = document.documentElement
            const attributo = ATTRIBUTO[tema]

            radice.setAttribute('data-tema-utente', tema)

            if (attributo === null) {
                radice.removeAttribute('data-theme')
            } else {
                radice.setAttribute('data-theme', attributo)
            }

            try {
                localStorage.setItem(chiave, tema)
            } catch (e) {
                // Vedi sopra: la cache è un anticipo, non la verità.
            }

            // L'anticipo dell'evidenziazione, dentro il solo gruppo che ha
            // ricevuto il click: due selettori sulla stessa pagina (la pagina
            // Preferenze e la scorciatoia in top bar) non si scrivono l'uno
            // addosso all'altro — a rimetterli d'accordo ci pensa comunque il
            // render del server.
            //
            // ⛔ **`$root` e non `$el`, ed è una trappola che costa un'ora.**
            // `$el` è l'elemento che sta **valutando l'espressione**, non la
            // radice del componente: chiamato da `x-on:click` su un bottone,
            // `this.$el` È il bottone, e `button.querySelectorAll('[data-tema]')`
            // non trova niente. Non dà errore — l'attributo semplicemente non si
            // muove, e l'evidenziazione resta indietro fino alla risposta del
            // server. Trovato guidando il componente in un browser vero: nessun
            // test Livewire poteva vederlo, perché Alpine lì non gira.
            this.$root.querySelectorAll('[data-tema]').forEach((bottone) => {
                bottone.setAttribute('aria-pressed', String(bottone.dataset.tema === tema))
            })
        },
    }))
})
