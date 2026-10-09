# أيقونات التطبيق (PWA) لبرنامج الرواتب: دائرة كحلية + «MSA / PAIE» ذهبي — تُولَّد مرّة واحدة
# python tools/make_app_icons.py  →  assets/img/icon-192.png + icon-512.png
import os
from PIL import Image, ImageDraw, ImageFont

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
OUT = os.path.join(ROOT, 'assets', 'img')
NAVY, GOLD = (10, 34, 64), (201, 169, 97)
FONT = r'C:\Windows\Fonts\arialbd.ttf'


def icon(size):
    img = Image.new('RGBA', (size, size), NAVY + (255,))  # خلفية ممتلئة = صالحة كـmaskable
    d = ImageDraw.Draw(img)
    pad = int(size * 0.10)
    d.ellipse([pad, pad, size - pad, size - pad], fill=NAVY, outline=GOLD, width=max(2, size // 48))
    f1 = ImageFont.truetype(FONT, int(size * 0.30))
    f2 = ImageFont.truetype(FONT, int(size * 0.12))
    for txt, font, y in (('MSA', f1, size * 0.40), ('PAIE', f2, size * 0.63)):
        w = d.textlength(txt, font=font)
        d.text(((size - w) / 2, y - font.size / 2), txt, font=font, fill=GOLD)
    # مثلّث صغير كما في شعار البرنامج
    t = size * 0.04
    cx, cy = size / 2, size * 0.78
    d.polygon([(cx, cy - t), (cx + t, cy + t), (cx - t, cy + t)], fill=GOLD)
    return img


for s in (192, 512):
    icon(s).save(os.path.join(OUT, f'icon-{s}.png'), optimize=True)
    print('ok', s)
