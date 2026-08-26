<?php

/**
 * 🔴 Meta-test delle **superfici**: nessuna vista usa più una tonalità di
 * **scala** per una superficie o per il testo — solo i token **semantici**
 * (🔗 `docs/Design/Design System Base.md` §8.1/§8.4/§8.5, ADR-034).
 *
 * ## Il guasto che questo file esiste per rendere rumoroso
 *
 * 🔴 **Una vista scritta fra sei mesi con `bg-white` torna invisibile in tema
 * scuro, e nessuno lo scopre finché non capita a un cliente.** Il markup è
 * giusto, la classe esiste davvero, Tailwind la genera, la pagina risponde 200 —
 * e la card è bianca sul fondo blu notte, col testo chiaro sopra. È la stessa
 * forma di tutte le altre trappole di colore di questo progetto, ma con
 * l'aggravante che qui **niente è rotto**: `bg-white` è una classe legittima,
 * solo non è più la classe giusta.
 *
 * La regola di ADR-034 è una sola frase: *le scale sono la palette, i semantici
 * dicono a quale gradino attinge ogni ruolo, e solo i secondi cambiano col
 * tema*. `bg-surface`, non `bg-white`. `text-ink`, non `text-neutral-800`.
 * `border-border`, non `border-neutral-200`.
 *
 * ⚠️ **E non si usa la variante `dark:`**: sarebbero ~1.190 varianti da tenere
 * allineate a mano su cinquanta file, e la prima dimenticata dà testo nero su
 * fondo nero. *La duplicazione non si gestisce: non si crea.*
 *
 * ## `DA_MIGRARE`: perché una rete nasce rossa e cosa ci si fa
 *
 * Questa rete nasce il 26 Ago 2026, **prima** che le viste migrino (F2–F5 del
 * restyling), e nascere prima è il punto: scritta dopo, avrebbe certificato il
 * lavoro invece di guidarlo, e ogni file migrato per ultimo sarebbe stato
 * migrato senza rete. Al momento della nascita **51 file** usano classi di
 * scala, quindi la lista `DA_MIGRARE` li tiene fuori dal controllo *uno per
 * uno*.
 *
 * **Ogni task di F2–F5 toglie i propri file dalla lista**, e da quel momento
 * quel file **non può più regredire**. Tre asserzioni tengono onesta la lista, e
 * ognuna chiude un modo diverso di renderla muta:
 *
 *  1. un file **non** in lista non contiene nessuna classe di scala — è il
 *     controllo vero, quello che impedisce la regressione;
 *  2. la lista **non nomina file inesistenti** — un rename o una cancellazione
 *     la renderebbero *muta* invece che *rossa*, cioè toglierebbe la protezione
 *     senza dirlo;
 *  3. un file in lista dev'essere **davvero ancora sporco** — se è già pulito,
 *     chi l'ha migrato ha dimenticato di toglierlo, e finché resta lì dentro può
 *     tornare indietro senza che nessuno se ne accorga.
 *
 * E una quarta: la lista **non è vuota** finché F6.3 non lo dichiara, così il
 * giorno in cui si svuota qualcuno deve venire qui a scriverlo invece di
 * scoprirlo per caso. *Il valore della lista è che rende il progresso una
 * quantità misurabile invece di una sensazione.*
 *
 * ## Ciò che questo file NON copre, e va detto
 *
 * - **Non guarda i valori né il contrasto.** Una vista può usare tutti i token
 *   giusti e restare illeggibile: `text-ink-3` su `bg-surface-sunken` passa di
 *   qui indisturbato. Il contrasto nei due temi resta una verifica **visiva e
 *   manuale** (DS §8.5), e ADR-034 lo mette fra le proprie conseguenze.
 * - **Non vede la variante `dark:`**, che DS §8.1 vieta. Oggi ne esiste ancora
 *   in `welcome.blade.php`; il divieto non ha una rete propria, ed è un buco
 *   dichiarato — chi lo chiuderà troverà qui il posto giusto.
 * - **Non vede un colore scritto in CSS a mano** (`style="background:#fff"` o un
 *   `background-color` dentro `app.css`): legge classi Tailwind, non stili. È
 *   già successo — `@utility tabella-a-card` aveva `background-color: white` — e
 *   a trovarlo è stata una rilettura, non un test.
 * - **Non vede un token di scala usato attraverso un valore arbitrario**
 *   (`bg-[--color-neutral-200]`): la parentesi quadra esce dal rilevatore.
 * - **Le famiglie sono derivate da `@theme`**, quindi una famiglia che sparisse
 *   dal tema sparirebbe anche da qui. È il prezzo della derivazione, pagato
 *   volentieri: la copia scritta a mano diverge sempre.
 */

/**
 * I file che **non sono ancora stati migrati** allo strato semantico.
 *
 * ⚠️ **Questa lista si accorcia, mai si allunga.** Aggiungere una riga qui per
 * far passare la suite significa spegnere la rete sul file in cui si sta
 * lavorando — cioè esattamente il gesto contro cui la rete esiste. Se un file
 * nuovo usa classi di scala, la risposta è tokenizzarlo, non elencarlo.
 *
 * ⚠️ `welcome.blade.php` è la pagina di benvenuto di Laravel e **nessuna rotta
 * la serve**, ma resta in lista di proposito: «si migra o si cancella» è una
 * decisione, e una decisione va presa: esentarla sarebbe evitarla per sempre.
 *
 * @var list<string> percorsi relativi alla radice del progetto
 */
const DA_MIGRARE = [
    'resources/views/auth/confirm-password.blade.php',
    'resources/views/auth/forgot-password.blade.php',
    'resources/views/auth/imposta-password-invito.blade.php',
    'resources/views/auth/login.blade.php',
    'resources/views/auth/reset-password.blade.php',
    'resources/views/auth/two-factor-challenge.blade.php',
    'resources/views/auth/verify-email.blade.php',
    'resources/views/bloccato.blade.php',
    'resources/views/components/app/nav-link.blade.php',
    'resources/views/components/guest-layout.blade.php',
    'resources/views/components/layouts/app.blade.php',
    'resources/views/components/piattaforma/nav.blade.php',
    'resources/views/components/ui/badge.blade.php',
    'resources/views/components/ui/button.blade.php',
    'resources/views/components/ui/card.blade.php',
    'resources/views/components/ui/combobox.blade.php',
    'resources/views/components/ui/input.blade.php',
    'resources/views/components/ui/modal.blade.php',
    'resources/views/components/ui/obsoleto.blade.php',
    'resources/views/components/ui/semaforo-forzato.blade.php',
    'resources/views/components/ui/semaforo.blade.php',
    'resources/views/components/ui/stat-tile.blade.php',
    'resources/views/components/ui/textarea.blade.php',
    'resources/views/livewire/anagrafica/albero.blade.php',
    'resources/views/livewire/campo/home.blade.php',
    'resources/views/livewire/dashboard/home.blade.php',
    'resources/views/livewire/fornitori/elenco-fornitori.blade.php',
    'resources/views/livewire/notifiche/campanella.blade.php',
    'resources/views/livewire/piattaforma/cabina.blade.php',
    'resources/views/livewire/piattaforma/editor-ruoli.blade.php',
    'resources/views/livewire/piattaforma/errori.blade.php',
    'resources/views/livewire/piattaforma/registro-audit.blade.php',
    'resources/views/livewire/piattaforma/scheda-errore.blade.php',
    'resources/views/livewire/ricambi/ricerca-ricambi.blade.php',
    'resources/views/livewire/settings/preferenze-notifiche.blade.php',
    'resources/views/livewire/settings/two-factor-authentication.blade.php',
    'resources/views/livewire/strumenti/_documenti.blade.php',
    'resources/views/livewire/strumenti/_form-fields.blade.php',
    'resources/views/livewire/strumenti/_garanzie.blade.php',
    'resources/views/livewire/strumenti/_interventi.blade.php',
    'resources/views/livewire/strumenti/_panoramica.blade.php',
    'resources/views/livewire/strumenti/_ricambi.blade.php',
    'resources/views/livewire/strumenti/_tabs.blade.php',
    'resources/views/livewire/strumenti/elenco-strumenti.blade.php',
    'resources/views/livewire/strumenti/import-strumenti.blade.php',
    'resources/views/livewire/strumenti/modelli-strumenti.blade.php',
    'resources/views/livewire/strumenti/scheda-strumento.blade.php',
    'resources/views/livewire/strumenti/stampa-qr.blade.php',
    'resources/views/livewire/tenancy/switcher-ente.blade.php',
    'resources/views/vendor/livewire/tailwind.blade.php',
    'resources/views/welcome.blade.php',
];

/**
 * Le superfici che restano **legittimamente** su token di scala, per nome e con
 * la ragione scritta accanto (🔗 DS §8.4, ADR-034).
 *
 * ⚠️ **L'esenzione è la più stretta possibile**, e la forma lo dice: `classi`
 * elenca le sole classi esentate *in quel file*, non esenta il file. Il banner
 * di impersonation vive dentro `app.blade.php`, che F3 migrerà per intero:
 * esentare il file avrebbe spento la rete su tutta la top bar e su tutta la
 * sidebar per proteggere tre righe.
 *
 * ⚠️ **`ancora` è ciò che impedisce a un'esenzione di sopravvivere a ciò che
 * esenta.** Se il banner traslocasse in un componente suo, la stringa non si
 * troverebbe più e l'esenzione diventerebbe rossa invece di restare lì a
 * coprire, per sempre, tre classi di un altro pezzo di markup.
 *
 * ⚠️ **`resources/views/pdf/` e `resources/views/mail/` sono esenzioni di
 * cartella, e oggi non sopprimono NIENTE**: il PDF porta il proprio CSS in un
 * `<style>` inline (che il rilevatore toglie) e le email sono componenti
 * Markdown senza classi. Restano perché la regola è del **bersaglio di
 * rendering** e non di un file: qualunque file nasca lì dentro sarà servito da
 * dompdf o da un client di posta, e nessuno dei due ha un tema. È dichiarato
 * apposta: un'esenzione che si crede al lavoro e non lo è vale meno di una
 * dichiarata inerte.
 *
 * ⚠️ **Il toast e il tooltip di DS §8.4 non sono qui perché non esistono ancora**
 * in questo repository — e un'esenzione scritta in anticipo per un file che non
 * c'è sarebbe rossa dal primo giorno (si veda il test «never lets an exemption
 * survive what it exempts»). Quando nasceranno, il loro posto è questo: restano
 * su token di **scala** (`neutral-900` in chiaro, `neutral-700` in scuro) perché
 * galleggiano sopra la pagina e non sono superfici della pagina — su fondo scuro
 * `neutral-900` sparirebbe dentro `--bg`. Chi li aggiunge qui aggiorna anche la
 * tabella di DS §8.4, che li elenca già.
 *
 * @return list<array{percorso: string, classi: list<string>, ancora: ?string, perche: string}>
 */
function esenzioniDiSuperficie(): array
{
    return [
        [
            // 🔗 DS §5.8 e §8.4. Il campione scrive `background:var(--warning-500);
            // color:var(--neutral-900)` — scale, NON semantici, di proposito: è un
            // allarme persistente e deve avere lo **stesso identico aspetto** nei
            // due temi, o smette di essere lo stesso segnale. Un banner che nel
            // tema scuro si ammorbidisce è un banner che si smette di vedere.
            // `bg-neutral-900` è il pulsante «Esci» dentro il banner, alle opacità
            // /10 e /20: sta sul giallo, non sulla superficie della pagina.
            'percorso' => 'resources/views/components/layouts/app.blade.php',
            'classi' => ['bg-warning-500', 'text-neutral-900', 'bg-neutral-900'],
            'ancora' => "app('impersonate')->isImpersonating()",
            'perche' => 'banner di impersonation: identico nei due temi, o non è più lo stesso segnale (DS §5.8)',
        ],
        [
            // 🔗 ADR-031. dompdf interpreta un sottoinsieme di CSS e non conosce
            // né Tailwind né le variabili del tema: il PDF porta hex a mano ed è
            // sempre chiaro. Un foglio A4 non ha un tema.
            'percorso' => 'resources/views/pdf/',
            'classi' => ['*'],
            'ancora' => null,
            'perche' => 'dompdf non vede Tailwind né il tema: hex a mano, sempre chiaro (ADR-031)',
        ],
        [
            // Nessun client di posta ha un tema affidabile, e un'email esce
            // dall'applicazione: il contesto in cui verrà letta non è nostro.
            'percorso' => 'resources/views/mail/',
            'classi' => ['*'],
            'ancora' => null,
            'perche' => 'nessun client di posta ha un tema affidabile: le email restano chiare (DS §8.4)',
        ],
    ];
}

/**
 * Le classi di **scala** usate per una superficie o per il testo in un sorgente.
 *
 * Restituisce la classe **con le sue varianti** (`hover:bg-neutral-50`), perché
 * è la stringa che chi corregge deve cercare nel file.
 *
 * ⚠️ **Le varianti si catturano invece di essere saltate**, e serve a una cosa
 * sola: riconoscere `print:`. Le utility di stampa restano su `bg-white` di
 * proposito — la stampa è **sempre chiara** (DS §8.4) — quindi `print:bg-white`
 * è corretto e `bg-white` no, sulla stessa riga dello stesso file. Un rilevatore
 * che partisse dopo i due punti non saprebbe distinguerli.
 *
 * ⚠️ **Il `(?<![\w:.\/-])` in testa è ciò che obbliga a consumare tutta la
 * catena di varianti**: senza, il motore potrebbe cominciare a leggere da `bg`
 * dentro `print:bg-white` e l'esenzione della stampa non scatterebbe mai.
 *
 * ⚠️ `white` e `black` non sono famiglie: sono i due colori **letterali** di
 * Tailwind, e `bg-white` è il caso singolo più frequente di tutta la migrazione
 * (card, modali, dropdown, top bar). Vanno cercati per nome.
 *
 * @param  list<string>  $famiglie  le famiglie di scala, derivate da `@theme`
 * @return list<string>
 */
function classiDiScalaNelSorgente(string $sorgente, array $famiglie): array
{
    if ($famiglie === []) {
        return [];
    }

    // Le stesse due potature del guardrail della palette, per le stesse due
    // ragioni: un foglio di stile inlinato non è markup, e un commento che
    // *nomina* una classe per spiegare perché non si usa più non è un uso.
    $sorgente = sorgenteSenzaStileNeCommenti($sorgente);

    $nomi = implode('|', array_map(fn (string $f) => preg_quote($f, '/'), $famiglie));

    preg_match_all(
        '/(?<![\w:.\/-])((?:[a-z0-9_-]+(?:\[[^\]\s]*\])?:)*)('.prefissiDiColore().')-((?:'.$nomi.')-\d{2,3}|white|black)(?![\w-])/',
        $sorgente,
        $trovate,
        PREG_SET_ORDER
    );

    $classi = [];

    foreach ($trovate as [, $varianti, $utility, $colore]) {
        if (str_contains($varianti, 'print:')) {
            continue;
        }

        $classi[] = $varianti.$utility.'-'.$colore;
    }

    sort($classi);

    return array_values(array_unique($classi));
}

/** La classe senza le sue varianti: `md:hover:bg-neutral-50` → `bg-neutral-50`. */
function classeSenzaVarianti(string $classe): string
{
    return substr($classe, strrpos($classe, ':') === false ? 0 : strrpos($classe, ':') + 1);
}

/**
 * Le classi di scala che restano dopo aver applicato le esenzioni di §8.4.
 *
 * @return array<string, list<string>> percorso relativo → classi
 */
function superficiNonTokenizzate(): array
{
    $famiglie = famiglieDelTema();
    $esenzioni = esenzioniDiSuperficie();
    $fuori = [];

    foreach (sorgentiDiStile() as $file) {
        $relativo = str_replace(base_path().'/', '', $file);
        $classi = classiDiScalaNelSorgente(file_get_contents($file), $famiglie);

        foreach ($esenzioni as $esenzione) {
            // `str_starts_with` copre in un colpo il file singolo e la cartella:
            // il percorso di una cartella finisce con `/`, quindi non può
            // agganciare per sbaglio un file che comincia con lo stesso nome.
            if (! str_starts_with($relativo, $esenzione['percorso'])) {
                continue;
            }

            $classi = array_values(array_filter(
                $classi,
                fn (string $c) => $esenzione['classi'] !== ['*']
                    && ! in_array(classeSenzaVarianti($c), $esenzione['classi'], true),
            ));
        }

        if ($classi !== []) {
            $fuori[$relativo] = $classi;
        }
    }

    ksort($fuori);

    return $fuori;
}

it('finds scale classes somewhere, so the comparison cannot pass for being empty', function () {
    // 🔴 Tutta la rete poggia su una regex e su `famiglieDelTema()`. Se una delle
    // due smettesse di trovare qualcosa, «nessun file migrato usa una scala»
    // diventerebbe `[] ⊆ []`, cioè verde per sempre — e verde proprio mentre le
    // viste tornano a riempirsi di `bg-white`. È la stessa rete che
    // `PaletteGuardrailTest` e `SorgentiTailwindGuardrailTest` mettono davanti
    // ai propri insiemi derivati.
    expect(famiglieDelTema())->toContain('neutral', 'primary', 'danger')
        ->and(superficiNonTokenizzate())->not->toBeEmpty();
});

it('never lets a migrated view go back to a scale colour', function () {
    // 🔴 **Il controllo vero.** Un file uscito da `DA_MIGRARE` non può tornare
    // indietro: da lì in poi un `bg-white` scritto per abitudine è rosso lo
    // stesso giorno, invece che scoperto da un cliente in tema scuro fra sei
    // mesi.
    $regressioni = collect(superficiNonTokenizzate())->except(DA_MIGRARE);

    expect($regressioni->keys()->all())->toBe([], $regressioni->isEmpty() ? '' :
        "Classi di SCALA usate per una superficie o per il testo in file già tokenizzati.\n\n".
        "⚠️ Non danno errore: la classe esiste, Tailwind la genera, la pagina risponde 200 — e in tema\n".
        "scuro la superficie resta bianca col testo chiaro sopra, cioè illeggibile. È la trappola che\n".
        "ADR-034 chiama «un token definito solo nel chiaro», vista dal lato della vista.\n\n".
        "Rimedio: la tabella di DS §8.2 dà la corrispondenza, ruolo per ruolo. In sintesi:\n".
        "  · `bg-white` → `bg-surface`      · `bg-neutral-50` → `bg-canvas` o `bg-surface-sunken`\n".
        "  · `text-neutral-900/800` → `text-ink`   · `text-neutral-600` → `text-ink-2`\n".
        "  · `text-neutral-500/400` → `text-ink-3` · `border-neutral-200` → `border-border`\n".
        "  · `bg-primary-600` → `bg-brand`   · `text-white` sul pieno → `text-ink-inverse`\n".
        "  · semaforo: `text-success-500` → `text-ok-dot`, `bg-danger-100` → `bg-bad-soft`, ecc.\n\n".
        "⛔ E NON si aggiunge il file a `DA_MIGRARE` per far passare la suite: quella lista si\n".
        "accorcia, mai si allunga. Se la superficie è una delle eccezioni di DS §8.4 (banner, PDF,\n".
        "email, stampa), va in `esenzioniDiSuperficie()` **per nome e con la ragione scritta**.\n\n".
        'Trovate: '.$regressioni->map(fn (array $classi, string $file) => $file.' → '.implode(' ', $classi))->implode("\n           ")
    );
});

it('never lets DA_MIGRARE name a file that does not exist', function () {
    // ⚠️ **Una voce morta rende la lista muta, non rossa.** Un file rinominato o
    // cancellato lascia qui una riga che non protegge più niente e che nessuno
    // rileggerà mai: la rete continuerebbe a dirsi soddisfatta mentre la
    // copertura si assottiglia da sola.
    $fantasmi = collect(DA_MIGRARE)->reject(fn (string $file) => file_exists(base_path($file)))->values();

    expect($fantasmi->all())->toBe([],
        "Voci di `DA_MIGRARE` che non corrispondono a nessun file.\n\n".
        'Un file rinominato o cancellato va tolto dalla lista: finché resta, è una riga che non '.
        'protegge nulla e che fa sembrare la migrazione più indietro di quanto sia.'
    );
});

it('never lets DA_MIGRARE keep a file that is already clean', function () {
    // 🔴 Il verso opposto, ed è quello che tiene la lista **al passo**: un file
    // già tokenizzato e ancora elencato è un file su cui la rete è spenta. Non
    // rompe nulla oggi; rompe fra sei mesi, quando qualcuno ci riscriverà dentro
    // `bg-white` e la lista lo starà ancora coprendo.
    //
    // ⚠️ È anche il modo in cui questa lista *si accorcia da sola*: chi migra un
    // file non deve **ricordarsi** di toglierlo — la suite glielo chiede.
    $sporchi = array_keys(superficiNonTokenizzate());
    $puliti = collect(DA_MIGRARE)
        ->filter(fn (string $file) => file_exists(base_path($file)) && ! in_array($file, $sporchi, true))
        ->values();

    expect($puliti->all())->toBe([],
        "File elencati in `DA_MIGRARE` che non usano più nessuna classe di scala.\n\n".
        "Sono già migrati: la riga va tolta dalla lista, ed è il gesto che li mette sotto rete. Finché\n".
        'restano lì dentro possono regredire senza che nessuno se ne accorga.'
    );
});

it('keeps DA_MIGRARE non-empty until F6.3 says the migration is over', function () {
    // ⚠️ **Il giorno in cui la lista si svuota, qualcuno deve venire qui a
    // dirlo.** Senza questo test la fine della migrazione sarebbe un non-evento:
    // la lista arriva a zero, i due controlli qui sopra restano verdi per vuoto,
    // e nessuno registra che da quel momento **l'intero repository** è sotto
    // rete — che è invece la notizia. F6.3 cancella questo test e la costante
    // insieme.
    expect(DA_MIGRARE)->not->toBeEmpty();
});

it('never lets an exemption survive what it exempts', function () {
    // ⚠️ **Un'esenzione è un buco nella rete, e un buco va tenuto attaccato alla
    // ragione per cui esiste.** Se il banner traslocasse in un componente suo,
    // queste tre classi resterebbero esentate in `app.blade.php` per sempre — e
    // il file è il layout, cioè il posto in cui `text-neutral-900` ricompare più
    // facilmente.
    $morte = [];

    foreach (esenzioniDiSuperficie() as $esenzione) {
        $assoluto = base_path($esenzione['percorso']);

        if (! file_exists($assoluto)) {
            $morte[] = $esenzione['percorso'].' — il percorso non esiste più';

            continue;
        }

        if ($esenzione['ancora'] === null) {
            continue;
        }

        // Un'esenzione per classi vuole due prove: che il markup che la giustifica
        // sia ancora lì (`ancora`), e che almeno una delle classi esentate sia
        // ancora usata — altrimenti sta coprendo un pezzo di file che non c'è più.
        $sorgente = file_get_contents($assoluto);
        $usate = classiDiScalaNelSorgente($sorgente, famiglieDelTema());

        if (! str_contains($sorgente, $esenzione['ancora'])) {
            $morte[] = $esenzione['percorso'].' — non contiene più `'.$esenzione['ancora'].'`';
        }

        $ancoraEsentate = collect($usate)
            ->map(fn (string $c) => classeSenzaVarianti($c))
            ->intersect($esenzione['classi']);

        if ($ancoraEsentate->isEmpty()) {
            $morte[] = $esenzione['percorso'].' — nessuna delle classi esentate ('.implode(', ', $esenzione['classi']).') è ancora usata';
        }
    }

    expect($morte)->toBe([],
        "Esenzioni di `esenzioniDiSuperficie()` che non coprono più ciò per cui sono state scritte.\n\n".
        "⚠️ Un'esenzione sopravvissuta al proprio markup è un buco nella rete che nessuno rileggerà.\n".
        'Va tolta da qui **e** dalla tabella di DS §8.4, che le elenca per nome.'
    );
});

it('tells a print utility from a page surface, proved on synthetic sources', function () {
    // 🔴 Il guardrail del guardrail: il rilevatore si prova su sorgenti
    // sintetici, non sperando che il repository contenga i casi giusti. È la
    // forma di `PaletteGuardrailTest` e di `ScrittureRbacGuardrailTest`.
    $famiglie = ['neutral', 'primary', 'danger'];

    // Ciò che DEVE essere visto.
    expect(classiDiScalaNelSorgente('<div class="bg-white">x</div>', $famiglie))->toBe(['bg-white'])
        ->and(classiDiScalaNelSorgente('<p class="text-white">x</p>', $famiglie))->toBe(['text-white'])
        ->and(classiDiScalaNelSorgente('<div class="bg-black">x</div>', $famiglie))->toBe(['bg-black'])
        ->and(classiDiScalaNelSorgente('<p class="text-neutral-800">x</p>', $famiglie))->toBe(['text-neutral-800'])
        ->and(classiDiScalaNelSorgente('<div class="border-t-neutral-200">x</div>', $famiglie))->toBe(['border-t-neutral-200'])
        ->and(classiDiScalaNelSorgente('<ul class="divide-neutral-100">x</ul>', $famiglie))->toBe(['divide-neutral-100'])
        // Le varianti restano attaccate alla classe: è la stringa da cercare nel file.
        ->and(classiDiScalaNelSorgente('<a class="hover:bg-primary-700">x</a>', $famiglie))->toBe(['hover:bg-primary-700'])
        ->and(classiDiScalaNelSorgente('<a class="md:focus:ring-danger-500">x</a>', $famiglie))->toBe(['md:focus:ring-danger-500'])
        ->and(classiDiScalaNelSorgente('<div class="bg-neutral-900/10">x</div>', $famiglie))->toBe(['bg-neutral-900']);

    // 🔴 Il caso che giustifica la cattura delle varianti: sulla stessa riga,
    // `print:bg-white` è CORRETTO (la stampa è sempre chiara, DS §8.4) e
    // `bg-white` no. Un rilevatore che leggesse dopo i due punti li confonderebbe.
    expect(classiDiScalaNelSorgente('<body class="bg-white print:bg-white">x</body>', $famiglie))->toBe(['bg-white'])
        ->and(classiDiScalaNelSorgente('<body class="print:bg-white print:text-neutral-900">x</body>', $famiglie))->toBe([]);

    // Ciò che NON deve essere visto.
    expect(classiDiScalaNelSorgente('<style>.bg-white{background:#fff}</style>', $famiglie))->toBe([])
        ->and(classiDiScalaNelSorgente('{{-- non usare più `bg-white`, si usa `bg-surface` --}}', $famiglie))->toBe([])
        // I token semantici, che sono la risposta e non il problema.
        ->and(classiDiScalaNelSorgente('<div class="bg-surface text-ink border-border">x</div>', $famiglie))->toBe([])
        // Una famiglia che non è nostra, e le utility che portano un numero.
        ->and(classiDiScalaNelSorgente('<p class="text-neutrale-500 text-3xl divide-y-2">x</p>', $famiglie))->toBe([])
        // ⚠️ **Dichiarato fra ciò che non copre**: il valore arbitrario esce dal
        // rilevatore. Il caso è qui per rendere il buco falsificabile invece che
        // opinabile — se un domani lo si volesse chiudere, questa riga diventa la
        // prima a cambiare.
        ->and(classiDiScalaNelSorgente('<div class="bg-[--color-neutral-200]">x</div>', $famiglie))->toBe([]);
});
