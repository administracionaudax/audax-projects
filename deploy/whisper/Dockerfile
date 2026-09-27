# Transcriptor de audios del chat (SPEC §12, Fase 6): whisper.cpp (MIT) compilado para x86-64
# BÁSICO. La VM del servidor expone una CPU KVM genérica solo con SSE2/SSE3 (sin SSSE3, SSE4, AVX,
# AVX2, FMA ni F16C): la imagen oficial, compilada con AVX2, fallaría con «instrucción ilegal».
# Se construye en GitHub Actions (.github/workflows/whisper-image.yml), se prueba con QEMU emulando
# esa CPU y se lleva al servidor con docker save/load. Nunca se compila en el servidor (gcc 8).
ARG DEBIAN=bookworm-slim

FROM debian:${DEBIAN} AS build
ARG WHISPER_VERSION=v1.9.4
ARG BLAS=OFF
RUN apt-get update \
 && apt-get install -y --no-install-recommends build-essential cmake git ca-certificates pkg-config libopenblas-dev \
 && rm -rf /var/lib/apt/lists/*
RUN git clone --depth 1 --branch "${WHISPER_VERSION}" https://github.com/ggml-org/whisper.cpp /src
WORKDIR /src
RUN cmake -B build -DCMAKE_BUILD_TYPE=Release -DBUILD_SHARED_LIBS=OFF \
      -DWHISPER_BUILD_TESTS=OFF -DWHISPER_BUILD_EXAMPLES=ON -DWHISPER_SDL2=OFF -DWHISPER_FFMPEG=OFF \
      -DGGML_NATIVE=OFF -DGGML_SSE42=OFF -DGGML_AVX=OFF -DGGML_AVX2=OFF -DGGML_AVX_VNNI=OFF \
      -DGGML_FMA=OFF -DGGML_F16C=OFF -DGGML_BMI2=OFF -DGGML_AVX512=OFF -DGGML_OPENMP=ON \
      -DGGML_BLAS="${BLAS}" -DGGML_BLAS_VENDOR=OpenBLAS \
      -DCMAKE_C_FLAGS="-march=x86-64 -mtune=generic" -DCMAKE_CXX_FLAGS="-march=x86-64 -mtune=generic" \
 && cmake --build build -j"$(nproc)" --config Release --target whisper-cli whisper-server

FROM debian:${DEBIAN} AS runtime
RUN apt-get update \
 && apt-get install -y --no-install-recommends ffmpeg libgomp1 libopenblas0-pthread ca-certificates \
 && rm -rf /var/lib/apt/lists/* \
 && useradd --system --uid 10091 --home-dir /nonexistent --shell /usr/sbin/nologin whisper
COPY --from=build /src/build/bin/whisper-cli /src/build/bin/whisper-server /usr/local/bin/
USER whisper
EXPOSE 8080
ENTRYPOINT ["whisper-server"]

# Prueba en la CI: los binarios corren en una CPU sin SSSE3/SSE4/AVX (QEMU, modelo qemu64) y
# transcriben el ejemplo del repositorio con el modelo tiny.
FROM runtime AS test
USER root
RUN apt-get update && apt-get install -y --no-install-recommends qemu-user curl && rm -rf /var/lib/apt/lists/*
COPY --from=build /src/samples/jfk.wav /tmp/jfk.wav
ARG TINY_URL=https://huggingface.co/ggerganov/whisper.cpp/resolve/main/ggml-tiny.bin
RUN curl -fsSL -o /tmp/tiny.bin "${TINY_URL}" \
 && qemu-x86_64 -cpu qemu64,-ssse3,-sse4.1,-sse4.2,-popcnt,-avx,-avx2 /usr/local/bin/whisper-cli \
      -m /tmp/tiny.bin -f /tmp/jfk.wav -l en -t 2 -nt 2>/dev/null | tee /tmp/out.txt \
 && grep -qi "country" /tmp/out.txt \
 && ffmpeg -hide_banner -loglevel error -f lavfi -i "sine=frequency=440:duration=2" -c:a libopus /tmp/tone.webm \
 && ffmpeg -hide_banner -loglevel error -i /tmp/tone.webm -ar 16000 -ac 1 -c:a pcm_s16le /tmp/tone.wav \
 && echo "OK: whisper.cpp sin AVX y ffmpeg con opus/webm"
