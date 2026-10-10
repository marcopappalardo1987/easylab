<!-- Testi SCRITTI, guida INTERNA. Vedi primo-accesso.md per il formato. -->

## premessa

Gli errori dell'applicazione si raccolgono dentro Easy Lab, in una pagina che
vede il solo Developer. È una scelta che costa lavoro — un servizio esterno
sarebbe stato pronto e senza costi — e la ragione per cui è stata presa vale la pena
saperla, perché spiega anche perché quella pagina è così chiusa.

## capitolo 1

Perché non si compra.

## 1

Quando qualcosa va storto, l'eccezione viene salvata qui col suo contesto:
il messaggio, la traccia, la richiesta che l'ha provocata. Non parte verso
nessun servizio esterno e nessun servizio esterno la vede mai, il che significa
anche che se questa pagina non la guarda nessuno, nessun errore verrà notato.

## 2

Il motivo non è il prezzo: un errore contiene messaggi, input di richiesta e
identificativi di utenti dei clienti. Mandarlo fuori significherebbe mandare
fuori dati dei clienti, a un fornitore che diventerebbe sub-responsabile con
tutto quello che comporta.

## 3

Da qui la scelta di costruirlo. Il costo di farlo è un blocco di lavoro, una
volta; il costo di adottare un servizio esterno sarebbe stato permanente e
contrattuale — un DPA da negoziare, una riga in più nel registro dei
trattamenti, dati fuori dal perimetro che il resto dell'architettura difende.

## capitolo 2

Chi la vede, e perché non tutti.

## 4

È la pagina più chiusa del prodotto: la vede il solo **Developer**. Il
Superadmin, che amministra la piattaforma, gestisce i contratti e può
impersonare i clienti, qui non entra — ed è l'unico punto del prodotto in cui il
ruolo più alto sul piano commerciale non è quello che vede di più.

## 5

Non è gerarchia: è che gli errori contengono dati dei clienti, e chi non ha
bisogno di leggerli non li legge. È anche il motivo per cui il Developer non è
impersonabile — altrimenti questo gate sarebbe aggirabile in due clic.

## capitolo 3

Come si legge, e che cosa non ci si trova.

## 6

Gli errori sono raggruppati per tipo: un problema che si ripete cento volte è
una riga sola con un conteggio, non cento righe. È la differenza fra una pagina
che si guarda ogni mattina e una che si smette di aprire dopo tre giorni.

## 7

Aprendone uno si vede quando è comparso la prima volta, quante volte è successo
e la traccia tecnica. Il primo dato è spesso il più utile: un errore vecchio che
ricompare e uno nato stanotte richiedono due reazioni diverse.

## 8

Il contesto viene **ripulito prima di essere salvato**, non mascherato dopo:
password, token e segreti non entrano affatto nel database. Mascherare in lettura
avrebbe lasciato i segreti scritti da qualche parte, ed è esattamente ciò che si
voleva evitare.
