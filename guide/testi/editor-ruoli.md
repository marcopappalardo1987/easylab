<!-- Testi SCRITTI, guida INTERNA. Vedi primo-accesso.md per il formato. -->

## premessa

Una riga per permesso, una colonna per ruolo: la matrice dice chi può fare che
cosa in tutto il prodotto. Si modifica a runtime e vale subito, il che la rende
la pagina più potente della piattaforma — e quella su cui conviene sapere
esattamente che cosa si sta facendo.

## capitolo 1

La matrice, e che cosa succede accendendo una cella.

## 1

Ogni riga è un permesso — creare uno strumento, vedere le garanzie ricambio,
leggere il registro — e ogni colonna un ruolo. La cella dice se quel ruolo ha
quel permesso, e insieme le celle sono l'intero sistema di autorizzazioni.

## 2

Accendere o spegnere una cella ha effetto **subito**, per tutti quelli che hanno
quel ruolo, su tutti i clienti. Non c'è un rilascio di mezzo e non c'è
un'approvazione: è la ragione per cui questa pagina merita rispetto.

## 3

Ed è anche il motivo per cui alcune celle non si toccano affatto: senza un
freno, un clic distratto potrebbe aprire dati a un ruolo che per contratto non
deve vederli.

## capitolo 2

I permessi bloccati sono vincoli, non dimenticanze.

## 4

Un permesso «bloccato» non è ridistribuibile dall'interfaccia. Sono i vincoli di
privacy e di sicurezza del prodotto: le cose su cui il progetto ha deciso una
volta e non vuole che si decida di nuovo per sbaglio in un pomeriggio.

## 5

Il caso da tenere a mente è il permesso di leggere gli errori dell'applicazione:
è del solo Developer, e resta tale anche per chi amministra la piattaforma. Se
fosse accendibile da qui, il gate più stretto del prodotto sarebbe una casella.

## 6

Cambiarli si può, ma passando dalla configurazione e da un rilascio — cioè da
qualcuno che se ne assume la responsabilità, con una traccia in un commit. La
differenza fra i due percorsi non è tecnica, è di responsabilità.

## capitolo 3

Le celle «personalizzato» e i permessi orfani.

## 7

Una cella marcata «personalizzato» dice che il database e la configurazione non
concordano, e mostra **i due valori affiancati**: quello che vale adesso e
quello che tornerebbe riseminando. C'è anche un interruttore per vedere solo le
differenze.

## 8

È il posto in cui si decide se riseminare, ed è una decisione seria:
riseminare riporta tutto alla configurazione e cancella ogni personalizzazione,
in entrambe le direzioni — un permesso concesso qui sparisce, uno revocato qui
torna. Un cliente a cui era stato tolto qualcosa per contratto se lo
ritroverebbe.

## 9

In fondo alla pagina ci sono i permessi **orfani**: righe che il codice non usa
più ma che restano attaccate a qualche ruolo, perché il seeder non cancella
quello che non conosce. Non fanno danno, ma vanno tolte a mano, e questa striscia
serve appunto a non dimenticarsene.
