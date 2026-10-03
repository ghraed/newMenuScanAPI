FROM ubuntu:24.04

WORKDIR /opt/runpod-worker

ENV DEBIAN_FRONTEND=noninteractive
ENV TZ=UTC
ENV PATH="/opt/rembg-env/bin:/opt/usd-tools-env/bin:$PATH"
ENV PYTHONDONTWRITEBYTECODE=1
ENV PYTHONUNBUFFERED=1

RUN ln -snf /usr/share/zoneinfo/$TZ /etc/localtime && echo $TZ > /etc/timezone

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        git \
        unzip \
        wget \
        python3 \
        python3-pip \
        python3-venv \
        blender \
        imagemagick \
        libgl1 \
        libglib2.0-0 \
    && python3 -m venv /opt/rembg-env \
    && python3 -m venv /opt/usd-tools-env \
    && /opt/rembg-env/bin/pip install --no-cache-dir --upgrade pip \
    && /opt/usd-tools-env/bin/pip install --no-cache-dir --upgrade pip \
    && /opt/rembg-env/bin/pip install --no-cache-dir "rembg[gpu,cli]" \
    && /opt/usd-tools-env/bin/pip install --no-cache-dir "usd-core==26.5" \
    && mkdir -p /opt/meshroom \
    && wget -O /tmp/Meshroom-2023.3.0-linux.tar.gz "https://github.com/alicevision/Meshroom/releases/download/v2023.3.0/Meshroom-2023.3.0-linux.tar.gz" \
    && tar --no-same-owner -xzf /tmp/Meshroom-2023.3.0-linux.tar.gz -C /opt/meshroom --strip-components=1 \
    && chmod +x /opt/meshroom/meshroom_batch \
    && ln -sf /opt/meshroom/meshroom_batch /usr/local/bin/meshroom_batch \
    && rm -f /tmp/Meshroom-2023.3.0-linux.tar.gz \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY --chown=0:0 worker/requirements.txt /opt/runpod-worker/requirements.txt
RUN python3 -m pip install --no-cache-dir --break-system-packages -r /opt/runpod-worker/requirements.txt

COPY --chown=0:0 scripts /opt/runpod-worker/scripts
COPY --chown=0:0 worker /opt/runpod-worker/worker

RUN chown -R 0:0 /opt/runpod-worker \
    && chmod +x /opt/runpod-worker/worker/start-worker.sh

EXPOSE 3000

ENTRYPOINT ["/opt/runpod-worker/worker/start-worker.sh"]
