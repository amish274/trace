using System;
using System.IO;
using System.Text.Json;
using System.Text.Json.Serialization;

namespace MonitorAgent
{
    public class AgentSettings
    {
        public string ServerBaseUrl { get; set; } = "https://ethnicboost.com/Trace";
        public string DeviceToken { get; set; } = "";
        public string DeviceName { get; set; } = Environment.MachineName;
        public string OperatingSystem { get; set; } = Environment.OSVersion.ToString();
        public string AgentVersion { get; set; } = "1.0.0";

        // Server-polled dynamic config properties
        public bool MonitoringEnabled { get; set; } = true;
        public bool ScreenshotEnabled { get; set; } = true;
        public int ScreenshotIntervalSeconds { get; set; } = 30;
        public int ScreenshotQuality { get; set; } = 70;
        public int ScreenshotWidth { get; set; } = 0;
        public int ScreenshotHeight { get; set; } = 0;
        public int IdleThresholdSeconds { get; set; } = 120;
    }

    public class BootstrapPayload
    {
        [JsonPropertyName("server_base_url")]
        public string ServerBaseUrl { get; set; } = "";

        [JsonPropertyName("enrollment_token")]
        public string EnrollmentToken { get; set; } = "";

        [JsonPropertyName("device_id")]
        public int DeviceId { get; set; } = 0;

        [JsonPropertyName("device_name")]
        public string DeviceName { get; set; } = "";

        [JsonPropertyName("agent_version")]
        public string AgentVersion { get; set; } = "1.0.0";
    }

    public static class AppConfig
    {
        private static readonly string ConfigPath = Path.Combine(
            Environment.GetFolderPath(Environment.SpecialFolder.ApplicationData),
            "SystemUtility",
            "agent_config.json"
        );

        public static AgentSettings Current { get; set; } = new AgentSettings();

        public static void Load()
        {
            try
            {
                if (File.Exists(ConfigPath))
                {
                    string json = File.ReadAllText(ConfigPath);
                    var settings = JsonSerializer.Deserialize<AgentSettings>(json);
                    if (settings != null)
                    {
                        Current = settings;
                    }
                }
            }
            catch
            {
                // Fallback to defaults if file corrupted
            }
        }

        public static void Save()
        {
            try
            {
                string? dir = Path.GetDirectoryName(ConfigPath);
                if (!string.IsNullOrEmpty(dir) && !Directory.Exists(dir))
                {
                    Directory.CreateDirectory(dir);
                }

                string json = JsonSerializer.Serialize(Current, new JsonSerializerOptions { WriteIndented = true });
                File.WriteAllText(ConfigPath, json);
            }
            catch (Exception ex)
            {
                System.Diagnostics.Debug.WriteLine($"Failed to save config: {ex.Message}");
            }
        }

        public static BootstrapPayload? ReadBootstrapFile()
        {
            try
            {
                string[] checkPaths = new[]
                {
                    Path.Combine(AppDomain.CurrentDomain.BaseDirectory, "bootstrap.json"),
                    Path.Combine(Directory.GetCurrentDirectory(), "bootstrap.json")
                };

                foreach (var path in checkPaths)
                {
                    if (File.Exists(path))
                    {
                        string json = File.ReadAllText(path);
                        var bootstrap = JsonSerializer.Deserialize<BootstrapPayload>(json);
                        if (bootstrap != null && !string.IsNullOrEmpty(bootstrap.EnrollmentToken))
                        {
                            return bootstrap;
                        }
                    }
                }
            }
            catch (Exception ex)
            {
                System.Diagnostics.Debug.WriteLine($"Error reading bootstrap.json: {ex.Message}");
            }
            return null;
        }

        public static void ClearBootstrapFile()
        {
            try
            {
                string path = Path.Combine(AppDomain.CurrentDomain.BaseDirectory, "bootstrap.json");
                if (File.Exists(path))
                {
                    File.Delete(path);
                }
            }
            catch { }
        }
    }
}
