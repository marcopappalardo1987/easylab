<div class="mx-auto w-full max-w-2xl px-4 py-10 sm:px-6">

    {{-- Header.

         ⚠️ **La pagina si chiama «Preferenze», l'indirizzo resta
         `/settings/notifiche`.** Dal 26 Ago 2026 qui non c'è più solo il
         digest: c'è anche il tema (ADR-034), e un titolo che dicesse
         «Notifiche» nasconderebbe metà della pagina a chi la cerca. L'URL e il
         nome della rotta **non** cambiano: sono citati dal menù utente, dal piè
         di pagina del digest email — cioè da messaggi già spediti — e da due
         test. Si cambia ciò che l'utente legge, non ciò che l'utente ha già in
         posta. --}}
    <h1 class="text-2xl font-bold tracking-tight text-neutral-900">Preferenze</h1>

    <div class="mt-8 rounded-lg border border-neutral-200 bg-white p-6 shadow-sm md:p-8">

        <div class="flex items-start justify-between gap-4">
            <div>
                <h2 class="font-semibold text-neutral-900">Riepilogo email delle scadenze</h2>
                <p class="mt-1 text-sm text-neutral-600">
                    Un'email al giorno con le scadenze appena superate e quelle in arrivo
                    nei prossimi 30 giorni. Nessuna email nei giorni in cui non cambia nulla.
                </p>
            </div>

            <button type="button"
                    wire:click="$toggle('riceveEmailScadenze')"
                    role="switch"
                    aria-checked="{{ $riceveEmailScadenze ? 'true' : 'false' }}"
                    aria-label="Riepilogo email delle scadenze"
                    class="relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition focus:ring-2 focus:ring-primary-600 focus:ring-offset-2 focus:outline-none {{ $riceveEmailScadenze ? 'bg-primary-600' : 'bg-neutral-200' }}">
                <span class="inline-block h-5 w-5 transform rounded-full bg-white shadow transition {{ $riceveEmailScadenze ? 'translate-x-5' : 'translate-x-0' }}"></span>
            </button>
        </div>

        <hr class="my-6 border-neutral-200">

        <p class="text-sm text-neutral-600">
            Le notifiche <strong>in applicazione</strong> restano sempre attive: sono la
            copia di ciò che vedi comunque entrando, e non lasciano Easy Lab.
        </p>

        <div class="mt-6 flex items-center gap-3">
            <button type="button" wire:click="salva"
                    class="inline-flex items-center justify-center rounded-md bg-primary-600 px-4 py-2.5 font-medium text-white transition hover:bg-primary-700 focus:ring-2 focus:ring-primary-600 focus:ring-offset-2 focus:outline-none">
                Salva
            </button>

            <span x-data="{ visibile: false }"
                  x-on:preferenze-salvate.window="visibile = true; setTimeout(() => visibile = false, 2500)"
                  x-show="visibile" x-cloak
                  class="text-sm text-success-600">Preferenze salvate.</span>
        </div>
    </div>

    {{-- Il tema (🔗 ADR-034 — DS §8.3).

         ⚠️ **Questo blocco nasce già sui token semantici** (`bg-surface`,
         `text-ink`, `border-border`) mentre il resto della pagina è ancora
         sulle scale: il file è in `DA_MIGRARE` e ci resta finché F4-C3 non lo
         migra per intero. Scriverlo qui con `bg-white` avrebbe voluto dire
         scrivere del lavoro da rifare — e in tema chiaro le due forme sono
         **lo stesso pixel**, quindi la pagina non si sfalsa nel frattempo.

         ⚠️ **Nessun «Salva» qui**, a differenza del digest sopra: il tema si
         applica nel millisecondo del click e la colonna si scrive nello stesso
         gesto. Un bottone in mezzo significherebbe una pagina già scura e un
         database ancora chiaro — cioè un tema che si dimentica al primo
         ricaricamento. La differenza fra le due sezioni è dichiarata nel testo,
         non lasciata indovinare. --}}
    <div class="mt-6 rounded-lg border border-border bg-surface p-6 shadow-sm md:p-8">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <h2 class="font-semibold text-ink">Tema</h2>
                <p class="mt-1 text-sm text-ink-2">
                    <strong>Sistema</strong> segue il tuo dispositivo, e cambia da sé quando
                    cambia lui. Chiaro e scuro valgono ovunque tu entri con questo account:
                    la scelta è tua, non del browser.
                </p>
            </div>

            {{-- `temaCorrente` arriva dal `render()`, cioè dalla colonna
                 `users.tema`: è il server a dire quale dei tre è premuto. --}}
            <x-ui.selettore-tema :corrente="$temaCorrente" class="shrink-0 self-start" />
        </div>

        <p class="mt-4 text-sm text-ink-3">La scelta si applica subito: non serve salvare.</p>
    </div>
</div>
