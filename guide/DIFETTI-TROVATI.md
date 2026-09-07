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

---

## 2. Gli strumenti seminati nascono senza token QR, e la pagina va in 500

**Trovato**: 7 Set 2026, girando `etichetta-qr`.
**Dove**: `database/seeders/DemoSeeder.php`, blocco degli strumenti.
**Effetto**: `/strumenti/{id}/qr` risponde **500** —
`UrlGenerationException: Missing required parameter for [Route: qr.strumento]
[Missing parameter: token]` — su **ogni** strumento seminato. Erano 7559 su 7559
nel DB dimostrativo, e lo stesso vale per il DB di sviluppo.

**Perché**: il token lo assegna un hook `creating` sul model:

```php
static::creating(function (Strumento $strumento): void {
    $strumento->qr_token ??= self::nuovoQrToken();
```

ma il seeder usa `Strumento::insert()` a blocchi, e con `insert()` gli eventi
Eloquent **non scattano**. Il docblock del seeder dichiara di costruire ogni riga
«già conforme agli invarianti del dominio»: `qr_token` era uno di quelli, e
mancava.

**✅ Corretto** — a differenza del difetto n. 1, questo non tocca aree rosse: è
un campo che il seeder doveva riempire e non riempiva. Il seeder ora lo genera,
e `verificaInvarianti()` fallisce rumorosamente se un solo strumento resta senza.

⚠️ **Resta da fare sul DB di sviluppo**: le righe già seminate lì hanno ancora
`qr_token` nullo, quindi la pagina QR è rotta anche in locale. Il DB
dimostrativo è stato riempito e riseminato; quello di sviluppo è di Marco e non
è stato toccato. Si sistema con una UPDATE mirata sulle sole righe nulle.

⚠️ **E la lezione generale vale oltre questo campo**: ogni `insert()` di massa
salta i hook del model, quindi ogni invariante che vive in un hook va
riprodotto a mano nel seeder — e verificato alla fine, o non se ne accorge
nessuno finché qualcuno non apre la pagina.
