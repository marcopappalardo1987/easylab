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

Ogni cella è un bottone. Un clic apre una finestra di conferma, e da lì
concedere o revocare ha effetto **subito**, per tutti quelli che hanno quel
ruolo, su tutti i clienti. Non c'è un rilascio di mezzo e non c'è
un'approvazione di qualcun altro: è la ragione per cui questa pagina merita
rispetto.

## 3

La finestra di conferma non è una formalità. Il secondo fattore è obbligatorio
per **nome di ruolo**, non per permesso: oggi lo chiedono Superadmin, Admin e
Gestore. Concedere qualcosa a Tenant, Tecnico o Responsabile Reparto lo dà
anche a chi entra con la sola password, e la finestra lo scrive prima che si
prema «Concedi». Revocando, dice invece quanti utenti hanno oggi quel ruolo.

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

Ogni clic sulla matrice resta nel registro di audit, col nome di chi l'ha
fatto, ed è reversibile con un altro clic. I blocchi e il catalogo dei permessi
invece non si cambiano da qui: si passa dalla configurazione e da un rilascio,
cioè da qualcuno che se ne assume la responsabilità, con una traccia in un
commit. La differenza fra i due percorsi non è tecnica, è di responsabilità.

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

In fondo alla pagina c'è la striscia dei permessi **non più nel catalogo**:
righe che esistono a database ma che `config/rbac.php` non dichiara più, e che
restano attaccate a qualche ruolo. Riseminare non le cancella, perché il seeder
non toglie ciò che non conosce: le stacca dai ruoli e le lascia lì, senza
nessuno che le porti. Vanno rimosse a mano, e questa striscia serve appunto a
non dimenticarsene.
