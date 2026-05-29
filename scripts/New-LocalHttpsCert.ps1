[CmdletBinding()]
param(
    [string] $IpAddress = "",
    [string] $OutputDir = "storage\certs",
    [string] $Password = "harusi-local",
    [switch] $TrustOnWindows
)

Set-StrictMode -Version Latest
$ErrorActionPreference = "Stop"

function Get-DefaultIpv4Address {
    $addresses = Get-NetIPAddress -AddressFamily IPv4 |
        Where-Object {
            $_.IPAddress -ne "127.0.0.1" -and
            $_.IPAddress -notlike "169.254.*" -and
            $_.PrefixOrigin -ne "WellKnown"
        } |
        Sort-Object -Property InterfaceMetric, InterfaceIndex

    if (-not $addresses) {
        throw "Could not find a LAN IPv4 address. Pass one explicitly, for example: -IpAddress 192.168.100.114"
    }

    return $addresses[0].IPAddress
}

if ([string]::IsNullOrWhiteSpace($IpAddress)) {
    $IpAddress = Get-DefaultIpv4Address
}

$projectRoot = Resolve-Path (Join-Path $PSScriptRoot "..")
$resolvedOutputDir = if ([System.IO.Path]::IsPathRooted($OutputDir)) {
    $OutputDir
} else {
    Join-Path $projectRoot $OutputDir
}

New-Item -ItemType Directory -Force -Path $resolvedOutputDir | Out-Null

$rootSubject = "CN=Harusi Local Development CA"
$serverSubject = "CN=Harusi Local HTTPS $IpAddress"
$now = Get-Date

$rootCert = Get-ChildItem Cert:\CurrentUser\My |
    Where-Object { $_.Subject -eq $rootSubject -and $_.NotAfter -gt $now.AddDays(30) } |
    Sort-Object -Property NotAfter -Descending |
    Select-Object -First 1

if (-not $rootCert) {
    $rootCert = New-SelfSignedCertificate `
        -Type Custom `
        -Subject $rootSubject `
        -KeyAlgorithm RSA `
        -KeyLength 4096 `
        -HashAlgorithm SHA256 `
        -KeyExportPolicy Exportable `
        -KeyUsage CertSign, CRLSign, DigitalSignature `
        -KeyUsageProperty Sign `
        -CertStoreLocation "Cert:\CurrentUser\My" `
        -NotAfter $now.AddYears(5) `
        -FriendlyName "Harusi Local Development CA"
}

$serverCert = New-SelfSignedCertificate `
    -Type SSLServerAuthentication `
    -Subject $serverSubject `
    -Signer $rootCert `
    -KeyAlgorithm RSA `
    -KeyLength 2048 `
    -HashAlgorithm SHA256 `
    -KeyExportPolicy Exportable `
    -CertStoreLocation "Cert:\CurrentUser\My" `
    -NotAfter $now.AddYears(2) `
    -FriendlyName "Harusi Local HTTPS" `
    -TextExtension @("2.5.29.17={text}DNS=localhost&IPAddress=127.0.0.1&IPAddress=$IpAddress")

$securePassword = ConvertTo-SecureString -String $Password -AsPlainText -Force
$pfxPath = Join-Path $resolvedOutputDir "harusi-local.pfx"
$caPath = Join-Path $resolvedOutputDir "harusi-local-ca.cer"

Export-PfxCertificate -Cert $serverCert -FilePath $pfxPath -Password $securePassword -Force | Out-Null
Export-Certificate -Cert $rootCert -FilePath $caPath -Force | Out-Null

if ($TrustOnWindows) {
    Import-Certificate -FilePath $caPath -CertStoreLocation Cert:\CurrentUser\Root | Out-Null
}

Write-Host "Local HTTPS certificate generated."
Write-Host "LAN IP: $IpAddress"
Write-Host "Server certificate: $pfxPath"
Write-Host "Phone CA certificate: $caPath"
Write-Host "PFX passphrase: $Password"
Write-Host ""
Write-Host "Install harusi-local-ca.cer on the phone as a trusted CA certificate, then open:"
Write-Host "https://${IpAddress}:8443/scanner/scan"
