#!/bin/sh

PLUGIN_PATH="${1:-./custom/plugins/MltisafeMultiSafepay/}"

output="$(shopware-cli extension validate "$PLUGIN_PATH" --full --only phpstan --reporter summary 2>&1 || true)"

issues="$(printf '%s\n' "$output" | awk '
  /^[^[:space:]]+\.(php|xml|ya?ml|twig|json|js|css|scss|html)$/ { file=$0; printed=0; next }
  /shopware\.|parameter\.implicitlyNullable/ {
    if (!printed) {
      sep="------ -----------------------------------------------------------------------";
      print "";
      print sep;
      print "  Line   " file;
      print sep;
      printed=1;
    }
    print
  }
')"

if [ -n "$issues" ]; then
  count="$(printf '%s\n' "$output" | grep -cE 'shopware\.|parameter\.implicitlyNullable')"
  echo "Plugin validation found issues:"
  echo "$issues"
  echo ""
  echo "[ERROR] Found $count errors"
  exit 1
fi

echo "Plugin validation passed: no issues found."
echo ""
echo "[OK] Found 0 errors"
