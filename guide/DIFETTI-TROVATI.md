# Difetti del prodotto emersi girando le guide

Le guide percorrono l'applicazione come la percorre un cliente, e trovano cose
che i test non trovano perché i test sanno già dove guardare. Qui restano
annotate: **non sono state corrette** — toccano aree rosse (tenancy,
autorizzazioni) e la correzione è una decisione di Marco, non dello studio guide.

---

## 1. Lo switcher fra sedi non compare mai a un Responsabile Reparto

**Trovato**: 6 Set 2026, girando `cambiare-sede`.
**Dove**: `app/Livewire/Tenancy/SwitcherEnte.php`, `mount()`.
**Effetto**: un Responsabile Reparto non vede né il nome della propria sede in
alto a sinistra, né la tendina per cambiarla — anche quando il suo account ne ha
più d'una e `sediRaggiungibili` vale correttamente 1.

**Perché**:

```php
$this->nomeEnte = UnitaOrganizzativa::whereKey($corrente)->value('nome');
```

La query gira con i global scope attivi. `TenantScope` la lascia passare (il
nodo Ente porta come `tenant_id` il proprio id, ed è esattamente il commento nel
codice), ma **`DepartmentScope` no**: per un Responsabile filtra su
`AccessibleNodes::forCurrentUser()`, e la radice dell'albero non è fra i reparti
assegnati né loro discendente. `nomeEnte` resta `null`, e
`switcher-ente.blade.php` è tutto dietro `@if ($nomeEnte !== null)`.

Verificato in tinker sul DB dimostrativo:

```
tenant corrente = 1
nomeEnte (con scope) = NULL
nodi accessibili = 17; radice inclusa = false
```

**Nota**: il commento in `mount()` motiva la scelta di leggere dal modello invece
che da `sediRaggiungibili()` — «un utente la cui sede non passa dal pivot del
contratto perdeva del tutto l'etichetta». La motivazione è sul livello 1 dello
scope; il livello 2 non è stato considerato.

**Aggirato come**: la guida `cambiare-sede` è girata con un utente **Tenant**
(`paolo.greco@aurora.test`), per cui `AccessibleNodes` restituisce `null` e
nessuna restrizione di reparto si applica. È anche la persona giusta per quel
racconto — l'intestatario del contratto — quindi la guida non ne soffre. Ma il
difetto resta, e riguarda ogni Responsabile di un cliente multi-sede.

**Da decidere**: se la radice debba essere sempre visibile a chi ci sta dentro
(cioè se `AccessibleNodes` debba includere gli antenati dei nodi assegnati), o
se basti leggere l'etichetta con `withoutGlobalScope(DepartmentScope::class)`.
La prima cambia il comportamento di ogni elenco; la seconda tocca solo la barra.
