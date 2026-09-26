<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Riallinea a `Europe/Rome` i timestamp scritti quando l'app era a UTC.
 *
 * 🔗 ADR-041. Accompagna il cambio di `config/app.php` e **non è separabile da
 * quello**: le colonne Postgres sono `timestamp without time zone` e non
 * portano il fuso, quindi cambiare la config non sposta i dati — cambia come
 * vengono letti. Senza questa migration ogni riga anteriore verrebbe riletta
 * come se fosse già ora italiana, cioè mostrata due ore avanti. Su
 * `activity_log`, che 🔗 ADR-027 dichiara non riscrivibile, sarebbe una
 * falsificazione retroattiva silenziosa.
 *
 * ⛔ **Le colonne `date` non si toccano, ed è il punto delicato.** Le nove
 * colonne castate `date` (`data_scadenza`, `data_esecuzione`,
 * `data_installazione`, `data_inizio`, `data_scadenza_dichiarata`,
 * `data_scadenza_effettiva`, `spostamenti_strumento.data`,
 * `ricambio_utilizzo.data`, `avvisi_scadenza.data_scadenza`) sono **giorni
 * civili**, non istanti: la mezzanotte di un giorno puro convertita a ritroso
 * diventa le 22:00 del giorno prima, cioè la scadenza slitta. Il filtro su
 * `data_type like 'timestamp%'` le esclude per costruzione — `date` è un tipo
 * diverso in `information_schema`, non una variante — e per questo l'elenco
 * **si deriva** invece di essere scritto a mano: 83 nomi copiati sarebbero 83
 * occasioni di sbagliarne uno, e l'errore sarebbe invisibile.
 *
 * ⚠️ **`AT TIME ZONE` due volte non è un refuso.** Il primo passaggio dichiara
 * come va *interpretato* il valore nudo (era UTC), il secondo dice in quale
 * fuso *renderlo*. Postgres applica l'ora legale storica da sé, riga per riga:
 * una riga di gennaio si sposta di un'ora, una di luglio di due. È la ragione
 * per cui non si somma un intervallo fisso.
 *
 * Le tabelle effimere restano fuori: `sessions`, `cache`, `jobs` e sorelle
 * portano scadenze relative che si rigenerano da sole, e correggerle
 * significherebbe allungare la vita a una sessione o rimandare un job.
 *
 * ⛔ **`down()` è reversibile solo se eseguito subito.** Riporta indietro
 * *tutte* le righe, comprese quelle scritte dopo la migration, che erano già in
 * ora italiana e finirebbero due ore nel passato. Non è una simmetria vera, ed
 * è scritto qui perché questo progetto ha già avuto un `down()` distruttivo
 * dichiarato chiuso e non lo era (🔗 ADR-019).
 */
return new class extends Migration
{
    /**
     * Tabelle il cui contenuto è transitorio: correggerle non ripara niente e
     * cambierebbe scadenze che il framework gestisce per conto suo.
     */
    private const EFFIMERE = [
        'migrations',
        'sessions',
        'cache',
        'cache_locks',
        'jobs',
        'job_batches',
        'failed_jobs',
        'password_reset_tokens',
    ];

    public function up(): void
    {
        $this->sposta('UTC', 'Europe/Rome');
    }

    public function down(): void
    {
        $this->sposta('Europe/Rome', 'UTC');
    }

    private function sposta(string $da, string $a): void
    {
        // SQLite non ha `AT TIME ZONE`, e non serve: la suite ricrea il
        // database da zero a ogni test, quindi non esiste nessuno storico
        // scritto sotto il fuso vecchio da riallineare.
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        foreach ($this->colonneTimestamp() as $colonna) {
            DB::statement(sprintf(
                'update %s set %s = (%s at time zone ?) at time zone ? where %s is not null',
                $colonna->tabella,
                $colonna->nome,
                $colonna->nome,
                $colonna->nome,
            ), [$da, $a]);
        }
    }

    /**
     * Le colonne da correggere, lette dallo schema invece che elencate.
     *
     * `BASE TABLE` esclude le viste, che non hanno righe proprie da riscrivere.
     *
     * @return list<object{tabella: string, nome: string}>
     */
    private function colonneTimestamp(): array
    {
        return DB::select(
            'select c.table_name as tabella, c.column_name as nome
               from information_schema.columns c
               join information_schema.tables t
                 on t.table_schema = c.table_schema and t.table_name = c.table_name
              where c.table_schema = current_schema()
                and t.table_type = ?
                and c.data_type like ?
                and c.table_name not in ('.implode(', ', array_fill(0, count(self::EFFIMERE), '?')).')
              order by c.table_name, c.column_name',
            ['BASE TABLE', 'timestamp%', ...self::EFFIMERE],
        );
    }
};
