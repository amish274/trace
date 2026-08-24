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
                // Prefer TLS 1.2 specifically on Windows Server 2012 R2
                System.Net.ServicePointManager.SecurityProtocol = System.Net.SecurityProtocolType.Tls12;
            }
            catch (Exception ex)
            {
                AppLogger.LogWarn($"ServicePointManager TLS 1.2 setup warning: {ex.Message}");
            }

            var handler = new HttpClientHandler();
            _httpClient = new HttpClient(handler);
            _httpClient.Timeout = TimeSpan.FromSeconds(15);
            _httpClient.DefaultRequestHeaders.UserAgent.ParseAdd("TeamTrace-Agent/1.0 (Windows NT)");
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

        public async Task<bool> RegisterAsync(string enrollmentToken)
        {
            try
            {
                string url = $"{AppConfig.Current.ServerBaseUrl.TrimEnd('/')}/api/agent/register.php";
                AppLogger.LogInfo($"RegisterAsync attempting device registration with server URL: {AppConfig.Current.ServerBaseUrl}");

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
                string respJson = await response.Content.ReadAsStringAsync();

                if (response.IsSuccessStatusCode)
                {
                    using var doc = JsonDocument.Parse(respJson);
                    var root = doc.RootElement;
                    if (root.TryGetProperty("success", out var succ) && succ.GetBoolean())
                    {
                        AppConfig.Current.DeviceToken = root.GetProperty("device_token").GetString() ?? "";
                        AppConfig.Save();
                        AppLogger.LogInfo("RegisterAsync SUCCESS: Device token acquired and saved.");
                        return true;
                    }
                    else
                    {
                        string err = root.TryGetProperty("error", out var errProp) ? errProp.GetString() ?? "Unknown error" : "Unknown error";
                        AppLogger.LogWarn($"RegisterAsync server returned logic error: {err}");
                    }
                }
                else
                {
                    AppLogger.LogWarn($"RegisterAsync HTTP error status: {(int)response.StatusCode} {response.StatusCode} | Body: {respJson}");
                }
            }
            catch (HttpRequestException ex)
            {
                AppLogger.LogError("RegisterAsync HttpRequestException (Network/TLS/DNS failure).", ex);
            }
            catch (Exception ex)
            {
                AppLogger.LogError("RegisterAsync unexpected exception.", ex);
            }
            return false;
        }

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
                else
                {
                    AppLogger.LogWarn($"FetchConfigAsync HTTP status: {(int)response.StatusCode}");
                }
            }
            catch (Exception ex)
            {
                AppLogger.LogError("FetchConfigAsync exception.", ex);
            }
            return false;
        }

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
                if (!response.IsSuccessStatusCode)
                {
                    AppLogger.LogWarn($"SendHeartbeatAsync HTTP status: {(int)response.StatusCode}");
                }
                return response.IsSuccessStatusCode;
            }
            catch (Exception ex)
            {
                AppLogger.LogError("SendHeartbeatAsync exception.", ex);
            }
            return false;
        }

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
                            AppLogger.LogInfo($"UploadScreenshotAsync SUCCESS on attempt {attempts}.");
                            return true;
                        }
                        else
                        {
                            AppLogger.LogWarn($"UploadScreenshotAsync attempt {attempts} HTTP status: {(int)response.StatusCode}");
                        }
                    }
                }
                catch (Exception ex)
                {
                    AppLogger.LogError($"UploadScreenshotAsync attempt {attempts} exception.", ex);
                }

                if (attempts < maxAttempts)
                {
                    await Task.Delay(2000);
                }
            }

            return false;
        }
    }
}
