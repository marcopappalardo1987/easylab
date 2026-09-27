#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/../.."
source guide/bin/demo-env.sh

php artisan migrate --force
php artisan db:seed --class=RolesAndPermissionsSeeder --force
php artisan easylab:provision-tenant "Laboratori Aurora" \
  --sede="Sede di Milano" \
  --admin-email=maria.conti@aurora.test \
  --admin-name="Maria Conti" \
  --admin-password=guida-demo
php artisan db:seed --class=DemoSeeder --force
# Seconda sede sullo stesso account: senza, lo switcher di ADR-032 non compare
# e la guida «cambiare-sede» non ha nulla da mostrare. Porta l'account su `saas`
# perché `free` ha max_enti = 1.
php artisan db:seed --class=GuidaSecondaSedeSeeder --force

# Responsabile Reparto: nessun 2FA obbligatorio (config/rbac.php lo impone a
# Developer/Superadmin/Admin), quindi è il ruolo che si riprende senza mostrare
# un passaggio di sicurezza che non c'entra col flusso raccontato.
php artisan tinker --execute='
use App\Models\{User,UnitaOrganizzativa,Account}; use Illuminate\Support\Facades\{DB,Hash};
$ente = UnitaOrganizzativa::withoutGlobalScopes()->whereNull("parent_id")->where("nome","Sede di Milano")->firstOrFail();
$u = User::updateOrCreate(["email"=>"giulia.ferrari@aurora.test"],["name"=>"Giulia Ferrari","password"=>Hash::make("guida-demo"),"email_verified_at"=>now()]);
$u->forceFill(["tenant_id"=>$ente->id])->save();
$u->syncRoles(["Responsabile Reparto"]);
Account::find($ente->account_id)->aggiungiMembro($u);
// Senza reparti assegnati il Responsabile è fail-closed e non vede nulla (ADR-006),
// e questo vale in OGNI sede: passando a Bologna senza reparti là, lo switcher
// funzionerebbe e la schermata resterebbe vuota.
foreach (UnitaOrganizzativa::withoutGlobalScopes()->whereNull("parent_id")->whereIn("nome",["Sede di Milano","Sede di Bologna"])->pluck("id") as $sede) {
    foreach (UnitaOrganizzativa::withoutGlobalScopes()->where("tenant_id",$sede)->where("tipo","dipartimento")->take(3)->pluck("id") as $n) {
        DB::table("responsabile_unita")->insertOrIgnore(["user_id"=>$u->id,"unita_organizzativa_id"=>$n]);
    }
}
// Cliente intestatario del contratto, ruolo Tenant, per la guida «cambiare-sede».
// ⚠️ Non un Responsabile: per lui lo switcher NON compare (DepartmentScope
// esclude il nodo Ente, quindi `nomeEnte` è null e la vista è tutta dietro quel
// controllo). Difetto segnalato, non aggirabile dai dati.
$t = User::updateOrCreate(["email"=>"paolo.greco@aurora.test"],["name"=>"Paolo Greco","password"=>Hash::make("guida-demo"),"email_verified_at"=>now()]);
$t->forceFill(["tenant_id"=>$ente->id])->save();
$t->syncRoles(["Tenant"]);
Account::find($ente->account_id)->aggiungiMembro($t);
// Invitato non ancora attivo, per la guida «primo-accesso»: il link firmato vale
// finché `email_verified_at` è NULL (ImpostaPasswordInvito::giaAttivato).
$invitato = User::updateOrCreate(
    ["email"=>"marta.villa@aurora.test"],
    ["name"=>"Marta Villa","password"=>Hash::make(str()->random(64)),"email_verified_at"=>null],
);
$invitato->forceFill(["tenant_id"=>$ente->id])->save();
$invitato->syncRoles(["Tecnico"]);
'

# ⚠️ Le notifiche in campanella devono ESISTERE, o la guida «Orientarsi» dice
# «la campanella porta le stesse scadenze» sopra un pannello che risponde
# «Nessuna notifica». Il digest scrive sul canale `database`, quindi basta
# lanciarlo: qui MAIL_MAILER=log, non parte niente verso nessuno.
#
# ⛔ Senza `--senza-invio`, apposta: è quel passaggio che crea le notifiche.
# In PRODUZIONE vale la regola opposta (primo avvio con `--senza-invio`, o
# parte un digest da migliaia di righe) — 🔗 CLAUDE.md.
php artisan easylab:notifica-scadenze
