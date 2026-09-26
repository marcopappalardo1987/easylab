🔍 **Restyling UI — Dossier per la revisione umana**

*Consegna del lavoro eseguito sul branch `restyling/design-system` fra il 26 e il 27 Ago 2026, secondo
`../Roadmap/Restyling UI — Roadmap Operativa.md`. Tre parti: **cosa è cambiato**, **cosa ho deciso di non
fare e perché**, **cosa non è stato verificato**. La terza è la più importante.*

---

## 0. I numeri

| | |
|---|---|
| Commit sul branch | **13** |
| Suite | **1.434 test, 1.432 verdi**, 2 saltati *(erano 1.382 all'inizio: +52)* |
| File migrati allo strato semantico | **51 → 0** ancora da migrare |
| Bundle CSS | 63,3 kB → **46,1 kB** (**−27%**) |
| Guardrail nuovi | 3 (`TemaScuro`, `SuperficiTokenizzate`, `Palette` esteso) |
| Viste cancellate | 1 (`welcome.blade.php`) |
| Regole di permesso, scope o tenancy modificate | **0** |

---

## 1. Cosa è cambiato

**Il teal è uscito dal progetto.** `primary` è ora il blu del marchio, con `primary-400` e `primary-600`
ancorati agli hex reali del logo. 🔗 ADR-033, **attuata**.

**L'interfaccia ha due temi.** Chiaro e scuro, con l'ordine che avevi chiesto: **prima il sistema
operativo, poi l'ultima scelta dell'utente**. La preferenza vive in `users.tema`; per chi è autenticato
l'attributo lo rende il **server** (zero lampo, zero JavaScript nel percorso critico), per chi non lo è uno
script inline e sincrono. Selettore a tre stati in due posti: la pagina «Preferenze» e il menù utente.
🔗 ADR-034, Design System **§8**.

**Nessuna vista usa una variante `dark:`.** Il tema si scambia sotto, nei token — due strati, come dimostra
il campione. Sarebbero state ~1.190 varianti da tenere allineate a mano, e la prima dimenticata avrebbe dato
testo nero su fondo nero **senza dare errore**.

**ADR-033 diceva «nessun test si accorgerà mai di questa decisione».** Ora tre reti la sorvegliano — ma
**nessuna guarda i valori**: verificano che un token esista e sia definito in **entrambi** i temi, non che
sia il colore giusto. Quella verifica è tua, ed è il motivo per cui esiste questo documento.

---

## 2. La checklist — cosa guardare, e in che ordine

Il banco è a **`http://easylab.test/design-system`**: monta tutti e 15 i componenti in tutti i loro stati,
senza database e senza autenticazione. È il posto da cui partire.

- [ ] **Il blu è quello del marchio**, su schermo e non su carta. Confronto rapido: `/login`, dove il logo e
      il bottone «Accedi» devono essere lo stesso colore.
- [ ] **Il tema scuro non è un chiaro invertito.** Guarda la gerarchia delle superfici: fondo pagina <
      incassato < superficie piena. E l'ombra, che in scuro si infittisce invece di sparire.
- [ ] **Il semaforo si legge a colpo d'occhio, e anche senza colore.** ⚠️ In tema scuro la coppia
      verde↔arancione scende a ΔE 6,9, sotto la soglia di sicurezza: la forma e la parola non sono una buona
      pratica, sono **ciò che rende leggibile** il grafico.
- [ ] **Il campo a 360px, in pieno sole.** `/campo` è la vista del tecnico ed è quella che si guarda peggio.
- [ ] **La stampa dell'etichetta QR** — e stampala **col tema scuro attivo**: è il caso che si vede solo lì.
- [ ] **La domanda che nessun agente può porsi: assomiglia a Easy Lab?**

Scatti raccolti: `.restyling/scatti/F6/banco-{light,dark}-{360,768,1280}.png` e
`.restyling/scatti/F6/pagine/login-*.png`.

---

## 3. Le decisioni che ti restano

### 3.1 🔴 Tre cose che cambierebbero il Design System — annotate, non applicate

Il DS è normativo e la sua numerazione è citata dai docblock del codice: nessun agente l'ha emendato di
propria iniziativa.

**a) `--ink-3` è sotto AA su due superfici, in tema chiaro.**

| `--ink-3` su | chiaro | scuro |
|---|---|---|
| `surface` | 4,76 ✅ | 5,17 ✅ |
| `surface-sunken` | 4,55 ✅ | 5,48 ✅ |
| **`canvas`** | **4,47 ❌** | 5,67 ✅ |
| **`surface-code`** | **4,38 ❌** | 5,44 ✅ |

Il 4,76:1 con cui §2.2 lo aveva approvato è calcolato **su bianco puro**, e quasi nessun testo terziario sta
sul bianco puro. Il caso è **reale**: `surface-code` è montato in quattro viste e tutte ci mettono sopra
testo terziario; il piè di pagina di `/login` sta su `canvas` (**misurato sul reso: 4,47:1**).
*Rimedi: alzare `--ink-3` di un gradino, oppure dichiarare in §8.2 che vale solo su `--surface`.*

**b) La regola dell'alfabeto, da scrivere in §5.2.** Tailwind ordina le utility della stessa proprietà in
modo **alfabetico** nel foglio generato. Misurato: `.bg-surface` a offset 21152, `.bg-warn-soft` a 21326 →
il giallo **vince**; `.bg-bad-soft` a 19821 → il rosso **perde**. Quindi un colore passato dall'esterno a un
componente funziona o no **a seconda di come si chiama**, e chi lo scrive non ha modo di saperlo.
Tampone adottato: `!` sempre. *Rimedio pulito: una prop `variant` su `x-ui.card`.*
⚠️ Questa regola spiega da sola **tre** difetti trovati in tre fasi diverse.

**c) `x-ui.button` misura 42px**, due sotto i 44 di §5.1 — misurato, non stimato. Le voci di sidebar 40.
Il campione fissa il bottone a 40px e prevede `is-touch` come variante separata. *Due pixel, ma toccano la
resa di ogni vista.*

### 3.2 Otto cose segnalate dagli agenti e non toccate

1. **`⏳` ignora `currentColor`**: su macOS ha presentazione emoji e rende marrone invece che viola. `⚑`
   invece rende monocromo su Chromium — ma potrebbe non valere su Safari. *(Stessa trappola già risolta per
   `☀ ☾` con SVG.)*
2. **Lo skeleton è quasi invisibile in entrambi i temi**: `surface-sunken` su `surface` fa **1,05:1**.
   Conforme al campione, quindi è materia da DS.
3. **`anagrafica/albero` non è un albero**: è un navigatore a breadcrumb più una griglia di card. §5.7
   descrive nodi espandibili `▾/▸` e un drawer che **non esistono**. Documento e realtà divergono.
4. **A 360px `/piattaforma` e `/piattaforma/ruoli` scrollano orizzontalmente** — provato rendendo le viste
   prima e dopo: **preesistente**, non introdotto dal restyling.
5. **Il `<th>` della banda di gruppo** nell'editor ruoli è `sticky left-0` ma ha `colspan` sull'intera riga:
   promette un'ancora che la struttura non può dare.
6. **`x-ui.input`/`x-ui.textarea` non hanno `aria-invalid`/`aria-describedby`**, che §5.6 prescrive insieme
   al testo d'errore. Conseguenza: la regola `[aria-invalid]` del campione **non ha modo di scattare**.
7. **Il bordo dei bottoni secondari** fa 1,48:1, sotto i 3:1 per elementi non testuali. Vale in tutta l'app,
   preesistente.
8. **Il toast del banco** usa tre regole in un `<style>` locale invece di token: DS §8.4 lo vuole su token di
   scala, e Tailwind non lo esprime senza una `dark:` che §8.1 vieta. **Debito dichiarato** nel file.

### 3.3 Il pallino arancione resta a 2,15:1, ed è giusto così

Confermato sul reso. È il numero che §2.5 dichiara — «e non può fare di meglio restando arancione». Alzarlo
di gradino darebbe un arancione che non è più arancione. **È l'aritmetica che rende obbligatoria la
tripletta di §4**, non un difetto da correggere.

*Il rovescio, misurato: in tema scuro quel problema **non esiste** (10,23:1), ma lì diventa critica la
vicinanza verde↔arancione. Due temi, due modi diversi di essere illeggibili, e la stessa cosa a salvarli.*

---

## 4. 🔴 Cosa NON è stato verificato

*La parte che conta di più, perché una rotta non verificata che sembra verificata è peggio di una dichiarata
scoperta.*

**Nessuna pagina autenticata è stata guardata in un browser.** L'audit visivo ha coperto il **banco** (6
combinazioni: 2 temi × 3 larghezze, 0 errori di console, 0 scorrimenti orizzontali) e `/login`. Le **12
rotte autenticate** — dashboard, strumenti, scheda, anagrafica, campo, ricambi, fornitori, cabina, matrice
ruoli, audit, errori, impostazioni — **non sono state raggiunte**: il browser non aveva una sessione, e
l'agente ha dichiarato le rotte non raggiunte invece di fabbricarsi un accesso.

⚠️ **Restano quindi senza verifica a schermo**: gli scatti nei due temi a 360 e 1280 di quelle 12 rotte, il
contrasto misurato sui loro elementi resi, e la conferma dello scorrimento orizzontale a 360px su
`/piattaforma`. *Gli agenti di F2 e F4 hanno però misurato i contrasti rendendo le viste dalla suite, e
quei numeri sono nei messaggi di commit.*

**Non verificato con Alpine e Livewire vivi**: la sopravvivenza dello stato al morph, gli `:hover` col
mouse, il fuoco reale passando col tab, i dropdown che si aprono. Sono i punti che
`ComboboxRicambiTest` elenca per nome fra ciò che non copre, e restano tali.

**Non verificato su altri motori**: tutto è stato guardato su Chromium. La presentazione dei glifi emoji
(punto 3.2.1) dipende dal font di sistema e potrebbe cambiare su Safari.

**Non verificato su carta**: la stampa dell'etichetta QR e del PDF dello storico è stata controllata
leggendo le regole `@media print`, non stampando.

---

## 5. Cosa fare del branch

Il branch è **`restyling/design-system`**, 13 commit, mai promosso. `staging` non è stato toccato.

⚠️ **Ogni push su `staging` deploya**, e le fasi intermedie di questo lavoro passano per stati visibilmente
sbagliati (per un tratto la sidebar era bianca in tema scuro, con le voci a 2,11:1). È la ragione per cui il
lavoro è stato fatto su un branch dedicato: **si promuove finito, non a fasi**.
