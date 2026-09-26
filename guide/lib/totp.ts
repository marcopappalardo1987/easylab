import crypto from 'node:crypto';

/**
 * Il codice a sei cifre della verifica in due passaggi, calcolato qui.
 *
 * ⚠️ **Perché serve.** `config/rbac.php` impone la 2FA a Developer, Superadmin
 * e Admin: senza questo, un copione non può girare nessuna schermata di
 * amministrazione, e un quarto del catalogo resta non filmabile (`STILE.md` §8).
 *
 * Il segreto è **piantato nel seme** del DB dimostrativo, noto e fisso: non è
 * una credenziale, è un dato di scena. Vive in `SEGRETO_2FA` e si rigenera con
 * `bin/pianta-2fa.sh`.
 *
 * ⚠️ Scritto a mano invece di aggiungere `otplib`: sono venti righe di crypto
 * standard, e `node_modules` dello studio è già la cosa più pesante del
 * repository. RFC 6238 con i parametri predefiniti — SHA-1, 6 cifre, finestra
 * di 30 secondi — che sono quelli che Fortify verifica.
 */
export const SEGRETO_2FA = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

const ALFABETO = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

const daBase32 = (base32: string): Buffer => {
  let bit = '';

  for (const carattere of base32.replace(/=+$/, '').toUpperCase()) {
    const valore = ALFABETO.indexOf(carattere);
    if (valore < 0) throw new Error(`Carattere non base32 nel segreto: «${carattere}»`);
    bit += valore.toString(2).padStart(5, '0');
  }

  const byte = bit.match(/.{8}/g) ?? [];

  return Buffer.from(byte.map((b) => parseInt(b, 2)));
};

export const codice2FA = (segreto: string = SEGRETO_2FA, quando: number = Date.now()): string => {
  const contatore = Buffer.alloc(8);
  contatore.writeBigUInt64BE(BigInt(Math.floor(quando / 1000 / 30)));

  const digest = crypto.createHmac('sha1', daBase32(segreto)).update(contatore).digest();

  // Troncamento dinamico: gli ultimi quattro bit dicono da dove leggere.
  const scarto = digest[digest.length - 1] & 0x0f;
  const numero = digest.readUInt32BE(scarto) & 0x7fffffff;

  return String(numero % 1_000_000).padStart(6, '0');
};
