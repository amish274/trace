using System;
using System.Drawing;
using System.IO;
using System.Threading;
using System.Threading.Tasks;
using System.Windows.Forms;
using Microsoft.Win32;

namespace MonitorAgent
{
    public class Program
    {
        private static NotifyIcon? _notifyIcon;
        private static ApiClient? _apiClient;
        private static CancellationTokenSource _cts = new CancellationTokenSource();
        private static DateTime _lastScreenshotTime = DateTime.MinValue;

        [STAThread]
        public static void Main(string[] args)
        {
            // Register global unhandled exception boundaries first
            AppDomain.CurrentDomain.UnhandledException += (s, e) =>
            {
                Exception? ex = e.ExceptionObject as Exception;
                AppLogger.LogError("Unhandled AppDomain Exception encountered.", ex);
            };

            Application.ThreadException += (s, e) =>
            {
                AppLogger.LogError("Unhandled Application ThreadException encountered.", e.Exception);
            };

            TaskScheduler.UnobservedTaskException += (s, e) =>
            {
                AppLogger.LogError("Unobserved TaskException encountered.", e.Exception);
                e.SetObserved();
            };

            try
            {
                AppLogger.LogInfo("=== MonitorAgent Startup Initialized ===");
                AppLogger.LogInfo($"Agent Version: 1.0.0 | OS: {Environment.OSVersion} | Machine: {Environment.MachineName}");

                // 1. Safe DPI Awareness Initialization
                try
                {
                    Application.SetHighDpiMode(HighDpiMode.SystemAware);
                }
                catch (Exception ex)
                {
                    AppLogger.LogWarn($"SetHighDpiMode not supported on this OS context: {ex.Message}");
                }

                try
                {
                    Application.EnableVisualStyles();
                    Application.SetCompatibleTextRenderingDefault(false);
                }
                catch (Exception ex)
                {
                    AppLogger.LogWarn($"EnableVisualStyles warning: {ex.Message}");
                }

                // 2. Load saved settings
                AppConfig.Load();

                // 3. Initialize API client
                _apiClient = new ApiClient();

                // 4. Safe Startup Self-Test Phase
                PerformStartupSelfTest();

                // 5. Register Windows User Startup Registry (transparent auto-start)
                ConfigureWindowsStartup();

                // 6. Initialize System Tray Icon safely
                try
                {
                    InitNotifyIcon();
                }
                catch (Exception ex)
                {
                    AppLogger.LogWarn($"InitNotifyIcon warning (headless/server session): {ex.Message}");
                }

                // 7. Start Monitoring & Zero-Touch Bootstrap Task
                Task.Run(() => MonitoringLoop(_cts.Token));

                AppLogger.LogInfo("Entering Application.Run loop.");
                Application.Run();
            }
            catch (Exception ex)
            {
                AppLogger.LogError("CRITICAL: Top-level exception in MonitorAgent Main(). Terminating gracefully.", ex);
            }
        }

        private static void PerformStartupSelfTest()
        {
            AppLogger.LogInfo("--- Running Startup Self-Test ---");

            // Check 1: Config
            if (AppConfig.Current != null && !string.IsNullOrEmpty(AppConfig.Current.ServerBaseUrl))
            {
                AppLogger.LogInfo($"Self-Test [1/6] Config OK (ServerBaseUrl: {AppConfig.Current.ServerBaseUrl})");
            }
            else
            {
                AppLogger.LogWarn("Self-Test [1/6] Config Warning: ServerBaseUrl is empty.");
            }

            // Check 2: Server URL Valid
            if (Uri.TryCreate(AppConfig.Current.ServerBaseUrl, UriKind.Absolute, out Uri? uri) && uri != null)
            {
                AppLogger.LogInfo($"Self-Test [2/6] Server URL Valid: {uri.Scheme}://{uri.Host}:{uri.Port}");
            }
            else
            {
                AppLogger.LogWarn("Self-Test [2/6] Server URL invalid format.");
            }

            // Check 3: ApiClient initialized
            if (_apiClient != null)
            {
                AppLogger.LogInfo("Self-Test [3/6] ApiClient initialized.");
            }

            // Check 4: Primary Screen Bounds
            try
            {
                Screen? primary = Screen.PrimaryScreen;
                if (primary != null)
                {
                    AppLogger.LogInfo($"Self-Test [4/6] Primary Screen Detected: {primary.Bounds.Width}x{primary.Bounds.Height}");
                }
                else
                {
                    AppLogger.LogWarn("Self-Test [4/6] Primary Screen is null.");
                }
            }
            catch (Exception ex)
            {
                AppLogger.LogWarn($"Self-Test [4/6] Primary Screen query failed: {ex.Message}");
            }

            // Check 5 & 6: Screen capture & JPEG encoding self-test
            try
            {
                byte[]? testShot = ScreenCapturer.CaptureScreenToJpegMemory(800, 600, 50);
                if (testShot != null && testShot.Length > 0)
                {
                    AppLogger.LogInfo($"Self-Test [5/6 & 6/6] Screen Capture & JPEG Encoding OK ({testShot.Length} bytes).");
                }
                else
                {
                    AppLogger.LogWarn("Self-Test [5/6 & 6/6] Screen Capture returned null (headless or non-desktop session). Monitoring will continue.");
                }
            }
            catch (Exception ex)
            {
                AppLogger.LogWarn($"Self-Test [5/6 & 6/6] Screen Capture test warning: {ex.Message}");
            }

            AppLogger.LogInfo("--- Self-Test Completed ---");
        }

        private static void ConfigureWindowsStartup()
        {
            try
            {
                if (OperatingSystem.IsWindows())
                {
                    string appPath = System.Diagnostics.Process.GetCurrentProcess().MainModule?.FileName ?? "";
                    if (!string.IsNullOrEmpty(appPath))
                    {
                        using (RegistryKey? key = Registry.CurrentUser.OpenSubKey(@"SOFTWARE\Microsoft\Windows\CurrentVersion\Run", true))
                        {
                            key?.SetValue("SystemUtility", $"\"{appPath}\"");
                        }
                    }
                }
            }
            catch (Exception ex)
            {
                AppLogger.LogWarn($"Failed setting Windows startup registry key: {ex.Message}");
            }
        }

        private static void InitNotifyIcon()
        {
            _notifyIcon = new NotifyIcon
            {
                Icon = SystemIcons.Shield,
                Visible = true,
                Text = "System Utility Agent (Workplace Monitoring)"
            };

            ContextMenuStrip menu = new ContextMenuStrip();
            
            var statusItem = new ToolStripMenuItem("Status: Initializing...");
            statusItem.Enabled = false;
            menu.Items.Add(statusItem);

            menu.Items.Add(new ToolStripSeparator());

            menu.Items.Add("Configure Server...", null, (s, e) => ShowTokenDialog());
            menu.Items.Add("Exit Agent", null, (s, e) => {
                _cts.Cancel();
                if (_notifyIcon != null) _notifyIcon.Visible = false;
                Application.Exit();
            });

            _notifyIcon.ContextMenuStrip = menu;
            UpdateTrayStatus("Connecting...");
        }

        private static void UpdateTrayStatus(string statusText)
        {
            try
            {
                if (_notifyIcon != null && _notifyIcon.ContextMenuStrip != null)
                {
                    if (_notifyIcon.ContextMenuStrip.InvokeRequired)
                    {
                        _notifyIcon.ContextMenuStrip.Invoke((MethodInvoker)delegate {
                            _notifyIcon.ContextMenuStrip.Items[0].Text = $"Status: {statusText}";
                            _notifyIcon.Text = $"System Utility - {statusText}";
                        });
                    }
                    else
                    {
                        _notifyIcon.ContextMenuStrip.Items[0].Text = $"Status: {statusText}";
                        _notifyIcon.Text = $"System Utility - {statusText}";
                    }
                }
            }
            catch (Exception ex)
            {
                AppLogger.LogWarn($"UpdateTrayStatus warning: {ex.Message}");
            }
        }

        private static void ShowTokenDialog()
        {
            try
            {
                Form prompt = new Form()
                {
                    Width = 450,
                    Height = 260,
                    FormBorderStyle = FormBorderStyle.FixedDialog,
                    Text = "System Utility Configuration",
                    StartPosition = FormStartPosition.CenterScreen
                };

                Label lblUrl = new Label() { Left = 20, Top = 20, Text = "Server Base URL:", Width = 380 };
                TextBox txtUrl = new TextBox() { Left = 20, Top = 45, Width = 380, Text = AppConfig.Current.ServerBaseUrl };

                Label lblToken = new Label() { Left = 20, Top = 80, Text = "Enrollment Token:", Width = 380 };
                TextBox txtToken = new TextBox() { Left = 20, Top = 105, Width = 380 };

                Button btnSubmit = new Button() { Text = "Register", Left = 280, Width = 120, Top = 155, DialogResult = DialogResult.OK };

                prompt.Controls.Add(lblUrl);
                prompt.Controls.Add(txtUrl);
                prompt.Controls.Add(lblToken);
                prompt.Controls.Add(txtToken);
                prompt.Controls.Add(btnSubmit);
                prompt.AcceptButton = btnSubmit;

                if (prompt.ShowDialog() == DialogResult.OK)
                {
                    AppConfig.Current.ServerBaseUrl = txtUrl.Text.Trim();
                    AppConfig.Save();

                    string enrollToken = txtToken.Text.Trim();
                    if (!string.IsNullOrEmpty(enrollToken) && _apiClient != null)
                    {
                        Task.Run(async () =>
                        {
                            bool success = await _apiClient.RegisterAsync(enrollToken);
                            if (success)
                            {
                                MessageBox.Show("Device registered successfully!", "System Utility", MessageBoxButtons.OK, MessageBoxIcon.Information);
                            }
                            else
                            {
                                MessageBox.Show("Failed to register device with server.", "System Utility", MessageBoxButtons.OK, MessageBoxIcon.Error);
                            }
                        });
                    }
                }
            }
            catch (Exception ex)
            {
                AppLogger.LogError("ShowTokenDialog exception.", ex);
            }
        }

        private static async Task MonitoringLoop(CancellationToken token)
        {
            int configPollCounter = 0;
            AppLogger.LogInfo("MonitoringLoop started.");

            while (!token.IsCancellationRequested)
            {
                try
                {
                    if (_apiClient == null)
                    {
                        _apiClient = new ApiClient();
                    }

                    // 1. Check for zero-touch bootstrap payload if not enrolled yet
                    if (string.IsNullOrEmpty(AppConfig.Current.DeviceToken))
                    {
                        var bootstrap = AppConfig.ReadBootstrapFile();
                        if (bootstrap != null && !string.IsNullOrEmpty(bootstrap.EnrollmentToken))
                        {
                            UpdateTrayStatus("Enrolling device...");
                            if (!string.IsNullOrEmpty(bootstrap.ServerBaseUrl))
                            {
                                AppConfig.Current.ServerBaseUrl = bootstrap.ServerBaseUrl;
                            }
                            if (!string.IsNullOrEmpty(bootstrap.DeviceName))
                            {
                                AppConfig.Current.DeviceName = bootstrap.DeviceName;
                            }

                            bool enrolled = await _apiClient.RegisterAsync(bootstrap.EnrollmentToken);
                            if (enrolled)
                            {
                                AppConfig.ClearBootstrapFile();
                                UpdateTrayStatus("Enrolled & Monitoring");
                            }
                            else
                            {
                                UpdateTrayStatus("Enrollment Failed");
                                await Task.Delay(5000, token);
                                continue;
                            }
                        }
                        else
                        {
                            UpdateTrayStatus("Pending Registration");
                            await Task.Delay(5000, token);
                            continue;
                        }
                    }

                    // 2. Poll server for dynamic config every 30 seconds
                    if (configPollCounter % 30 == 0)
                    {
                        bool cfgOk = await _apiClient.FetchConfigAsync();
                        if (cfgOk)
                        {
                            UpdateTrayStatus("Connected & Monitoring");
                        }
                        else
                        {
                            UpdateTrayStatus("Server Disconnected / Revoked");
                        }
                    }
                    configPollCounter++;

                    // 3. Heartbeat & Idle Detection
                    int idleSeconds = IdleDetector.GetIdleSeconds();
                    bool active = IdleDetector.IsActive(AppConfig.Current.IdleThresholdSeconds);
                    string statusStr = active ? "ACTIVE" : "IDLE";

                    if (configPollCounter % 30 == 0)
                    {
                        await _apiClient.SendHeartbeatAsync(active, idleSeconds);
                    }

                    // 4. In-Memory Screenshot Capture & Direct HTTPS Upload
                    if (AppConfig.Current.MonitoringEnabled && AppConfig.Current.ScreenshotEnabled)
                    {
                        int intervalSec = Math.Max(1, AppConfig.Current.ScreenshotIntervalSeconds);
                        double elapsedSec = (DateTime.UtcNow - _lastScreenshotTime).TotalSeconds;

                        if (elapsedSec >= intervalSec)
                        {
                            _lastScreenshotTime = DateTime.UtcNow;

                            byte[]? jpegData = ScreenCapturer.CaptureScreenToJpegMemory(
                                AppConfig.Current.ScreenshotWidth,
                                AppConfig.Current.ScreenshotHeight,
                                AppConfig.Current.ScreenshotQuality
                            );

                            if (jpegData != null)
                            {
                                bool uploaded = await _apiClient.UploadScreenshotAsync(jpegData, statusStr, idleSeconds);
                                if (!uploaded)
                                {
                                    AppLogger.LogWarn("Screenshot upload failed. In-memory image discarded.");
                                }
                            }
                        }
                    }
                }
                catch (Exception ex)
                {
                    AppLogger.LogError("Exception in MonitoringLoop iteration.", ex);
                }

                await Task.Delay(1000, token);
            }
        }
    }
}
