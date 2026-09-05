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
     * ⚠️ **L'ingresso chiuso non toglie il link: lo marca.** Fino al 5 Set 2026
     * a interruttore spento l'elenco usciva vuoto, e la cella non mostrava
     * niente — cioè la stessa faccia che ha un price non configurato, un piano
     * archiviato e un difetto di questa classe. Un'assenza che significa quattro
     * cose diverse non è una difesa, è una domanda a cui bisogna rispondere
     * andando a leggere il `.env`: è successo il giorno stesso del rilascio.
     * Il link resta, con accanto la ragione per cui oggi risponde 404, che è
     * l'unica forma in cui la pagina può dirlo prima del cliente.
     *
     * @return array<string, string>
     */
    public static function perPiano(): array
    {
        $link = [];

        foreach (PianiRegistrabili::codici() as $codice) {
            $link[$codice] = route('registrazione.mostra', ['piano' => $codice]);
        }

        return $link;
    }

    /**
     * Se oggi quel link porta davvero da qualche parte.
     *
     * `registrazione.aperta` spegne il **solo** ingresso: `/registrati` risponde
     * 404, mentre i passi successivi restano aperti perché chi ha già pagato
     * deve poter completare (vedi `RegistrazionePubblica`). Da qui la cabina
     * distingue «il link non c'è» da «il link c'è ma la porta è chiusa».
     */
    public static function ingressoAperto(): bool
    {
        return (bool) config('easylab.registrazione.aperta', false);
    }
}
