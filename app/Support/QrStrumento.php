<?php

namespace App\Support;

use App\Models\Strumento;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\URL;

/**
 * Il QR di uno strumento: l'URL che ci sta dentro e l'SVG da stampare
 * (🔗 ADR-003, Tech Stack §6).
 *
 * **Perché `bacon/bacon-qr-code` e non `simplesoftwareio/simple-qrcode`**, che
 * il Tech Stack §46 indicava: quest'ultima è ferma a `bacon ^2.0`, mentre il
 * progetto ha già `bacon v3.1.1` — installata da **Fortify**, che la usa per il
 * QR della verifica in due passaggi. Installare il wrapper avrebbe richiesto di
 * retrocedere una dipendenza dell'autenticazione per avere una comodità di
 * sintassi. Si usa quindi direttamente la libreria sottostante, con lo stesso
 * idioma di `TwoFactorAuthenticatable`: zero dipendenze nuove.
 *
 * **L'URL è firmato e non scade** (decisione del 15 Ago 2026): un adesivo su
 * una macchina vive quanto la macchina, e ciò che serve davvero — invalidare
 * una singola etichetta — lo dà `Strumento::rigeneraQrToken()`. Una scadenza
 * costringerebbe a ristampare periodicamente migliaia di etichette, cosa che
 * nessuno farà: una scadenza che non si può rispettare è peggio che nessuna.
 *
 * ⚠️ **La firma dipende da `APP_URL`**: cambiare il dominio dell'applicazione
 * invalida ogni etichetta già stampata. Va saputo prima di stampare il parco,
 * non dopo.
 */
final class QrStrumento
{
    /**
     * L'URL firmato che finisce dentro il QR.
     *
     * Punta al token e non all'id: l'id è indovinabile per definizione — chi
     * legge l'adesivo di una macchina proverebbe il numero accanto — mentre il
     * token è un segreto per riga. La firma protegge da un'altra cosa ancora,
     * cioè che qualcuno *costruisca* un URL valido conoscendo un token.
     */
    public static function url(Strumento $strumento): string
    {
        return URL::signedRoute('qr.strumento', ['token' => $strumento->qr_token]);
    }

    /**
     * L'SVG del QR, pronto da inserire nella pagina.
     *
     * SVG e non PNG di proposito: si stampa nitido a qualunque dimensione — un
     * QR sbiadito su una macchina è un QR che non si legge — e non richiede né
     * `ext-gd` né `imagick`, che sono estensioni in più da avere in produzione.
     *
     * `$dimensione` è in pixel CSS e serve alla resa a schermo: nel foglio di
     * stampa la misura vera la decide il CSS in millimetri.
     */
    public static function svg(Strumento $strumento, int $dimensione = 220): string
    {
        $writer = new Writer(new ImageRenderer(
            new RendererStyle($dimensione, margin: 1),
            new SvgImageBackEnd,
        ));

        return $writer->writeString(self::url($strumento));
    }
}
