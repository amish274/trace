using System;
using System.Net.Http;
using System.Net.Http.Headers;
using System.Text;
using System.Text.Json;
using System.Threading.Tasks;

namespace MonitorAgent
{
    public class ApiClient
    {
        private readonly HttpClient _httpClient;

        public ApiClient()
        {
            try
            {
                System.Net.ServicePointManager.SecurityProtocol |= System.Net.SecurityProtocolType.Tls12 | System.Net.SecurityProtocolType.Tls13;
            }
            catch { }

            var handler = new HttpClientHandler();
            // In production, server validation is automatic via HTTPS
            _httpClient = new HttpClient(handler);
            _httpClient.Timeout = TimeSpan.FromSeconds(15);
        }

        private void SetAuthHeader()
        {
            _httpClient.DefaultRequestHeaders.Authorization = null;
            if (!string.IsNullOrEmpty(AppConfig.Current.DeviceToken))
            {
                _httpClient.DefaultRequestHeaders.Authorization = 
                    new AuthenticationHeaderValue("Bearer", AppConfig.Current.DeviceToken);
            }
        }

        /// <summary>
        /// Register device using enrollment token
        /// </summary>
        public async Task<bool> RegisterAsync(string enrollmentToken)
        {
            try
            {
                string url = $"{AppConfig.Current.ServerBaseUrl.TrimEnd('/')}/api/agent/register.php";
                var payload = new
                {
                    enrollment_token = enrollmentToken,
                    device_name = AppConfig.Current.DeviceName,
                    operating_system = AppConfig.Current.OperatingSystem,
                    agent_version = AppConfig.Current.AgentVersion
                };

                string json = JsonSerializer.Serialize(payload);
                var content = new StringContent(json, Encoding.UTF8, "application/json");

                var response = await _httpClient.PostAsync(url, content);
                if (response.IsSuccessStatusCode)
                {
                    string respJson = await response.Content.ReadAsStringAsync();
                    using var doc = JsonDocument.Parse(respJson);
                    var root = doc.RootElement;
                    if (root.GetProperty("success").GetBoolean())
                    {
                        AppConfig.Current.DeviceToken = root.GetProperty("device_token").GetString() ?? "";
                        AppConfig.Save();
                        return true;
                    }
                }
            }
            catch (Exception ex)
            {
                System.Diagnostics.Debug.WriteLine($"Register error: {ex.Message}");
            }
            return false;
        }

        /// <summary>
        /// Fetch current configuration from VPS endpoint
        /// </summary>
        public async Task<bool> FetchConfigAsync()
        {
            try
            {
                SetAuthHeader();
                string url = $"{AppConfig.Current.ServerBaseUrl.TrimEnd('/')}/api/agent/config.php";
                var response = await _httpClient.GetAsync(url);

                if (response.IsSuccessStatusCode)
                {
                    string respJson = await response.Content.ReadAsStringAsync();
                    using var doc = JsonDocument.Parse(respJson);
                    var root = doc.RootElement;

                    if (root.GetProperty("success").GetBoolean() && root.TryGetProperty("config", out var config))
                    {
                        AppConfig.Current.MonitoringEnabled = config.GetProperty("monitoring_enabled").GetBoolean();
                        AppConfig.Current.ScreenshotEnabled = config.GetProperty("screenshot_enabled").GetBoolean();
                        AppConfig.Current.ScreenshotIntervalSeconds = config.GetProperty("screenshot_interval_seconds").GetInt32();
                        AppConfig.Current.ScreenshotQuality = config.GetProperty("screenshot_quality").GetInt32();
                        AppConfig.Current.ScreenshotWidth = config.GetProperty("screenshot_width").GetInt32();
                        AppConfig.Current.ScreenshotHeight = config.GetProperty("screenshot_height").GetInt32();
                        AppConfig.Current.IdleThresholdSeconds = config.GetProperty("idle_threshold_seconds").GetInt32();
                        
                        AppConfig.Save();
                        return true;
                    }
                }
            }
            catch (Exception ex)
            {
                System.Diagnostics.Debug.WriteLine($"FetchConfig error: {ex.Message}");
            }
            return false;
        }

        /// <summary>
        /// Send periodic heartbeat to VPS endpoint
        /// </summary>
        public async Task<bool> SendHeartbeatAsync(bool active, int idleSeconds)
        {
            try
            {
                SetAuthHeader();
                string url = $"{AppConfig.Current.ServerBaseUrl.TrimEnd('/')}/api/agent/heartbeat.php";
                var payload = new
                {
                    agent_version = AppConfig.Current.AgentVersion,
                    active = active ? 1 : 0,
                    idle_seconds = idleSeconds,
                    timestamp = DateTime.UtcNow.ToString("o")
                };

                string json = JsonSerializer.Serialize(payload);
                var content = new StringContent(json, Encoding.UTF8, "application/json");

                var response = await _httpClient.PostAsync(url, content);
                return response.IsSuccessStatusCode;
            }
            catch (Exception ex)
            {
                System.Diagnostics.Debug.WriteLine($"Heartbeat error: {ex.Message}");
            }
            return false;
        }

        /// <summary>
        /// Multipart HTTPS upload of JPEG screenshot array.
        /// Retries safely up to 3 times on network glitch then discards if unfulfilled.
        /// </summary>
        public async Task<bool> UploadScreenshotAsync(byte[] jpegBytes, string activityStatus, int idleSeconds)
        {
            if (jpegBytes == null || jpegBytes.Length == 0) return false;

            int attempts = 0;
            const int maxAttempts = 3;

            while (attempts < maxAttempts)
            {
                attempts++;
                try
                {
                    SetAuthHeader();
                    string url = $"{AppConfig.Current.ServerBaseUrl.TrimEnd('/')}/api/agent/screenshot.php";

                    using (var multipartContent = new MultipartFormDataContent())
                    {
                        var imageContent = new ByteArrayContent(jpegBytes);
                        imageContent.Headers.ContentType = new MediaTypeHeaderValue("image/jpeg");
                        multipartContent.Add(imageContent, "screenshot", $"shot_{DateTime.UtcNow.Ticks}.jpg");

                        multipartContent.Add(new StringContent(activityStatus), "activity_status");
                        multipartContent.Add(new StringContent(idleSeconds.ToString()), "idle_seconds");
                        multipartContent.Add(new StringContent(DateTime.UtcNow.ToString("o")), "captured_at");

                        var response = await _httpClient.PostAsync(url, multipartContent);
                        if (response.IsSuccessStatusCode)
                        {
                            return true;
                        }
                    }
                }
                catch (Exception ex)
                {
                    System.Diagnostics.Debug.WriteLine($"Upload attempt {attempts} failed: {ex.Message}");
                }

                if (attempts < maxAttempts)
                {
                    await Task.Delay(2000); // 2 sec retry delay
                }
            }

            // Discard image memory after bounded retries to prevent unbounded memory buildup
            return false;
        }
    }
}
