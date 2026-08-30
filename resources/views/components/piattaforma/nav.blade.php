{{--
    Le pagine di piattaforma, condivise da tutte.

    Vive in un componente e non duplicata nei Blade perché il giorno in cui ne
    arriva una nuova si aggiunge in un posto solo — ed è la stessa ragione per
    cui la voce di sidebar resta **una**: `piattaforma.*` le accende tutte.

    ⚠️ **Ogni voce dichiara il proprio permesso, e si filtra su quello.** Sono
    quattro permessi diversi per cinque voci: le prime due condividono
    `tenants.view_all`, la terza sta dietro `roles.manage`, la quarta dietro
    `billing.manage_global` e la quinta dietro `system.logs.view` (vedi i
    docblock di `EditorRuoli`, di `Listino` e di `Errori`). Senza
    il filtro, chi può guardare la piattaforma ma non governare i ruoli vedrebbe
    un tab che porta a un 403 — cioè un invito a bussare, che è la stessa
    ragione per cui la card della dashboard è gatata.

    ⚠️ **E dall'ULTIMA voce il filtro smette di essere una precauzione teorica.**
    Per le altre quattro il caso da evitare non si realizza con nessun ruolo del
    catalogo: `roles.manage`, `billing.manage_global` e `tenants.view_all` stanno
    oggi sugli stessi due ruoli, quindi la terza e la quarta voce si possono far
    sparire solo fabbricando a mano lo stato con l'API di spatie
    (`AccessoEditorRuoliTest` e `AccessoListinoTest` fanno esattamente così).
    `system.logs.view` no: è del **solo Developer**, e il Superadmin — che entra
    qui ogni giorno — è il primo utente vero che deve vedere **quattro** voci e
    non cinque. Togliere il filtro non è più un difetto latente: è un 403 che il
    Superadmin incontra al primo click.

    ⚠️ **La quinta voce sta in mezzo e non in fondo, e la posizione è una
    decisione.** «Piani» va dopo «Ruoli e permessi» e prima di «Errori» perché
    l'ultima resta così quella del **solo Developer**: la fila che il Superadmin
    vede finisce dove finisce il suo insieme di permessi, invece di avere un buco
    in mezzo. È presentazione, ma è la presentazione che rende leggibile la
    partizione dei permessi a chi guarda la barra.

    Il filtro si fa qui **e** sulla rotta: questa è presentazione, e la
    presentazione non protegge niente (un `@can` in Blade si toglie in un
    secondo). Il permesso di ciascuna voce si legge da una **costante**, mai da
    una stringa ribattuta: due copie dello stesso nome sono due occasioni di
    gatare la pagina su un permesso e il menù su un altro. ⚠️ Le costanti non
    sono però omogenee, e va detto invece di lasciarlo dedurre: dalla terza in poi
    sono del proprio componente (`EditorRuoli::PERMESSO`, `Listino::PERMESSO`,
    `Errori::PERMESSO`), le prime due sono della **porta di tenancy**
    (`VistaPiattaforma::PERMESSO`), perché né `Cabina` né `RegistroAudit` ne
    dichiarano una — è la porta a custodire il loro permesso.

    ⚠️ **I colori vengono dai token semantici, mai dalla scala** (DS §8.2): il
    tab attivo è `border-brand text-brand`, l'inattivo `text-ink-2` che
    all'hover sale a `text-ink` facendo comparire il bordo `border-border-strong`.
    È la traduzione uno-a-uno di `.el-tabs` nel campione, dove il tab selezionato
    porta anche `font-weight:600`: il **grassetto e il bordo** sono ciò che
    distingue la sezione corrente per chi non vede la tinta, e `aria-current="page"`
    per chi non vede affatto. ⚠️ Il peso del carattere sta **solo** dentro il
    ternario e mai nella base — `font-medium` e `font-semibold` sono la stessa
    proprietà, e a decidere quale vince non è l'ordine nell'attributo `class` ma
    l'ordine nel foglio di stile generato.

    ⚠️ **Due misure vengono dal campione e non sono colore**, e vanno dette:
    `min-h-11` sono le `2.75rem` di `.el-tabs [role="tab"]`, cioè il bersaglio da
    44px di DS §5.1 (con `py-2` erano 36); `overflow-x-auto` + `whitespace-nowrap`
    perché a 360px queste cinque etichette misurano ben oltre la larghezza, e senza lo
    scorrimento della **barra** a scorrere sarebbe la **pagina** (DS §5.5,
    «mobile: scroll orizzontale»). Il campione nasconde anche la scrollbar; qui
    no, perché servirebbe una regola in `app.css` — file che questo task non
    tocca — e una barra visibile resta comunque un invito a scorrere.
--}}
@php
    $voci = collect([
        ['rotta' => 'piattaforma.index', 'etichetta' => 'Clienti', 'permesso' => App\Support\Tenancy\VistaPiattaforma::PERMESSO],
        ['rotta' => 'piattaforma.audit', 'etichetta' => 'Registro di audit', 'permesso' => App\Support\Tenancy\VistaPiattaforma::PERMESSO],
        ['rotta' => 'piattaforma.ruoli', 'etichetta' => 'Ruoli e permessi', 'permesso' => App\Livewire\Piattaforma\EditorRuoli::PERMESSO],
        ['rotta' => 'piattaforma.piani', 'etichetta' => 'Piani', 'permesso' => App\Livewire\Piattaforma\Listino::PERMESSO],
        ['rotta' => 'piattaforma.parco', 'etichetta' => 'Parco clienti', 'permesso' => App\Livewire\Piattaforma\ParcoGlobale::PERMESSO],
        ['rotta' => 'piattaforma.tecnici', 'etichetta' => 'I miei tecnici', 'permesso' => App\Livewire\Piattaforma\Tecnici::PERMESSO],
        ['rotta' => 'piattaforma.errori', 'etichetta' => 'Errori', 'permesso' => App\Livewire\Piattaforma\Errori::PERMESSO],
    ])->filter(fn (array $voce) => auth()->user()?->can($voce['permesso']));
@endphp

<nav class="flex gap-1 overflow-x-auto border-b border-border" aria-label="Sezioni della piattaforma">
    @foreach ($voci as $voce)
        {{-- ⚠️ Anche le **sotto-rotte** della voce, non solo la sua: da S6 la
             voce «Errori» ha una scheda propria (`piattaforma.errori.mostra`), e
             col solo nome esatto la sub-nav non evidenzierebbe nulla mentre si
             legge un errore — cioè la pagina direbbe di non stare in nessuna
             sezione. Il jolly non tocca le altre quattro, che di sotto-rotte non
             ne hanno. --}}
        @php $attiva = request()->routeIs($voce['rotta'], $voce['rotta'].'.*'); @endphp
        <a href="{{ route($voce['rotta']) }}"
           @if ($attiva) aria-current="page" @endif
           class="-mb-px flex min-h-11 items-center whitespace-nowrap border-b-2 px-3 text-sm {{ $attiva
               ? 'border-brand font-semibold text-brand'
               : 'border-transparent font-medium text-ink-2 hover:border-border-strong hover:text-ink' }}">
            {{ $voce['etichetta'] }}
        </a>
    @endforeach
</nav>
