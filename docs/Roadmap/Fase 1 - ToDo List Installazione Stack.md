**🛠️ Core e Ambiente**

- [ ] Verificare l'installazione di PHP e Composer.
- [ ] Inizializzare il nuovo progetto Laravel (composer create-project laravel/laravel easy-lab).
- [ ] Configurare il database (PostgreSQL o MySQL) aggiornando i parametri nel file .env.
- [ ] Avviare il server Redis e impostare CACHE_STORE=redis e QUEUE_CONNECTION=redis nel .env.
- [ ] Eseguire le migrazioni base (php artisan migrate).

**🎨 TALL Stack (Frontend)**

- [ ] Installare Livewire (composer require livewire/livewire).
- [ ] Installare Tailwind CSS, PostCSS e Autoprefixer (npm install -D tailwindcss postcss autoprefixer).
- [ ] Inizializzare la configurazione di Tailwind (npx tailwindcss init -p).
- [ ] Mappare i percorsi delle viste Blade all'interno del file tailwind.config.js.
- [ ] Installare Alpine.js (npm install alpinejs) se serve una gestione custom oltre a quella nativa di Livewire 3.
- [ ] Compilare gli asset frontend (npm run build o npm run dev).

**⚙️ SaaS, Ruoli e Autenticazione**

- [ ] Installare Laravel Cashier per l'integrazione con Stripe (composer require laravel/cashier).
- [ ] Pubblicare le migrazioni di Cashier ed aggiornare il database (php artisan migrate).
- [ ] Inserire le chiavi API e i webhook di Stripe (STRIPE_KEY, STRIPE_SECRET) nel file .env.
- [ ] Installare Spatie Laravel Permission per la gestione di Super Admin e ruoli di reparto (composer require spatie/laravel-permission).
- [ ] Pubblicare la configurazione di Spatie ed eseguire le relative migrazioni.
- [ ] Installare il pacchetto per l'impersonificazione utente (composer require lab404/laravel-impersonate).

**📦 Moduli Specifici (QR Code e PDF)**

- [ ] Installare il pacchetto per la generazione dei QR Code fisici da applicare alle macchine (composer require simplesoftwareio/simple-qrcode).
- [ ] Installare la libreria per l'esportazione dei report di fine lavoro in PDF (composer require barryvdh/laravel-dompdf oppure spatie/laravel-pdf).
- [ ] Pubblicare i file di configurazione (vendor:publish) per i pacchetti QR e PDF in modo da personalizzare la grafica e i margini dei documenti.
