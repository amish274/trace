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
        /// </summary>
        public static byte[]? CaptureScreenToJpegMemory(int targetWidth, int targetHeight, int jpegQuality)
        {
            try
            {
                // Determine primary screen dimensions
                Rectangle bounds = Screen.PrimaryScreen?.Bounds ?? new Rectangle(0, 0, 1920, 1080);
                
                using (Bitmap bitmap = new Bitmap(bounds.Width, bounds.Height, PixelFormat.Format32bppArgb))
                {
                    using (Graphics g = Graphics.FromImage(bitmap))
                    {
                        g.CopyFromScreen(bounds.X, bounds.Y, 0, 0, bounds.Size, CopyPixelOperation.SourceCopy);
                    }

                    // Resize if target resolution requested and smaller than actual screen
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
                            if (jpegEncoder == null) return null;

                            EncoderParameters encoderParameters = new EncoderParameters(1);
                            encoderParameters.Param[0] = new EncoderParameter(Encoder.Quality, (long)jpegQuality);

                            finalBitmap.Save(ms, jpegEncoder, encoderParameters);
                            return ms.ToArray();
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
                System.Diagnostics.Debug.WriteLine($"Screen capture error: {ex.Message}");
                return null;
            }
        }

        private static ImageCodecInfo? GetEncoder(ImageFormat format)
        {
            ImageCodecInfo[] codecs = ImageCodecInfo.GetImageEncoders();
            foreach (ImageCodecInfo codec in codecs)
            {
                if (codec.FormatID == format.Guid)
                {
                    return codec;
                }
            }
            return null;
        }
    }
}
