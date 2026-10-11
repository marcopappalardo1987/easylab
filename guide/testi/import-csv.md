<!--
  Testi SCRITTI della guida «Importare strumenti da CSV». Vedi il preambolo di
  primo-accesso.md per come si scrive questo file.
-->

## premessa

All'avvio non si registrano trecento macchine una per una: si parte da un foglio
di calcolo che quasi sempre esiste già. L'importazione lo legge, dice riga per
riga che cosa entrerà e che cosa no, e importa solo dopo che hai visto l'esito.
È un gesto che si fa una volta o due nella vita di un laboratorio, e vale la
pena farlo con calma.

## capitolo 1

Il formato è semplice ma va rispettato: sei colonne, la prima riga con le
intestazioni, e due sole obbligatorie.

## 1

Ci si arriva da «Importa CSV», in cima all'elenco degli strumenti. È una strada
diversa da «Aggiungi strumento» di «Laboratori»: quella crea una macchina alla
volta nel posto in cui ti trovi, questa ne crea molte insieme, e ognuna porta
scritta la propria ubicazione.

## 2

Le colonne sono `nome`, `modello`, `matricola`, `data_installazione`,
`ubicazione`, `provenienza`. **Obbligatorie sono solo `nome` e `ubicazione`**:
tutto il resto può restare vuoto e si completa più avanti, macchina per
macchina. Il separatore può essere il punto e virgola o la virgola, e la data si
accetta sia come `2015-03-01` sia come `01/07/2020`.

## 3

«Scarica template CSV» dà il file già intestato. Partire da lì è la strada più
corta per non sbagliare i nomi delle colonne, che è l'errore che manda a monte
l'intero caricamento invece di una riga sola.

## 4

Si carica il proprio file e si chiede di analizzarlo. Il limite è di mille righe
per file e due megabyte: un parco più grande si spezza in più caricamenti, che
è comunque una buona idea per altre ragioni.

## capitolo 2

L'analisi è la parte che conta: non scrive niente, dice soltanto che cosa
succederebbe.

## 5

Nessuna macchina viene creata in questa fase. È una simulazione completa, e
serve proprio a essere rifatta: si analizza, si legge l'esito, si correggono le
righe nel proprio foglio e si ricarica, tutte le volte che serve.

## 6

Ogni riga porta il proprio esito, con il numero di riga del file per ritrovarla
nel foglio. In cima ci sono i due conteggi — quante valide e quante con errori —
che sono anche il numero che comparirà sul pulsante di importazione.

## 7

Gli errori tipici sono due. Il primo è un **campo obbligatorio vuoto**: una riga
senza nome, o senza ubicazione, non può diventare una macchina. Il secondo, e di
gran lunga il più frequente, è un'**ubicazione che fra i laboratori non esiste**:
il foglio dice «Reparto di Cardiologia» e in Easy Lab quel nodo si chiama
diversamente, o non è ancora stato creato.

## capitolo 3

Si importa solo ciò che è valido, e quello che resta fuori non è perduto.

## 8

Il pulsante dice quante righe verranno importate, ed è il numero delle valide:
le righe con errori vengono semplicemente saltate. Non c'è modo di importare
«tutto lo stesso», ed è voluto — una macchina senza ubicazione non saprebbe dove
stare.

## 9

Le righe scartate restano nel tuo foglio, con l'indicazione del problema: si
correggono lì e si ricarica il file. Le righe già importate non si duplicano se
le ricarichi? Sì che si duplicano: conviene togliere dal foglio quelle andate a
buon fine, o tenere un file separato per le correzioni.

## 10

La prima volta conviene caricare un lotto piccolo, cinque o dieci righe, e
guardare l'esito. Se le ubicazioni combaciano, il resto del file passerà; se non
combaciano, l'hai scoperto su dieci righe invece che su trecento, ed è il
momento giusto per sistemare la struttura prima di continuare.
