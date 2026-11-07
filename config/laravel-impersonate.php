<?php

return [

    /**
     * The session key used to store the original user id.
     */
    'session_key' => 'impersonated_by',

    /**
     * The session key used to stored the original user guard.
     */
    'session_guard' => 'impersonator_guard',

    /**
     * The session key used to stored what guard is impersonator using.
     */
    'session_guard_using' => 'impersonator_guard_using',

    /**
     * The default impersonator guard used.
     */
    'default_impersonator_guard' => 'web',

    /**
     * The URI to redirect after taking an impersonation.
     *
     * Only used in the built-in controller.
     * * Use 'back' to redirect to the previous page
     */
    // ⛔ Mai `/`: rimanda a `/login`, e nel browser passare dalla pagina di
    // accesso subito dopo il cambio d'identità chiudeva la sessione. Il
    // Superadmin si ritrovava fuori, sia entrando sia uscendo (trovato girando
    // le guide il 7 Nov 2026; nei test PHP non si riproduce). La Dashboard è
    // una pagina protetta che ogni ruolo ha, come la usa già il parco.
    'take_redirect_to' => '/dashboard',

    /**
     * The URI to redirect after leaving an impersonation.
     *
     * Only used in the built-in controller.
     * Use 'back' to redirect to the previous page
     */
    'leave_redirect_to' => '/dashboard',

];
