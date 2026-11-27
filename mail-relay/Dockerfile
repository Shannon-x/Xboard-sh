# xboard 邮件投递服务。构建：docker build -t xboard-mail-relay mail-relay/
FROM rust:1-slim-bookworm AS build
WORKDIR /src
# 先只拷依赖清单把依赖编好，改源码时这一层能复用
COPY Cargo.toml Cargo.lock ./
RUN mkdir src && echo 'fn main() {}' > src/main.rs && cargo build --release --locked \
    && rm -rf src target/release/xboard-mail-relay target/release/deps/xboard_mail_relay* target/release/.fingerprint/xboard-mail-relay-*
COPY src ./src
# COPY 保留的是仓库里的文件时间，可能比上面那个空 main 还旧，cargo 会以为不用重编、把空程序打进镜像（启动即退出、没有任何输出）。
# 所以上面删掉了空程序的编译记录，这里再 touch 一下源码，确保一定按真源码重编
RUN touch src/main.rs && cargo build --release --locked && strip target/release/xboard-mail-relay

FROM debian:bookworm-slim
# rustls 用系统 CA 校验 SMTP 证书
RUN apt-get update && apt-get install -y --no-install-recommends ca-certificates curl && rm -rf /var/lib/apt/lists/*
COPY --from=build /src/target/release/xboard-mail-relay /usr/local/bin/xboard-mail-relay
ENV RELAY_HTTP_ADDR=0.0.0.0:9810 RELAY_LOG=info
EXPOSE 9810
HEALTHCHECK --interval=30s --timeout=5s --start-period=10s CMD curl -fsS http://127.0.0.1:9810/healthz || exit 1
# 停机：收到 SIGTERM 后不再取新信、正在发的发完（最多 3 个 SMTP 超时）。compose 里 stop_grace_period 要比这个长
STOPSIGNAL SIGTERM
ENTRYPOINT ["/usr/local/bin/xboard-mail-relay"]
