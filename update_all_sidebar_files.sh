#!/bin/bash

# List of files to update
files=(
    "ai.php"
    "audit_log.php"
    "aziende.php"
    "calendar.php"
    "chat.php"
    "conformita.php"
    "configurazioni.php"
    "profilo.php"
    "tasks.php"
    "ticket.php"
    "utenti.php"
)

# Function to update a file
update_file() {
    local file="$1"
    local file_path="/mnt/c/xampp/htdocs/CollaboraNexio/$file"

    if [ ! -f "$file_path" ]; then
        echo "❌ File not found: $file"
        return 1
    fi

    # Check if already updated
    if grep -q "sidebar-responsive.css" "$file_path"; then
        echo "✓ Already updated: $file"
        return 0
    fi

    # Check if file contains styles.css link
    if ! grep -q 'href="assets/css/styles.css"' "$file_path"; then
        echo "⚠️  No styles.css link found in: $file"
        return 1
    fi

    # Create backup
    cp "$file_path" "${file_path}.bak.$(date +%Y%m%d-%H%M%S)"

    # Add the sidebar-responsive.css after styles.css
    sed -i '/href="assets\/css\/styles.css"/a\    <!-- Sidebar Responsive Optimization CSS -->\n    <link rel="stylesheet" href="assets/css/sidebar-responsive.css">' "$file_path"

    if [ $? -eq 0 ]; then
        echo "✅ Updated: $file"
        return 0
    else
        echo "❌ Failed to update: $file"
        return 1
    fi
}

# Counter variables
updated=0
already_updated=0
errors=0

echo "========================================="
echo "Updating PHP files with sidebar CSS..."
echo "========================================="

# Process each file
for file in "${files[@]}"; do
    update_file "$file"
    case $? in
        0)
            if grep -q "Already updated" <<< "$(update_file "$file" 2>&1)"; then
                ((already_updated++))
            else
                ((updated++))
            fi
            ;;
        *)
            ((errors++))
            ;;
    esac
done

echo ""
echo "========================================="
echo "Update Summary:"
echo "========================================="
echo "✅ Updated: $updated files"
echo "✓  Already updated: $already_updated files"
echo "❌ Errors: $errors files"
echo "========================================="
echo "Total processed: ${#files[@]} files"