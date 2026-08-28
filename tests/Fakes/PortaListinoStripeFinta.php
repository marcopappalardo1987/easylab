<?php

namespace Tests\Fakes;

use App\Models\Piano;
use App\Support\Listino\Stripe\PortaListinoStripe;
use App\Support\Listino\Stripe\PrezzoRemoto;
use RuntimeException;

/**
 * La porta verso Stripe, finta (🔗 ADR-035).
 *
 * ⚠️ **Non verifica le risposte di Stripe, e non deve provarci**: la porta vera
 * (`PortaListinoStripeReale`) è dichiarata senza test di suite, per la stessa
 * ragione di `easylab:abbona` — mockare `StripeClient` produce un test che
 * verifica il proprio mock. Questa finta esiste per provare l'altra metà,
 * quella che conta: le **nostre** transizioni di stato — l'idempotenza a due
 * livelli, lo storico dei prezzi, cosa resta scritto quando Stripe rifiuta.
 *
 * ⚠️ **Conta le chiamate, e il conteggio è un'asserzione di prima classe.** Due
 * prove del blocco poggiano solo su questo: che un doppio invio non lasci
 * prodotti né prezzi gemelli, e che il `render()` della schermata **non tocchi
 * Stripe affatto** — una pagina di piattaforma che muore perché un fornitore
 * esterno è giù è il difetto che ADR-035 nomina per primo.
 *
 * Vive in `tests/Fakes/` (namespace `Tests\Fakes`, già coperto dal `psr-4` di
 * `autoload-dev`) e **non** in un file di test: una classe dichiarata in un file
 * di test esiste solo se quel file viene selezionato, ed è la cicatrice già
 * pagata dal progetto con `snapshotDa()`.
 */
final class PortaListinoStripeFinta implements PortaListinoStripe
{
    /**
     * Ogni chiamata, in ordine e per nome del metodo.
     *
     * Lista e non contatori separati: l'**ordine** è parte di ciò che si prova
     * (il prezzo si crea dopo il prodotto, il vecchio si archivia dopo che il
     * nuovo è scritto a database), e un contatore per metodo lo perderebbe.
     *
     * @var list<string>
     */
    public array $chiamate = [];

    /**
     * Se valorizzato, **ogni** metodo lancia con questo messaggio.
     *
     * La chiamata resta comunque registrata: è ciò che permette di distinguere
     * «Stripe ha rifiutato» da «non ci siamo nemmeno arrivati».
     */
    public ?string $lancia = null;

    /** @var array<string, PrezzoRemoto> price id → ciò che Stripe risponde */
    public array $prezzi = [];

    /** @var array<string, array{id: string, nome: string, attivo: bool}> product id → ciò che Stripe risponde */
    public array $prodotti = [];

    /** Rende irripetibili gli id dei price creati nella stessa richiesta. */
    private int $progressivo = 0;

    public function creaOAggiornaProdotto(Piano $piano): string
    {
        // I due gesti si distinguono per nome, e non è cosmesi: il primo livello
        // di idempotenza è precisamente «se il product id c'è già, non se ne
        // crea un secondo», e un solo nome per entrambi renderebbe la prova
        // cieca proprio sulla cosa che deve vedere.
        $this->registra(filled($piano->stripe_product_id) ? 'aggiornaProdotto' : 'creaProdotto');

        $id = filled($piano->stripe_product_id) ? (string) $piano->stripe_product_id : 'prod_'.$piano->codice;

        $this->prodotti[$id] = [
            'id' => $id,
            'nome' => (string) $piano->etichetta,
            'attivo' => $this->prodotti[$id]['attivo'] ?? true,
        ];

        return $id;
    }

    public function creaPrezzo(Piano $piano, int $importoCent, string $valuta, string $chiaveIdempotenza): PrezzoRemoto
    {
        $this->registra('creaPrezzo');

        $prezzo = new PrezzoRemoto(
            id: 'price_'.$piano->codice.'_'.(++$this->progressivo),
            importoCent: $importoCent,
            valuta: $valuta,
            attivo: true,
        );

        $this->prezzi[$prezzo->id] = $prezzo;

        return $prezzo;
    }

    public function archiviaPrezzo(string $priceId): void
    {
        $this->registra('archiviaPrezzo');

        if (isset($this->prezzi[$priceId])) {
            $vecchio = $this->prezzi[$priceId];

            $this->prezzi[$priceId] = new PrezzoRemoto($vecchio->id, $vecchio->importoCent, $vecchio->valuta, false);
        }
    }

    public function leggiProdotto(string $productId): ?array
    {
        $this->registra('leggiProdotto');

        return $this->prodotti[$productId] ?? null;
    }

    public function leggiPrezzo(string $priceId): ?PrezzoRemoto
    {
        $this->registra('leggiPrezzo');

        return $this->prezzi[$priceId] ?? null;
    }

    /** Quante volte è stato chiamato un metodo — o quante chiamate in tutto. */
    public function quante(?string $metodo = null): int
    {
        return $metodo === null
            ? count($this->chiamate)
            : count(array_filter($this->chiamate, fn (string $c) => $c === $metodo));
    }

    /** Dichiara cosa Stripe risponde per un price già esistente. */
    public function conPrezzo(string $id, int $importoCent, string $valuta = 'eur', bool $attivo = true): self
    {
        $this->prezzi[$id] = new PrezzoRemoto($id, $importoCent, $valuta, $attivo);

        return $this;
    }

    /** Dichiara cosa Stripe risponde per un product già esistente. */
    public function conProdotto(string $id, string $nome = 'Prodotto', bool $attivo = true): self
    {
        $this->prodotti[$id] = ['id' => $id, 'nome' => $nome, 'attivo' => $attivo];

        return $this;
    }

    private function registra(string $metodo): void
    {
        $this->chiamate[] = $metodo;

        if ($this->lancia !== null) {
            throw new RuntimeException($this->lancia);
        }
    }
}
