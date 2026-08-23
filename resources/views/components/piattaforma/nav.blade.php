{{--
    Le pagine di piattaforma, condivise da tutte.

    Vive in un componente e non duplicata nei Blade perché il giorno in cui ne
    arriva una nuova si aggiunge in un posto solo — ed è la stessa ragione per
    cui la voce di sidebar resta **una**: `piattaforma.*` le accende tutte.

    ⚠️ **Ogni voce dichiara il proprio permesso, e si filtra su quello.** Sono
    tre permessi diversi per quattro voci: le prime due condividono
    `tenants.view_all`, la terza sta dietro `roles.manage` e la quarta dietro
    `system.logs.view` (vedi i docblock di `EditorRuoli` e di `Errori`). Senza
    il filtro, chi può guardare la piattaforma ma non governare i ruoli vedrebbe
    un tab che porta a un 403 — cioè un invito a bussare, che è la stessa
    ragione per cui la card della dashboard è gatata.

    ⚠️ **E dalla quarta voce il filtro smette di essere una precauzione teorica.**
    Per le prime tre il caso da evitare non si realizzava con nessun ruolo del
    catalogo: `roles.manage` e `tenants.view_all` stanno oggi sugli stessi due
    ruoli, quindi la terza voce si poteva far sparire solo fabbricando a mano lo
    stato con l'API di spatie (`AccessoEditorRuoliTest` fa esattamente così).
    `system.logs.view` no: è del **solo Developer**, e il Superadmin — che entra
    qui ogni giorno — è il primo utente vero che deve vedere **tre** voci e non
    quattro. Togliere il filtro non è più un difetto latente: è un 403 che il
    Superadmin incontra al primo click.

    Il filtro si fa qui **e** sulla rotta: questa è presentazione, e la
    presentazione non protegge niente (un `@can` in Blade si toglie in un
    secondo). Il permesso di ciascuna voce si legge da una **costante**, mai da
    una stringa ribattuta: due copie dello stesso nome sono due occasioni di
    gatare la pagina su un permesso e il menù su un altro. ⚠️ Le costanti non
    sono però omogenee, e va detto invece di lasciarlo dedurre: la terza e la
    quarta sono del proprio componente (`EditorRuoli::PERMESSO`,
    `Errori::PERMESSO`), le prime due sono della **porta di tenancy**
    (`VistaPiattaforma::PERMESSO`), perché né `Cabina` né `RegistroAudit` ne
    dichiarano una — è la porta a custodire il loro permesso.
--}}
@php
    $voci = collect([
        ['rotta' => 'piattaforma.index', 'etichetta' => 'Clienti', 'permesso' => App\Support\Tenancy\VistaPiattaforma::PERMESSO],
        ['rotta' => 'piattaforma.audit', 'etichetta' => 'Registro di audit', 'permesso' => App\Support\Tenancy\VistaPiattaforma::PERMESSO],
        ['rotta' => 'piattaforma.ruoli', 'etichetta' => 'Ruoli e permessi', 'permesso' => App\Livewire\Piattaforma\EditorRuoli::PERMESSO],
        ['rotta' => 'piattaforma.errori', 'etichetta' => 'Errori', 'permesso' => App\Livewire\Piattaforma\Errori::PERMESSO],
    ])->filter(fn (array $voce) => auth()->user()?->can($voce['permesso']));
@endphp

<nav class="flex gap-1 border-b border-neutral-200" aria-label="Sezioni della piattaforma">
    @foreach ($voci as $voce)
        {{-- ⚠️ Anche le **sotto-rotte** della voce, non solo la sua: da S6 la
             quarta voce ha una scheda propria (`piattaforma.errori.mostra`), e
             col solo nome esatto la sub-nav non evidenzierebbe nulla mentre si
             legge un errore — cioè la pagina direbbe di non stare in nessuna
             sezione. Il jolly non tocca le altre tre, che di sotto-rotte non ne
             hanno. --}}
        @php $attiva = request()->routeIs($voce['rotta'], $voce['rotta'].'.*'); @endphp
        <a href="{{ route($voce['rotta']) }}"
           @if ($attiva) aria-current="page" @endif
           class="-mb-px border-b-2 px-3 py-2 text-sm font-medium {{ $attiva
               ? 'border-primary-600 text-primary-700'
               : 'border-transparent text-neutral-600 hover:border-neutral-300 hover:text-neutral-800' }}">
            {{ $voce['etichetta'] }}
        </a>
    @endforeach
</nav>
