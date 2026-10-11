#!/bin/bash

# Eva Course Bookings - Stop Development Environment
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"

cd "$PROJECT_DIR"

echo "🛑 Stopping Eva Course Bookings development environment..."

docker compose down

echo ""
echo "✅ Environment stopped"
echo ""
echo "💡 Data is preserved in Docker volumes."
echo "   Run './bin/up.sh' to start again."
echo "   Run './bin/reset.sh' to completely reset."
echo ""

