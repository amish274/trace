using System;
using System.Drawing;
using System.Drawing.Imaging;
using System.IO;
using System.Windows.Forms;

namespace MonitorAgent
{
    public static class ScreenCapturer
    {
        /// <summary>
        /// Captures primary screen, encodes JPEG image directly in memory, and returns byte array.
        /// Does NOT save temporary screenshot files to disk on the employee machine.
        /// Isolate screen capture errors so failures never terminate the agent process.
        /// </summary>
        public static byte[]? CaptureScreenToJpegMemory(int targetWidth, int targetHeight, int jpegQuality)
        {
            AppLogger.LogInfo("SCREENSHOT_CAPTURE_STARTED");
            try
            {
                Screen? primary = Screen.PrimaryScreen;
                if (primary == null)
                {
                    AppLogger.LogWarn("SCREENSHOT_CAPTURE_FAILED: Screen.PrimaryScreen is null.");
                    return null;
                }

                Rectangle bounds = primary.Bounds;
                if (bounds.Width <= 0 || bounds.Height <= 0)
                {
                    AppLogger.LogWarn($"SCREENSHOT_CAPTURE_FAILED: invalid screen bounds ({bounds.Width}x{bounds.Height}).");
                    return null;
                }

                using (Bitmap bitmap = new Bitmap(bounds.Width, bounds.Height, PixelFormat.Format32bppArgb))
                {
                    using (Graphics g = Graphics.FromImage(bitmap))
                    {
                        g.CopyFromScreen(bounds.X, bounds.Y, 0, 0, bounds.Size, CopyPixelOperation.SourceCopy);
                    }

                    Bitmap finalBitmap = bitmap;
                    bool needResize = (targetWidth > 0 && targetHeight > 0 && 
                                      (targetWidth < bounds.Width || targetHeight < bounds.Height));

                    if (needResize)
                    {
                        finalBitmap = new Bitmap(bitmap, new Size(targetWidth, targetHeight));
                    }

                    try
                    {
                        using (MemoryStream ms = new MemoryStream())
                        {
                            ImageCodecInfo? jpegEncoder = GetEncoder(ImageFormat.Jpeg);
                            if (jpegEncoder == null)
                            {
                                AppLogger.LogWarn("SCREENSHOT_CAPTURE_FAILED: JPEG encoder codec not found.");
                                return null;
                            }

                            using (EncoderParameters encoderParameters = new EncoderParameters(1))
                            {
                                encoderParameters.Param[0] = new EncoderParameter(Encoder.Quality, (long)jpegQuality);
                                finalBitmap.Save(ms, jpegEncoder, encoderParameters);
                            }

                            byte[] result = ms.ToArray();
                            AppLogger.LogInfo($"SCREENSHOT_CAPTURE_SUCCESS size={result.Length}");
                            return result;
                        }
                    }
                    finally
                    {
                        if (needResize && finalBitmap != bitmap)
                        {
                            finalBitmap.Dispose();
                        }
                    }
                }
            }
            catch (Exception ex)
            {
                AppLogger.LogError("SCREENSHOT_CAPTURE_FAILED: Exception encountered during capture.", ex);
                return null;
            }
        }

        private static ImageCodecInfo? GetEncoder(ImageFormat format)
        {
            try
            {
                ImageCodecInfo[] codecs = ImageCodecInfo.GetImageEncoders();
                foreach (ImageCodecInfo codec in codecs)
                {
                    if (codec.FormatID == format.Guid)
                    {
                        return codec;
                    }
                }
            }
            catch (Exception ex)
            {
                AppLogger.LogError("Error enumerating image encoders.", ex);
            }
            return null;
        }
    }
}
