#!/usr/bin/env bash
# Pianta nel SEME del DB dimostrativo gli account di PIATTAFORMA.
#
# ⚠️ Il seme nasce da `DemoSeeder`, che semina clienti: Superadmin e Developer
# non ci sono, quindi le dieci guide del gruppo M non erano filmabili con
# nessuno degli utenti esistenti.
#
# Usa `SuperadminSeeder`, che è il seeder vero del progetto: l'account di
# piattaforma nasce col proprio Ente e `di_piattaforma`, come in produzione
# (ERD §4.1/4.2, ADR-018). Il Developer si aggiunge accanto, perché tre guide —
# editor dei ruoli, registro di audit, error tracker — vivono dietro permessi
# che il Superadmin non ha.
#
# ⚠️ Entrambi hanno la 2FA obbligatoria: si pianta lo stesso segreto di scena
# di `pianta-2fa.sh`, così `lib/accesso.ts` entra con tutti allo stesso modo.
#
# ⛔ Va nel SEME: `azzera.sh` gira prima di ogni copione. Ordine: azzera,
# pianta, risemina.
set -euo pipefail
cd "$(dirname "$0")/.."

./bin/azzera.sh

cd ..
source guide/bin/demo-env.sh

SUPERADMIN_EMAIL=superadmin@easylab.test \
SUPERADMIN_PASSWORD=guida-demo \
php artisan db:seed --class=SuperadminSeeder --no-interaction

php artisan tinker --execute="
\$segreto = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

\$super = App\Models\User::withoutGlobalScopes()->where('email','superadmin@easylab.test')->firstOrFail();

// Il Developer vive nello stesso Ente di piattaforma del Superadmin.
\$dev = App\Models\User::withoutGlobalScopes()->firstOrCreate(
    ['email' => 'developer@easylab.test'],
    ['name' => 'Developer EasyLab', 'password' => bcrypt('guida-demo'), 'tenant_id' => \$super->tenant_id, 'email_verified_at' => now()],
);
\$dev->forceFill(['tenant_id' => \$super->tenant_id, 'password' => bcrypt('guida-demo'), 'email_verified_at' => now()])->save();
\$dev->syncRoles(['Developer']);

foreach ([\$super, \$dev] as \$u) {
    \$u->forceFill([
        'two_factor_secret' => encrypt(\$segreto),
        'two_factor_recovery_codes' => encrypt(json_encode(['demo-recupero-1','demo-recupero-2'])),
        'two_factor_confirmed_at' => now(),
    ])->save();
}

echo 'piattaforma: ', \$super->email, ' (', \$super->roles->pluck('name')->implode(','), ') e ', \$dev->email, ' (', \$dev->fresh()->roles->pluck('name')->implode(','), ')';
"

cd guide
./bin/azzera.sh --semina
echo "Il seme porta ora Superadmin e Developer di piattaforma."
