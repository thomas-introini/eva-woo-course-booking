#!/bin/bash

# Eva Course Bookings - Build Script
# Creates a zip file ready for WordPress installation

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN_NAME="eva-course-bookings"
VERSION=$(grep -o "Version: [0-9.]*" "$SCRIPT_DIR/$PLUGIN_NAME/$PLUGIN_NAME.php" | cut -d' ' -f2)
OUTPUT_FILE="$SCRIPT_DIR/${PLUGIN_NAME}.zip"

echo "🔨 Building $PLUGIN_NAME v$VERSION..."

# Remove existing zip
if [ -f "$OUTPUT_FILE" ]; then
    rm "$OUTPUT_FILE"
    echo "   Removed existing zip file"
fi

# Create zip excluding unnecessary files
cd "$SCRIPT_DIR"

zip -r "$OUTPUT_FILE" "$PLUGIN_NAME" \
    -x "*.git*" \
    -x "*/.DS_Store" \
    -x "*/node_modules/*" \
    -x "*/vendor/*" \
    -x "*.log" \
    -x "*/tests/*" \
    -x "*/.phpunit*" \
    -x "*/phpunit.xml" \
    -x "*/.editorconfig" \
    -x "*/composer.lock" \
    -x "*/package-lock.json"

# Get file size
SIZE=$(ls -lh "$OUTPUT_FILE" | awk '{print $5}')

echo ""
echo "✅ Build complete!"
echo ""
echo "📦 Output: $OUTPUT_FILE"
echo "📊 Size: $SIZE"
echo ""
echo "To install:"
echo "1. Go to WordPress Admin → Plugins → Add New"
echo "2. Click 'Upload Plugin'"
echo "3. Select $OUTPUT_FILE"
echo "4. Click 'Install Now' and then 'Activate'"
echo ""

