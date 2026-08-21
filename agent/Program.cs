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
        private static readonly ApiClient _apiClient = new ApiClient();
        private static CancellationTokenSource _cts = new CancellationTokenSource();
        private static DateTime _lastScreenshotTime = DateTime.MinValue;

        [STAThread]
        public static void Main(string[] args)
        {
            Application.SetHighDpiMode(HighDpiMode.SystemAware);
            Application.EnableVisualStyles();
            Application.SetCompatibleTextRenderingDefault(false);

            // 1. Load saved settings
            AppConfig.Load();

            // 2. Register Windows User Startup Registry (transparent auto-start)
            ConfigureWindowsStartup();

            // 3. Initialize System Tray Icon
            InitNotifyIcon();

            // 4. Start Monitoring & Zero-Touch Bootstrap Task
            Task.Run(() => MonitoringLoop(_cts.Token));

            Application.Run();
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
                System.Diagnostics.Debug.WriteLine($"Failed to set Windows startup key: {ex.Message}");
            }
        }

        private static void InitNotifyIcon()
        {
            _notifyIcon = new NotifyIcon
            {
                Icon = SystemIcons.Shield,
                Visible = true,
                Text = "System Utility (Authorized Workplace Monitoring)"
            };

            ContextMenuStrip menu = new ContextMenuStrip();
            
            var statusItem = new ToolStripMenuItem("Status: Initializing...");
            statusItem.Enabled = false;
            menu.Items.Add(statusItem);

            menu.Items.Add(new ToolStripSeparator());

            menu.Items.Add("Configure Server...", null, (s, e) => ShowTokenDialog());
            menu.Items.Add("Exit Agent", null, (s, e) => {
                _cts.Cancel();
                _notifyIcon.Visible = false;
                Application.Exit();
            });

            _notifyIcon.ContextMenuStrip = menu;
            UpdateTrayStatus("Connecting...");
        }

        private static void UpdateTrayStatus(string statusText)
        {
            if (_notifyIcon != null && _notifyIcon.ContextMenuStrip != null)
            {
                _notifyIcon.ContextMenuStrip.Invoke((MethodInvoker)delegate {
                    _notifyIcon.ContextMenuStrip.Items[0].Text = $"Status: {statusText}";
                    _notifyIcon.Text = $"System Utility - {statusText}";
                });
            }
        }

        private static void ShowTokenDialog()
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
                if (!string.IsNullOrEmpty(enrollToken))
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

        private static async Task MonitoringLoop(CancellationToken token)
        {
            int configPollCounter = 0;

            while (!token.IsCancellationRequested)
            {
                try
                {
                    // 1. Check for zero-touch bootstrap.json if not enrolled yet
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
                                    System.Diagnostics.Debug.WriteLine("Screenshot upload failed. In-memory image discarded.");
                                }
                            }
                        }
                    }
                }
                catch (Exception ex)
                {
                    System.Diagnostics.Debug.WriteLine($"Monitoring loop error: {ex.Message}");
                }

                await Task.Delay(1000, token);
            }
        }
    }
}
