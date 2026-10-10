{{-- Il cartello davanti al Billing Portal ospitato di Stripe — 🔗 ADR-032
     (l'intestatario è l'Account), ADR-013 (raggiungibile anche in lockout),
     ADR-002 (il piano Free non ha customer Stripe).

     ⛔ **Nessun motivo del blocco, in nessuna forma.** `locked_reason` e
     `stripe_lock_reason` sono annotazioni operative interne il cui destinatario
     è la cabina di regia, non il cliente: il componente non li passa nemmeno
     alla vista, e un test negativo presidia la cosa.

     ⛔ **Nessuna cifra su ciò che il cliente paga.** Il listino è dichiarato «a
     LISTINO, non incassato», e chi è rimasto su un prezzo storico paga un
     importo che il listino non dice. Le cifre vere stanno nel portale, che è il
     posto in cui ci sono davvero.

     ⚠️ Il prezzo dei piani **in vendita** in fondo alla pagina invece c'è
     (ADR-045): è l'importo del Price corrente, cioè ciò che il checkout
     addebiterà. Chi sceglie deve saperlo prima di pagare.

     ⚠️ Solo token semantici (DS §8.2, ADR-034): `surface`, `ink`, `border`,
     `lock-soft`, `bad-soft`. Nessuna classe di scala, e questo file NON va
     aggiunto a `DA_MIGRARE`. --}}
<div class="mx-auto w-full max-w-2xl px-4 py-10 sm:px-6">
    {{-- Il link di ritorno se lo porta la PAGINA, perché il layout è quello
         ospite (zero componenti Livewire, v. il docblock del componente: la top
         bar dell'app qui sarebbe una fuga dal lockout). Destinazione diversa a
         seconda dello stato: per un account sospeso la dashboard rimbalza su
         /bloccato, e mandarcelo passando da un 302 sarebbe solo un giro in più. --}}
    <a href="{{ $sospeso ? route('bloccato') : route('dashboard') }}"
       class="text-sm font-medium text-ink-2 transition hover:text-ink">&larr; Torna indietro</a>

    <h1 class="mt-4 text-2xl font-bold tracking-tight text-ink">Abbonamento</h1>
    <p class="mt-1 text-sm text-ink-2">{{ $ragioneSociale }}</p>

    @if (session('erroreAbbonamento'))
        <div class="mt-6 rounded-lg border border-border bg-bad-soft p-4 text-sm text-bad-soft-ink">
            {{ session('erroreAbbonamento') }}
        </div>
    @endif

    @if (session('esitoAbbonamento'))
        <div class="mt-6 rounded-lg border border-border bg-ok-soft p-4 text-sm text-ok-soft-ink">
            {{ session('esitoAbbonamento') }}
        </div>
    @endif

    {{-- ⚠️ Al CONDIZIONALE, e non è prudenza di stile: il parametro lo scrive il
         ritorno da Stripe, ma chiunque può digitarlo nella barra degli
         indirizzi. La pagina non sa se il pagamento è avvenuto — lo sa il
         webhook, ed è lui a cambiare il piano. «Pagamento ricevuto» qui sarebbe
         un'affermazione che la pagina non può sostenere (ADR-045). --}}
    @if ($ritornoCheckout === 'ok')
        <div class="mt-6 rounded-lg border border-border bg-ok-soft p-4 text-sm text-ok-soft-ink">
            Se hai completato il pagamento, il piano si aggiorna appena Stripe conferma l'incasso:
            di solito bastano pochi secondi. Ricarica la pagina per vederlo.
        </div>
    @elseif ($ritornoCheckout === 'annullato')
        <div class="mt-6 rounded-lg border border-border bg-surface p-4 text-sm text-ink-2">
            Hai lasciato il pagamento prima di concluderlo: nessun piano è stato attivato.
        </div>
    @endif

    @if ($sospeso)
        {{-- ⚠️ La copy è onesta su DUE cose che sarebbero facili da promettere
             e sbagliate da promettere:

             1. **il rientro non è immediato.** Chi paga nel portale torna qui
                mentre l'account è ancora `is_locked`: lo sblocco arriva col
                `customer.subscription.updated` in stato sano, che solo il
                webhook traduce in riapertura. «Ora puoi rientrare» sarebbe
                falso per qualche secondo o qualche minuto;
             2. **un blocco disposto a mano non si riapre pagando.** Il webhook
                spegne solo la sorgente Stripe, e `is_locked` vale «almeno una
                delle due accesa» (ADR-013).

             ⚠️ Il nome del metodo che riapre NON si scrive qui: un guardrail
             (`LeveAmministrativeTest`) scandisce le viste per quel nome, perché
             lo sblocco per pagamento deve esistere in un posto solo — il
             webhook — e nessuna superficie deve nemmeno sembrare offrirlo. --}}
        <div class="mt-6 rounded-lg border border-border bg-lock-soft p-4 md:p-6">
            <p class="text-sm font-medium text-lock-soft-ink">L'accesso è sospeso</p>
            <p class="mt-2 text-sm text-lock-soft-ink">
                Da questa pagina puoi comunque aggiornare il metodo di pagamento e saldare la
                posizione. Il rientro non è immediato: l'accesso si riapre quando Stripe conferma
                l'incasso, e non nell'istante del pagamento. Se la sospensione è stata disposta
                dall'amministrazione, il pagamento da solo non la revoca: contatta EasyLab.
            </p>
        </div>
    @endif

    <x-ui.card class="mt-6">
        <dl class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <div>
                <dt class="text-xs font-medium uppercase tracking-wide text-ink-3">Piano</dt>
                <dd class="mt-1 text-sm font-medium text-ink">{{ $etichettaPiano }}</dd>
            </div>
            <div>
                <dt class="text-xs font-medium uppercase tracking-wide text-ink-3">Abbonamento</dt>
                <dd class="mt-1 text-sm text-ink">{{ $statoAbbonamento }}</dd>
            </div>
            <div>
                <dt class="text-xs font-medium uppercase tracking-wide text-ink-3">Sedi</dt>
                {{-- ⛔ «∞» solo quando il piano lo dichiara illimitato. Su un
                     piano fuori catalogo il limite NON si conosce, e stamparlo
                     comunque affermerebbe al cliente che ha sedi illimitate
                     mentre il provisioning della successiva esplode. --}}
                <dd class="mt-1 text-sm text-ink">{{ $entiUsati }}@if ($limiteEnti !== null) su {{ $limiteEnti }}@endif</dd>
            </div>
            {{-- 🔗 ADR-049. Assente su un piano fuori catalogo, per la stessa
                 ragione del limite di sedi qui sopra. --}}
            @if ($strumenti !== null)
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-ink-3">Strumenti</dt>
                    <dd class="mt-1 text-sm text-ink" data-strumenti>{{ $strumenti }}</dd>
                </div>
            @endif
        </dl>
    </x-ui.card>

    <x-ui.card class="mt-4">
        {{-- 🔴 L'impersonazione PRIMA di ogni altro ramo, e con una copy
             propria: la sessione di portale si aprirebbe sul customer del
             CLIENTE, e da lì si può disdire il suo abbonamento e cambiargli il
             metodo di pagamento. Il rifiuto vero sta nel controller; qui si
             toglie il bottone e si dice perché — far cadere il caso nel ramo
             «non disponibile per questo account» direbbe una cosa falsa
             sull'account del cliente. --}}
        @if ($impersonazione)
            <p class="text-sm text-ink-2">
                Il portale di fatturazione non si apre durante un&rsquo;impersonazione: la sessione
                sarebbe intestata al customer del cliente e consentirebbe di disdirne l&rsquo;abbonamento.
                Esci dall&rsquo;impersonazione, oppure usa la dashboard di Stripe.
            </p>
        @elseif ($haPortale)
            {{-- Form POST classico verso un controller invokable, NON un'azione
                 Livewire: `skipRender()` farebbe saltare del tutto il `render()`
                 di questo componente, quindi una guardia scritta lì non
                 coprirebbe l'azione — e il progetto vieta comunque di chiamare
                 la rete da un ciclo di render. Il precedente vivo è la fuga da
                 lockout. Il controller riscrive la propria autorizzazione. --}}
            <form method="POST" action="{{ route('abbonamento.portale') }}">
                @csrf
                <x-ui.button type="submit">Apri il portale di fatturazione</x-ui.button>
            </form>
            <p class="mt-3 text-sm text-ink-2">
                Nel portale puoi scaricare le fatture, aggiornare il metodo di pagamento e
                disdire l'abbonamento. Si apre su Stripe, fuori da Easy Lab.
            </p>
        @elseif ($pianoGratuito)
            {{-- ⚠️ «Non c'è nulla da gestire qui» era vero finché la pagina non
                 vendeva niente. Dal 3 Ott 2026 (ADR-045) sotto questa scheda ci
                 sono i piani attivabili: dirlo due righe sopra sarebbe
                 contraddirsi da soli. --}}
            <p class="text-sm text-ink-2">
                Il piano Free non ha un portale di fatturazione: è la sua definizione.
                @if ($pianiOfferti !== [])
                    Per passare a un piano a pagamento, scegline uno qui sotto.
                @else
                    Non c'è nulla da gestire qui — la fatturazione, se prevista, avviene fuori dal software.
                @endif
            </p>
        @else
            <p class="text-sm text-ink-2">
                Il portale di fatturazione non è disponibile per questo account. Contatta EasyLab
                per gestire fatture, metodo di pagamento e disdetta.
            </p>
        @endif
    </x-ui.card>

    {{-- 🔗 ADR-045 — i piani che questo account può comprare da sé.

         🔴 **La lista viene da `PianiAcquistabili`, e non si filtra qui**: mai
         un piano gratuito, mai uno che costa meno di ciò che l'account ha o ha
         avuto. La vista stampa ciò che riceve; la regola la rifà il controller
         sul POST, perché un bottone nascosto non è una guardia.

         ⚠️ **Form POST classici verso un controller invokable**, come il
         portale qui sopra e per la stessa ragione: questo componente non deve
         avere azioni (la fuga dal lockout, v. il suo docblock). --}}
    @if ($pianiOfferti !== [])
        <x-ui.card class="mt-4" data-piani-in-vendita>
            <h2 class="text-base font-semibold text-ink">
                {{ $offerta->eUnCambio() ? 'Passa a un piano più grande' : 'Attiva un piano' }}
            </h2>

            @if ($impersonazione)
                <p class="mt-2 text-sm text-ink-2">
                    Un piano non si attiva e non si cambia durante un&rsquo;impersonazione: l&rsquo;acquisto
                    sarebbe a nome del cliente. Qui sotto c&rsquo;è ciò che il cliente vede.
                </p>
            @elseif (! $puoComprare)
                <p class="mt-2 text-sm text-ink-2">
                    I pagamenti non sono configurati su questo ambiente. Contatta EasyLab.
                </p>
            @elseif ($offerta->eUnCambio())
                <p class="mt-2 text-sm text-ink-2">
                    Il cambio è immediato. La differenza per il periodo già iniziato la calcola Stripe
                    e va nella prossima fattura. Da questa pagina non si passa a un piano di importo inferiore.
                </p>
            @else
                {{-- ⚠️ La partita IVA si dice PRIMA: al checkout è obbligatoria
                     (ADR-045), e chi arriva lì senza averla sotto mano torna
                     indietro con la sessione aperta. --}}
                <p class="mt-2 text-sm text-ink-2">
                    Il pagamento avviene su Stripe, fuori da Easy Lab: lì ti vengono chiesti la partita IVA
                    e l&rsquo;indirizzo di fatturazione. Il piano si attiva quando Stripe conferma l&rsquo;incasso.
                </p>
            @endif

            <ul class="mt-4 space-y-4">
                @foreach ($pianiOfferti as $riga)
                    @php $prezzo = number_format($riga['importoCent'] / 100, 2, ',', '.'); @endphp
                    <li class="border-t border-border pt-4" data-piano="{{ $riga['codice'] }}">
                        <div class="flex flex-wrap items-center gap-2">
                            <p class="text-sm font-semibold text-ink">{{ $riga['etichetta'] }}</p>
                            @if ($riga['proposto'])
                                <x-ui.badge variant="primary">Proposto da EasyLab</x-ui.badge>
                            @endif
                        </div>
                        <p class="mt-1 text-sm text-ink-2">
                            {{ $prezzo }} € al mese ·
                            {{ $riga['maxEnti'] === null ? 'sedi illimitate' : 'fino a '.$riga['maxEnti'].' sedi' }} ·
                            {{ $riga['strumenti'] }}
                        </p>

                        @if ($puoComprare)
                            <form method="POST" action="{{ route('abbonamento.piano') }}" class="mt-3 space-y-3">
                                @csrf
                                <input type="hidden" name="piano" value="{{ $riga['codice'] }}">

                                {{-- ⚠️ Solo sul cambio: lì un clic impegna a
                                     pagare di più senza una pagina di Stripe a
                                     chiedere conferma. All'attivazione quella
                                     pagina c'è, ed è il checkout. --}}
                                @if ($offerta->eUnCambio())
                                    <label class="flex items-start gap-2 text-sm text-ink-2">
                                        <input type="checkbox" name="conferma" value="1" required
                                               class="mt-0.5 rounded border border-border-strong bg-surface text-brand focus:ring-ring">
                                        <span>Confermo il passaggio a {{ $riga['etichetta'] }}, {{ $prezzo }} € al mese.</span>
                                    </label>
                                @endif

                                <x-ui.button type="submit">
                                    {{ $offerta->eUnCambio() ? 'Passa a' : 'Attiva' }} {{ $riga['etichetta'] }}
                                </x-ui.button>
                            </form>
                        @endif
                    </li>
                @endforeach
            </ul>
        </x-ui.card>
    @elseif ($offerta->impedimento !== null)
        {{-- ⛔ La frase viene dall'offerta ed è la stessa che il controller
             risponde al POST. Nessuna cita il motivo di un blocco: v.
             `OffertaPiani`. --}}
        <x-ui.card class="mt-4" data-piani-in-vendita>
            <p class="text-sm text-ink-2">{{ $offerta->messaggioImpedimento() }}</p>
        </x-ui.card>
    @endif
</div>
