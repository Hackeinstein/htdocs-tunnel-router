#!/usr/bin/env python3
"""Rebuild assets/icons.js: only the Lucide icons the interface references (about 10% of the full 450 KB set).

   usage: python3 dev/build_icons.py <path to node_modules/lucide-static/icons>
   (developer-only step; the generated assets/icons.js is committed, so the deployed tool needs no Node.)
"""
import re, sys, json, glob, os
icons_dir = sys.argv[1]
root = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
available = {os.path.basename(p)[:-4] for p in glob.glob(icons_dir + '/*.svg')}
src = ''.join(open(f, encoding='utf-8').read() for f in glob.glob(root + '/views/*.php') + [root + '/assets/ui.js', root + '/assets/portal.js', root + '/src/helpers.php'])
used = sorted({t for t in re.findall(r"['\"`]([a-z][a-z0-9]*(?:-[a-z0-9]+)*)['\"`]", src) if t in available} |
              set(re.findall(r'data-lucide="([a-z0-9-]+)"', src)))
out, missing = {}, []
for n in used:
    svg = open(f'{icons_dir}/{n}.svg', encoding='utf-8').read()
    m = re.search(r'<svg[^>]*>(.*)</svg>', svg, re.S)
    out[n] = re.sub(r'\s+', ' ', m.group(1)).strip() if m else ''
wanted = set(re.findall(r'data-lucide="([a-z0-9-]+)"', src)) | set(re.findall(r"(?:UI\.i|\bic)\('([a-z0-9-]+)'", src))
missing = sorted(w for w in wanted if w not in available)
js = '/* Lucide icons used by Tunnel Router (subset). Rebuild with dev/build_icons.py */\nwindow.__ICONS=' + json.dumps(out, separators=(',', ':')) + ';\n'
open(root + '/assets/icons.js', 'w', encoding='utf-8').write(js)
print(f'{len(out)} icons, {len(js)//1024} KB (full set: 447 KB)')
if missing: print('NOT IN LUCIDE:', ', '.join(missing)); sys.exit(1)
