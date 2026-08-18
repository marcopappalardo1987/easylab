<?php

/*
|--------------------------------------------------------------------------
| Messaggi di validazione in italiano
|--------------------------------------------------------------------------
|
| Il progetto scrive ogni stringa di UI in italiano hard-coded, ma la
| validazione veniva dal fallback interno del framework: fino al 9 Ago 2026
| un campo obbligatorio rispondeva «The intervento form.descrizione field is
| required.» — cioè in inglese e con la struttura interna del form in bella
| vista. Non era un difetto di un form: era così in tutta l'app dalla S1, e si
| è visto solo quando un errore di validazione è comparso davvero a schermo.
|
| ⚠️ `APP_FALLBACK_LOCALE` resta **en**, di proposito: se un domani mancasse
| una chiave qui dentro, l'utente legge il messaggio inglese — degradato ma
| comprensibile — invece della chiave grezza `validation.foo`. Un fallback
| `it` trasformerebbe ogni traduzione mancante in una stringa senza senso.
|
| La sezione `attributes` in coda è la ragione per cui il messaggio è
| leggibile: senza, anche col locale corretto direbbe «Il campo intervento
| form.descrizione è obbligatorio». È l'UNICA definizione dei nomi dei campi —
| il `validationAttributes()` che viveva in SchedaStrumento è stato rimosso.
|
*/

return [

    'accepted' => 'Il campo :attribute deve essere accettato.',
    'accepted_if' => 'Il campo :attribute deve essere accettato quando :other è :value.',
    'active_url' => 'Il campo :attribute non è un URL valido.',
    'after' => 'Il campo :attribute deve essere una data successiva al :date.',
    'after_or_equal' => 'Il campo :attribute deve essere una data successiva o uguale al :date.',
    'alpha' => 'Il campo :attribute può contenere solo lettere.',
    'alpha_dash' => 'Il campo :attribute può contenere solo lettere, numeri, trattini e trattini bassi.',
    'alpha_num' => 'Il campo :attribute può contenere solo lettere e numeri.',
    'array' => 'Il campo :attribute deve essere un elenco.',
    'ascii' => 'Il campo :attribute può contenere solo caratteri alfanumerici e simboli a singolo byte.',
    'before' => 'Il campo :attribute deve essere una data precedente al :date.',
    'before_or_equal' => 'Il campo :attribute deve essere una data precedente o uguale al :date.',
    'between' => [
        'array' => 'Il campo :attribute deve contenere fra :min e :max elementi.',
        'file' => 'Il campo :attribute deve essere compreso fra :min e :max kilobyte.',
        'numeric' => 'Il campo :attribute deve essere compreso fra :min e :max.',
        'string' => 'Il campo :attribute deve contenere fra :min e :max caratteri.',
    ],
    'boolean' => 'Il campo :attribute deve essere vero o falso.',
    'confirmed' => 'La conferma del campo :attribute non corrisponde.',
    'current_password' => 'La password non è corretta.',
    'date' => 'Il campo :attribute non è una data valida.',
    'date_equals' => 'Il campo :attribute deve essere una data uguale al :date.',
    'date_format' => 'Il campo :attribute non corrisponde al formato :format.',
    'decimal' => 'Il campo :attribute deve avere :decimal cifre decimali.',
    'declined' => 'Il campo :attribute deve essere rifiutato.',
    'different' => 'Il campo :attribute e :other devono essere diversi.',
    'digits' => 'Il campo :attribute deve essere di :digits cifre.',
    'digits_between' => 'Il campo :attribute deve essere fra :min e :max cifre.',
    'dimensions' => 'Il campo :attribute ha dimensioni immagine non valide.',
    'distinct' => 'Il campo :attribute contiene un valore duplicato.',
    'doesnt_end_with' => 'Il campo :attribute non può terminare con uno dei seguenti valori: :values.',
    'doesnt_start_with' => 'Il campo :attribute non può iniziare con uno dei seguenti valori: :values.',
    'email' => 'Il campo :attribute deve essere un indirizzo email valido.',
    'ends_with' => 'Il campo :attribute deve terminare con uno dei seguenti valori: :values.',
    'enum' => 'Il valore selezionato per :attribute non è valido.',
    'exists' => 'Il valore selezionato per :attribute non è valido.',
    'extensions' => 'Il campo :attribute deve avere una di queste estensioni: :values.',
    'file' => 'Il campo :attribute deve essere un file.',
    'filled' => 'Il campo :attribute deve contenere un valore.',
    'gt' => [
        'array' => 'Il campo :attribute deve contenere più di :value elementi.',
        'file' => 'Il campo :attribute deve essere maggiore di :value kilobyte.',
        'numeric' => 'Il campo :attribute deve essere maggiore di :value.',
        'string' => 'Il campo :attribute deve contenere più di :value caratteri.',
    ],
    'gte' => [
        'array' => 'Il campo :attribute deve contenere almeno :value elementi.',
        'file' => 'Il campo :attribute deve essere maggiore o uguale a :value kilobyte.',
        'numeric' => 'Il campo :attribute deve essere maggiore o uguale a :value.',
        'string' => 'Il campo :attribute deve contenere almeno :value caratteri.',
    ],
    'image' => 'Il campo :attribute deve essere un\'immagine.',
    'in' => 'Il valore selezionato per :attribute non è valido.',
    'in_array' => 'Il campo :attribute non è presente in :other.',
    'integer' => 'Il campo :attribute deve essere un numero intero.',
    'ip' => 'Il campo :attribute deve essere un indirizzo IP valido.',
    'ipv4' => 'Il campo :attribute deve essere un indirizzo IPv4 valido.',
    'ipv6' => 'Il campo :attribute deve essere un indirizzo IPv6 valido.',
    'json' => 'Il campo :attribute deve essere una stringa JSON valida.',
    'lowercase' => 'Il campo :attribute deve essere in minuscolo.',
    'lt' => [
        'array' => 'Il campo :attribute deve contenere meno di :value elementi.',
        'file' => 'Il campo :attribute deve essere minore di :value kilobyte.',
        'numeric' => 'Il campo :attribute deve essere minore di :value.',
        'string' => 'Il campo :attribute deve contenere meno di :value caratteri.',
    ],
    'lte' => [
        'array' => 'Il campo :attribute non deve contenere più di :value elementi.',
        'file' => 'Il campo :attribute deve essere minore o uguale a :value kilobyte.',
        'numeric' => 'Il campo :attribute deve essere minore o uguale a :value.',
        'string' => 'Il campo :attribute deve contenere al massimo :value caratteri.',
    ],
    'max' => [
        'array' => 'Il campo :attribute non deve contenere più di :max elementi.',
        'file' => 'Il campo :attribute non deve superare i :max kilobyte.',
        'numeric' => 'Il campo :attribute non deve essere maggiore di :max.',
        'string' => 'Il campo :attribute non deve superare i :max caratteri.',
    ],
    'mimes' => 'Il campo :attribute deve essere un file di tipo: :values.',
    'mimetypes' => 'Il campo :attribute deve essere un file di tipo: :values.',
    'min' => [
        'array' => 'Il campo :attribute deve contenere almeno :min elementi.',
        'file' => 'Il campo :attribute deve essere di almeno :min kilobyte.',
        'numeric' => 'Il campo :attribute deve essere almeno :min.',
        'string' => 'Il campo :attribute deve contenere almeno :min caratteri.',
    ],
    'missing' => 'Il campo :attribute deve essere assente.',
    'multiple_of' => 'Il campo :attribute deve essere un multiplo di :value.',
    'not_in' => 'Il valore selezionato per :attribute non è valido.',
    'not_regex' => 'Il formato del campo :attribute non è valido.',
    'numeric' => 'Il campo :attribute deve essere un numero.',
    'present' => 'Il campo :attribute deve essere presente.',
    'prohibited' => 'Il campo :attribute non è ammesso.',
    'prohibited_if' => 'Il campo :attribute non è ammesso quando :other è :value.',
    'prohibits' => 'Il campo :attribute impedisce a :other di essere presente.',
    'regex' => 'Il formato del campo :attribute non è valido.',
    'required' => 'Il campo :attribute è obbligatorio.',
    'required_array_keys' => 'Il campo :attribute deve contenere le voci: :values.',
    'required_if' => 'Il campo :attribute è obbligatorio quando :other è :value.',
    'required_unless' => 'Il campo :attribute è obbligatorio a meno che :other non sia fra :values.',
    'required_with' => 'Il campo :attribute è obbligatorio quando :values è presente.',
    'required_with_all' => 'Il campo :attribute è obbligatorio quando :values sono presenti.',
    'required_without' => 'Il campo :attribute è obbligatorio quando :values non è presente.',
    'required_without_all' => 'Il campo :attribute è obbligatorio quando nessuno di :values è presente.',
    'same' => 'Il campo :attribute e :other devono coincidere.',
    'size' => [
        'array' => 'Il campo :attribute deve contenere :size elementi.',
        'file' => 'Il campo :attribute deve essere di :size kilobyte.',
        'numeric' => 'Il campo :attribute deve essere :size.',
        'string' => 'Il campo :attribute deve contenere :size caratteri.',
    ],
    'starts_with' => 'Il campo :attribute deve iniziare con uno dei seguenti valori: :values.',
    'string' => 'Il campo :attribute deve essere una stringa.',
    'timezone' => 'Il campo :attribute deve essere un fuso orario valido.',
    'unique' => 'Il valore del campo :attribute è già stato utilizzato.',
    'uploaded' => 'Il caricamento del campo :attribute non è riuscito.',
    'uppercase' => 'Il campo :attribute deve essere in maiuscolo.',
    'url' => 'Il campo :attribute deve essere un URL valido.',
    'uuid' => 'Il campo :attribute deve essere un UUID valido.',

    'custom' => [],

    /*
    |--------------------------------------------------------------------------
    | Nomi leggibili dei campi
    |--------------------------------------------------------------------------
    |
    | Le proprietà Livewire sono array (`interventoForm`, `garanziaForm`, …) e
    | senza queste voci il messaggio esporrebbe la chiave: «Il campo intervento
    | form.descrizione è obbligatorio». È l'unica definizione dei nomi — non
    | vanno duplicati con `validationAttributes()` nei componenti.
    |
    */
    'attributes' => [

        // Scheda strumento — anagrafica (ManagesStrumentoForm)
        'strumentoForm.nome' => 'nome',
        'strumentoForm.modello' => 'modello',
        'strumentoForm.matricola' => 'matricola',
        'strumentoForm.data_installazione' => 'data di installazione',
        'parametri.*.chiave' => 'chiave del parametro',
        'parametri.*.valore' => 'valore del parametro',

        // Interventi
        'interventoForm.descrizione' => 'descrizione',
        'interventoForm.tipo' => 'tipo',
        'interventoForm.data_scadenza' => 'data di scadenza',
        'interventoForm.tecnico_id' => 'assegnatario',
        'interventoForm.gia_eseguito' => 'già eseguito',
        'interventoForm.data_esecuzione' => 'data di esecuzione',
        'dataEsecuzione' => 'data di esecuzione',

        // Ricambi dal form intervento (ADR-022)
        'ricambiEffettuati' => 'ricambio effettuato',
        'ricambiNuovi' => 'ricambi',
        'ricambiNuovi.*.nome' => 'nome del ricambio',
        'ricambiNuovi.*.scadenza_garanzia' => 'scadenza garanzia',

        // Garanzie
        'garanziaForm.data_inizio' => 'data di inizio',
        'garanziaForm.durata_mesi' => 'durata in mesi',

        // Forzatura semaforo
        'forzaForm.stato' => 'stato',
        'forzaForm.motivo' => 'motivo',

        // Spostamenti
        'destinazioneId' => 'destinazione',
        'dataSpostamento' => 'data dello spostamento',
        'notaSpostamento' => 'nota',

        // Anagrafica e import
        'nome' => 'nome',
        'tipo' => 'tipo',
        'file' => 'file',
    ],

];
