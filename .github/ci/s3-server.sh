#!/usr/bin/env bash
# Starts an S3-compatible server on localhost:9000 for the S3 connection tests and exports OPENIMPORT_TEST_S3
# ("endpoint|access key|secret key"). MinIO publishes no new images since late 2025, so the last known images are
# tried first, then other S3 servers that check Signature V4. Without any, the S3 tests are skipped (with a warning).
set -u
key=openimport
secret=openimport-s3-secret

start_minio() {
  docker run -d --name s3 -p 9000:9000 -e MINIO_ROOT_USER=$key -e MINIO_ROOT_PASSWORD=$secret "$1" server /data
}
start_rustfs() {
  docker run -d --name s3 -p 9000:9000 -e RUSTFS_ACCESS_KEY=$key -e RUSTFS_SECRET_KEY=$secret "$1" /data
}
start_cloudserver() {
  docker run -d --name s3 -p 9000:8000 -e REMOTE_MANAGEMENT_DISABLE=1 -e S3BACKEND=mem \
    -e SCALITY_ACCESS_KEY_ID=$key -e SCALITY_SECRET_ACCESS_KEY=$secret "$1"
}

for candidate in \
  "minio minio/minio:RELEASE.2025-04-22T22-12-26Z" \
  "minio quay.io/minio/minio:RELEASE.2025-04-22T22-12-26Z" \
  "minio minio/minio:latest" \
  "minio quay.io/minio/minio:latest" \
  "rustfs rustfs/rustfs:latest" \
  "cloudserver zenko/cloudserver:latest"; do
  kind=${candidate%% *}
  image=${candidate#* }
  if ! docker pull -q "$image" >/dev/null 2>&1; then
    echo "::warning::S3 test server: $image cannot be pulled"
    continue
  fi
  docker rm -f s3 >/dev/null 2>&1
  "start_$kind" "$image" >/dev/null || { echo "::warning::S3 test server: $image does not start"; continue; }
  for i in $(seq 1 30); do
    code=$(curl -s -o /dev/null -w '%{http_code}' http://localhost:9000/ || true)
    [ "$code" != "000" ] && break
    sleep 2
  done
  if [ "${code:-000}" = "000" ]; then
    echo "::warning::S3 test server: $image does not answer"
    docker logs s3 2>&1 | tail -5
    continue
  fi
  echo "::notice::S3 test server: $image (HTTP $code without a signature)"
  echo "OPENIMPORT_TEST_S3=http://127.0.0.1:9000|$key|$secret" >> "$GITHUB_ENV"
  exit 0
done
echo "::warning::No S3 test server could be started; the S3 tests are skipped."
