using System;
using System.IO;

namespace MonitorAgent
{
    public static class AppLogger
    {
        private static readonly object _lock = new object();

        private static string GetLogPath()
        {
            try
            {
                // Try CommonApplicationData (%ProgramData%) first
                string programData = Environment.GetFolderPath(Environment.SpecialFolder.CommonApplicationData);
                if (!string.IsNullOrEmpty(programData))
                {
                    string dir = Path.Combine(programData, "TeamTrace", "logs");
                    if (!Directory.Exists(dir))
                    {
                        Directory.CreateDirectory(dir);
                    }
                    return Path.Combine(dir, "MonitorAgent.log");
                }
            }
            catch
            {
                // Fallback to LocalApplicationData (%LocalAppData%)
            }

            try
            {
                string localData = Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData);
                string dir = Path.Combine(localData, "SystemUtility", "logs");
                if (!Directory.Exists(dir))
                {
                    Directory.CreateDirectory(dir);
                }
                return Path.Combine(dir, "MonitorAgent.log");
            }
            catch
            {
                return Path.Combine(AppDomain.CurrentDomain.BaseDirectory, "MonitorAgent.log");
            }
        }

        public static void Log(string category, string message, Exception? ex = null)
        {
            try
            {
                lock (_lock)
                {
                    string logFile = GetLogPath();
                    string timestamp = DateTime.UtcNow.ToString("yyyy-MM-dd HH:mm:ss.fff UTC");
                    string osVersion = Environment.OSVersion.ToString();
                    string sanitizedMsg = Sanitize(message);

                    string entry = $"[{timestamp}] [{category}] OS: {osVersion} | {sanitizedMsg}";
                    if (ex != null)
                    {
                        entry += $"{Environment.NewLine}  [EXCEPTION] Type: {ex.GetType().FullName} | Message: {Sanitize(ex.Message)}{Environment.NewLine}  [STACKTRACE] {ex.StackTrace}";
                    }
                    entry += Environment.NewLine;

                    File.AppendAllText(logFile, entry);
                }
            }
            catch
            {
                // Never throw from logger
            }
        }

        public static void LogInfo(string message) => Log("INFO", message);
        public static void LogWarn(string message) => Log("WARN", message);
        public static void LogError(string message, Exception? ex = null) => Log("ERROR", message, ex);

        private static string Sanitize(string text)
        {
            if (string.IsNullOrEmpty(text)) return text;
            // Never log sensitive secrets
            text = text.Replace("Bearer ", "Bearer [REDACTED]");
            return text;
        }
    }
}
