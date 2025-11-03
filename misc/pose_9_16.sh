#!/usr/bin/env bash
# pad_trim_to_9x16_uniform.sh
# 1) trim transparent edges
# 2) pad to 9:16 (bottom aligned; no padding down, only up/sides)
# 3) resize all outputs to the same 9:16 size (largest result as reference)
# 4) optimize via pngquant if available

set -u  # kein -e, damit Batch weiterläuft; wir loggen Fehler

OUTDIR="${1:-_out_9x16}"; shift || true
UNIFORM_DIR="${OUTDIR}_uniform"
mkdir -p "$OUTDIR" "$UNIFORM_DIR" || { echo "❌ OUTDIR anlegen fehlgeschlagen." >&2; exit 1; }

# --- ImageMagick wählen ---
if command -v magick >/dev/null 2>&1; then
  IM_ID="magick identify"
  IM_CONV="magick"
elif command -v identify >/dev/null 2>&1 && command -v convert >/dev/null 2>&1; then
  IM_ID="identify"
  IM_CONV="convert"
else
  echo "❌ ImageMagick (magick/identify/convert) nicht gefunden." >&2; exit 1;
fi

# --- Eingaben sammeln ---
if [ "$#" -eq 0 ]; then
  set -- *.png *.PNG *.webp *.WEBP *.jpg *.JPG *.jpeg *.JPEG *.tif *.tiff
fi
found=0; for f in "$@"; do [ -f "$f" ] && { found=1; break; }; done
[ "$found" -eq 1 ] || { echo "⚠️  Keine Eingabedateien gefunden."; exit 0; }

processed=0; failed=0
maxW=0; maxH=0

echo "— Phase 1: Trim + Pad → $OUTDIR"

for IMG in "$@"; do
  [ -f "$IMG" ] || continue

  base="$(basename "$IMG")"
  name="${base%.*}"
  tmp="$OUTDIR/.tmp_${name}.png"
  out="$OUTDIR/${name}.png"

  # 1) trim transparente Ränder (robust, inkl. halbtransparenter Kanten)
  if ! $IM_CONV -quiet "$IMG" -alpha set -bordercolor none -border 1 \
       -fuzz 1% -trim +repage PNG32:"$tmp" 2>/dev/null; then
    echo "❌ Trim fehlgeschlagen: $IMG" >&2
    rm -f "$tmp" 2>/dev/null || true
    ((failed++)); continue
  fi

  WH=$($IM_ID -format "%w %h" "$tmp" 2>/dev/null || true)
  if [ -z "${WH:-}" ]; then
    echo "❌ Maße nach Trim unlesbar: $IMG" >&2
    rm -f "$tmp" 2>/dev/null || true
    ((failed++)); continue
  fi
  W=${WH%% *}; H=${WH##* }
  case "$W$H" in (*[!0-9]*)
    echo "❌ Ungültige Maße nach Trim: ${W}x${H} ($IMG)" >&2
    rm -f "$tmp"; ((failed++)); continue ;;
  esac

  # 2) auf 9:16 erweitern (unten bündig, kein Padding nach unten)
  if (( 9*H < 16*W )); then
    # zu breit → Höhe anheben (Padding oben)
    HNEED=$(( (W*16 + 8) / 9 ))   # ceil(W*16/9)
    if ! $IM_CONV -quiet "$tmp" -background none -gravity south -extent "${W}x${HNEED}" PNG32:"$out" 2>/dev/null; then
      echo "❌ Extent fehlgeschlagen (H): $IMG" >&2
      rm -f "$tmp"; ((failed++)); continue
    fi
    echo "✅ $IMG → $out  (trim ${W}x${H} → pad ${W}x${HNEED}, bottom-align)"
    Wfinal=$W; Hfinal=$HNEED
  elif (( 9*H > 16*W )); then
    # zu hoch/schmal → Breite anheben (Padding links/rechts; unten bleibt bündig)
    WNEED=$(( (H*9 + 15) / 16 ))  # ceil(H*9/16)
    if ! $IM_CONV -quiet "$tmp" -background none -gravity south -extent "${WNEED}x${H}" PNG32:"$out" 2>/dev/null; then
      echo "❌ Extent fehlgeschlagen (W): $IMG" >&2
      rm -f "$tmp"; ((failed++)); continue
    fi
    echo "✅ $IMG → $out  (trim ${W}x${H} → pad ${WNEED}x${H}, bottom-align)"
    Wfinal=$WNEED; Hfinal=$H
  else
    mv -f "$tmp" "$out"
    echo "ℹ️  $IMG → $out  (nach Trim bereits 9:16, kein Padding)"
    Wfinal=$W; Hfinal=$H
  fi

  rm -f "$tmp" 2>/dev/null || true
  # größtes Ergebnis tracken (wir nehmen die größte Breite als Referenz)
  if (( Wfinal > maxW )); then
    maxW=$Wfinal
    maxH=$(( (maxW*16 + 8) / 9 ))  # exakte 9:16-Höhe dazu
  fi

  ((processed++))
done

echo "— Phase 1 fertig: $processed verarbeitet, $failed fehlgeschlagen."
if (( processed == 0 )); then
  echo "⚠️  Keine erfolgreichen Ausgaben. Abbruch." >&2
  exit 1
fi

# --- Phase 2: alle auf gleiche Größe skalieren (größte Breite als Ziel) ---
echo "— Phase 2: Vereinheitlichen auf ${maxW}x${maxH} → $UNIFORM_DIR"
uniform_ok=0; uniform_fail=0
for out in "$OUTDIR"/*.png; do
  [ -f "$out" ] || continue
  name="$(basename "$out")"
  dest="$UNIFORM_DIR/$name"

  # Resize auf Zielbox (gleicher 9:16-Shape), dann Extent um +/-1px zu korrigieren
  if ! $IM_CONV -quiet "$out" -resize "${maxW}x${maxH}" \
        -background none -gravity south -extent "${maxW}x${maxH}" PNG32:"$dest" 2>/dev/null; then
    echo "❌ Vereinheitlichung fehlgeschlagen: $out" >&2
    ((uniform_fail++)); continue
  fi
  echo "🔧 $out → $dest (uniform ${maxW}x${maxH})"
  ((uniform_ok++))
done
echo "— Phase 2 fertig: $uniform_ok ok, $uniform_fail fehlgeschlagen."

# --- Phase 3: PNG-Optimierung (pngquant) ---
if command -v pngquant >/dev/null 2>&1; then
  echo "— Phase 3: PNG-Optimierung mit pngquant (lossy, 65–95)"
  # pngquant schreibt in-place mit neuem Alpha-Quantizer; skip-if-larger schützt Ausreißer
  pngquant --force --skip-if-larger --strip --quality=65-95 \
           --ext .png "$UNIFORM_DIR"/*.png 2>/dev/null || true
  echo "— Optimierung abgeschlossen."
else
  echo "ℹ️  pngquant nicht gefunden – Überspringe Optimierung."
fi

echo "✓ Fertig. Ergebnisordner:"
echo "   • Phase 1 (roh 9:16): $OUTDIR"
echo "   • Phase 2/3 (uniform & optimiert): $UNIFORM_DIR"
