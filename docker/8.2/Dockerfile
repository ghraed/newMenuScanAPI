FROM laravelsail/php82-composer:latest

USER root

ENV DEBIAN_FRONTEND=noninteractive
ENV PATH="/opt/rembg-env/bin:$PATH"

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
    python3 \
    python3-pip \
    python3-venv \
    blender \
    imagemagick \
    wget \
    unzip \
    libgl1 \
    libglib2.0-0 \
    && python3 -m venv /opt/rembg-env \
    && /opt/rembg-env/bin/pip install --no-cache-dir --upgrade pip \
    && /opt/rembg-env/bin/pip install --no-cache-dir rembg \
    && mkdir -p /opt/meshroom \
    && curl -L --retry 5 --retry-delay 5 -o /tmp/Meshroom-2023.3.0-linux.tar.gz "https://downloads.sourceforge.net/project/meshroom/v2023.3.0/Meshroom-2023.3.0-linux.tar.gz" \
    && tar -xzf /tmp/Meshroom-2023.3.0-linux.tar.gz -C /opt/meshroom --strip-components=1 \
    && chmod +x /opt/meshroom/meshroom_batch \
    && ln -sf /opt/meshroom/meshroom_batch /usr/local/bin/meshroom_batch \
    && rm -f /tmp/Meshroom-2023.3.0-linux.tar.gz \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

