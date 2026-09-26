{{--
    Il piè di pagina, in ITALIANO e fisso (🔗 ADR-011: il difetto che questo file
    chiude si chiamava «All rights reserved»).

    ⛔ **Dice sempre «Easy Lab», anche per un Ente brandizzato**: il prodotto e il
    mittente reale siamo noi, e un piè di pagina per-tenant renderebbe l'email
    indistinguibile da una scritta dal cliente — cioè una superficie di phishing
    dentro un messaggio che parte dal nostro dominio.

    ⛔⛔ **Qui non entra la parola «ricambio»/«ricambi».**
    `tests/Feature/Notifiche/DigestScadenzeTest.php` asserisce
    `not->toContain('ricambio')` sull'email RESA (ADR-004: nominarli rivelerebbe
    che sulla macchina c'è un pezzo sostituito). Un claim tipo «strumentazione,
    interventi e ricambi» farebbe diventare rosso un test di privacy che non
    c'entra niente col marchio, e chi lo vedesse fallire cercherebbe il difetto
    nel posto sbagliato.
--}}
<tr>
<td>
<table class="footer" align="center" width="570" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="content-cell" align="center">
<p>© {{ date('Y') }} Easy Lab · Gestione strumentazione e manutenzione</p>
<p>Questa email è stata inviata da Easy Lab all'indirizzo registrato per il tuo account.</p>
</td>
</tr>
</table>
</td>
</tr>
