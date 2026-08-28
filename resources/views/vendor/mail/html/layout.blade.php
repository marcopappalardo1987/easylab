<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
<title>{{ config('app.name') }}</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
{{-- 🌙 ADR-034: **le email non hanno un tema.** Questi due meta dicono al client
     di non applicare la propria inversione automatica in dark mode — è la stessa
     riga che il layout del pacchetto porta, ed è esattamente ciò che ADR-034
     chiede: il contesto in cui l'email verrà letta non è nostro, quindi il
     documento si dichiara chiaro e resta chiaro. --}}
<meta name="color-scheme" content="light">
<meta name="supported-color-schemes" content="light">
{{-- ⚠️ Il blocco <style> RESTA, e non è una svista: `CssToInlineStyles` non sa
     inlinare una `@media`, quindi queste tre regole sono le sole che devono
     vivere nel documento. Toglierle romperebbe il montaggio su schermo stretto. --}}
<style>
@media only screen and (max-width: 600px) {
.inner-body {
width: 100% !important;
}

.footer {
width: 100% !important;
}
}

@media only screen and (max-width: 500px) {
.button {
width: 100% !important;
}
}
</style>
{!! $head ?? '' !!}
</head>
<body>

<table class="wrapper" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td align="center">
<table class="content" width="100%" cellpadding="0" cellspacing="0" role="presentation">
{!! $header ?? '' !!}

<!-- Corpo -->
<tr>
<td class="body" width="100%" cellpadding="0" cellspacing="0" style="border: hidden !important;">
<table class="inner-body" align="center" width="570" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="content-cell">
{!! Illuminate\Mail\Markdown::parse($slot) !!}

{!! $subcopy ?? '' !!}
</td>
</tr>
</table>
</td>
</tr>

{!! $footer ?? '' !!}
</table>
</td>
</tr>
</table>
</body>
</html>
