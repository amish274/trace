using System;
using System.IO;
using System.Net.Http;
using System.Net.Http.Headers;
using System.Text;
using System.Text.Json;
using System.Text.Json.Serialization;
using System.Threading.Tasks;
using System.Diagnostics;
using Microsoft.Win32;

namespace TeamTraceBootstrap
{
    public class BootstrapConfig
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

    public class AgentConfigPayload
    {
        public string ServerBaseUrl { get; set; } = "";
        public string DeviceToken { get; set; } = "";
        public string DeviceName { get; set; } = Environment.MachineName;
        public string OperatingSystem { get; set; } = Environment.OSVersion.ToString();
        public string AgentVersion { get; set; } = "1.0.0";
    }

    public class Program
    {
        private const string TagStart = "###TEAMTRACE_BOOTSTRAP_START###";
        private const string TagEnd = "###TEAMTRACE_BOOTSTRAP_END###";

        public static async Task Main(string[] args)
        {
            Console.Title = "TeamTrace Installer";
            Console.WriteLine("=========================================================");
            Console.WriteLine("   TeamTrace Workplace Monitoring - Installer            ");
            Console.WriteLine("=========================================================\n");
            try
            {
                System.Net.ServicePointManager.SecurityProtocol = System.Net.SecurityProtocolType.Tls12;
            }
            catch { }

            // 1. Read configuration (CLI Args > Sidecar JSON > Binary Overlay)
            BootstrapConfig? config = ReadConfig(args);
            if (config == null || string.IsNullOrEmpty(config.EnrollmentToken))
            {
                Console.ForegroundColor = ConsoleColor.Red;
                Console.WriteLine("Error: Missing or invalid TeamTrace enrollment configuration.");
                Console.ResetColor();
                Console.WriteLine("Place 'teamtrace.config.json' next to the installer or pass --token=YOUR_TOKEN.");
                Console.WriteLine("Press any key to exit...");
                Console.ReadKey();
                return;
            }

            Console.WriteLine($"[1/5] Connecting to TeamTrace server ({config.ServerBaseUrl})...");
            Console.WriteLine($"      Target Device: {config.DeviceName} (ID: {config.DeviceId})");

            using var httpClient = new HttpClient();
            httpClient.Timeout = TimeSpan.FromSeconds(30);
            httpClient.DefaultRequestHeaders.UserAgent.ParseAdd("TeamTrace-Installer/1.0 (Windows NT)");

            // 2. Perform zero-touch enrollment API call
            Console.WriteLine("[2/5] Registering computer with TeamTrace server...");
            string regUrl = rtrimUrl(config.ServerBaseUrl) + "/api/agent/register.php";

            var regPayload = new
            {
                enrollment_token = config.EnrollmentToken,
                device_name = config.DeviceName,
                operating_system = Environment.OSVersion.ToString(),
                agent_version = config.AgentVersion
            };

            string jsonString = JsonSerializer.Serialize(regPayload);
            var content = new StringContent(jsonString, Encoding.UTF8, "application/json");

            HttpResponseMessage regResponse;
            try
            {
                regResponse = await httpClient.PostAsync(regUrl, content);
            }
            catch (Exception ex)
            {
                Console.ForegroundColor = ConsoleColor.Red;
                Console.WriteLine($"Error: Cannot reach server at {regUrl}. {ex.Message}");
                Console.ResetColor();
                Console.ReadKey();
                return;
            }

            if (!regResponse.IsSuccessStatusCode)
            {
                string errText = await regResponse.Content.ReadAsStringAsync();
                Console.ForegroundColor = ConsoleColor.Red;
                Console.WriteLine($"Error: Server registration failed ({regResponse.StatusCode}): {errText}");
                Console.ResetColor();
                Console.ReadKey();
                return;
            }

            string regResponseBody = await regResponse.Content.ReadAsStringAsync();
            using var doc = JsonDocument.Parse(regResponseBody);
            var root = doc.RootElement;

            bool success = root.TryGetProperty("success", out var succProp) && succProp.GetBoolean();
            string deviceToken = root.TryGetProperty("device_token", out var tokProp) ? tokProp.GetString() ?? "" : "";

            if (!success || string.IsNullOrEmpty(deviceToken))
            {
                Console.ForegroundColor = ConsoleColor.Red;
                Console.WriteLine("Error: Server returned invalid registration response.");
                Console.ResetColor();
                Console.ReadKey();
                return;
            }

            Console.ForegroundColor = ConsoleColor.Green;
            Console.WriteLine("      Registration successful! Device token acquired.");
            Console.ResetColor();

            // 3. Download shared canonical MonitorAgent.exe from server download API
            Console.WriteLine("[3/5] Downloading TeamTrace agent binary...");
            string downloadUrl = rtrimUrl(config.ServerBaseUrl) + "/api/agent/download.php";

            var downloadReq = new HttpRequestMessage(HttpMethod.Get, downloadUrl);
            downloadReq.Headers.Authorization = new AuthenticationHeaderValue("Bearer", deviceToken);

            HttpResponseMessage downloadRes;
            try
            {
                downloadRes = await httpClient.SendAsync(downloadReq);
            }
            catch (Exception ex)
            {
                Console.ForegroundColor = ConsoleColor.Red;
                Console.WriteLine($"Error: Failed downloading agent binary: {ex.Message}");
                Console.ResetColor();
                Console.ReadKey();
                return;
            }

            if (!downloadRes.IsSuccessStatusCode)
            {
                Console.ForegroundColor = ConsoleColor.Red;
                Console.WriteLine($"Error: Failed downloading agent binary ({downloadRes.StatusCode})");
                Console.ResetColor();
                Console.ReadKey();
                return;
            }

            byte[] agentBytes = await downloadRes.Content.ReadAsByteArrayAsync();
            Console.WriteLine($"      Downloaded canonical agent ({agentBytes.Length / 1024} KB).");

            // 4. Install agent binary to standard application directory
            Console.WriteLine("[4/5] Installing TeamTrace agent...");
            string installDir = Path.Combine(
                Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
                "TeamTrace"
            );

            try
            {
                if (!Directory.Exists(installDir))
                {
                    Directory.CreateDirectory(installDir);
                }

                string agentExePath = Path.Combine(installDir, "MonitorAgent.exe");
                await File.WriteAllBytesAsync(agentExePath, agentBytes);

                // Save permanent device configuration
                string appDataDir = Path.Combine(
                    Environment.GetFolderPath(Environment.SpecialFolder.ApplicationData),
                    "TeamTrace"
                );
                if (!Directory.Exists(appDataDir))
                {
                    Directory.CreateDirectory(appDataDir);
                }

                var agentCfg = new AgentConfigPayload
                {
                    ServerBaseUrl = config.ServerBaseUrl,
                    DeviceToken = deviceToken,
                    DeviceName = config.DeviceName,
                    OperatingSystem = Environment.OSVersion.ToString(),
                    AgentVersion = config.AgentVersion
                };

                string cfgJson = JsonSerializer.Serialize(agentCfg, new JsonSerializerOptions { WriteIndented = true });
                await File.WriteAllTextAsync(Path.Combine(appDataDir, "agent_config.json"), cfgJson);

                // 5. Configure Windows User Session Startup
                ConfigureWindowsStartup(agentExePath);

                // 6. Launch MonitorAgent.exe silently
                Console.WriteLine("[5/5] Starting TeamTrace workplace agent service...");
                ProcessStartInfo psi = new ProcessStartInfo
                {
                    FileName = agentExePath,
                    UseShellExecute = true,
                    CreateNoWindow = true,
                    WindowStyle = ProcessWindowStyle.Hidden,
                    WorkingDirectory = installDir
                };
                Process.Start(psi);

                Console.ForegroundColor = ConsoleColor.Green;
                Console.WriteLine("\n=========================================================");
                Console.WriteLine("   Installation complete. TeamTrace is now running.      ");
                Console.WriteLine("=========================================================\n");
                Console.ResetColor();

                await Task.Delay(2000);
            }
            catch (Exception ex)
            {
                Console.ForegroundColor = ConsoleColor.Red;
                Console.WriteLine($"Error during agent installation: {ex.Message}");
                Console.ResetColor();
                Console.ReadKey();
            }
        }

        private static BootstrapConfig? ReadConfig(string[] args)
        {
            // Priority 1: Command line arguments (--url=... --token=...)
            string? argUrl = null;
            string? argToken = null;
            string? argDevice = null;

            foreach (var arg in args)
            {
                if (arg.StartsWith("--url=", StringComparison.OrdinalIgnoreCase))
                    argUrl = arg.Substring(6).Trim('"');
                else if (arg.StartsWith("--token=", StringComparison.OrdinalIgnoreCase))
                    argToken = arg.Substring(8).Trim('"');
                else if (arg.StartsWith("--device=", StringComparison.OrdinalIgnoreCase))
                    argDevice = arg.Substring(9).Trim('"');
            }

            if (!string.IsNullOrEmpty(argToken))
            {
                return new BootstrapConfig
                {
                    ServerBaseUrl = argUrl ?? "https://ethnicboost.com/Trace",
                    EnrollmentToken = argToken,
                    DeviceName = argDevice ?? Environment.MachineName
                };
            }

            // Priority 2: Sidecar config file teamtrace.config.json or bootstrap.json
            string[] configNames = new[] { "teamtrace.config.json", "bootstrap.json" };
            string baseDir = AppDomain.CurrentDomain.BaseDirectory;
            string currentDir = Directory.GetCurrentDirectory();

            foreach (var name in configNames)
            {
                string[] paths = new[]
                {
                    Path.Combine(baseDir, name),
                    Path.Combine(currentDir, name)
                };

                foreach (var path in paths)
                {
                    if (File.Exists(path))
                    {
                        try
                        {
                            string json = File.ReadAllText(path);
                            var cfg = JsonSerializer.Deserialize<BootstrapConfig>(json);
                            if (cfg != null && !string.IsNullOrEmpty(cfg.EnrollmentToken))
                            {
                                Console.WriteLine($"[Config] Loaded configuration from sidecar file: {Path.GetFileName(path)}");
                                return cfg;
                            }
                        }
                        catch { }
                    }
                }
            }

            // Priority 3: Embedded PE payload overlay (Legacy / Testing fallback)
            return ReadEmbeddedConfig();
        }

        private static BootstrapConfig? ReadEmbeddedConfig()
        {
            try
            {
                string exePath = Process.GetCurrentProcess().MainModule?.FileName ?? "";
                if (string.IsNullOrEmpty(exePath) || !File.Exists(exePath))
                {
                    exePath = Path.Combine(AppDomain.CurrentDomain.BaseDirectory, "TeamTraceBootstrap.exe");
                }

                if (File.Exists(exePath))
                {
                    byte[] fileBytes = File.ReadAllBytes(exePath);
                    byte[] startBytes = Encoding.UTF8.GetBytes(TagStart);
                    byte[] endBytes = Encoding.UTF8.GetBytes(TagEnd);

                    int startIdx = FindByteSequence(fileBytes, startBytes, 0);
                    if (startIdx != -1)
                    {
                        int jsonStart = startIdx + startBytes.Length;
                        int endIdx = FindByteSequence(fileBytes, endBytes, jsonStart);
                        if (endIdx != -1)
                        {
                            int jsonLength = endIdx - jsonStart;
                            string json = Encoding.UTF8.GetString(fileBytes, jsonStart, jsonLength).Trim();
                            return JsonSerializer.Deserialize<BootstrapConfig>(json);
                        }
                    }
                }
            }
            catch (Exception ex)
            {
                Console.WriteLine($"Debug: Error reading embedded payload: {ex.Message}");
            }
            return null;
        }

        private static int FindByteSequence(byte[] source, byte[] pattern, int startIndex)
        {
            if (source == null || pattern == null || pattern.Length == 0 || source.Length < pattern.Length)
                return -1;

            for (int i = startIndex; i <= source.Length - pattern.Length; i++)
            {
                bool found = true;
                for (int j = 0; j < pattern.Length; j++)
                {
                    if (source[i + j] != pattern[j])
                    {
                        found = false;
                        break;
                    }
                }
                if (found) return i;
            }
            return -1;
        }

        private static void ConfigureWindowsStartup(string agentExePath)
        {
            try
            {
                if (OperatingSystem.IsWindows())
                {
                    using RegistryKey? key = Registry.CurrentUser.OpenSubKey(@"SOFTWARE\Microsoft\Windows\CurrentVersion\Run", true);
                    key?.SetValue("TeamTrace", $"\"{agentExePath}\"");
                }
            }
            catch (Exception ex)
            {
                Console.WriteLine($"Warning: Could not set registry startup key: {ex.Message}");
            }
        }

        private static string rtrimUrl(string url)
        {
            return url.TrimEnd('/');
        }
    }
}
