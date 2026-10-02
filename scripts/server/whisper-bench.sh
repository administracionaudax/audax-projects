#!/bin/bash
# Medición de los modelos de whisper.cpp en el servidor real (SPEC §12, RUNBOOK A2). Como root,
# lanzado con systemd-run (Nice 19, E/S idle). Cada ejecución, en un contenedor efímero con los
# límites previstos para audax-whisper: 2 CPU fijadas a los núcleos 6-7, cpu-shares 64, memoria
# acotada y sin swap. Aborta si el servidor tiene mucha carga o poca memoria antes de cada prueba.
# Uso: whisper-bench.sh <dir de trabajo con es60.wav> <etiqueta de imagen> [modelos...]
set -euo pipefail
W=${1:?Falta el directorio de trabajo}
IMAGE_BASE=${2:?Falta la imagen (sin variante)}
shift 2
MODELS=("${@:-tiny base small}")
MODELS_DIR=/var/lib/audax/whisper/models
OUT=$W/results.tsv

guard() {
  local load mem
  load=$(cut -d' ' -f1 /proc/loadavg)
  mem=$(awk '/MemAvailable/ {print int($2/1024)}' /proc/meminfo)
  if awk -v l="$load" 'BEGIN{exit !(l > 7.0)}' || [ "$mem" -lt 4096 ]; then
    echo "ABORTADO: carga $load o memoria disponible ${mem} MB" | tee -a "$W/bench.log"
    exit 3
  fi
}

run() { # variante modelo memoria fichero
  local variant=$1 model=$2 memory=$3 file=$4 name start end secs peak
  name=$(basename "$file" .wav)
  guard
  start=$(date +%s.%N)
  peak=$(docker run --rm --name "audax-whisper-bench" \
      --cpus 2 --cpuset-cpus 6,7 --cpu-shares 64 --memory "$memory" --memory-swap "$memory" \
      --memory-swappiness 0 --oom-score-adj 1000 --network none --read-only --tmpfs /tmp \
      -v "$MODELS_DIR:/models:ro" -v "$W:/work" --entrypoint bash "$IMAGE_BASE-$variant" -c \
      "whisper-cli -m /models/ggml-$model.bin -f /work/$name.wav -l es -t 2 -np -nt -otxt -of /work/out-$variant-$model-$name >/dev/null 2>/work/err-$variant-$model-$name.log; \
       cat /sys/fs/cgroup/memory/memory.max_usage_in_bytes 2>/dev/null || cat /sys/fs/cgroup/memory.peak")
  end=$(date +%s.%N)
  secs=$(awk -v a="$start" -v b="$end" 'BEGIN{printf "%.1f", b-a}')
  printf '%s\t%s\t%s\t%s\t%d\n' "$variant" "$model" "$name" "$secs" "$((peak / 1024 / 1024))" | tee -a "$OUT"
}

echo -e "variante\tmodelo\taudio\tsegundos\tpico_MB" > "$OUT"
for model in ${MODELS[@]}; do
  memory=1536m
  [ "$model" = medium ] && memory=3g
  for variant in sse2 sse2-openblas; do
    run "$variant" "$model" "$memory" "$W/es60.wav"
  done
  run sse2-openblas "$model" "$memory" "$W/es60-ruido.wav"
done
echo "FIN $(date '+%F %T')" | tee -a "$W/bench.log"
