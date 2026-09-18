$csharpSource = @"
using System;
using System.Drawing;
using System.Drawing.Imaging;

public class FastBgRemover {
    public static void Process(string srcPath, string destPngPath) {
        using (Bitmap src = new Bitmap(srcPath)) {
            int width = src.Width;
            int height = src.Height;

            Bitmap dest = new Bitmap(width, height, PixelFormat.Format32bppArgb);

            BitmapData srcData = src.LockBits(new Rectangle(0, 0, width, height), ImageLockMode.ReadOnly, PixelFormat.Format24bppRgb);
            BitmapData destData = dest.LockBits(new Rectangle(0, 0, width, height), ImageLockMode.WriteOnly, PixelFormat.Format32bppArgb);

            unsafe {
                byte* srcPtr = (byte*)srcData.Scan0;
                byte* destPtr = (byte*)destData.Scan0;

                int srcStride = srcData.Stride;
                int destStride = destData.Stride;

                for (int y = 0; y < height; y++) {
                    byte* srcRow = srcPtr + (y * srcStride);
                    byte* destRow = destPtr + (y * destStride);

                    for (int x = 0; x < width; x++) {
                        byte b = srcRow[x * 3];
                        byte g = srcRow[x * 3 + 1];
                        byte r = srcRow[x * 3 + 2];

                        double lum = 0.299 * r + 0.587 * g + 0.114 * b;

                        // Check for background white/near-white
                        if (r >= 238 && g >= 238 && b >= 238) {
                            destRow[x * 4] = 0;
                            destRow[x * 4 + 1] = 0;
                            destRow[x * 4 + 2] = 0;
                            destRow[x * 4 + 3] = 0;
                        } else if (lum > 218 && (r > 210 && g > 210 && b > 210)) {
                            double alphaRatio = (238.0 - lum) / (238.0 - 218.0);
                            byte alpha = (byte)Math.Max(0, Math.Min(255, (int)(alphaRatio * 255)));

                            destRow[x * 4] = b;
                            destRow[x * 4 + 1] = g;
                            destRow[x * 4 + 2] = r;
                            destRow[x * 4 + 3] = alpha;
                        } else {
                            destRow[x * 4] = b;
                            destRow[x * 4 + 1] = g;
                            destRow[x * 4 + 2] = r;
                            destRow[x * 4 + 3] = 255;
                        }
                    }
                }
            }

            src.UnlockBits(srcData);
            dest.UnlockBits(destData);

            dest.Save(destPngPath, ImageFormat.Png);
            dest.Dispose();
        }
    }
}
"@

$params = New-Object System.CodeDom.Compiler.CompilerParameters
$params.CompilerOptions = '/unsafe'
$params.ReferencedAssemblies.Add('System.Drawing.dll') | Out-Null

Add-Type -TypeDefinition $csharpSource -CompilerParameters $params

$src = "C:\Users\DELL Latitude 5420\.gemini\antigravity-ide\brain\023812ca-1aaa-4848-9f35-6b8b022bc34f\gemini_star_whitebg_1789722035658.jpg"
$destPng = "c:\clients\Etulle\assets\images\ai_copilot_avatar.png"

[FastBgRemover]::Process($src, $destPng)
Write-Output "Clean transparent PNG generated successfully."
