<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>{{ $invoice->number }}</title>
<style>
    @page { margin: 0; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 12px; color: #1e293b; margin: 0; }
</style>
</head>
<body>
@include('invoices._body', ['invoice' => $invoice])
</body>
</html>
