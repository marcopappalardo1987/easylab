<!--
  Testi SCRITTI della guida «Forzare il semaforo». Vedi il preambolo di
  primo-accesso.md per come si scrive questo file.
-->

## premessa

Il semaforo di una macchina lo calcola Easy Lab dalle sue scadenze: è una
conseguenza dei dati, non un'opinione. Ci sono però situazioni che le scadenze
non sanno descrivere — una macchina ferma in attesa di un pezzo, una che una
verifica esterna ha dichiarato inutilizzabile — e per quelle esiste la
forzatura. È una funzione da usare poco, e la guida spiega anche perché.

## capitolo 1

Prima di forzare conviene sapere che cosa si sta scavalcando, altrimenti la
forzatura diventa il modo di far sparire i problemi invece di dichiararli.

## 1

Normalmente il colore è il riassunto delle scadenze aperte: verde se non c'è
niente, giallo se qualcosa sta arrivando, rosso se qualcosa è fermo. Il riquadro
«Motivi» in Panoramica elenca riga per riga che cosa ha prodotto quel colore,
quindi il semaforo non è mai un verdetto senza spiegazione.

## 2

Ci sono però stati reali che nessuna scadenza rappresenta. Una macchina in
attesa di un ricambio ordinato non ha una manutenzione scaduta, eppure non si
può usare; una dichiarata non conforme da un ente esterno può avere tutte le
tarature in regola. In entrambi i casi il semaforo calcolato direbbe una cosa
falsa a chi entra in laboratorio.

## 3

«Forza semaforo» serve a quei casi, e conviene tenerlo per quelli. Forzare al
verde una macchina che ha manutenzioni scadute non risolve niente: nasconde
un'informazione a tutti gli altri, e la scadenza resta lì.

## capitolo 2

Due campi: lo stato che vuoi mostrare e il perché. Il secondo non è un optional.

## 4

Si sceglie fra i tre stati normali — in regola, azione richiesta, non idoneo —
perché la forzatura non inventa un quarto colore: sostituisce il calcolo, non il
vocabolario. Chi guarda l'elenco vedrà un pallino uguale a tutti gli altri.

## 5

La motivazione è obbligatoria, ed è il punto della funzione. Senza, resterebbe
uno stato deciso da qualcuno e non spiegato a nessuno: fra due settimane
nemmeno chi l'ha impostato ricorderebbe perché quella macchina è rossa.

## 6

Vale la pena scrivere due cose: che cosa è successo e che cosa si sta
aspettando. «Guarnizione da sostituire, pezzo ordinato» dice a un collega se può
usarla, quanto durerà la situazione, e a chi chiedere. «Guasta» non dice niente
di tutto questo.

## 7

Confermando, la forzatura è attiva da subito e in ogni pagina. Il gesto resta
nel registro di audit con il tuo nome e la data: è una decisione, e le decisioni
in Easy Lab si sa sempre chi le ha prese.

## capitolo 3

Uno stato forzato non deve mai potersi confondere con uno calcolato: per questo
si dichiara ovunque, e per questo si toglie con lo stesso gesto con cui si mette.

## 8

Il pallino accanto al nome è quello che hai scelto, e la macchina compare così
in ogni elenco, in ogni filtro per stato e nei conteggi della Dashboard: un
rosso forzato è rosso per tutti, esattamente come un rosso calcolato.

## 9

Nella Panoramica però compare la dicitura «Semaforo forzato», con chi l'ha
fatto, quando e perché. È la differenza che conta: chi legge sa che quel colore
è una decisione di una persona e può risalire a chi chiedere, invece di cercare
una scadenza che non troverebbe.

## 10

Per tornare al calcolo automatico si riapre la stessa finestra e si toglie la
forzatura. Le scadenze non sono mai state toccate: erano lì sotto tutto il
tempo, e il semaforo ricomincia a dire quello che i dati dicono. È il motivo per
cui una forzatura dimenticata non fa danni permanenti, ma è comunque meglio
toglierla quando la situazione si risolve.
