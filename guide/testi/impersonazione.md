<!-- Testi SCRITTI, guida INTERNA. Vedi primo-accesso.md per il formato. -->

## premessa

Impersonare significa vedere Easy Lab esattamente come lo vede una persona di un
cliente, e poter agire al posto suo. È lo strumento che rende possibile
l'assistenza vera — sistemare un dato, mostrare un gesto mentre si è al telefono
— e per la stessa ragione è lo strumento su cui il prodotto è più severo.

## capitolo 1

Dal guardare all'agire c'è un gesto esplicito, ed è questo.

## 1

Il parco di piattaforma è in sola lettura: si vedono le macchine di tutti i
clienti, e non c'è niente da premere. È voluto, ed è ciò che rende
l'impersonazione una scelta invece di una scorciatoia.

## 2

Quando serve intervenire davvero si impersona la persona giusta di quel cliente.
Non c'è una modalità «amministratore che scrive dappertutto»: si passa sempre da
qualcuno che quel permesso ce l'ha per conto suo.

## 3

Da quel momento vedi Easy Lab **come lo vede lei**: stesso ruolo, stessi reparti
accessibili, stessi pulsanti. Se quella persona non può fare una cosa, non la
puoi fare nemmeno tu — impersonare non somma permessi, li sostituisce.

## capitolo 2

I limiti sono tre, e sono tutti deliberati.

## 4

Il **Developer non è mai impersonabile**, e nessuno può impersonare sé stesso.
Il primo limite protegge la pagina più chiusa del prodotto: se il Developer
fosse impersonabile, il gate sugli errori sarebbe aggirabile da chiunque
amministri la piattaforma.

## 5

Durante l'impersonazione le pagine di piattaforma sono chiuse. Si è quella
persona, non due persone insieme: entrare nella cabina di regia «mentre» si è un
cliente sarebbe un miscuglio in cui non si capirebbe più chi ha fatto che cosa.

## 6

E alcune schermate restano chiuse comunque, per esempio la sicurezza del proprio
account: la 2FA di qualcun altro non si governa per conto suo, nemmeno con le
migliori intenzioni.

## capitolo 3

L'impersonazione è accettabile perché non è invisibile.

## 7

L'apertura e la chiusura sono due righe nel registro di audit, con il tuo nome.
Non c'è modo di entrare come qualcun altro senza lasciarne evidenza, e non c'è
un pulsante per farlo in silenzio.

## 8

Ogni scrittura fatta nel frattempo porta il **timbro** di chi c'era dietro: il
registro non attribuisce al cliente ciò che hai fatto tu. Senza quel timbro il
registro direbbe una cosa falsa, ed è per questo che il timbro è arrivato prima
della pagina che lo mostra.

## 9

È tutto qui il motivo per cui uno strumento del genere è accettabile in un
prodotto che tratta dati altrui: non perché sia limitato, ma perché è tracciato.
Un cliente che chiede «chi ha cambiato questo dato» ha una risposta, sempre.
