<?php
/**
 * GET api/captcha.php?t=<cachebuster>
 *
 * Renders the self-hosted CAPTCHA image for the challenge already stored in the
 * visitor's session (issued by api/csrf.php). No third-party service and no keys
 * are involved: the answer never leaves the server.
 *
 * Falls back to a plain-text SVG challenge when PHP's GD extension is missing.
 */

declare(strict_types=1);

$runtime = require __DIR__ . '/bootstrap.php';

$security = new Security($runtime['config']);

if (!$security->is_captcha_enabled()) {
	http_response_code(404);
	header('Content-Type: text/plain; charset=utf-8');
	echo 'CAPTCHA is disabled.';
	exit;
}

$code = $security->captcha_image_code();

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Content-Type-Options: nosniff');

if (!function_exists('imagecreatetruecolor')) {
	render_svg_fallback($code);
	exit;
}

render_png($code);

// ---------------------------------------------------------------------------
// Rendering
// ---------------------------------------------------------------------------

function render_png(string $code): void
{
	$width = 210;
	$height = 60;
	$padding = 14;

	$image = imagecreatetruecolor($width, $height);
	imagealphablending($image, true);

	// Light background so the dark, warped glyphs stay readable.
	$bg = imagecolorallocate($image, 246, 249, 254);
	imagefilledrectangle($image, 0, 0, $width, $height, $bg);

	// Interference: pale wavy bands behind the glyphs.
	for ($i = 0; $i < 6; $i++) {
		$band = imagecolorallocate($image, random_int(205, 238), random_int(212, 240), random_int(220, 248));
		$points = array();
		$x = 0;
		while ($x <= $width) {
			$points[] = $x;
			$points[] = random_int(0, $height);
			$x += random_int(18, 30);
		}
		imagefilledpolygon($image, $points, count($points) / 2, $band);
	}

	// Glyphs, each randomly skewed and vertically offset, with a soft shadow.
	$length = strlen($code);
	$slot = ($width - 2 * $padding) / max(1, $length);
	$tileWidth = 46;
	$tileHeight = 44;

	for ($index = 0; $index < $length; $index++) {
		$char = $code[$index];

		$tile = imagecreatetruecolor($tileWidth, $tileHeight);
		$tileBg = imagecolorallocate($tile, 246, 249, 254);
		imagefilledrectangle($tile, 0, 0, $tileWidth, $tileHeight, $tileBg);
		imagealphablending($tile, true);

		$ink = imagecolorallocate($tile, random_int(15, 70), random_int(25, 80), random_int(70, 140));
		imagestring($tile, 5, 13, 13, $char, $ink);

		$rotated = imagerotate($tile, random_int(-24, 24), $tileBg);
		if ($rotated !== false) {
			imagedestroy($tile);
			$tile = $rotated;
		}

		$tw = imagesx($tile);
		$th = imagesy($tile);

		$destX = (int) round($padding + $index * $slot - 6);
		$destY = (int) round(($height - $th) / 2 + random_int(-4, 4));

		imagecopyresampled($image, $tile, $destX, $destY, 0, 0, $tw, $th, $tw, $th);
		imagedestroy($tile);
	}

	// Darker strokes drawn over the glyphs to frustrate naive OCR.
	for ($i = 0; $i < 4; $i++) {
		$stroke = imagecolorallocate($image, random_int(60, 140), random_int(70, 150), random_int(120, 190));
		imagesetthickness($image, random_int(1, 2));
		$y1 = random_int(4, $height - 4);
		$y2 = random_int(4, $height - 4);
		imageline($image, 0, $y1, (int) ($width * 0.55), $y2, $stroke);
		imageline($image, (int) ($width * 0.45), $y2, $width, random_int(4, $height - 4), $stroke);
	}

	// Speckle noise.
	for ($i = 0; $i < 260; $i++) {
		$dot = imagecolorallocate($image, random_int(110, 200), random_int(120, 205), random_int(140, 215));
		imagesetpixel($image, random_int(0, $width - 1), random_int(0, $height - 1), $dot);
	}

	// Border
	$border = imagecolorallocate($image, 190, 210, 235);
	imagerectangle($image, 0, 0, $width - 1, $height - 1, $border);

	header('Content-Type: image/png');
	imagepng($image);
	imagedestroy($image);
}

function render_svg_fallback(string $code): void
{
	header('Content-Type: image/svg+xml; charset=utf-8');

	$escaped = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');
	$noise = '';
	for ($i = 0; $i < 18; $i++) {
		$noise .= '<line x1="' . random_int(0, 210) . '" y1="' . random_int(0, 60) . '" x2="' . random_int(0, 210) . '" y2="' . random_int(0, 60)
			. '" stroke="rgba(' . random_int(90, 180) . ',' . random_int(110, 190) . ',' . random_int(140, 210) . ',0.6)" stroke-width="1"/>';
	}

	echo '<svg xmlns="http://www.w3.org/2000/svg" width="210" height="60" viewBox="0 0 210 60" role="img" aria-label="Security code">';
	echo '<rect width="210" height="60" fill="#f6f9fe" stroke="#bed2eb"/>';
	echo $noise;
	echo '<text x="105" y="41" text-anchor="middle" font-family="monospace" font-size="34" font-weight="700" letter-spacing="8" fill="#1e3a8a" transform="rotate(-3 105 30)">' . $escaped . '</text>';
	echo '</svg>';
}
