from PIL import Image, ImageDraw
from pathlib import Path

src = Path(r"C:\xampp\htdocs\resume\assets\images\profile.jpg")
out_dir = Path(r"C:\xampp\htdocs\resume\assets\icons")
out_dir.mkdir(parents=True, exist_ok=True)

img = Image.open(src).convert("RGBA")
w, h = img.size
side = min(w, h)
left = (w - side) // 2
top = max(0, int((h - side) * 0.12))
if top + side > h:
    top = h - side
crop = img.crop((left, top, left + side, top + side))


def circle_icon(size):
    base = crop.resize((size, size), Image.Resampling.LANCZOS)
    mask = Image.new("L", (size, size), 0)
    draw = ImageDraw.Draw(mask)
    draw.ellipse((0, 0, size - 1, size - 1), fill=255)
    out = Image.new("RGBA", (size, size), (0, 0, 0, 0))
    out.paste(base, (0, 0))
    out.putalpha(mask)
    return out


def rounded_square(size, radius_ratio=0.22):
    base = crop.resize((size, size), Image.Resampling.LANCZOS)
    mask = Image.new("L", (size, size), 0)
    draw = ImageDraw.Draw(mask)
    r = int(size * radius_ratio)
    draw.rounded_rectangle((0, 0, size - 1, size - 1), radius=r, fill=255)
    out = Image.new("RGBA", (size, size), (0, 0, 0, 0))
    out.paste(base, (0, 0))
    out.putalpha(mask)
    return out


for size, name in [(16, "favicon-16.png"), (32, "favicon-32.png"), (48, "favicon-48.png")]:
    circle_icon(size).save(out_dir / name, optimize=True)

rounded_square(180).save(out_dir / "apple-touch-icon.png", optimize=True)
rounded_square(192).save(out_dir / "icon-192.png", optimize=True)
rounded_square(512).save(out_dir / "icon-512.png", optimize=True)
circle_icon(32).save(out_dir / "favicon.png", optimize=True)

ico_images = [circle_icon(s) for s in (16, 32, 48)]
ico_images[0].save(
    out_dir / "favicon.ico",
    format="ICO",
    sizes=[(16, 16), (32, 32), (48, 48)],
    append_images=ico_images[1:],
)

root_ico = Path(r"C:\xampp\htdocs\resume\favicon.ico")
root_ico.write_bytes((out_dir / "favicon.ico").read_bytes())

print("ok")
for p in sorted(out_dir.iterdir()):
    if p.suffix.lower() in {".png", ".ico"} and ("favicon" in p.name or "icon-" in p.name or "apple" in p.name):
        print(p.name, p.stat().st_size)
print("root", root_ico.stat().st_size)
