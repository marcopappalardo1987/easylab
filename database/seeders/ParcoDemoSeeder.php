<?php

namespace Database\Seeders;

use App\Enums\TipoUnitaOrganizzativa;
use App\Models\Account;
use App\Models\UnitaOrganizzativa;
use Illuminate\Database\Seeder;

/**
 * Molti clienti, molte sedi, molte macchine — per far vedere il **Parco
 * clienti** con dati veri invece che con un cliente solo (🔗 ADR-037, ADR-032).
 *
 * ## Perché non basta `DemoSeeder`
 *
 * Quello popola gli Enti che trova e ne aggiunge due, condividendo un account:
 * risponde alla domanda «l'isolamento funziona?». Il Parco fa una domanda
 * diversa — «la colonna Cliente sta facendo il suo mestiere, il perimetro
 * seleziona davvero, i numeri reggono su volumi grossi?» — e con un cliente
 * solo non è giudicabile a occhio. Serve **varietà**: clienti con piani
 * diversi, con una sede o con molte, e volumi che sfidino la paginazione.
 *
 * ## Cosa costruisce
 *
 * Otto clienti, ciascuno con 1..4 sedi, e per ogni sede l'alberatura completa
 * con le sue macchine, interventi, garanzie, ricambi e spostamenti — perché
 * riusa `popolaEnte()` di `DemoSeeder`, che è l'unico posto in cui quella
 * forma è definita. Riscriverla qui significherebbe avere due dati demo che
 * divergono, e il Parco mostrerebbe righe diverse dalle schermate per-Ente.
 *
 * ⚠️ **Il piano si assegna col metodo del modello e non a mano**:
 * `cambiaPiano()` rifiuta un codice fuori catalogo, mentre un `forceFill`
 * scriverebbe qualunque stringa — e un cliente su un piano inesistente vale
 * 0 € nell'MRR e non compare in nessun filtro «per piano». È lo stato che la
 * cabina chiama «fuori catalogo», ed è giusto che ci si arrivi solo dismettendo
 * un piano davvero, non seminandolo.
 *
 * ⛔ **Il tetto di sedi del piano NON si rispetta qui, e va detto**: `saas` ne
 * include 5 e `free` una sola, ma `Account::puoAggiungereEnte()` è la guardia
 * dell'*interfaccia* di provisioning, non un vincolo di schema. Un seeder che
 * la aggira crea uno stato che dalla UI non si potrebbe raggiungere — comodo
 * per la dimostrazione, sbagliato da confondere con la realtà. I clienti con
 * più sedi del proprio piano sono quindi **deliberati**, e servono anche a
 * vedere come il Parco si comporta su un account fuori limite.
 *
 * ⚠️ **Additivo e ripetibile**: `firstOrCreate` sugli account, e gli Enti si
 * creano solo se il cliente non ne ha già. Rilanciarlo non duplica i clienti,
 * ma **aggiunge macchine** alle sedi esistenti, perché `popolaEnte()` è di per
 * sé additivo. Volendo più volume, è la via.
 */
class ParcoDemoSeeder extends DemoSeeder
{
    /**
     * I clienti, con quante sedi e su quale piano.
     *
     * Nomi di fantasia ma verosimili: un elenco di «Cliente 1..8» non fa vedere
     * se la colonna Cliente tronca, ordina e si distingue a colpo d'occhio, che
     * è metà del motivo per cui questa vista esiste.
     *
     * @var list<array{nome: string, sedi: list<string>, piano: string}>
     */
    private const CLIENTI = [
        ['nome' => 'Gruppo Ospedaliero Lombardo', 'piano' => 'saas', 'sedi' => [
            'Presidio di Milano Niguarda', 'Presidio di Monza', 'Presidio di Lecco', 'Presidio di Bergamo',
        ]],
        ['nome' => 'Azienda Sanitaria Sud Tirreno', 'piano' => 'saas', 'sedi' => [
            'Ospedale di Salerno', 'Ospedale di Battipaglia', 'Poliambulatorio di Cava',
        ]],
        ['nome' => 'Rete Laboratori Veneti', 'piano' => 'saas', 'sedi' => [
            'Sede di Verona', 'Sede di Vicenza', 'Sede di Rovigo',
        ]],
        ['nome' => 'Istituto Oncologico Adriatico', 'piano' => 'saas', 'sedi' => [
            'Sede di Ancona', 'Sede di Pescara',
        ]],
        ['nome' => 'Centro Diagnostico Etneo', 'piano' => 'saas', 'sedi' => [
            'Catania Centro', 'Acireale',
        ]],
        ['nome' => 'Laboratorio Analisi Bianchi', 'piano' => 'free', 'sedi' => [
            'Sede unica di Torino',
        ]],
        ['nome' => 'Poliambulatorio San Giorgio', 'piano' => 'free', 'sedi' => [
            'Sede di Genova',
        ]],
        ['nome' => 'Studio Biomedico Aurora', 'piano' => 'free', 'sedi' => [
            'Sede di Firenze',
        ]],
    ];

    public function run(): void
    {
        // ⚠️ NIENTE transazione unica attorno a tutto, a differenza di
        // `DemoSeeder`: qui si creano decine di migliaia di righe, e una
        // transazione sola le terrebbe tutte in sospeso fino alla fine — su
        // Postgres significa un lock lungo minuti su un ambiente che qualcuno
        // potrebbe stare guardando. Ogni sede è già atomica per conto suo.
        foreach (self::CLIENTI as $definizione) {
            $account = Account::firstOrCreate(['ragione_sociale' => $definizione['nome']]);
            $account->cambiaPiano($definizione['piano']);

            $this->command?->info("Cliente «{$definizione['nome']}» ({$definizione['piano']}):");

            foreach ($definizione['sedi'] as $nomeSede) {
                $ente = $this->sedeDi($account, $nomeSede);

                $this->command?->info("  sede «{$nomeSede}»…");
                $this->popolaEnte($ente, $account);
            }
        }

        $this->verificaInvarianti();
        $this->riepilogo();
    }

    /**
     * La sede, creata solo se non c'è già.
     *
     * ⚠️ `withoutGlobalScopes()` è la forma corretta **in un seeder**: gira in
     * console, dove `CurrentTenant::shouldScope()` è già falso e il
     * `TenantScope` non filtrerebbe comunque. Lo si scrive lo stesso perché la
     * riga dica da sé che qui si guarda oltre il tenant, invece di dipendere
     * dal contesto in cui capita di essere eseguita.
     */
    private function sedeDi(Account $account, string $nome): UnitaOrganizzativa
    {
        $esistente = UnitaOrganizzativa::withoutGlobalScopes()
            ->where('account_id', $account->id)
            ->where('nome', $nome)
            ->first();

        if ($esistente !== null) {
            return $esistente;
        }

        $ente = UnitaOrganizzativa::create([
            'tipo' => TipoUnitaOrganizzativa::Ente,
            'parent_id' => null,
            'nome' => $nome,
            'note' => 'Sede di dimostrazione del Parco clienti',
        ]);

        // Il nodo Ente porta come `tenant_id` il proprio id: è l'invariante su
        // cui poggia tutto il TenantScope.
        $ente->forceFill(['tenant_id' => $ente->id, 'account_id' => $account->id])->saveQuietly();

        return $ente;
    }
}
