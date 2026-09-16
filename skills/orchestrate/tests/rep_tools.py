#!/usr/bin/env python3
"""List a scenario rep's tool calls and say whether the rep is void.

Usage: rep_tools.py <output_file>

A rep may only Read files under /tmp/cc-7f3a/ and make the one SubagentHandback every subagent
uses to report. Anything else makes it void. Prints the calls, then OK or VOID (exit 1).
Never prints the rep's message text.
"""
import json
import os
import sys

ALLOWED_ROOT = "/tmp/cc-7f3a/"


def tool_calls(path):
    with open(path, errors="ignore") as transcript:
        for line in transcript:
            try:
                entry = json.loads(line)
            except json.JSONDecodeError:
                continue
            message = entry.get("message") if isinstance(entry, dict) else None
            content = message.get("content") if isinstance(message, dict) else None
            for block in content if isinstance(content, list) else []:
                if isinstance(block, dict) and block.get("type") == "tool_use":
                    yield block.get("name"), block.get("input") or {}


def describe(name, arguments):
    return f"{name}({str(arguments.get('file_path') or arguments.get('command') or '')[:90]})"


def allowed(name, arguments, handbacks):
    if name == "Read":
        return os.path.normpath(str(arguments.get("file_path", ""))).startswith(ALLOWED_ROOT)
    return name == "SubagentHandback" and handbacks == 1


def main():
    calls = list(tool_calls(sys.argv[1]))
    handbacks = sum(1 for name, _ in calls if name == "SubagentHandback")
    offending = [describe(name, arguments) for name, arguments in calls if not allowed(name, arguments, handbacks)]
    for name, arguments in calls:
        print("  " + describe(name, arguments))
    print("VOID: " + ", ".join(offending) if offending else "OK")
    sys.exit(1 if offending else 0)


if __name__ == "__main__":
    main()
