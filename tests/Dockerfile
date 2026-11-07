# PHP 8.4 on Debian Trixie: the versions Raspberry Pi OS Trixie ships.
FROM php:8.4-cli-trixie@sha256:7e7413ed31b8c42b31c939cc83b3758b1cd618f7bf3803f5a0c39f98d4adcbee
RUN apt-get update && apt-get install -y --no-install-recommends python3 python3-jsonschema openssl ca-certificates shellcheck && rm -rf /var/lib/apt/lists/*
ENV PYTHONDONTWRITEBYTECODE=1
WORKDIR /app
CMD ["python3", "tests/run_tests.py"]
