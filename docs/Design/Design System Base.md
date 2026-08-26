🎨 Design System Base — Easy Lab

*Fondamenta visive della V1: palette, tipografia, spaziature, stati semaforo e componenti Tailwind. Traduce in token e regole concrete i wireframe di `Wireframe Viste Chiave.md` ed è il **contratto** per la configurazione Tailwind dello Sprint 1 e per i componenti Livewire/Blade degli sprint successivi. Impostazione **mobile-first**.*

> **Stato (21 Ago 2026).** Documento **normativo**: è qui che si decide, ed è questo file che i docblock
> citano per numero di sezione (`Design System §4` compare in `Strumento.php`, in `x-ui.semaforo`, nel PDF
> dello storico e nei test). **La numerazione delle sezioni non si cambia.**
>
> Il **campione visivo** è `design-system.html`, in questa stessa cartella: si apre col doppio click, non
> richiede toolchain, e mostra montato ciò che qui è descritto. Regola per non far divergere i due:
> **qui le regole e il perché, lì la dimostrazione.** Se un componente si può *mostrare*, si mostra lì e qui
> se ne cita solo il vincolo.
>
> ⚠️ **`resources/css/app.css` non è ancora allineato a questo documento.** La palette qui sotto è il blu del
> marchio (§2.1); i token in esecuzione sono ancora il teal dello Sprint 0. È uno scarto **noto e voluto**: il
> restyling è un intervento a parte, con la sua verifica visiva. Fino ad allora, chi legge un colore in
> `app.css` e uno qui **non ha trovato un bug**. 🔗 ADR-033.

---

## 1. Principi

1. **Mobile-first.** Si progetta prima per lo schermo piccolo (tecnico sul campo, ADR-003/007), poi si espande con i breakpoint. Ogni componente deve essere usabile a 360px.
2. **Chiarezza clinica.** Interfaccia sobria, alta leggibilità, poco "rumore": il dato (stato strumento, scadenza) viene prima della decorazione.
3. **Lo stato non si affida al solo colore.** Il semaforo combina **colore + icona/forma + etichetta** (accessibilità daltonici, WCAG). Vedi §4.
4. **Coerenza dei token.** Colori, spazi e raggi sono variabili nel tema Tailwind, mai valori "magici" nei componenti.

---

## 2. Palette colori

### 2.1 Brand / Primary (Blu del marchio) — 🔗 ADR-033
Derivata dal logo: `primary-400` e `primary-600` **non sono interpolati**, sono i due hex presi da
`assets/Logo-EasyLab.svg`. Il resto della scala è costruito attorno a loro.

| Token | Hex | Uso |
|---|---|---|
| `primary-50` | `#EFF7FC` | sfondi tenui, hover leggeri |
| `primary-100` | `#D6EAF7` | badge/sfondi |
| `primary-200` | `#AFD6EF` | bordi accento; logo su fondo scuro |
| `primary-300` | `#7FBDE4` | testo brand su fondo scuro |
| `primary-400` | `#2997D4` | **azzurro del logo**: accenti, bordi, riempimenti dei grafici |
| `primary-500` | `#1878B8` | stati intermedi |
| `primary-600` | `#06589C` | **blu del logo**: bottoni primari, link |
| `primary-700` | `#054880` | hover bottone primario |
| `primary-800` | `#063A66` | superfici brand scure |
| `primary-900` | `#072F52` | testo su sfondo chiaro brand |
| `primary-950` | `#041D33` | header scuri |

> ⚠️ **`primary-400` non è un colore da testo.** Su bianco fa **3.24:1**: sta sotto il 4.5:1 del corpo
> testo. Si usa per bordi, riempimenti dei grafici e testo su fondo scuro. Il gradiente del marchio
> (`primary-600 → primary-400`) vale per superfici d'identità — icona app, login — e **mai** dietro a un
> paragrafo, perché il contrasto cambia lungo la superficie.
>
> *Fino al 20 Ago 2026 questa scala era un **teal** (`#0D9488`), scelto allo Sprint 0 quando il logo non
> esisteva ancora. Marchio e interfaccia erano di due colori diversi.*

### 2.2 Neutri (Slate)
Testo, bordi, sfondi, superfici.

| Token | Hex | Uso |
|---|---|---|
| `neutral-50` | `#F8FAFC` | sfondo pagina |
| `neutral-100` | `#F1F5F9` | superfici/card secondarie |
| `neutral-200` | `#E2E8F0` | bordi, divisori |
| `neutral-300` | `#CBD5E1` | bordi marcati, contorno dei campi |
| `neutral-400` | `#94A3B8` | **solo elementi non testuali**: icone decorative, separatori |
| `neutral-500` | `#64748B` | **placeholder**, testo terziario |
| `neutral-600` | `#475569` | testo secondario |
| `neutral-700` | `#334155` | testo su fondo tenue |
| `neutral-800` | `#1E293B` | testo primario |
| `neutral-900` | `#0F172A` | titoli, header scuro |
| `neutral-950` | `#020617` | fondi scuri pieni |

> **Corretto il 21 Ago 2026: il placeholder era a `neutral-400`, cioè 2.56:1 su bianco — sotto AA.**
> Va a `neutral-500` (**4.76:1**). `neutral-400` resta, ma smette di poter portare testo.
>
> La scala era volutamente rada e i gradini `300/500/700/950` mancavano. Non è pedanteria: `app.blade.php`
> usava già `text-neutral-500`, che non esistendo nel tema **ricadeva in silenzio sulla scala di default di
> Tailwind** invece che su questa. Un token assente non dà errore, dà un colore diverso.

### 2.3 Stati semaforo (semantici) — 🔗 ADR-005
Colori dedicati allo stato strumento; **non** riusare il rosso/verde generico per altro.

| Stato | Token base | Hex base | Sfondo (badge) | Testo (su badge) |
|---|---|---|---|---|
| 🟢 In regola | `success-500` | `#16A34A` | `#DCFCE7` | `#166534` |
| 🟠 Azione richiesta | `warning-500` | `#F59E0B` | `#FEF3C7` | `#92400E` |
| 🔴 Non idoneo | `danger-500` | `#DC2626` | `#FEE2E2` | `#991B1B` |

### 2.4 Accenti funzionali

| Token | Hex | Uso |
|---|---|---|
| `info-500` | = `primary-600` | notifiche informative, link secondari |
| `obsolete-500` | `#7C3AED` | badge "Obsoleto" (distinto dal semaforo, ADR-014) |
| `locked-500` | `#64748B` | stato lockout/insoluto e permessi 🔒 (ADR-013/016) |

> **`info` non è più un secondo blu.** Nasceva `#2563EB` quando il brand era teal: un blu informativo aveva
> senso perché non somigliava a niente. Col brand blu, `#2563EB` e `#06589C` sarebbero **simili senza essere
> uguali** — il caso peggiore, quello in cui chi guarda non sa se la differenza voglia dire qualcosa. Il
> token resta perché `x-ui.badge` espone la variante `info`; il colore diventa un alias di `primary-600`.
>
> **`locked-500` è invece identico a `neutral-500` di proposito**, e va bene così: il grigio del lockout
> *deve* essere il grigio neutro. Il nome separato serve a dichiarare l'intenzione (ADR-013), non a
> introdurre un colore in più.

### 2.5 Colori dei grafici — 🔗 ADR-005

Il pallino in tabella e il segmento nel grafico appartengono alla **stessa famiglia ma non allo stesso
gradino**: un riempimento di superficie deve staccarsi dal fondo di almeno 3:1, e il gradino chiaro non ci
arriva.

| Ruolo | Tema chiaro | Tema scuro |
|---|---|---|
| Verde | `#15803D` | `#15803D` |
| Arancione | `#CA8A04` | `#C47F0F` |
| Rosso | `#B91C1C` | `#DC2626` |
| Obsoleto | `#7C3AED` | `#8B5CF6` |
| Brand | `#06589C` | `#2997D4` |

Le due terne sono state **verificate con uno strumento**, non a occhio (banda di luminosità OKLCH, soglia di
croma, separazione per protanopia/deuteranopia/tritanopia, contrasto sulla superficie). Coppia peggiore in
tema chiaro: verde↔arancione a ΔE 9,9. In tema scuro scende a **ΔE 6,9**, sotto la soglia di sicurezza, ed è
legittima **solo** perché ogni voce porta anche glifo ed etichetta (§4). *La regola di ADR-005 è ciò che
rende usabile la palette, non un adempimento accanto ad essa.*

**Mai due assi Y.** Due grandezze di scala diversa vogliono due grafici, o un indice a base comune: un
doppio asse permette di far dire al grafico qualunque cosa scegliendo le scale.

> **Contrasto.** Testo su sfondo ≥ 4.5:1 (WCAG AA); elementi **non testuali** (pallini, bordi, riempimenti)
> ≥ 3:1. Bottoni primari: bianco su `primary-600` = **7.29:1**. Le coppie badge in §2.3 rispettano AA.
>
> ⚠️ **Il pallino arancione su bianco fa 2.15:1, e non può fare di meglio restando arancione.** Non è un
> difetto da correggere alzando il gradino — un arancione più scuro non è più arancione. È l'aritmetica che
> rende §4 obbligatorio: la forma e la parola portano ciò che quel colore non può portare. Le coppie
> misurate, ricalcolate dagli hex, sono in `design-system.html` §2.

---

## 3. Tipografia, spazi, raggi, ombre

- **Font:** `Inter` (fallback: system-ui, sans-serif). Numeri tabellari per le colonne di date/quantità (`font-variant-numeric: tabular-nums`).
- **Scala tipografica:** `text-xs 12` · `sm 14` · `base 16` · `lg 18` · `xl 20` · `2xl 24` · `3xl 30`. Corpo testo = `base`; tabelle dense = `sm`.
- **Spaziatura:** scala Tailwind di base (4px step). Padding card `p-4` (mobile) → `p-6` (≥md). Gap griglie `gap-4`.
- **Raggi:** `rounded-md` (6px) default · `rounded-lg` (8px) card · `rounded-full` per pill/dot semaforo.
- **Ombre:** `shadow-sm` superfici · `shadow-md` dropdown/modal · header `shadow-sm` con bordo `neutral-200`.

### 3.1 Breakpoint (mobile-first)
| Nome | Min-width | Uso tipico |
|---|---|---|
| (base) | 0 | tecnico mobile, drawer chiuso |
| `sm` | 640px | tablet verticale |
| `md` | 768px | sidebar/albero affiancato (vista §5 wireframe) |
| `lg` | 1024px | dashboard a più colonne |
| `xl` | 1280px | tabelle larghe complete |

---

## 4. Indicatore Semaforo (componente cardine) — 🔗 ADR-005

Tripletta **colore + icona/forma + etichetta**. Mai solo colore.

| Stato | Dot | Icona/forma | Etichetta | Classi (esempio) |
|---|---|---|---|---|
| In regola | 🟢 | ● cerchio pieno | "In regola" | `bg-success-500` |
| Azione richiesta | 🟠 | ◐ semicerchio / ⚠ | "Azione richiesta" | `bg-warning-500` |
| Non idoneo | 🔴 | ■ quadrato / ⛔ | "Non idoneo" | `bg-danger-500` |

**Varianti aggiuntive:**
- **Forzato** `⚑`: pill `bg-warning-100 text-warning-800` con icona bandiera; tooltip/click → `forced_by` / `forced_at` / `forced_reason` (ADR-005). Lo stato forzato vince sul calcolato.
- **Obsoleto** `⏳`: badge `obsolete-500` separato, può coesistere col semaforo (ADR-014).
- **Dimensioni:** `dot-sm` (8px, in tabella), `dot-md` (12px, in card/header scheda).

```
Esempi:  [● In regola]   [◐ Azione richiesta]   [■ Non idoneo  ⚑]   [⏳ Obsoleto]
```

---

## 5. Libreria componenti base

Specifiche minime; ogni componente è un Blade/Livewire component riusabile.

### 5.1 Bottoni
| Variante | Stile | Uso |
|---|---|---|
| Primario | `bg-primary-600 text-white hover:bg-primary-700 rounded-md` | azione principale (Salva, + Nuovo) |
| Secondario | `bg-white border border-neutral-200 text-neutral-800 hover:bg-neutral-50` | azioni neutre (Annulla) |
| Pericolo | `bg-danger-500 text-white hover:bg-danger-600` | eliminazioni/lockout |
| Ghost/icona | `text-neutral-600 hover:bg-neutral-100 rounded-md` | icone (✎, ⠿, 🔳 QR) |
| Disabilitato | `opacity-50 cursor-not-allowed` | permesso assente o azione bloccata |

Touch target minimo **44×44px** (campo mobile). Bottoni full-width su mobile nei form.

### 5.2 Card / superfici
`bg-white rounded-lg shadow-sm border border-neutral-200 p-4 md:p-6`. KPI card: numero `text-3xl font-semibold`, label `text-sm text-neutral-600`, eventuale dot semaforo.

> **Realizzata il 21 Ago 2026** come `x-ui.stat-tile` (props `label`, `valore`, `dettaglio`), costruita **sopra `x-ui.card`** invece di ripeterne le classi: la superficie è una decisione sola, e duplicarla qui vorrebbe dire che il giorno in cui cambia il bordo delle card questa resta indietro.
>
> ⚠️ **Dal 25 Ago 2026 la `label` accetta anche uno SLOT**, e serve a una cosa sola: la dashboard per ruolo (Wireframe §1) ci mette `x-ui.semaforo`, perché la tripletta di §4 è colore **+ forma + etichetta** e un riquadro che dicesse solo «Azione richiesta» in grigio perderebbe le prime due. Passando invece la parola come prop *e* il pallino accanto, l'etichetta comparirebbe **due volte** nel testo della pagina — una visibile e una in `sr-only` — e ogni asserzione su quel testo diventerebbe ambigua.
>
> ⛔ **Ma la label resta obbligatoria**, ed è rimessa a mano con un `throw_if`: prima era un prop senza default, quindi ometterlo era un errore rumoroso. Con `'label' => null` una tile senza etichetta renderebbe **un `<p>` vuoto sopra un numero** — cioè «il numero senza il suo contesto», la cosa che questo componente esiste per impedire, e in silenzio.
>
> Il terzo slot, `dettaglio`, non era nella specifica ed è stato aggiunto per una ragione che vale la pena scrivere: **un totale senza il suo contesto è la cifra che poi viene citata da sola**. «Ricavo mensile 588 €» diventa un dato di bilancio in una riunione; «588 € · a listino · 2 clienti bloccati» no. Vale per ogni numero aggregato, non solo per quello.
>
> ⚠️ Gli importi si formattano con `number_format` e **non** con `Number::currency()`: quest'ultimo richiede `ext-intl`, che è presente in locale ma **non è installata dalla CI** (`.github/workflows/ci.yml` monta `pdo_pgsql, redis, mbstring, bcmath`). Sarebbe verde sulla macchina e rossa in pipeline — la divergenza che `phpunit.xml` esiste per estirpare.

### 5.3 Tabella dati
Header `bg-neutral-50 text-neutral-600 text-sm`, righe con `divide-y divide-neutral-200`, hover `hover:bg-neutral-50`, riga cliccabile → scheda. Prima colonna = dot semaforo. **Mobile:** la tabella collassa in lista di card (label:valore).

> **Intestazione ordinabile** (S6, cabina di regia). Il `<th>` porta `aria-sort` (`ascending`/`descending`/`none`) e un `<button wire:click>` con la freccia `▲`/`▼` sulla sola colonna attiva.
>
> ⚠️ **La freccia legge l'ordinamento *effettivo*, mai la property.** `sortBy` e `sortDir` arrivano dal browser — via `#[Url]`, quindi senza passare dagli hook — e il componente li ri-valida contro una whitelist a ogni render. Se la vista leggesse le property, `?sortBy=password` mostrerebbe la freccia su una colonna mentre l'elenco è ordinato per un'altra: una bugia piccola, e per questo credibile. Il valore sanificato si espone con un metodo (`ordinamentoEffettivo()`), non con una seconda copia della whitelist in Blade — due copie divergono, e il giorno in cui divergono nessuno se ne accorge.
>
> **Deviazione ammessa dalla modalità card**: le viste da scrivania con riga espandibile (cabina di regia, elenco strumenti) restano a scorrimento orizzontale. Va **dichiarata nel template**, con la ragione, e i `<td>` devono comunque avere **un solo figlio diretto** — così la conversione resta possibile invece di diventare una riscrittura.

### 5.4 Badge / pill
`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium`. Colori dai token semantici (semaforo §4, stato cliente, piano, 🔒 lockout/bloccato).

### 5.5 Tab (scheda strumento)
Barra orizzontale, tab attivo `border-b-2 border-primary-600 text-primary-700`, inattivo `text-neutral-600`. Tab nascosti per permesso (es. **Ricambi** assente per chi non ha `ricambi.view`). *Corretto l'8 Ago 2026: l'esempio diceva «**Garanzie** assente per Tenant/Tecnico — ADR-004», ed era sbagliato due volte. Il tab Garanzie è visibile in sola lettura anche a Tenant e Tecnico, che hanno `garanzie.macchina.view` (già così da S3); e il vincolo di ADR-004 riguarda le **righe** `soggetto = ricambio`, applicato per scope, mai il tab — per il solo Tenant dopo ADR-027. Un tab nascosto e una riga filtrata sono due meccanismi diversi: confonderli è ciò che ha prodotto l'errore corretto da ADR-027.* **Mobile:** scroll orizzontale o `select ▼`.

### 5.6 Form & input
`rounded-md border-neutral-300 focus:border-primary-600 focus:ring-primary-600`. Label `text-sm font-medium text-neutral-800`. **Placeholder `text-neutral-500`** (§2.2: a `neutral-400` era sotto AA). Errori `text-danger-600 text-sm`, con `aria-invalid` e `aria-describedby` — non il solo bordo rosso. L'etichetta è **sempre visibile**: un placeholder non è un'etichetta, sparisce appena si scrive. Autocomplete ricambi (ADR-008/022) come combobox con creazione "al volo", su **nome** del pezzo. Le **righe ripetitore** del form intervento (wireframe §2.1) riusano lo stesso combobox: ogni riga è `nome` + `data`, con `[ ✕ ]` per rimuoverla e `[ + Aggiungi ricambio ]` in coda.

> **`x-ui.combobox`, realizzato in S4 blocco 3.** Due scelte da conoscere prima di riusarlo.
>
> **Alpine non scrive mai il valore.** Il dropdown è renderizzato dal server e ogni suggerimento è un `<button wire:click>` vero; Alpine apre/chiude, evidenzia, e su Invio *clicca* il bottone già evidenziato. Esiste quindi un solo percorso di selezione — testabile con Livewire — invece di una seconda implementazione in JS che nessun test vedrebbe; e senza JavaScript il campo resta usabile a click. Chi lo riuserà (tab Ricambi, vista mobile) erediti questa forma: è ciò che rende il componente verificabile.
>
> **Primi `aria-*` del progetto**, ed è una scelta: `role="combobox"`/`listbox`/`option`, `aria-expanded`, `aria-controls`, `aria-activedescendant`. Il DS finora non li menzionava (c'erano solo `role="dialog"` nella modale e gli `sr-only` dei glifi), ma un combobox senza di essi è, per uno screen reader, una casella di testo con del rumore accanto. Lo stato «＋ Nuovo ricambio: verrà creato» segue la regola §1/§4: glifo + testo, mai solo colore. C'era anche un «🔗 Collegato», rimosso il 9 Ago: col dropdown visibile ripeteva ciò che le opzioni mostrano già. **Un messaggio di stato dice ciò che la lista non può dire, non ciò che la lista mostra.**

> ⚠️ **Regola generale del componente, imparata rompendola.** Lo **stato Alpine può solo NASCONDERE ciò che il server ha deciso di mostrare, mai il contrario.** La prima stesura teneva la visibilità del dropdown in un flag `aperto`: digitando, Livewire rifà il render dopo il debounce, il nodo viene rimpiazzato e `x-data` si reinizializza — il flag tornava `false` a ogni battuta e **la lista non compariva mai**, mentre il messaggio di stato, renderizzato dal server, si vedeva benissimo. È lo stesso principio già adottato per la selezione («Alpine non scrive mai il valore»), che alla visibilità non era stato applicato. Vale per chiunque riusi il combobox o ne scriva uno nuovo.
>
> ⚠️ **Ciò che vive in Alpine non è coperto dai test** (i test Livewire non lo eseguono): tastiera, click-outside, aria dinamici, 44px reali, sopravvivenza dello stato al morph. Sono elencati per nome nel docblock di `ComboboxRicambiTest` e si verificano a mano; il browser test è un debito dichiarato. Il fallback a click rende **silenzioso** ogni fallimento JS: è il motivo per cui la verifica manuale non è facoltativa.

### 5.7 Navigazione
- **Top bar:** logo, contesto (Ente/ruolo), 🔔 notifiche, menù utente `▼`. Altezza `h-14`.
- **Albero** (vista §5): nodi espandibili `▾/▸`, nodo attivo `bg-primary-50 text-primary-700`. Su mobile in **drawer** `☰`.
- **Sidebar Superadmin:** voci con icona (Permessi 🛡, Audit 📜, Billing 💳).

### 5.8 Banner impersonation (persistente) — 🔗 ADR-016/activitylog
Barra fissa in alto, alto contrasto `bg-warning-500 text-neutral-900`, testo "Stai impersonando **{utente}** ({Ente}) — sei **{impersonatore}**" + `[ Esci dall'impersonation ]`. Sempre visibile durante la sessione impersonata, `print:hidden` in stampa.

> ⚠️ **Dice entrambi i nomi** *(corretto il 21 Ago 2026, S6 blocco F: prima diceva solo l'impersonato).* Chi sta impersonando lo sa già; non lo sa il collega davanti allo stesso schermo, e non lo sa chi legge lo screenshot allegato a un ticket sei mesi dopo — dove «Stai impersonando Mario Rossi» non dice **chi** stesse guardando, cioè l'unica cosa che serve per ricostruire il gesto. L'impersonatore si legge da `app('impersonate')->getImpersonator()`, perché `auth()->user()` è **già** l'impersonato.

**L'ingresso è un `<a href>` GET, mai un'azione Livewire.** `take()` sostituisce l'utente in sessione: una risposta Livewire lascerebbe in pagina un componente **montato per l'utente precedente**, col suo scope e i suoi permessi già risolti. Il giro completo dal server è ciò che rende lo scambio osservabile. Si bersaglia **una persona**, non un contratto — un candidato solo → link diretto, più d'uno → si sceglie, perché «il primo» sarebbe una decisione presa dall'ordinamento di una query.

### 5.9 Stati di servizio
- **Empty state:** icona neutra + frase guida + CTA.
- **Loading:** skeleton `animate-pulse bg-neutral-100` (no spinner a pagina intera dove evitabile).
- **Toast:** conferme azioni (salvataggio permessi, intervento chiuso) in basso, auto-dismiss.

---

## 6. Mappatura nel tema Tailwind

Il progetto è su **Tailwind v4, CSS-first**: `tailwind.config.js` **non esiste** e non va creato. I token
vivono in un blocco `@theme` dentro `resources/css/app.css`.

*Fino al 21 Ago 2026 questa sezione mostrava un `tailwind.config.js` con `theme.extend.colors`, rimasto dallo
Sprint 1 e mai aggiornato al passaggio a v4. Chi lo avesse seguito alla lettera avrebbe creato un file che
Tailwind ignora — senza nessun errore.*

```css
/* resources/css/app.css */
@theme {
    --font-sans: 'Inter', ui-sans-serif, system-ui, sans-serif, 'Apple Color Emoji', 'Segoe UI Emoji',
        'Segoe UI Symbol', 'Noto Color Emoji';

    /* Brand / Primary — 400 e 600 sono gli hex del logo (§2.1) */
    --color-primary-50:  #eff7fc;
    --color-primary-100: #d6eaf7;
    --color-primary-200: #afd6ef;
    --color-primary-300: #7fbde4;
    --color-primary-400: #2997d4;
    --color-primary-500: #1878b8;
    --color-primary-600: #06589c;
    --color-primary-700: #054880;
    --color-primary-800: #063a66;
    --color-primary-900: #072f52;
    --color-primary-950: #041d33;

    /* Neutri (Slate) — completati: 300, 500, 700, 950 mancavano (§2.2) */
    --color-neutral-50:  #f8fafc;
    --color-neutral-100: #f1f5f9;
    --color-neutral-200: #e2e8f0;
    --color-neutral-300: #cbd5e1;
    --color-neutral-400: #94a3b8;
    --color-neutral-500: #64748b;
    --color-neutral-600: #475569;
    --color-neutral-700: #334155;
    --color-neutral-800: #1e293b;
    --color-neutral-900: #0f172a;
    --color-neutral-950: #020617;

    /* Stati semaforo — ADR-005, invariati */
    --color-success-50:  #f0fdf4;
    --color-success-100: #dcfce7;
    --color-success-500: #16a34a;
    --color-success-600: #15803d;
    --color-success-700: #166534;
    --color-warning-50:  #fffbeb;
    --color-warning-100: #fef3c7;
    --color-warning-500: #f59e0b;
    --color-warning-600: #d97706;
    --color-warning-800: #92400e;
    --color-danger-50:   #fef2f2;
    --color-danger-100:  #fee2e2;
    --color-danger-500:  #dc2626;
    --color-danger-600:  #b91c1c;
    --color-danger-800:  #991b1b;

    /* Accenti — info è un alias del brand, non un secondo blu (§2.4) */
    --color-info-500: var(--color-primary-600);
    --color-obsolete-50:  #f5f3ff;
    --color-obsolete-100: #ede9fe;
    --color-obsolete-500: #7c3aed;
    --color-obsolete-700: #6d28d9;
    --color-locked-100: #e2e8f0;
    --color-locked-500: #64748b;
    --color-locked-700: #334155;

    /* Grafici — §2.5 */
    --color-chart-verde:     #15803d;
    --color-chart-arancione: #ca8a04;
    --color-chart-rosso:     #b91c1c;
    --color-chart-obsoleto:  #7c3aed;
    --color-chart-brand:     #06589c;
}
```

⚠️ **Questo blocco non è ancora in `app.css`**: vedi lo Stato in testa al documento e ADR-033. Quando ci
andrà: `npm run build`, e poi la verifica **visiva** — nessun test guarda i *valori* dei colori.

> 🛡️ **Un test guarda però che i token ESISTANO** *(25 Ago 2026)*. `tests/Feature/PaletteGuardrailTest.php`
> deriva le tonalità dichiarate nell'`@theme` di `app.css` e quelle usate nelle viste, e diventa rosso su
> una tonalità usata e non definita. Serviva: **otto ne erano sfuggite**, e le due metà sbagliavano in modo
> opposto — `neutral-300/500/700` *rendevano*, col grigio acromatico di Tailwind al posto dello Slate (154
> usi, diciotto viste), mentre `primary-300`, `warning-50` e altre tre non generavano alcuna regola: fra
> queste, un `hover:bg-danger-700` su un pulsante distruttivo della cabina di regia, cioè un hover che non
> colorava niente. È la §2.2 di questo documento — «un token assente non dà errore, dà un colore diverso» —
> messa sotto una rete invece che sotto un'avvertenza.
>
> ⚠️ **Tre delle otto non erano nel DS e non sono state inventate in `app.css`**: le viste chiamavano
> `danger-700`, `success-800` e `warning-900`, mentre §2.3/§6 dicono `danger-800` (`#991B1B`) e
> `success-700` (`#166534`) e un nono gradino d'ambra non ce l'hanno. Sono state corrette **le viste**. Il
> `hover:text-warning-900` della cabina — un link già a `warning-800`, cioè il gradino più scuro — è
> diventato `hover:no-underline`: la palette non si allarga per far posto a un hover.
>
> 🔴 **E una tonalità può mancare anche senza essere sbagliata: basta che Tailwind non legga il file che
> la usa** *(25 Ago 2026)*. Il sintomo è identico — classe scritta, pagina 200, colore assente — ma la
> causa è all'altro capo: le utility esistono solo per le classi che il compilatore **trova**.
> `app.css` importa quindi Tailwind con `source(none)` e dichiara le proprie sorgenti, e
> `tests/Feature/SorgentiTailwindGuardrailTest.php` verifica che ogni file capace di contenere una classe
> sia coperto da almeno un `@source`.
>
> ⚠️ **Serviva anche nel verso opposto, ed era quello che stavamo pagando**: la scoperta automatica legge
> tutto ciò che non è gitignorato, `tests/` compreso, quindi le classi delle fixture — comprese le
> **mutazioni** scritte apposta per provare il guardrail della palette — finivano nel CSS dei clienti. E
> `storage/framework/views` fra le sorgenti rendeva il bundle dipendente da *cosa era stato reso di
> recente*: due build dallo stesso commit, due CSS diversi. Misurato: 176 selettori su 738 di troppo, il
> 21% del peso, e nessuna classe di una vista vera fra quelli tolti.
>
> ⚠️ **Conseguenza pratica per chi aggiunge una cartella**: `resources/` e `app/` sono coperte; una
> `moduli/` nuova alla radice **no**, e il guardrail lo dice invece di lasciare pagine senza colore.

> ⚠️ **`primary-300` in `app.css` è il teal `#5eead4`, non il `#7fbde4` qui sopra**, e rientra nello scarto
> dichiarato in testa: la scala in esercizio è ancora quella dello Sprint 0, e metterci dentro un solo blu
> sarebbe attuare mezza ADR-033 senza la verifica visiva che quella decisione pretende.

---

## 7. Checklist di adozione (per ogni nuova vista)
- [ ] Disegnata prima a 360px, poi espansa coi breakpoint §3.1.
- [ ] Stato semaforo con colore **+** icona **+** etichetta (§4).
- [ ] Solo token di colore/spazio (niente hex inline).
- [ ] Azioni/tab filtrati per permesso (`../Architettura/Schema Ruoli e Permessi.md`).
- [ ] Contrasto testo ≥ 4.5:1; elementi **non testuali** ≥ 3:1 (§2.5); touch target ≥ 44px sulle azioni mobile.
- [ ] Tabelle con `tabella-a-card`: ogni `<td>` porta `data-etichetta` uguale al proprio `<th>`, la cella
      delle azioni porta `data-azioni`, e una cella su **due righe** va avvolta in un solo figlio — in
      modalità card il `<td>` diventa flex, e ogni figlio diretto finirebbe affiancato agli altri.
- [ ] Componente cercato prima in `design-system.html`: se là c'è già, si riusa invece di riscriverlo.
