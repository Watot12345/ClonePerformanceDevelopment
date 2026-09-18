Add-Type -AssemblyName System.Drawing

$srcPath = "C:\Users\DELL Latitude 5420\.gemini\antigravity-ide\brain\023812ca-1aaa-4848-9f35-6b8b022bc34f\gemini_sparkle_3d_1789721313448.jpg"
$destPath = "c:\clients\Etulle\assets\images\ai_copilot_avatar.png"

$src = [System.Drawing.Bitmap]::FromFile($srcPath)
$width = $src.Width
$height = $src.Height

# Create ARGB 32-bit bitmap
$dest = New-Object System.Drawing.Bitmap($width, $height, [System.Drawing.Imaging.PixelFormat]::Format32bppArgb)

# Background color sample from top-left corner
$bgSamples = @(
    $src.GetPixel(5, 5),
    $src.GetPixel($width - 6, 5),
    $src.GetPixel(5, $height - 6),
    $src.GetPixel($width - 6, $height - 6)
)
$bgR = ($bgSamples | Measure-Object -Property R -Average).Average
$bgG = ($bgSamples | Measure-Object -Property G -Average).Average
$bgB = ($bgSamples | Measure-Object -Property B -Average).Average

Write-Host "Sampled Background RGB: $bgR, $bgG, $bgB"

# Center and radius
$centerX = $width / 2.0
$centerY = $height / 2.0
$maxRadius = [Math]::Min($width, $height) / 2.0

# Process pixels with smooth alpha feathering
for ($y = 0; $y -lt $height; $y++) {
    for ($x = 0; $x -lt $width; $x++) {
        $pixel = $src.GetPixel($x, $y)
        $r = $pixel.R
        $g = $pixel.G
        $b = $pixel.B

        # Luminance
        $lum = (0.299 * $r + 0.587 * $g + 0.114 * $b)
        
        # Color difference from background
        $diff = [Math]::Sqrt([Math]::Pow($r - $bgR, 2) + [Math]::Pow($g - $bgG, 2) + [Math]::Pow($b - $bgB, 2))

        # Alpha calculation
        # Low luminance/low diff pixels become fully transparent
        # Mid range pixels smoothly blend alpha
        # High luminosity or high saturation remain fully opaque
        if ($diff -lt 18 -and $lum -lt 28) {
            $dest.SetPixel($x, $y, [System.Drawing.Color]::FromArgb(0, 0, 0, 0))
        } elseif ($diff -lt 45 -and $lum -lt 55) {
            $alphaRatio = ($diff - 18.0) / (45.0 - 18.0)
            $alpha = [int]([Math]::Max(0, [Math]::Min(255, $alphaRatio * 255)))
            
            # De-contaminate background color
            $newR = [int][Math]::Min(255, [Math]::Max(0, ($r - $bgR * (1.0 - $alphaRatio)) / [Math]::Max(0.1, $alphaRatio)))
            $newG = [int][Math]::Min(255, [Math]::Max(0, ($g - $bgG * (1.0 - $alphaRatio)) / [Math]::Max(0.1, $alphaRatio)))
            $newB = [int][Math]::Min(255, [Math]::Max(0, ($b - $bgB * (1.0 - $alphaRatio)) / [Math]::Max(0.1, $alphaRatio)))

            $dest.SetPixel($x, $y, [System.Drawing.Color]::FromArgb($alpha, $newR, $newG, $newB))
        } else {
            $dest.SetPixel($x, $y, [System.Drawing.Color]::FromArgb(255, $r, $g, $b))
        }
    }
}

$dest.Save($destPath, [System.Drawing.Imaging.ImageFormat]::Png)
$src.Dispose()
$dest.Dispose()

Write-Host "Exported transparent PNG to $destPath"
