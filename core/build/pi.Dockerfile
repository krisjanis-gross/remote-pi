# Build image for the Raspberry Pi binary: ARMv6 with hardware floating point, which
# runs on every Pi model with the 32-bit OS (Pi 1 and Zero included). The cross-rs image
# has a crosstool-ng toolchain built for ARMv6 throughout (Debian's armhf cross-compiler
# would mix in ARMv7 code); Rust is added on top. pi.sh builds it for linux/amd64.
FROM ghcr.io/cross-rs/arm-unknown-linux-gnueabihf:0.2.5@sha256:2e3ba7a2284844975124febea982bda2586ae4910ef51294729284ff31b0aec5
ENV RUSTUP_HOME=/opt/rustup CARGO_HOME=/opt/cargo PATH=/opt/cargo/bin:$PATH
RUN curl -sSf https://sh.rustup.rs | sh -s -- -y --no-modify-path --profile minimal \
        --default-toolchain 1.90.0 --target arm-unknown-linux-gnueabihf \
    && chmod -R a+rwX /opt/cargo /opt/rustup
