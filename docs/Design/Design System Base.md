🎨 Design System Base — Easy Lab

*Fondamenta visive della V1: palette, tipografia, spaziature, stati semaforo e componenti Tailwind. Traduce in token e regole concrete i wireframe di `Wireframe Viste Chiave.md` ed è il **contratto** per la configurazione Tailwind dello Sprint 1 e per i componenti Livewire/Blade degli sprint successivi. Impostazione **mobile-first**.*

> **Stato:** bozza di Sprint 0 (task S0.4). Da approvare prima di configurare Tailwind (S1).

---

## 1. Principi

1. **Mobile-first.** Si progetta prima per lo schermo piccolo (tecnico sul campo, ADR-003/007), poi si espande con i breakpoint. Ogni componente deve essere usabile a 360px.
2. **Chiarezza clinica.** Interfaccia sobria, alta leggibilità, poco "rumore": il dato (stato strumento, scadenza) viene prima della decorazione.
3. **Lo stato non si affida al solo colore.** Il semaforo combina **colore + icona/forma + etichetta** (accessibilità daltonici, WCAG). Vedi §4.
4. **Coerenza dei token.** Colori, spazi e raggi sono variabili nel tema Tailwind, mai valori "magici" nei componenti.

---

## 2. Palette colori

### 2.1 Brand / Primary (Teal)
Identità Easy Lab: affidabile, "lab/medicale". Usata per azioni primarie, link, elementi attivi.

| Token | Hex | Uso |
|---|---|---|
| `primary-50` | `#F0FDFA` | sfondi tenui, hover leggeri |
| `primary-100` | `#CCFBF1` | badge/sfondi |
| `primary-500` | `#14B8A6` | accenti |
| `primary-600` | `#0D9488` | **bottoni primari**, link |
| `primary-700` | `#0F766E` | hover bottone primario |
| `primary-900` | `#134E4A` | testo su sfondo chiaro brand |

### 2.2 Neutri (Slate)
Testo, bordi, sfondi, superfici.

| Token | Hex | Uso |
|---|---|---|
| `neutral-50` | `#F8FAFC` | sfondo pagina |
| `neutral-100` | `#F1F5F9` | superfici/card secondarie |
| `neutral-200` | `#E2E8F0` | bordi, divisori |
| `neutral-400` | `#94A3B8` | testo disabilitato, placeholder |
| `neutral-600` | `#475569` | testo secondario |
| `neutral-800` | `#1E293B` | testo primario |
| `neutral-900` | `#0F172A` | titoli, header scuro |

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
| `info-500` | `#2563EB` | notifiche informative, link secondari |
| `obsolete-500` | `#7C3AED` | badge "Obsoleto" (distinto dal semaforo, ADR-014) |
| `locked-500` | `#64748B` | stato lockout/insoluto e permessi 🔒 (ADR-013/016) |

> **Contrasto.** Testo su sfondo ≥ 4.5:1 (WCAG AA). Bottoni primari: testo bianco su `primary-600`. Le coppie badge in §2.3 rispettano AA.

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

### 5.3 Tabella dati
Header `bg-neutral-50 text-neutral-600 text-sm`, righe con `divide-y divide-neutral-200`, hover `hover:bg-neutral-50`, riga cliccabile → scheda. Prima colonna = dot semaforo. **Mobile:** la tabella collassa in lista di card (label:valore).

### 5.4 Badge / pill
`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium`. Colori dai token semantici (semaforo §4, stato cliente, piano, 🔒 lockout/bloccato).

### 5.5 Tab (scheda strumento)
Barra orizzontale, tab attivo `border-b-2 border-primary-600 text-primary-700`, inattivo `text-neutral-600`. Tab nascosti per permesso (es. **Garanzie** assente per Tenant/Tecnico — ADR-004). **Mobile:** scroll orizzontale o `select ▼`.

### 5.6 Form & input
`rounded-md border-neutral-200 focus:border-primary-600 focus:ring-primary-600`. Label `text-sm font-medium text-neutral-800`. Errori `text-danger-600 text-sm`. Autocomplete ricambi (ADR-008/022) come combobox con creazione "al volo", su **nome** del pezzo. Le **righe ripetitore** del form intervento (wireframe §2.1) riusano lo stesso combobox: ogni riga è `nome` + `data`, con `[ ✕ ]` per rimuoverla e `[ + Aggiungi ricambio ]` in coda.

### 5.7 Navigazione
- **Top bar:** logo, contesto (Ente/ruolo), 🔔 notifiche, menù utente `▼`. Altezza `h-14`.
- **Albero** (vista §5): nodi espandibili `▾/▸`, nodo attivo `bg-primary-50 text-primary-700`. Su mobile in **drawer** `☰`.
- **Sidebar Superadmin:** voci con icona (Permessi 🛡, Audit 📜, Billing 💳).

### 5.8 Banner impersonation (persistente) — 🔗 ADR-016/activitylog
Barra fissa in alto, alto contrasto `bg-warning-500 text-neutral-900`, testo "Stai impersonando **{utente}**" + `[ Esci dall'impersonation ]`. Sempre visibile durante la sessione impersonata.

### 5.9 Stati di servizio
- **Empty state:** icona neutra + frase guida + CTA.
- **Loading:** skeleton `animate-pulse bg-neutral-100` (no spinner a pagina intera dove evitabile).
- **Toast:** conferme azioni (salvataggio permessi, intervento chiuso) in basso, auto-dismiss.

---

## 6. Esempio di mappatura nel tema Tailwind (indicativo per S1)

Solo riferimento per il task S1 "Tailwind via Vite" — il codice si scrive in S1.

```js
// tailwind.config.js → theme.extend.colors
colors: {
  primary:  { 50:'#F0FDFA',100:'#CCFBF1',500:'#14B8A6',600:'#0D9488',700:'#0F766E',900:'#134E4A' },
  neutral:  { 50:'#F8FAFC',100:'#F1F5F9',200:'#E2E8F0',400:'#94A3B8',600:'#475569',800:'#1E293B',900:'#0F172A' },
  success:  { 100:'#DCFCE7',500:'#16A34A',600:'#15803D' },
  warning:  { 100:'#FEF3C7',500:'#F59E0B',800:'#92400E' },
  danger:   { 100:'#FEE2E2',500:'#DC2626',600:'#B91C1C' },
  info:     { 500:'#2563EB' },
  obsolete: { 500:'#7C3AED' },
  locked:   { 500:'#64748B' },
}
// fontFamily.sans = ['Inter','system-ui','sans-serif']
```

---

## 7. Checklist di adozione (per ogni nuova vista)
- [ ] Disegnata prima a 360px, poi espansa coi breakpoint §3.1.
- [ ] Stato semaforo con colore **+** icona **+** etichetta (§4).
- [ ] Solo token di colore/spazio (niente hex inline).
- [ ] Azioni/tab filtrati per permesso (`../Architettura/Schema Ruoli e Permessi.md`).
- [ ] Contrasto testo ≥ AA; touch target ≥ 44px sulle azioni mobile.
- [ ] Tabelle con fallback a card su mobile.
