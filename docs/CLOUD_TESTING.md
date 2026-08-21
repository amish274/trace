# Cloud Windows VM Testing Guide (Azure / AWS EC2)

This guide documents how to test the TeamTrace Windows Agent (`MonitorAgent.exe`) using a Cloud Windows VM (such as AWS Windows EC2 or Azure Windows 10/11 VM) when local Windows hardware is not available.

## Option 1: AWS Windows EC2 Instance Setup

1. **Launch EC2 Instance:**
   - AMI: **Windows Server 2022 Base** or **Windows 10/11 Desktop**.
   - Instance Type: `t3.medium` or `t3.small` (Minimum 2 vCPU, 4GB RAM).
   - Security Group: Allow outbound HTTP/HTTPS (Port 80/443) and RDP (Port 3389 from your IP).

2. **Connect via RDP:**
   - Download `.rdp` file from AWS Console.
   - Decrypt Administrator password using your SSH private key.
   - Connect using Microsoft Remote Desktop.

3. **Install .NET 8 Desktop Runtime:**
   - Open Microsoft Edge inside the VM and download **.NET 8 Desktop Runtime x64**:
     `https://dotnet.microsoft.com/download/dotnet/8.0`

4. **Transfer & Launch Agent:**
   - Copy compiled `MonitorAgent.exe` to `C:\Program Files\TeamTrace\`.
   - Double click `MonitorAgent.exe`.
   - Check the Windows System Tray (near clock) for the Shield icon.
   - Right click icon -> **Configure Token...**
   - Enter your VPS Base URL and Enrollment Token.

---

## Option 2: Azure Windows 10/11 Virtual Machine Setup

1. **Create Azure VM:**
   - Image: **Windows 11 Pro, Version 22H2 - x64 Gen2**.
   - Size: `Standard_D2s_v3` or `Standard_B2s`.
   - Networking: Default outbound NSG.

2. **RDP & Testing:**
   - RDP into Azure VM.
   - Run `MonitorAgent.exe`.
   - Observe real-time desktop screenshot capture and active/idle state updates in the Admin Dashboard.

---

## Verifying Dynamic Configuration Changes

1. Open Admin Dashboard -> **Settings**.
2. Change **Screenshot Interval** from `30 seconds` to `5 seconds`.
3. Save Settings.
4. Observe the agent on the Cloud Windows VM: Within 30 seconds, it will fetch the new interval and transition to taking screenshots every 5 seconds seamlessly without requiring a restart or reinstall.
