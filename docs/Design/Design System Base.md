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
> ✅ **Dal 27 Ago 2026 `resources/css/app.css` è allineato a questo documento.** Lo scarto dichiarato qui —
> il blu nel documento, il teal in esecuzione — è stato chiuso dal restyling: 🔗 ADR-033 è **attuata**, e
> §8 aggiunge lo strato semantico e il tema scuro (🔗 ADR-034). Chi oggi legge un colore in `app.css` e uno
> qui e li trova **diversi** ha trovato un bug, non uno scarto voluto.
>
> ⚠️ **E c'è una rete, ma non guarda i valori.** `PaletteGuardrailTest`, `TemaScuroGuardrailTest` e
> `SuperficiTokenizzateGuardrailTest` verificano che ogni token esista, sia definito in **entrambi** i temi e
> non sia stato sostituito da una classe di scala. Nessuno dei tre guarda **quale** colore sia: quella
> verifica resta visiva, e questo documento resta la sua unica fonte.

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

---

## 8. Tema scuro e strato semantico — 🔗 ADR-034

*Aggiunta il 26 Ago 2026. §1–§7 non sono state toccate: la numerazione è citata per numero dai docblock del
codice, e questa sezione si aggiunge in coda senza spostare nulla.*

### 8.1 Il principio: due strati, e solo il secondo cambia

Il campione lo scrive nei propri commenti, ed è la regola:

> **Le SCALE** (`primary`, `neutral`, semaforo, accenti, grafici — §2) sono la palette, e sono **identiche nei
> due temi**. **I token SEMANTICI** dicono *a quale gradino della scala attinge ogni ruolo*, e sono **gli unici
> che cambiano col tema**. È il motivo per cui nessun componente contiene un colore letterale o una regola
> dentro un `@media`.

**Le viste usano solo i semantici.** `bg-surface`, non `bg-white`. `text-ink`, non `text-neutral-800`.
`border-border`, non `border-neutral-200`.

⛔ **E non usano mai la variante `dark:`.** Sarebbero ~1.190 varianti da tenere allineate a mano, e la prima
dimenticata **non dà errore**: dà testo nero su fondo nero. È lo stesso sintomo di §2.2 — «un token assente non
dà errore, dà un colore diverso» — un livello più su. *La duplicazione non si gestisce: non si crea.*

### 8.2 I token semantici, coi due valori affiancati

Il tema chiaro è il `:root` di base. Non esiste un blocco `[data-theme="light"]` con valori propri.

| Token | Classe Tailwind | **Chiaro** | **Scuro** | Ruolo |
|---|---|---|---|---|
| `--bg` | `bg-canvas` | `#F6F8FB` | `#0A1220` | fondo della pagina |
| `--surface` | `bg-surface` | `#FFFFFF` | `#111C2E` | card, modale, dropdown, top bar |
| `--surface-sunken` | `bg-surface-sunken` | `neutral-50` | `#0D1626` | `<thead>`, hover riga, footer card, campo disabilitato, skeleton, **sidebar** |
| `--surface-code` | `bg-surface-code` | `#F2F6FA` | `#0B1728` | superfici monospazio: stack trace della scheda errore, matrice dei ruoli, righe di audit |
| `--border` | `border-border` · `divide-border` | `neutral-200` | `#22314A` | bordi e divisori |
| `--border-strong` | `border-border-strong` | `neutral-300` | `#31435F` | contorno dei campi, bottone secondario |
| `--ink` | `text-ink` | `neutral-900` | `#E8EEF6` | testo primario e titoli |
| `--ink-2` | `text-ink-2` | `neutral-600` | `#A3B4CB` | testo secondario |
| `--ink-3` | `text-ink-3` | `neutral-500` | `#7C8FA8` | testo terziario, **placeholder**, `.muted` |
| `--ink-inverse` | `text-ink-inverse` | `#FFFFFF` | `#071019` | testo sul pieno di un colore di stato |
| `--brand` | `bg-brand` `text-brand` | `primary-600` | `primary-400` | bottone primario, link, tab attivo |
| `--brand-hover` | `hover:bg-brand-hover` | `primary-700` | `primary-300` | |
| `--brand-ink` | `text-brand-ink` | `#FFFFFF` | `#04263F` | testo sul bottone primario |
| `--brand-soft` | `bg-brand-soft` | `primary-50` | `#0E2740` | riga selezionata, alert info, nodo attivo |
| `--brand-soft-strong` | `bg-brand-soft-strong` | `primary-100` | `#123253` | voce di menù attiva |
| `--brand-soft-ink` | `text-brand-soft-ink` | `primary-700` | `primary-200` | testo su superficie brand tenue |
| `--brand-line` | `border-brand-line` | `primary-200` | `#1D4A77` | bordo d'accento |
| `--ok-dot` / `--ok-soft` / `--ok-soft-ink` | `text-ok-dot` · `bg-ok-soft` · `text-ok-soft-ink` | `#16A34A` / `#DCFCE7` / `#166534` | `#4ADE80` / `#0C2A1B` / `#86EFAC` | 🟢 in regola |
| `--warn-dot` / `--warn-soft` / `--warn-soft-ink` | idem | `#F59E0B` / `#FEF3C7` / `#92400E` | `#FBBF24` / `#33240A` / `#FCD34D` | 🟠 azione richiesta |
| `--bad-dot` / `--bad-soft` / `--bad-soft-ink` | idem | `#DC2626` / `#FEE2E2` / `#991B1B` | `#F87171` / `#3A1618` / `#FCA5A5` | 🔴 non idoneo, **e il testo d'errore dei form** |
| `--obs-dot` / `--obs-soft` / `--obs-soft-ink` | idem | `#7C3AED` / `#EDE9FE` / `#6D28D9` | `#A78BFA` / `#241B41` / `#C4B5FD` | ⏳ obsoleto (ADR-014) |
| `--lock-dot` / `--lock-soft` / `--lock-soft-ink` | idem | `#64748B` / `#E2E8F0` / `#334155` | `#94A3B8` / `#1E2B40` / `#CBD5E1` | 🔒 lockout (ADR-013) |
| `--ring` | `ring-ring` | `primary-500` | `primary-300` | anello di fuoco |
| `--overlay` | `bg-overlay` | `rgb(15 23 42 / .40)` | `rgb(2 6 23 / .68)` | velo dietro la modale |
| `--chart-verde` · `--chart-arancione` · `--chart-rosso` · `--chart-obsoleto` · `--chart-brand` | `fill-chart-verde` … `text-chart-brand` | §2.5, colonna «Tema chiaro» | §2.5, colonna «Tema scuro» | le cinque serie |
| `--chart-grid` / `--chart-axis` / `--chart-band` | `stroke-chart-grid` · `stroke-chart-axis` · `fill-chart-band` | `neutral-200` / `neutral-400` / `primary-50` | `#22314A` / `#5A6E88` / `#0E2740` | griglia, assi, banda evidenziata. ⚠️ **Non sono in §2.5**, che dà le sole cinque serie |
| `--shadow-sm` / `--shadow-md` | `shadow-sm` / `shadow-md` | ombre di §3 | `0 1px 2px rgb(0 0 0/.5)` / `0 4px 6px -1px rgb(0 0 0/.5), 0 10px 24px -8px rgb(0 0 0/.7)` | in scuro l'ombra è più densa, o non si vede |

> **`--overlay` è l'unico token che il campione non aveva, ed è stato aggiunto con una ragione.** Il velo della
> modale era `bg-neutral-900/40`: sul fondo scuro `#0A1220` un velo così è quasi invisibile e la modale smette
> di staccarsi dal contenuto. **Non si allarga la palette per un hover** — è già stato deciso una volta, e la
> risposta fu `hover:no-underline` — ma qui mancava un **ruolo**, non un gradino.

> ⚠️ **`primary-400` non è un colore da testo su fondo chiaro (3.24:1) ma lo è su fondo scuro**, dove infatti è
> il `--brand`. Non contraddice §2.1: il contrasto è una **relazione fra due colori**, non una proprietà di uno.

**Quattro cose da leggere prima di trascrivere questa tabella in `@theme`.**

1. ⚠️ **La colonna «Token» porta i nomi del campione, la colonna «Classe» quelli di `@theme`, e per uno solo
   non coincidono.** In Tailwind v4 la classe si genera da `--color-<nome>`, quindi `bg-surface` vuole
   `--color-surface`. Il fondo pagina fa eccezione: nel campione è `--bg`, in `@theme` va dichiarato
   **`--color-canvas`** — `bg-bg` non è un nome. Tutti gli altri si trascrivono alla lettera.
2. ⚠️ **`--ink` è `neutral-900`, cioè il gradino che §2.2 assegna ai *titoli*** (§2.2 mette il *testo primario*
   a `neutral-800`). Il campione unifica i due sul gradino più scuro, e vince il campione: §8 non riapre §2.2,
   che resta la mappa della **scala**; qui si dichiara a quale gradino attinge il **ruolo**.
3. ⚠️ **L'anello di fuoco è `primary-500`, mentre §5.6 scrive `focus:ring-primary-600`.** Vince `--ring`: dal
   passaggio ai semantici il fuoco è un token solo, e in tema scuro deve poter salire a `primary-300`. L'hex
   di §5.6 va letto come esempio pre-tema, non come una seconda verità.
4. 🔴 **Il testo d'errore dei form è `text-bad-soft-ink`, e §5.6 va letta come esempio pre-tema.** §5.6 scrive
   `text-danger-600` (`#B91C1C`): sul `--surface` scuro `#111C2E` fa **2,64:1**, cioè sarebbe illeggibile
   proprio nel tema in cui un errore conta di più. Il campione ha già deciso — `.el-field .err{color:var(--bad-soft-ink)}`
   — e i numeri gli danno ragione, misurati e non stimati:

   | | su `--surface` chiaro | su `--surface` scuro |
   |---|---|---|
   | **`--bad-soft-ink`** (`#991B1B` / `#FCA5A5`) | **8,31:1** ✅ | **9,00:1** ✅ |
   | `--bad-dot` (`#DC2626` / `#F87171`) | 4,83:1 ✅ | 6,18:1 ✅ |
   | `danger-600` fisso, come da §5.6 | 6,45:1 ✅ | **2,64:1** ❌ |

   Vince `--bad-soft-ink`: è il più leggibile in tutti e due, ed è già ciò che il campione monta. **Non serve
   un `--bad-ink` in più** — si toglie un colore, non se ne aggiunge uno (§2.4).

> **I quattro `--el-logo-*` del campione non entrano in questa tabella, di proposito.** Là il marchio è un SVG
> inline e si ricolora (in scuro `--el-logo-deep` sale a `primary-200`); qui è un `<img>`, che non eredita le
> variabili CSS della pagina. Sul fondo scuro serve un **secondo file**, non un token. 🔗 ADR-033/034.

### 8.3 Il meccanismo: `data-theme`, tre stati

| `data-theme` sull'`<html>` | Significato |
|---|---|
| **assente** | segue il sistema operativo (`prefers-color-scheme`) |
| `"light"` | chiaro, **anche se il sistema dice scuro** |
| `"dark"` | scuro, anche se il sistema dice chiaro |

```css
:root                      { color-scheme: light; /* i valori chiari */ }
@media (prefers-color-scheme: dark) {
  :root:not([data-theme="light"]) { color-scheme: dark; /* i valori scuri */ }
}
:root[data-theme="dark"]   { color-scheme: dark; /* gli stessi valori scuri */ }
@media print { :root, :root[data-theme="dark"] { /* la stampa è SEMPRE chiara */ } }
```

- ⚠️ **Il `:not([data-theme="light"])` non è pedanteria**: senza, una scelta esplicita di *chiaro* verrebbe
  sovrascritta dal sistema operativo, cioè l'interruttore non funzionerebbe in una delle due direzioni.
- ⚠️ **`color-scheme` va dichiarato**, o scrollbar, `<select>`, date picker e campi nativi restano chiari sul
  fondo scuro. È l'unica riga che parla al browser invece che alla pagina.
- La preferenza vive in `users.tema` (`sistema|chiaro|scuro`): **il DB è la verità, `localStorage` è la cache
  che evita il lampo**. Per l'autenticato l'attributo lo rende il server; per l'ospite lo scrive uno script
  **inline e sincrono** nel `<head>`. 🔗 ADR-034.

### 8.4 Ciò che non passa dai token semantici, e perché

⚠️ **Due righe diverse, sotto lo stesso titolo.** Il banner, il PDF, le email e la stampa **non cambiano
affatto** col tema. Il toast e il tooltip **cambiano**, ma restano su token di **scala** invece che sui
semantici: non sono superfici della pagina, sono cose che ci galleggiano sopra. In tutti i casi le due reti di
§8.5 li **esentano per nome**, quindi chi ne aggiunge o toglie uno aggiorna anche questa tabella.

| Superficie | Resta | Perché |
|---|---|---|
| **Banner di impersonation** (§5.8) | `warning-500` + `neutral-900`, **e `neutral-900/10` · `/20`** sul pulsante «Esci» | è un allarme persistente: deve avere lo **stesso identico aspetto** nei due temi, o smette di essere lo stesso segnale. Il pulsante sta **sul giallo**, non sulla superficie della pagina, quindi segue il banner e non il tema. Già `print:hidden` |
| **Toast** (§5.9) | scala: `neutral-900` in chiaro, `neutral-700` in scuro | galleggia sopra tutto: la sua superficie è indipendente da quella della pagina, e su fondo scuro `neutral-900` sparirebbe dentro `--bg` |
| **Tooltip** | scala: `neutral-900` in chiaro, `neutral-700` in scuro | stesso motivo del toast, ed è la stessa coppia di valori — il campione tratta i due nello stesso modo |
| **Stampa** | sempre chiara, via `@media print` | il fondo scuro si stampa come una campitura che consuma toner e non dice niente |
| **PDF** (`pdf/*`) | hex a mano, sempre chiaro | dompdf non vede Tailwind né il tema — 🔗 ADR-031 |
| **Email** (`mail/*`) | chiare | nessun client di posta ha un tema affidabile |

### 8.5 Checklist aggiuntiva per §7

Da leggere **insieme** alla checklist di §7, non al posto suo.

- [ ] Nessuna classe di **scala** per una superficie o per il testo: `bg-white`, `text-neutral-*`,
      `border-neutral-*` non compaiono nelle viste. 🛡️ `SuperficiTokenizzateGuardrailTest`.
- [ ] Nessuna variante **`dark:`** in nessuna vista.
- [ ] Ogni token semantico usato ha un valore in **entrambi** i temi. 🛡️ `TemaScuroGuardrailTest`.
      *Un token definito solo nel chiaro non dà errore: resta chiaro sul fondo scuro.*
- [ ] La pagina guardata **nei due temi**, a 360px e a 1280px. ⚠️ Molti difetti del tema scuro esistono **solo
      lì**: un bordo che sparisce, un'ombra che diventa un buco nero, un velo che non stacca più.
- [ ] Contrasto verificato **in tutti e due**: §2.5 vale due volte, non una.
- [ ] Il **semaforo** conserva colore **+ forma + etichetta** (§4). ⚠️ In tema scuro i colori dei grafici
      scendono a **ΔE 6,9** fra verde e arancione (§2.5), sotto la soglia di sicurezza: la forma e la parola
      non sono più una buona pratica, sono **ciò che rende leggibile il grafico**.
