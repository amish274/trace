# TeamTrace - Lightweight Employee Activity Monitoring System

TeamTrace is a high-performance, lightweight workplace employee monitoring system designed specifically for authorized, transparent employee productivity auditing on company-owned hardware.

## Architecture
- **Server:** PHP 8.2+ (Vanilla PHP, No Frameworks), MySQL / MariaDB, Apache / Nginx.
- **Admin Dashboard:** HTML5, Modern Dark-Mode CSS, Vanilla JavaScript (No React, No Node server).
- **Windows Agent:** Native C# / .NET 8 WPF Background Application using Windows Native APIs (`GetLastInputInfo`, `GDI+`).

---

## Directory Structure
```
Trace/
├── .env.example                # Base environment template
├── config/
│   └── config.php              # Global configuration loader
├── database/
│   ├── schema.sql              # MySQL database structure & indexes
│   └── seed.sql                # Default admin & demo data
├── includes/
│   ├── db.php                  # PDO database connection helper
│   └── auth.php                # Token auth & session security helper
├── api/
│   └── agent/
│       ├── register.php        # Device enrollment API
│       ├── config.php          # Polled agent settings API
│       ├── heartbeat.php       # Periodic heartbeat API
│       ├── activity.php        # Active / Idle input log API
│       ├── screenshot.php      # Multipart HTTPS screenshot upload
│       └── version.php         # Agent update version API
├── admin/
│   ├── index.php               # Employee overview dashboard
│   ├── device.php              # Detailed employee device spec view
│   ├── screenshots.php         # Chronological screenshot timeline viewer
│   ├── activity.php            # Daily input activity breakdown
│   ├── settings.php           # Interval, resolution & storage estimator
│   ├── enroll.php              # One-time enrollment token generator
│   ├── login.php               # Secure admin login
│   ├── logout.php              # Admin session logout
│   ├── screenshot.php         # Authenticated image file proxy
│   └── assets/style.css        # Dashboard stylesheet
├── agent/
│   ├── MonitorAgent.csproj    # .NET 8 C# project file
│   ├── Program.cs              # System Tray UI & worker thread
│   ├── ApiClient.cs            # HTTPS Bearer token client & multipart upload
│   ├── IdleDetector.cs         # Native GetLastInputInfo Windows API wrapper
│   ├── ScreenCapturer.cs       # In-memory JPEG GDI+ screen capture
│   ├── AppConfig.cs            # Local agent JSON settings manager
│   └── simulator.php           # Development agent simulator CLI
├── cron/
│   └── cleanup_screenshots.php # CLI retention purge script
├── tools/
│   └── test_api.php            # Diagnostic API test suite
├── docs/
│   ├── VPS_DEPLOYMENT.md       # Full VPS deployment guide
│   └── CLOUD_TESTING.md        # AWS/Azure Cloud VM test guide
└── health.php                  # System health diagnostic endpoint
```

---

## Quick Setup & Local Development

### 1. Database Initialization
Import the schema and seed scripts into MySQL:
```bash
mysql -u root -p -e "CREATE DATABASE IF NOT EXISTS employee_monitor;"
mysql -u root -p employee_monitor < database/schema.sql
mysql -u root -p employee_monitor < database/seed.sql
```

### 2. Environment Configuration
Copy `.env.example` to `.env` and set your database credentials:
```bash
cp .env.example .env
```
Update `.env`:
```ini
APP_ENV=development
SERVER_BASE_URL=http://localhost:8888/ethnicboost/Trace
DB_HOST=127.0.0.1
DB_DATABASE=employee_monitor
DB_USERNAME=root
DB_PASSWORD=root
```

### 3. Default Admin Access
Navigate to `/admin/login.php`:
- **Username:** `admin`
- **Password:** `password123` *(Change in production)*

---

## Windows Agent Build & Installation

### Building Executable (`MonitorAgent.exe`)
Run from the `agent/` folder on a system with .NET 8 SDK:
```bash
cd agent
dotnet publish -c Release -r win-x64 --self-contained false -o ../build/Agent
```
The compiled release executable `MonitorAgent.exe` will be generated in `build/Agent/`.

### Device Enrollment Flow
1. Open Admin Dashboard -> **Enroll Device**.
2. Enter Employee Name and Device Computer Name to generate an enrollment token (e.g. `ENROLL-DEMO-2026`).
3. Launch `MonitorAgent.exe` on the employee computer.
4. Right-click the Tray Icon -> **Configure Token...**
5. Enter Server Base URL (`https://YOUR_VPS_DOMAIN`) and the enrollment token.
6. The agent registers, retrieves settings, and begins monitoring automatically.

---

## Storage & Interval Customization
The administrator can change screenshot frequency from **Settings**:
- **Supported Intervals:** 1s, 2s, 5s, 10s, 15s, 30s, 60s, 120s, 300s.
- **Dynamic Polling:** Connected Windows agents automatically poll for configuration changes every 30 seconds and update their capture timer locally without requiring reinstall.

---

## Automated Retention Cleanup Cron
Set up a daily server cron job to purge screenshots past retention period (Default: 30 days):
```cron
0 3 * * * /usr/bin/php /path/to/Trace/cron/cleanup_screenshots.php > /dev/null 2>&1
```

---

## Security & Compliance
- **No Stealth Evasion:** Built strictly for authorized transparent employee monitoring. Includes system tray indicator and status menu.
- **No Permanent Local Screenshots:** Screenshots are captured directly into RAM, encoded as JPEG in memory, uploaded over HTTPS, and immediately released. Zero disk traces left on employee machine.
- **Input Metrics Accuracy:** Idle metrics are strictly labeled as *"Active / Idle based on keyboard/mouse input"*.
