#!/bin/bash
# Convert all HEIC files to JPEG (1440px max width)

convert_heic() {
    find content -type f \( -iname "*.heic" \) | while read f; do
        echo "Converting: $f"
        sips -s format jpeg -Z 1440 "$f" --out "${f%.*}.jpg" && rm "$f"
    done
}

# Initial conversion
convert_heic

# Watch mode if --watch flag is passed
if [ "$1" = "--watch" ]; then
    echo "Watching for HEIC files..."
    fswatch -0 content | while read -d "" event; do
        if [[ "$event" =~ \.[hH][eE][iI][cC]$ ]]; then
            convert_heic
        fi
    done
fi
