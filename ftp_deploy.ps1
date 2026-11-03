[System.Net.ServicePointManager]::ServerCertificateValidationCallback = { $true }

$hosts = @("ftp://seikouedu.com", "ftp://thsv43.hostatom.com")
$users = @("seikou", "seikou@seikouedu.com", "seikou@sktbkk.seikouedu.com")
$ftpPass = "1lP4a2mr7F"

foreach ($h in $hosts) {
    foreach ($u in $users) {
        try {
            $req = [System.Net.FtpWebRequest]::Create("$h/")
            $req.Credentials = New-Object System.Net.NetworkCredential($u, $ftpPass)
            $req.Method = [System.Net.WebRequestMethods+Ftp]::ListDirectory
            $req.UsePassive = $true
            $req.EnableSsl = $true
            $req.Timeout = 6000
            $res = $req.GetResponse()
            Write-Output "SUCCESS (SSL) with Host: $h and User: $u"
            $reader = New-Object System.IO.StreamReader($res.GetResponseStream())
            $list = $reader.ReadToEnd()
            Write-Output $list
            $reader.Close()
            $res.Close()
            exit 0
        } catch {
            Write-Output "Failed SSL: $h with $u -> $($_.Exception.Message)"
        }
    }
}
