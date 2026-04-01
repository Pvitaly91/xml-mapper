$ErrorActionPreference = 'Stop'

$domain = 'xml-mapper.loc'
$projectPath = 'G:\DEV\htdocs\xml-mapper.loc'
$publicPath = Join-Path $projectPath 'public'
$vhostsPath = 'C:\Program Files\xampp\apache\conf\extra\httpd-vhosts.conf'
$hostsPath = 'C:\Windows\System32\drivers\etc\hosts'

$principal = New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())
if (-not $principal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    throw 'Run this script as Administrator.'
}

$vhostBlock = @"
<VirtualHost *:80>
    ServerAdmin webmaster@$domain
    DocumentRoot "$publicPath"
    ServerName $domain
    ErrorLog "logs/$domain.log"
    CustomLog "logs/$domain.log" common
    <Directory "$publicPath">
        Options Indexes FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
"@

$vhostsContent = Get-Content $vhostsPath -Raw
if ($vhostsContent -notmatch "(?m)^\s*ServerName\s+$([regex]::Escape($domain))\s*$") {
    Add-Content -Path $vhostsPath -Value "`r`n$vhostBlock`r`n"
}

$hostsContent = Get-Content $hostsPath -Raw
if ($hostsContent -notmatch "(?m)^\s*127\.0\.0\.1\s+$([regex]::Escape($domain))\s*$") {
    Add-Content -Path $hostsPath -Value "`r`n127.0.0.1`t$domain`r`n"
}

& 'C:\Program Files\xampp\apache\bin\httpd.exe' -t

Write-Host "Local domain files updated for $domain."
Write-Host 'Restart Apache from the XAMPP Control Panel to apply the new virtual host.'
