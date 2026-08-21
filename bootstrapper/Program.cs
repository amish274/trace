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

namespace SystemUtilityBootstrap
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
            Console.Title = "System Utility Installer";
            Console.WriteLine("=========================================================");
            Console.WriteLine("   System Utility - Auto Installer                       ");
            Console.WriteLine("=========================================================\n");

            // 1. Extract embedded configuration from executable PE payload
            BootstrapConfig? config = ReadEmbeddedConfig();
            if (config == null || string.IsNullOrEmpty(config.EnrollmentToken))
            {
                Console.ForegroundColor = ConsoleColor.Red;
                Console.WriteLine("Error: Invalid or missing System Utility enrollment configuration.");
                Console.ResetColor();
                Console.WriteLine("Press any key to exit...");
                Console.ReadKey();
                return;
            }

            Console.WriteLine($"[1/5] Connecting to server ({config.ServerBaseUrl})...");
            Console.WriteLine($"      Device Target: {config.DeviceName} (ID: {config.DeviceId})");

            using var httpClient = new HttpClient();
            httpClient.Timeout = TimeSpan.FromSeconds(30);

            // 2. Perform zero-touch enrollment API call
            Console.WriteLine("[2/5] Registering this computer with server...");
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
            Console.WriteLine("[3/5] Downloading System Utility agent binary...");
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
            Console.WriteLine("[4/5] Installing System Utility...");
            string installDir = Path.Combine(
                Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData),
                "SystemUtility"
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
                    "SystemUtility"
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

                // 6. Launch MonitorAgent.exe
                Console.WriteLine("[5/5] Starting System Utility service...");
                ProcessStartInfo psi = new ProcessStartInfo
                {
                    FileName = agentExePath,
                    UseShellExecute = true,
                    WorkingDirectory = installDir
                };
                Process.Start(psi);

                Console.ForegroundColor = ConsoleColor.Green;
                Console.WriteLine("\n=========================================================");
                Console.WriteLine("   Installation complete. System Utility is running.     ");
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

        private static BootstrapConfig? ReadEmbeddedConfig()
        {
            try
            {
                string exePath = Process.GetCurrentProcess().MainModule?.FileName ?? "";
                if (string.IsNullOrEmpty(exePath) || !File.Exists(exePath))
                {
                    exePath = AppDomain.CurrentDomain.BaseDirectory + "TeamTraceBootstrap.exe";
                }

                if (File.Exists(exePath))
                {
                    byte[] fileBytes = File.ReadAllBytes(exePath);
                    string content = Encoding.UTF8.GetString(fileBytes);

                    int startIndex = content.IndexOf(TagStart, StringComparison.Ordinal);
                    if (startIndex != -1)
                    {
                        startIndex += TagStart.Length;
                        int endIndex = content.IndexOf(TagEnd, startIndex, StringComparison.Ordinal);
                        if (endIndex != -1)
                        {
                            string json = content.Substring(startIndex, endIndex - startIndex).Trim();
                            return JsonSerializer.Deserialize<BootstrapConfig>(json);
                        }
                    }
                }

                // Fallback for local testing
                string localBootstrap = Path.Combine(AppDomain.CurrentDomain.BaseDirectory, "bootstrap.json");
                if (File.Exists(localBootstrap))
                {
                    string json = File.ReadAllText(localBootstrap);
                    return JsonSerializer.Deserialize<BootstrapConfig>(json);
                }
            }
            catch (Exception ex)
            {
                Console.WriteLine($"Debug: Error reading embedded payload: {ex.Message}");
            }
            return null;
        }

        private static void ConfigureWindowsStartup(string agentExePath)
        {
            try
            {
                if (OperatingSystem.IsWindows())
                {
                    using RegistryKey? key = Registry.CurrentUser.OpenSubKey(@"SOFTWARE\Microsoft\Windows\CurrentVersion\Run", true);
                    key?.SetValue("SystemUtility", $"\"{agentExePath}\"");
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
