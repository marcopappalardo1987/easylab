<!--
  Testi SCRITTI della guida «La verifica in due passaggi». Vedi il preambolo di
  primo-accesso.md per come si scrive questo file.
-->

## premessa

La password è una cosa sola: chi la indovina, la intercetta o la trova scritta
da qualche parte entra come te. La verifica in due passaggi ne aggiunge una
seconda che non si può rubare a distanza, perché vive sul tuo telefono e cambia
ogni mezzo minuto. Per alcuni ruoli Easy Lab la impone; per tutti gli altri è
una scelta, e questa guida serve a farla con cognizione.

## capitolo 1

Sta nelle impostazioni del proprio account, insieme alle altre cose che
riguardano te e non il laboratorio. Attivarla non chiede permessi a nessuno.

## 1

La pagina «Sicurezza» si raggiunge dal menù col tuo nome, in alto a destra, ed
è la stessa per tutti i ruoli. Qui non si governano i dati dell'Ente: si governa
il proprio accesso, quindi nessuno può attivarla o disattivarla al posto tuo.

## 2

Finché non la accendi il riquadro dice «Non attivo», ed è una constatazione,
non un rimprovero. Se il tuo ruolo è fra quelli a cui Easy Lab la impone —
Amministratore e superiori — non vedrai mai questa schermata in questo stato: al
primo accesso vieni portato qui e non si prosegue finché non è fatta.

## 3

«Abilita 2FA» non attiva ancora niente: prepara il segreto e ti mostra come
consegnarlo al telefono. Fino alla conferma del capitolo successivo il tuo
accesso funziona esattamente come prima, quindi non c'è modo di restare chiusi
fuori a metà strada.

## capitolo 2

Il telefono e Easy Lab devono mettersi d'accordo su un segreto condiviso. Il QR
serve solo a questo, e da quel momento in poi i due contano il tempo insieme
senza più parlarsi.

## 4

Il QR contiene il segreto che l'applicazione ha appena coniato per te. Va
inquadrato con un'app di autenticazione — Google Authenticator, 1Password,
Authy, quella che già usi — che da lì in avanti genererà i codici da sola,
anche senza rete e anche senza Easy Lab aperto.

## 5

Sotto il QR c'è la stessa identica informazione in lettere e numeri. Serve
quando la fotocamera non mette a fuoco, quando l'app gira sullo stesso
dispositivo da cui stai guardando lo schermo, o quando il QR semplicemente non
si legge: si copia la chiave e si incolla nell'app, con lo stesso risultato.

## 6

L'app mostra un codice di sei cifre e una barra che si consuma: ogni trenta
secondi quel codice scade e ne compare un altro. Non è una password da
ricordare e non va conservata da nessuna parte, perché fra mezzo minuto non vale
già più.

## 7

Si digita il codice che l'app mostra **in quel momento**. Se la barra sta per
finire conviene aspettare quello nuovo, invece di rincorrere: un codice appena
scaduto viene rifiutato, e sembra un errore di battitura quando non lo è.

## 8

La conferma è il momento in cui la protezione si accende davvero, e serve
proprio a dimostrare che il telefono è stato configurato bene. Se il codice non
viene accettato non è successo niente di irreversibile: si riprova, o si annulla
e si ricomincia con un segreto nuovo.

## capitolo 3

Da qui in poi per entrare servono due cose, e una delle due può rompersi,
smarrirsi o essere cambiata. I codici di recupero sono la risposta a quel
giorno, e vanno messi al sicuro adesso che ci sono.

## 9

Il riquadro dice «Attivo», e da questo momento la password da sola non basta
più: al prossimo accesso, dopo email e password, Easy Lab chiederà il codice del
telefono. Vale su ogni dispositivo e ogni browser, perché la protezione è
sull'account e non sulla postazione.

## 10

I codici di recupero compaiono una volta, subito dopo la conferma, e sono la sola
via d'ingresso se il telefono si rompe, si perde o viene cambiato senza
trasferire l'app. Non è un dettaglio da rimandare: senza di loro, un telefono
perso significa chiedere a un amministratore di riaprirti l'accesso.

## 11

Vanno conservati **fuori** dal telefono, altrimenti non risolvono il problema
per cui esistono: stampati, in un gestore di password su un altro dispositivo, in
una cassaforte. Ognuno funziona una volta sola e poi si consuma, quindi otto
codici sono otto emergenze, non un secondo accesso permanente.

## 12

Se li hai usati tutti, o se sospetti che qualcuno li abbia visti, «Rigenera
codici di recupero» ne produce otto nuovi e invalida i vecchi nello stesso
istante. Rigenerarli non tocca il telefono né il segreto: l'app continua a
funzionare come prima.
