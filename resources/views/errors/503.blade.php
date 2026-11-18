{{--
    ⛔ Autosufficiente: niente layout, niente @vite, niente sessione né
    database. Questa pagina si mostra proprio quando qualcosa dell'applicazione
    non funziona, e non deve dipendere da quel qualcosa.
--}}
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Easy Lab è in manutenzione — Easy Lab</title>
    <style>
        :root { --fondo: #f8fafc; --testo: #0f172a; --testo-2: #475569; --marchio: #1d4ed8; }
        @media (prefers-color-scheme: dark) { :root { --fondo: #0b1220; --testo: #e2e8f0; --testo-2: #94a3b8; --marchio: #60a5fa; } }
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; background: var(--fondo); color: var(--testo); font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif; }
        main { max-width: 24rem; padding: 3rem 1rem; text-align: center; }
        .codice { color: var(--marchio); font-size: .875rem; font-weight: 600; margin: 0; }
        h1 { font-size: 1.5rem; margin: .5rem 0 0; }
        p.testo { color: var(--testo-2); font-size: .875rem; line-height: 1.5; margin-top: .75rem; }
    </style>
</head>
<body>
    <main data-errore="503">
        <p class="codice">503</p>
        <h1>Easy Lab è in manutenzione</h1>
        <p class="testo">Stiamo aggiornando l'applicazione. Torna fra qualche minuto.</p>
    </main>
</body>
</html>
