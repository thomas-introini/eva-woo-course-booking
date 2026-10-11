#!/bin/bash

# Eva Course Bookings - Reset Development Environment
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(dirname "$SCRIPT_DIR")"

cd "$PROJECT_DIR"

echo "⚠️  This will DELETE all WordPress data and database!"
echo ""
read -p "Are you sure? (y/N) " -n 1 -r
echo ""

if [[ ! $REPLY =~ ^[Yy]$ ]]; then
    echo "Cancelled."
    exit 1
fi

echo ""
echo "🗑️  Removing containers and volumes..."

docker compose down -v --remove-orphans

echo ""
echo "✅ Environment reset complete"
echo ""
echo "Run './bin/up.sh' to start fresh."
echo ""

