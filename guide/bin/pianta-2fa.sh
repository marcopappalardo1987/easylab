#!/usr/bin/env bash
# Pianta nel SEME del DB dimostrativo un segreto TOTP noto, e lo conferma.
#
# ⚠️ Senza questo, un quarto del catalogo non è filmabile: `config/rbac.php`
# impone la verifica in due passaggi a Developer, Superadmin e Admin, quindi
# nessun copione può aprire una schermata di amministrazione (STILE.md §8).
#
# Il segreto è un DATO DI SCENA, non una credenziale: è scritto in chiaro in
# `lib/totp.ts`, da cui Playwright calcola il codice a sei cifre al momento del
# login. Vive solo in `easylab_demo`, che nessun ambiente vero raggiunge.
#
# ⛔ Va nel SEME, non nel DB corrente: `azzera.sh` gira prima di ogni copione e
# ricrea `easylab_demo` dal template, quindi un segreto piantato a valle
# sparirebbe alla giratura successiva. Da qui l'ordine: azzera, pianta, risemina.
set -euo pipefail
cd "$(dirname "$0")/.."

./bin/azzera.sh

cd ..
source guide/bin/demo-env.sh
php artisan tinker --execute="
\$u = App\Models\User::withoutGlobalScopes()->where('email','maria.conti@aurora.test')->firstOrFail();
\$u->forceFill([
    'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP'),
    'two_factor_recovery_codes' => encrypt(json_encode(['demo-recupero-1','demo-recupero-2'])),
    'two_factor_confirmed_at' => now(),
])->save();
echo \$u->fresh()->hasEnabledTwoFactorAuthentication() ? '2FA attiva su '.\$u->email : 'FALLITO';
"

cd guide
./bin/azzera.sh --semina
echo "Il seme porta ora la 2FA dell'Admin dimostrativo."
