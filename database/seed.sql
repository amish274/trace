-- Default Seed Data for Employee Monitor

-- Default Admin User: admin / password123 (Development Only)
-- BCrypt Hash of 'password123'
INSERT INTO admin_users (username, password_hash, email) VALUES 
('admin', '$2y$10$God4NiwT04IuTGqueHWKMOpeKnWOT1F7zR1pooYJJcG9m6pwYfe7W', 'admin@example.com')
ON DUPLICATE KEY UPDATE password_hash=VALUES(password_hash);

-- Seed Demo Employee
INSERT INTO employees (id, name, email, status) VALUES
(1, 'Rahul Sharma', 'rahul@example.com', 'active')
ON DUPLICATE KEY UPDATE name=VALUES(name);

-- Seed Demo Device
-- Token: 'demo_token_123456789012345678901234'
-- SHA256 Hash of 'demo_token_123456789012345678901234' = 0f423ab65a3d722e0e014ec47d25e1732e75e9545465e94b29bb887550302b1f
INSERT INTO devices (id, employee_id, device_name, device_token_hash, enrollment_token, operating_system, agent_version, last_seen_at) VALUES
(1, 1, 'OFFICE-PC-01', '0f423ab65a3d722e0e014ec47d25e1732e75e9545465e94b29bb887550302b1f', 'ENROLL-DEMO-2026', 'Windows 11 Pro 64-bit', '1.0.0', NOW())
ON DUPLICATE KEY UPDATE device_name=VALUES(device_name);

-- Seed Monitor Settings for DEMO-PC
INSERT INTO monitor_settings (device_id, monitoring_enabled, screenshot_enabled, screenshot_interval_seconds, screenshot_quality, screenshot_width, screenshot_height, idle_threshold_seconds) VALUES
(1, 1, 1, 30, 70, 0, 0, 120)
ON DUPLICATE KEY UPDATE screenshot_interval_seconds=VALUES(screenshot_interval_seconds);

-- Global settings default
INSERT INTO global_settings (setting_key, setting_value) VALUES
('retention_days', '30')
ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value);
