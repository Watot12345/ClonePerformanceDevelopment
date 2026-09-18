Add-Type -AssemblyName System.Drawing

$srcPath = "C:\Users\DELL Latitude 5420\.gemini\antigravity-ide\brain\023812ca-1aaa-4848-9f35-6b8b022bc34f\gemini_star_whitebg_1789722035658.jpg"
$destPath = "c:\clients\Etulle\assets\images\ai_copilot_avatar.png"
$destJpgPath = "c:\clients\Etulle\assets\images\ai_copilot_avatar.jpg"

$src = [System.Drawing.Bitmap]::FromFile($srcPath)
$width = $src.Width
$height = $src.Height

# Create 32-bit ARGB bitmap
$dest = New-Object System.Drawing.Bitmap($width, $height, [System.Drawing.Imaging.PixelFormat]::Format32bppArgb)

# Flood fill / BFS from edges to identify true background
$visited = New-Object 'bool[,]' $width, $height
$queue = New-Object System.Collections.Generic.Queue[System.Drawing.Point]

# Enqueue all boundary pixels
for ($x = 0; $x -lt $width; $x++) {
    $queue.Enqueue((New-Object System.Drawing.Point($x, 0)))
    $queue.Enqueue((New-Object System.Drawing.Point($x, $height - 1)))
    $visited[$x, 0] = $true
    $visited[$x, $height - 1] = $true
}
for ($y = 0; $y -lt $height; $y++) {
    $queue.Enqueue((New-Object System.Drawing.Point(0, $y)))
    $queue.Enqueue((New-Object System.Drawing.Point($width - 1, $y)))
    $visited[0, $y] = $true
    $visited[$width - 1, $y] = $true
}

# BFS floodfill
while ($queue.Count -gt 0) {
    $pt = $queue.Dequeue()
    $px = $src.GetPixel($pt.X, $pt.Y)
    
    # Check if pixel is light background (R > 230, G > 230, B > 230)
    if ($px.R -gt 225 -and $px.G -gt 225 -and $px.B -gt 225) {
        $dest.SetPixel($pt.X, $pt.Y, [System.Drawing.Color]::FromArgb(0, 0, 0, 0))
        
        # Check 4 neighbors
        $neighbors = @(
            (New-Object System.Drawing.Point($pt.X + 1, $pt.Y)),
            (New-Object System.Drawing.Point($pt.X - 1, $pt.Y)),
            (New-Object System.Drawing.Point($pt.X, $pt.Y + 1)),
            (New-Object System.Drawing.Point($pt.X, $pt.Y - 1))
        )
        
        foreach ($n in $neighbors) {
            if ($n.X -ge 0 -and $n.X -lt $width -and $n.Y -ge 0 -and $n.Y -lt $height) {
                if (-not $visited[$n.X, $n.Y]) {
                    $visited[$n.X, $n.Y] = $true
                    $nPx = $src.GetPixel($n.X, $n.Y)
                    if ($nPx.R -gt 225 -and $nPx.G -gt 225 -and $nPx.B -gt 225) {
                        $queue.Enqueue($n)
                    }
                }
            }
        }
    }
}

# Fill remaining non-background pixels and smooth edges
for ($y = 0; $y -lt $height; $y++) {
    for ($x = 0; $x -lt $width; $x++) {
        $p = $dest.GetPixel($x, $y)
        if ($p.A -eq 0) {
            continue
        }
        $srcP = $src.GetPixel($x, $y)
        
        # Edge antialiasing for boundary pixels
        $r = $srcP.R
        $g = $srcP.G
        $b = $srcP.B
        $lum = (0.299 * $r + 0.587 * $g + 0.114 * $b)
        
        if ($lum -gt 235 -and ($r -gt 230 -and $g -gt 230 -and $b -gt 230)) {
            $alphaRatio = (255.0 - $lum) / (255.0 - 235.0)
            $alpha = [int]([Math]::Max(0, [Math]::Min(255, $alphaRatio * 255)))
            $dest.SetPixel($x, $y, [System.Drawing.Color]::FromArgb($alpha, $r, $g, $b))
        } else {
            $dest.SetPixel($x, $y, [System.Drawing.Color]::FromArgb(255, $r, $g, $b))
        }
    }
}

$dest.Save($destPath, [System.Drawing.Imaging.ImageFormat]::Png)

# Also save transparent-backed or matching jpg for fallback
$dest.Save($destJpgPath, [System.Drawing.Imaging.ImageFormat]::Jpeg)

$src.Dispose()
$dest.Dispose()

Write-Host "Successfully generated transparent AI logo: $destPath"
