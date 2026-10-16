#!/bin/sh
# Rebuilds the committed front-end files in assets/. Developer-only: needs Node + network. The deployed tool needs neither.
#   assets/app.css            Tailwind v4 compiled and minified from assets/src/app.css (scans views/*.php and assets/ui.js)
#   assets/icons.js           only the Lucide icons the interface uses
#   assets/apexcharts.min.js  vendored charting library (loaded on demand)
# Run after adding Tailwind classes or icons:   sh dev/build_assets.sh        (TOOLS=/some/dir to reuse an install)
set -e
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
TOOLS="${TOOLS:-$(mktemp -d)}"
if [ ! -d "$TOOLS/node_modules/@tailwindcss/cli" ]; then
  (cd "$TOOLS" && npm init -y >/dev/null 2>&1 && npm i --silent lucide-static@1.53.0 @tailwindcss/cli@4.3.3 tailwindcss@4.3.3 apexcharts@7.8.0)
fi
ln -sfn "$TOOLS/node_modules" "$ROOT/assets/src/node_modules"          # lets "@import tailwindcss" resolve; removed below
trap 'rm -f "$ROOT/assets/src/node_modules"' EXIT
"$TOOLS/node_modules/.bin/tailwindcss" -i "$ROOT/assets/src/app.css" -o "$ROOT/assets/app.css" --minify
python3 "$ROOT/dev/build_icons.py" "$TOOLS/node_modules/lucide-static/icons"
cp "$TOOLS/node_modules/apexcharts/dist/apexcharts.min.js" "$ROOT/assets/apexcharts.min.js"
# the portal is served from public/ alone, so it gets its own copies of the shared files
mkdir -p "$ROOT/public/assets"
cp "$ROOT/assets/app.css" "$ROOT/assets/icons.js" "$ROOT/assets/portal.js" "$ROOT/public/assets/"
ls -l "$ROOT"/assets/*.css "$ROOT"/assets/*.js | awk '{printf "%8d  %s\n", $5, $9}'
