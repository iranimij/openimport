#!/usr/bin/env bash
# Runs a command, keeps its output in the job log, and on failure repeats the last lines as one error annotation:
# annotations are readable through the GitHub REST API (check-runs/{id}/annotations), raw job logs are not always.
# Usage: .github/ci/logged.sh <title> <command> [args...]
set -uo pipefail
title="$1"; shift
log="$(mktemp)"
"$@" 2>&1 | tee "$log"
status="${PIPESTATUS[0]}"
if [ "$status" -ne 0 ]; then
  # Annotations are cut at about 4 KB: drop known noise and colours, keep the last 3,500 characters, encode.
  sed -E 's/\x1B\[[0-9;]*[A-Za-z]//g' "$log" \
    | grep -vE 'recognized as part of Magento Multi Source Inventory|No special rule applied for|^\s*$' \
    | tail -n 60 | tail -c 3500 \
    | awk 'BEGIN { ORS = "" } { gsub(/%/, "%25"); gsub(/\r/, "%0D"); print $0 "%0A" }' > "$log.tail"
  echo "::error title=${title} (exit ${status})::$(cat "$log.tail")"
  # PHPUnit: one more annotation per failed test (its own 1,500 characters, long lines cut), so a failure with a big
  # message (an HTML page) does not push the others out of the tail.
  if command -v php >/dev/null; then
    php -r '
      $text = preg_replace("/\x1B\[[0-9;]*[A-Za-z]/", "", file_get_contents($argv[1]));
      if (!preg_match_all("/^\d+\) [^\n]+\n.*?(?=^\d+\) |^(FAILURES|ERRORS|OK, but)|\z)/ms", $text, $m)) { exit(0); }
      foreach (array_slice($m[0], 0, 10) as $block) {
        $lines = array_map(static fn ($l) => strlen($l) > 300 ? substr($l, 0, 300) . " …" : $l, explode("\n", trim($block)));
        // Head and tail of the block: the test name and message first, the "Failed asserting" lines and file last.
        if (count($lines) > 14) { $lines = array_merge(array_slice($lines, 0, 4), ["…"], array_slice($lines, -10)); }
        $body = substr(implode("\n", $lines), 0, 2000);
        echo "::error title=" . str_replace(["%", ":", ","], ["%25", "%3A", "%2C"], substr(strtok($block, "\n"), 0, 120)) . "::" . str_replace(["%", "\r", "\n"], ["%25", "%0D", "%0A"], $body) . "\n";
      }' "$log"
  fi
fi
exit "$status"
