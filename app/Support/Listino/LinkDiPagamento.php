<?php

namespace App\Support\Listino;

use App\Support\Registrazione\PianiRegistrabili;

/**
 * Il link da mandare a un cliente perché paghi un piano: il **modulo pubblico**
 * con la radio già scelta (🔗 ADR-012 il self-signup, ADR-035 il listino).
 *
 * ## 🔴 Perché NON un Payment Link di Stripe
 *
 * Un `plink_...` incassa e basta: la sessione che apre non porta nei metadata la
 * riga `registrazioni`, quindi `checkout.session.completed` non ha niente da
 * completare e `CompletaRegistrazione` non fa nascere nessun Account (vedi
 * `PortaleCheckoutStripe`). Sarebbe un pagamento senza consegna, cioè il guasto
 * peggiore che questa area possa produrre. Passando da `/registrati?piano=` il
 * percorso resta quello provato: verifica email → Checkout → provisioning.
 *
 * ## Perché una classe e non un metodo del componente
 *
 * ⚠️ `AccessoRegistrazioniGuardrailTest` vieta a **ogni** componente Livewire di
 * nominare una `Registrazione`: le schermate sono autenticate, e `registrazioni`
 * è la tabella senza scope che l'esenzione di ADR-012 ha reso possibile. La
 * regola di vendibilità vive quindi qui, e la cabina chiama un nome che non
 * appartiene a quel namespace. Duplicarla nel componente sarebbe l'alternativa
 * peggiore: due definizioni di «cosa si vende» che divergono al primo piano
 * archiviato.
 */
final class LinkDiPagamento
{
    /**
     * I link per i piani che il modulo pubblico venderebbe davvero, per codice.
     *
     * A ingresso chiuso l'elenco è **vuoto**: `/registrati` risponde 404, e
     * mostrare un link che porta a una pagina inesistente è peggio che non
     * mostrarne nessuno, perché lo si scopre dal lato del cliente.
     *
     * @return array<string, string>
     */
    public static function perPiano(): array
    {
        if (! config('easylab.registrazione.aperta', false)) {
            return [];
        }

        $link = [];

        foreach (PianiRegistrabili::codici() as $codice) {
            $link[$codice] = route('registrazione.mostra', ['piano' => $codice]);
        }

        return $link;
    }
}
