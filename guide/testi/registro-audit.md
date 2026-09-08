<!-- Testi SCRITTI, guida INTERNA. Vedi primo-accesso.md per il formato. -->

## premessa

Il registro raccoglie le scritture di dominio: chi ha creato, modificato o
cancellato che cosa, e quando. Non è un log tecnico e non serve a sorvegliare le
persone — serve il giorno in cui due ricordi non coincidono, e quel giorno arriva
sempre.

## capitolo 1

Che cosa ci finisce dentro.

## 1

Ogni scrittura di dominio lascia una riga: strumenti, interventi, garanzie,
ricambi, documenti, utenti. Non ci finiscono le letture — sarebbe un volume
ingestibile — con l'eccezione degli scarichi di documenti, che sono una lettura
che vale la pena tracciare.

## 2

La differenza con un log tecnico è il destinatario: un log lo legge chi ripara,
questo lo legge chi deve **capire una decisione**. Per questo le righe parlano di
oggetti di dominio e non di query.

## 3

Ogni riga ha un autore, un soggetto e un momento; aprendola si vedono i valori
cambiati, prima e dopo. È il livello di dettaglio che serve per rispondere a «chi
ha spostato questa macchina» senza doverlo ricostruire a memoria.

## capitolo 2

L'impersonazione, e perché il timbro c'è.

## 4

Le righe scritte durante un'impersonazione portano il nome di chi c'era davvero
dietro, oltre a quello dell'utente impersonato. Sono due informazioni entrambe
vere, e servono entrambe.

## 5

Senza quel timbro il registro direbbe una cosa **falsa**: che un gesto nostro è
stato del cliente. Su un registro di sicurezza un'attribuzione sbagliata è
peggio di un'attribuzione mancante, perché nessuno la mette in dubbio.

## 6

Vale per ciò che succede dentro una richiesta. Quello che parte in coda, da
console o da un webhook non ha una sessione, quindi non ha timbro — ed è
dichiarato in pagina invece di essere ricostruito con un'euristica, che sarebbe
di nuovo un'attribuzione inventata.

## capitolo 3

Quello che il registro non fa, e che è la sua forza.

## 7

Non si modifica e non si cancella. È l'unica proprietà che lo rende utile: un
registro che si può correggere non dimostra niente, e nessuno perde tempo a
consultarlo.

## 8

Un soggetto cestinato resta nominato: la riga dice «non più presente» invece di
sparire. Cancellare le righe di ciò che non esiste più sarebbe il modo più
elegante di perdere esattamente la storia che serve.

## 9

L'attribuzione è affidabile a partire da una data dichiarata in pagina. Prima di
quella il timbro dell'impersonazione non c'era, e lo storico è quello che è:
dirlo è meglio che lasciar credere che valga per tutto.
