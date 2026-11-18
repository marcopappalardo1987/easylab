<!-- Testi SCRITTI, guida INTERNA. Vedi primo-accesso.md per il formato. -->

## premessa

Il listino tiene i piani che si possono vendere: quanto costano, quante sedi
consentono, quali sono ancora in vendita. È la pagina in cui si decide quello che
i clienti poi leggono nella propria pagina «Abbonamento», ed è anche quella che
decide se la registrazione pubblica funziona.

## capitolo 1

Piani, tetti e prezzi correnti.

## 1

Ogni piano ha un'etichetta, un tetto di **sedi**, un tetto di **strumenti** e un
prezzo corrente. Il piano senza canone si chiama «comodato d'uso». Il tetto di
strumenti può valere per sede o per cliente intero, e lasciato vuoto significa
illimitato.

## 2

I tetti che si impostano qui sono esattamente quelli che il cliente vede nella
propria pagina «Abbonamento»: le sedi nella forma «2 su 5», gli strumenti come
numero o «illimitati». Qui si decide, là si subisce: cambiare un tetto cambia
quello che un cliente può fare, e se qualcuno ci finisce sotto la finestra lo
chiede prima di salvare.

## 3

È il **prezzo agganciato a Stripe** a rendere vendibile un piano: senza, il piano
non compare nella registrazione pubblica, e se nessun piano è vendibile la
registrazione lo dichiara invece di ripiegare su un piano in comodato d'uso. È
una scelta deliberata: un ripiego rassicurante nasconderebbe l'errore di
configurazione e regalerebbe account.

## capitolo 2

I prezzi hanno una storia, e serve a non toccare i contratti in corso.

## 4

Cambiare un prezzo non sovrascrive il precedente: alla sincronizzazione nasce un
prezzo nuovo su Stripe, che diventa corrente, e i vecchi restano. Il listino è
quindi un archivio, non un valore singolo, ed è la ragione per cui si può
alzare un prezzo senza telefonare a nessuno.

## 5

Un cliente entrato l'anno scorso continua a pagare il prezzo di allora finché
non cambia piano: quello che è stato sottoscritto resta valido. È il
comportamento che ci si aspetta da un abbonamento, e qui è una conseguenza della
struttura dei dati, non una regola scritta a parte.

## 6

Archiviare un piano lo toglie dalla vendita e non tocca chi ce l'ha già. Nessun
cliente cambia condizioni per una decisione di listino: smette solo di essere
un'opzione per chi si registra da domani.
