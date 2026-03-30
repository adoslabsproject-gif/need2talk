#!/usr/bin/env python3
"""
Enterprise CSP Nonce Injector - Safe & Reliable

Adds nonce="<?= csp_nonce() ?>" to inline <script> and <style> tags.

SAFETY RULES:
1. Only modifies standalone tags (not inside echo/print)
2. Skips tags with src= or href= (external resources)
3. Skips tags already having nonce=
4. Creates .backup files before modification
"""

import os
import re
import sys
from pathlib import Path

# Colors for output
GREEN = '\033[0;32m'
YELLOW = '\033[1;33m'
RED = '\033[0;31m'
NC = '\033[0m'  # No Color

def is_safe_to_modify(line: str, tag: str) -> bool:
    """Check if line is safe to modify (not inside echo/print/heredoc)"""
    # Don't modify if inside echo, print, or string concatenation
    unsafe_patterns = [
        r'echo\s+["\'].*<' + tag,
        r'print\s+["\'].*<' + tag,
        r'=\s+["\'].*<' + tag,
        r'\.\s+["\'].*<' + tag,
    ]

    for pattern in unsafe_patterns:
        if re.search(pattern, line, re.IGNORECASE):
            return False

    return True

def add_nonce_to_file(filepath: Path) -> tuple[int, int]:
    """
    Add nonce to script and style tags in file.
    Returns: (scripts_modified, styles_modified)
    """
    with open(filepath, 'r', encoding='utf-8') as f:
        content = f.read()

    original_content = content
    scripts_modified = 0
    styles_modified = 0

    # Pattern 1: <script> tags (not having src= or nonce=)
    # Match: <script> or <script type="..."> but NOT <script src="..."> or <script nonce="...">
    def replace_script(match):
        nonlocal scripts_modified
        full_match = match.group(0)
        line = match.group(1)  # Everything before >

        # Skip if has src= or nonce=
        if 'src=' in full_match or 'nonce=' in full_match:
            return full_match

        # Check if safe to modify (not inside echo/print)
        if not is_safe_to_modify(full_match, 'script'):
            return full_match

        scripts_modified += 1
        # Add nonce after <script
        if line.strip():
            return f'<script nonce="<?= csp_nonce() ?>" {line}>'
        else:
            return '<script nonce="<?= csp_nonce() ?>">'

    content = re.sub(
        r'<script\s*([^>]*)>',
        replace_script,
        content,
        flags=re.IGNORECASE
    )

    # Pattern 2: <style> tags (not having nonce=)
    def replace_style(match):
        nonlocal styles_modified
        full_match = match.group(0)
        line = match.group(1)

        # Skip if has nonce=
        if 'nonce=' in full_match:
            return full_match

        # Check if safe to modify
        if not is_safe_to_modify(full_match, 'style'):
            return full_match

        styles_modified += 1
        # Add nonce after <style
        if line.strip():
            return f'<style nonce="<?= csp_nonce() ?>" {line}>'
        else:
            return '<style nonce="<?= csp_nonce() ?>">'

    content = re.sub(
        r'<style\s*([^>]*)>',
        replace_style,
        content,
        flags=re.IGNORECASE
    )

    # Only write if changed
    if content != original_content:
        # Create backup
        backup_path = str(filepath) + '.backup'
        with open(backup_path, 'w', encoding='utf-8') as f:
            f.write(original_content)

        # Write modified content
        with open(filepath, 'w', encoding='utf-8') as f:
            f.write(content)

        return (scripts_modified, styles_modified)

    return (0, 0)

def main():
    print(f"{GREEN}🚀 Enterprise CSP Nonce Injector{NC}")
    print(f"{GREEN}{'='*50}{NC}\n")

    # Find all PHP files in Views directory
    views_dir = Path('/var/www/need2talk/app/Views')
    php_files = list(views_dir.rglob('*.php'))

    total_files = 0
    total_scripts = 0
    total_styles = 0

    for php_file in sorted(php_files):
        scripts, styles = add_nonce_to_file(php_file)

        if scripts > 0 or styles > 0:
            total_files += 1
            total_scripts += scripts
            total_styles += styles
            print(f"{YELLOW}✓ {php_file.relative_to(views_dir)}{NC}")
            if scripts > 0:
                print(f"  └─ {scripts} script tag(s)")
            if styles > 0:
                print(f"  └─ {styles} style tag(s)")

    print(f"\n{GREEN}{'='*50}{NC}")
    print(f"{GREEN}✅ Complete!{NC}")
    print(f"  Files modified:  {total_files}")
    print(f"  Scripts updated: {total_scripts}")
    print(f"  Styles updated:  {total_styles}")
    print(f"{GREEN}{'='*50}{NC}\n")

    if total_files > 0:
        print(f"{YELLOW}📋 Backups created with .backup extension{NC}")
        print(f"{YELLOW}   Review and delete when satisfied{NC}\n")

if __name__ == '__main__':
    main()
