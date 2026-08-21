using System;
using System.Runtime.InteropServices;

namespace MonitorAgent
{
    public static class IdleDetector
    {
        [StructLayout(LayoutKind.Sequential)]
        private struct LASTINPUTINFO
        {
            public uint cbSize;
            public uint dwTime;
        }

        [DllImport("user32.dll")]
        private static extern bool GetLastInputInfo(ref LASTINPUTINFO plii);

        /// <summary>
        /// Gets the total idle duration in seconds based on system keyboard and mouse inputs.
        /// Label accurately as "Active / Idle based on keyboard/mouse input".
        /// </summary>
        public static int GetIdleSeconds()
        {
            try
            {
                if (RuntimeInformation.IsOSPlatform(OSPlatform.Windows))
                {
                    LASTINPUTINFO lastInput = new LASTINPUTINFO();
                    lastInput.cbSize = (uint)Marshal.SizeOf(lastInput);

                    if (GetLastInputInfo(ref lastInput))
                    {
                        uint envTicks = (uint)Environment.TickCount;
                        uint idleTicks = envTicks - lastInput.dwTime;
                        return (int)(idleTicks / 1000);
                    }
                }
            }
            catch
            {
                // Fallback for non-windows testing environments
            }

            return 0;
        }

        /// <summary>
        /// Returns true if user input occurred within the specified idle threshold seconds.
        /// </summary>
        public static bool IsActive(int idleThresholdSeconds)
        {
            return GetIdleSeconds() < idleThresholdSeconds;
        }
    }
}
