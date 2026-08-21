# VPS Deployment & Server Hardening Guide

This document outlines the step-by-step procedure for deploying TeamTrace Employee Monitor to an existing or new Linux VPS running Apache or Nginx.

## Prerequisites
- Linux VPS (Ubuntu 20.04/22.04, Debian, AlmaLinux, or RHEL)
- Web Server: Apache 2.4+ or Nginx
- PHP 8.2+ with `pdo_mysql`, `gd`, `curl`, and `mbstring` extensions
- MySQL 8.0+ or MariaDB 10.5+
- SSL Certificate (Let's Encrypt / Certbot)

---

## 1. Environment Safety Inspection
Before modifying any configuration on your live VPS:
```bash
# Check existing web servers and ports
sudo netstat -tulpn | grep -E '80|443|3306'

# Check installed PHP version
php -v

# Inspect active virtual hosts
ls -la /etc/apache2/sites-enabled/  # Apache
# OR
ls -la /etc/nginx/sites-enabled/    # Nginx
```

---

## 2. Directory & Storage Permissions
Clone or upload the `Trace` application to your web root (e.g. `/var/www/html/trace`):

Set permissions so web server user (`www-data` or `nginx`) can read files and write screenshots:
```bash
cd /var/www/html/trace
sudo chown -R www-data:www-data .
sudo chmod -R 755 .
sudo chmod -R 775 storage/screenshots
```

---

## 3. Database Setup
Create database and user in MySQL:
```sql
CREATE DATABASE employee_monitor CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'monitor_user'@'localhost' IDENTIFIED BY 'STRONG_SECURE_PASSWORD';
GRANT ALL PRIVILEGES ON employee_monitor.* TO 'monitor_user'@'localhost';
FLUSH PRIVILEGES;
```

Import schema and default admin seed data:
```bash
mysql -u monitor_user -p employee_monitor < database/schema.sql
mysql -u monitor_user -p employee_monitor < database/seed.sql
```

---

## 4. Production `.env` Configuration
Create `.env` file in the project root:
```ini
APP_ENV=production
SERVER_BASE_URL=https://monitor.yourdomain.com
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=employee_monitor
DB_USERNAME=monitor_user
DB_PASSWORD=STRONG_SECURE_PASSWORD
APP_KEY=32_CHARACTER_RANDOM_SECRET_STRING_HERE
SCREENSHOT_STORAGE_PATH=storage/screenshots
```

---

## 5. Web Server Configuration (Nginx Example)
Create `/etc/nginx/sites-available/employee-monitor.conf`:
```nginx
server {
    listen 80;
    server_name monitor.yourdomain.com;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;
    server_name monitor.yourdomain.com;

    root /var/www/html/trace;
    index index.php health.php;

    ssl_certificate /etc/letsencrypt/live/monitor.yourdomain.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/monitor.yourdomain.com/privkey.pem;

    client_max_body_size 15M;

    # Block public direct access to screenshot storage directory
    location /storage/ {
        deny all;
        return 403;
    }

    # Admin routing
    location /admin {
        try_files $uri $uri/ /admin/index.php;
    }

    # API & General PHP handling
    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }

    location ~ /\.ht {
        deny all;
    }
}
```

Enable virtual host and reload Nginx:
```bash
sudo ln -s /etc/nginx/sites-available/employee-monitor.conf /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl reload nginx
```

---

## 6. Daily Cron Setup
Add automated cleanup job to crontab:
```bash
sudo crontab -u www-data -e
```
Add line:
```cron
0 3 * * * /usr/bin/php /var/www/html/trace/cron/cleanup_screenshots.php > /dev/null 2>&1
```

---

## 7. Verification & Health Check
Verify API operations by running diagnostic test:
```bash
php tools/test_api.php
```
Or check endpoint in browser: `https://monitor.yourdomain.com/health.php`
Result: `{"status":"ok","php":"8.2.x","database":"connected"}`
