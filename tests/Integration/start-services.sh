#!/usr/bin/env bash
# Starts Qdrant, pgvector and OpenSearch for the integration tests and waits until each answers.
set -euo pipefail
cd "$(dirname "$0")"

docker compose up -d

wait_for() {
    local name=$1 check=$2
    for _ in $(seq 1 120); do
        if eval "$check" >/dev/null 2>&1; then
            echo "$name is ready."
            return 0
        fi
        sleep 1
    done
    echo "$name did not start." >&2
    docker compose logs "$name" | tail -30 >&2
    exit 1
}

wait_for qdrant "curl -sf -H 'api-key: kipchak-qdrant-key' http://127.0.0.1:56333/collections"
wait_for pgvector "docker compose exec -T pgvector pg_isready -U kipchak -d vectors"
wait_for opensearch "curl -sf 'http://127.0.0.1:59200/_cluster/health?wait_for_status=yellow&timeout=1s'"
