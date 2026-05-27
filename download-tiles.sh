#!/bin/bash
# Descargar tiles de un mapa desde recoil.org preservando la estructura LODS
# Uso: ./download-tiles.sh everon-d012

MAP=${1:-everon-d012}
BASE="https://reforger.recoil.org/${MAP}/LODS"
OUTDIR="tiles/${MAP}/LODS"

# Niveles de zoom (LODs) y cuadrantes
for Z in 0 1 2 3 4 5; do
  MAX=$(( (1 << Z) - 1 ))
  for X in $(seq 0 $MAX); do
    for Y in $(seq 0 $MAX); do
      URL="${BASE}/${Z}/${X}/${Y}/tile.jpg"
      DIR="${OUTDIR}/${Z}/${X}/${Y}"
      mkdir -p "$DIR"
      if [ ! -f "${DIR}/tile.jpg" ]; then
        HTTP=$(curl -s -o "${DIR}/tile.jpg" -w "%{http_code}" "$URL")
        if [ "$HTTP" = "200" ]; then
          echo "OK  $Z/$X/$Y"
        fi
      fi
    done
  done
done
echo "Descarga completada en $OUTDIR"
