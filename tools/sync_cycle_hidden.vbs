' 🔁 يشغّل دورة المزامنة بلا أي نافذة (المهمّة المجدولة «MSA Payroll Sync» كل 5 دقائق)
Dim sh, php, script
Set sh = CreateObject("WScript.Shell")
php = "C:\xampp\php\php.exe"
script = "C:\xampp\htdocs\saint-maxime-payroll\tools\sync_cycle_cli.php"
sh.Run """" & php & """ """ & script & """", 0, False
