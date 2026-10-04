{{-- Testo utente: sempre da TestoMarkdown::sicuro(), che neutralizza link e tabelle (D-T1c-1).
     La ragione sociale la scrive un operatore in cabina e l'etichetta del piano
     chi governa il listino: non sono il pubblico, ma sono testo libero, e la
     regola non fa eccezioni per provenienza. --}}
@use('App\Support\Mail\TestoMarkdown')
<x-mail::message :marchio="$marchio" :logo="$marchio->cid($message ?? null)">
# Un piano da attivare

Per **{{ TestoMarkdown::sicuro($ragioneSociale) }}** Easy Lab ha previsto il piano **{{ TestoMarkdown::sicuro($piano) }}**, che oggi costa **{{ $prezzo }} € al mese**.

Il piano parte quando lo attivi: fino ad allora l'account resta sul piano con cui è nato.

<x-mail::button :url="$url" :colore="$marchio->colore" :colore-testo="$marchio->coloreTesto">
Attiva il piano
</x-mail::button>

Il pagamento avviene su Stripe, dalla pagina **Abbonamento** del tuo account. Tieni a portata di mano la **partita IVA**: al pagamento è richiesta.

{{-- ⚠️ Detto qui perché chi nasce dalla cabina non ha ancora una password: senza
     questa riga il bottone sopra porta a un login da cui non si entra. --}}
Se è il tuo primo accesso, imposta prima la password dall'invito che hai ricevuto a parte.
</x-mail::message>
