{{-- Il cartello davanti al Billing Portal ospitato di Stripe — 🔗 ADR-032
     (l'intestatario è l'Account), ADR-013 (raggiungibile anche in lockout),
     ADR-002 (il piano Free non ha customer Stripe).

     ⛔ **Nessun motivo del blocco, in nessuna forma.** `locked_reason` e
     `stripe_lock_reason` sono annotazioni operative interne il cui destinatario
     è la cabina di regia, non il cliente: il componente non li passa nemmeno
     alla vista, e un test negativo presidia la cosa.

     ⛔ **Nessun importo.** Il listino in `config/easylab.php` è dichiarato «a
     LISTINO, non incassato» e nulla nel repository si accorgerebbe se
     divergesse dal Price su Stripe. Le cifre vere stanno nel portale, che è il
     posto in cui ci sono davvero.

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
            <p class="text-sm text-ink-2">
                Il piano Free non ha un portale di fatturazione: è la sua definizione. Non c'è
                nulla da gestire qui — la fatturazione, se prevista, avviene fuori dal software.
            </p>
        @else
            <p class="text-sm text-ink-2">
                Il portale di fatturazione non è disponibile per questo account. Contatta EasyLab
                per gestire fatture, metodo di pagamento e disdetta.
            </p>
        @endif
    </x-ui.card>
</div>
