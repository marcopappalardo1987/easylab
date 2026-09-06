<x-mail::message :marchio="$marchio" :logo="$marchio->cid($message ?? null)">
# Il tuo account Easy Lab è stato chiuso

L'account **{{ $ragioneSociale !== '' ? $ragioneSociale : 'associato a questo indirizzo' }}** è stato chiuso e i dati che conteneva sono stati eliminati.

Se era attivo un abbonamento, è stato **interrotto**: non ci saranno altri addebiti. L'eventuale periodo già pagato non viene rimborsato automaticamente — se pensi ti spetti, scrivici.

{{-- ⛔ Detto per esteso e senza attenuanti: chi legge deve sapere che non c'è
     una finestra di ripensamento da cercare. Lasciarlo intendere sarebbe
     peggio di dirlo, perché lo scoprirebbe provando. --}}
**L'operazione non è reversibile**: strumenti, interventi, documenti e storico non sono recuperabili, e non esiste un ripristino.

Se non te lo aspettavi, rispondi a questa email: è possibile che ci sia stato un errore, e prima ci scrivi più è probabile che si possa fare qualcosa.
</x-mail::message>
