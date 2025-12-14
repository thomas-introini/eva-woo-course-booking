#!/bin/bash

# Eva Course Bookings - View Logs

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"

cd "$PROJECT_DIR"

# Check if specific service is requested
SERVICE=${1:-""}

if [ -n "$SERVICE" ]; then
    echo "📋 Showing logs for $SERVICE..."
    docker compose logs -f "$SERVICE"
else
    echo "📋 Showing logs for all services (Ctrl+C to exit)..."
    docker compose logs -f
fi

