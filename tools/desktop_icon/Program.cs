// MSA Payroll — icône autonome du Bureau (un seul .exe, icône intégrée). 2026-10-31
//   • Sans clé (modèle) : ouvre la page de connexion.
//   • Avec clé (le serveur ajoute « MSAKEY1|selector|secret|base|key_login.php| » à la fin du fichier) : envoie la clé + l'empreinte
//     de l'ordinateur (MachineGuid haché) au serveur, reçoit un lien d'ouverture à usage unique (90 s) et ouvre le programme
//     directement — ni identifiant ni mot de passe. La clé se lie au premier ordinateur qui l'utilise ; une copie ailleurs est refusée.
using System;
using System.Diagnostics;
using System.IO;
using System.Net;
using System.Security.Cryptography;
using System.Text;
using System.Windows.Forms;
using Microsoft.Win32;

[assembly: System.Reflection.AssemblyTitle("MSA Payroll")]
[assembly: System.Reflection.AssemblyDescription("Systeme de gestion de la paie - acces direct")]
[assembly: System.Reflection.AssemblyCompany("MSA Payroll")]
[assembly: System.Reflection.AssemblyProduct("MSA Payroll")]
[assembly: System.Reflection.AssemblyCopyright("MSA Payroll 2026")]
[assembly: System.Reflection.AssemblyVersion("1.1.0.0")]
[assembly: System.Reflection.AssemblyFileVersion("1.1.0.0")]

static class Program
{
    const string DefaultUrl = "https://msapayroll.com/";
    const string DefaultEndpoint = "key_login.php";
    const string Marker = "MSAKEY1|";
    const string Title = "MSA Payroll";

    [STAThread]
    static void Main()
    {
        string url = DefaultUrl, endpoint = DefaultEndpoint, sel = null, sec = null;
        try { ReadKey(ref sel, ref sec, ref url, ref endpoint); } catch { }
        string target = url + "login.php";
        if (sel != null)
        {
            string err;
            string go = KeyLogin(url + endpoint, sel, sec, out err);
            if (go != null) target = go;
            else if (err != null) MessageBox.Show(err, Title, MessageBoxButtons.OK, MessageBoxIcon.Information);
        }
        Open(target);
    }

    static void ReadKey(ref string sel, ref string sec, ref string url, ref string endpoint)
    {
        string path = Process.GetCurrentProcess().MainModule.FileName;
        byte[] all = File.ReadAllBytes(path);
        int n = Math.Min(2048, all.Length);
        string tail = Encoding.ASCII.GetString(all, all.Length - n, n);
        int i = tail.LastIndexOf(Marker, StringComparison.Ordinal);
        if (i < 0) return;
        string[] p = tail.Substring(i + Marker.Length).Split('|');
        if (p.Length < 3 || !IsHex(p[0], 16) || !IsHex(p[1], 64)) return;
        sel = p[0]; sec = p[1];
        if (p[2].StartsWith("http", StringComparison.OrdinalIgnoreCase)) url = p[2].EndsWith("/") ? p[2] : p[2] + "/";
        if (p.Length >= 4 && p[3].Trim().Length > 0) endpoint = p[3].Trim();
    }

    static bool IsHex(string s, int len)
    {
        if (s == null || s.Length != len) return false;
        foreach (char c in s) if (!((c >= '0' && c <= '9') || (c >= 'a' && c <= 'f'))) return false;
        return true;
    }

    static string KeyLogin(string loginUrl, string sel, string sec, out string err)
    {
        err = null;
        try
        {
            try { ServicePointManager.SecurityProtocol = (SecurityProtocolType)3072 | (SecurityProtocolType)12288; }
            catch { ServicePointManager.SecurityProtocol = (SecurityProtocolType)3072; }
            HttpWebRequest req = (HttpWebRequest)WebRequest.Create(loginUrl);
            req.Method = "POST"; req.ContentType = "application/x-www-form-urlencoded"; req.Timeout = 12000; req.ReadWriteTimeout = 12000;
            req.UserAgent = "MSA-Payroll-Icon/1.1 (" + Environment.OSVersion.VersionString + ")";
            string body = "selector=" + Uri.EscapeDataString(sel) + "&secret=" + Uri.EscapeDataString(sec)
                        + "&machine=" + Uri.EscapeDataString(MachineId())
                        + "&label=" + Uri.EscapeDataString(Environment.MachineName + " / " + Environment.UserName);
            byte[] b = Encoding.UTF8.GetBytes(body); req.ContentLength = b.Length;
            using (Stream s = req.GetRequestStream()) s.Write(b, 0, b.Length);
            string resp;
            using (HttpWebResponse r = (HttpWebResponse)req.GetResponse())
            using (StreamReader sr = new StreamReader(r.GetResponseStream(), Encoding.UTF8)) resp = sr.ReadToEnd();
            string[] lines = resp.Replace("\r", "").Split('\n');
            if (lines.Length >= 2 && lines[0] == "OK" && lines[1].StartsWith("http", StringComparison.OrdinalIgnoreCase)) return lines[1].Trim();
            if (lines.Length >= 2 && lines[0] == "ERR") err = string.Join("\n", lines, 1, lines.Length - 1).Trim();
            return null;
        }
        catch { return null; }
    }

    static string MachineId()
    {
        string g = "";
        try
        {
            using (RegistryKey k = RegistryKey.OpenBaseKey(RegistryHive.LocalMachine, RegistryView.Registry64).OpenSubKey(@"SOFTWARE\Microsoft\Cryptography"))
                if (k != null) g = Convert.ToString(k.GetValue("MachineGuid") ?? "");
        }
        catch { }
        if (g == "") g = Environment.MachineName;
        using (SHA256 sha = SHA256.Create())
        {
            byte[] h = sha.ComputeHash(Encoding.UTF8.GetBytes("msa-payroll|" + g));
            StringBuilder sb = new StringBuilder(); foreach (byte x in h) sb.Append(x.ToString("x2")); return sb.ToString();
        }
    }

    static void Open(string target)
    {
        try
        {
            string[] edges = {
                Environment.ExpandEnvironmentVariables(@"%ProgramFiles(x86)%\Microsoft\Edge\Application\msedge.exe"),
                Environment.ExpandEnvironmentVariables(@"%ProgramFiles%\Microsoft\Edge\Application\msedge.exe"),
                Environment.ExpandEnvironmentVariables(@"%LocalAppData%\Microsoft\Edge\Application\msedge.exe"),
            };
            foreach (string edge in edges)
                if (File.Exists(edge)) { Process.Start(new ProcessStartInfo(edge, "--no-first-run --app=\"" + target + "\"") { UseShellExecute = false }); return; }
            Process.Start(new ProcessStartInfo(target) { UseShellExecute = true });
        }
        catch (Exception ex)
        {
            try { Process.Start(new ProcessStartInfo(target) { UseShellExecute = true }); }
            catch { MessageBox.Show("Impossible d'ouvrir le navigateur.\nOuvrez ce lien :\n" + target + "\n\n" + ex.Message, Title, MessageBoxButtons.OK, MessageBoxIcon.Information); }
        }
    }
}
