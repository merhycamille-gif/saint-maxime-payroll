# Construit le MODÈLE "MSA Payroll.exe" (icône autonome, sans clé). Le serveur y ajoute la clé de chaque icône
# (page « Accès écoles » ⇒ « Icône ordinateur », ou tools/make_key.php). Le modèle est versionné avec le programme.
$ErrorActionPreference = 'Stop'
$here = Split-Path -Parent $MyInvocation.MyCommand.Path
$csc  = Get-ChildItem "C:\Windows\Microsoft.NET\Framework64\v4*\csc.exe" | Select-Object -First 1 -ExpandProperty FullName
$ico  = Join-Path $here 'icon.ico'
if (-not (Test-Path $ico)) { Copy-Item (Join-Path $here '..\..\payroll.ico') $ico }
$out  = Join-Path $here 'MSA Payroll.exe'
& $csc /nologo /codepage:65001 /target:winexe /platform:anycpu /optimize+ `
    /win32icon:"$ico" /win32manifest:"$here\app.manifest" `
    /r:System.dll /r:System.Windows.Forms.dll /out:"$out" "$here\Program.cs"
if ($LASTEXITCODE -ne 0) { throw "csc a échoué ($LASTEXITCODE)" }
Write-Host "OK -> $out ($((Get-Item $out).Length) octets)"
