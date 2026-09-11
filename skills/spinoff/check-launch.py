#!/usr/bin/env python3
"""Report whether a `claude --bg` session really started working.

Usage: check-launch.py <id> [--wait SECONDS]

`claude --bg` prints "backgrounded · <id>" even for a session that dies at
startup, and `claude agents` prints nothing but an error without a TTY. This
reads `claude agents --json --all` and the session transcript instead, and
polls until the session has made a tool call, stopped, or asked for input.

Exit codes:
  0  working or done, with tool calls
  1  failed, or no such session
  2  blocked on the user (permission prompt or question): `claude attach <id>`
  3  still no tool calls when the wait ran out
"""
import argparse
import glob
import json
import os
import subprocess
import sys
import time
from collections import Counter

POLL_SECONDS = 5


def find_session(short_id):
    listing = subprocess.run(
        ["claude", "agents", "--json", "--all"],
        capture_output=True, text=True, check=True,
    ).stdout
    return next((s for s in json.loads(listing) if s["id"] == short_id), None)


def transcript_path(session_id):
    # The transcript moves to the worktree's project folder once the session
    # enters a worktree, so look it up by session id rather than by cwd.
    matches = glob.glob(os.path.expanduser(f"~/.claude/projects/*/{session_id}.jsonl"))
    return matches[0] if matches else None


def activity(path):
    tools, last_text = Counter(), ""
    if path is None:
        return tools, last_text
    with open(path) as transcript:
        for line in transcript:
            try:
                entry = json.loads(line)
            except json.JSONDecodeError:
                continue  # a line still being written
            if entry.get("type") != "assistant":
                continue
            for block in entry["message"].get("content", []):
                if block.get("type") == "tool_use":
                    tools[block["name"]] += 1
                elif block.get("type") == "text" and block["text"].strip():
                    last_text = block["text"].strip()
    return tools, last_text


def report(session, tools, last_text):
    status = f" ({session['status']})" if session.get("status") else ""
    print(f"{session['id']} \"{session.get('name', '')}\": {session.get('state', 'unknown')}{status}")
    print(f"cwd: {session.get('cwd')}")
    breakdown = ", ".join(f"{name} {count}" for name, count in tools.most_common())
    print(f"tool calls: {sum(tools.values())}" + (f" ({breakdown})" if breakdown else ""))
    if session.get("waitingFor"):
        print(f"waiting for: {session['waitingFor']}")
    if last_text:
        print(f"last message: {last_text[:400]}")


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("id", help="the short id `claude --bg` printed")
    parser.add_argument("--wait", type=int, default=60, help="seconds to wait for a first tool call (default 60)")
    args = parser.parse_args()

    deadline = time.monotonic() + args.wait
    while True:
        session = find_session(args.id)
        if session is None:
            print(f"{args.id}: no such session in `claude agents --json --all`")
            return 1

        tools, last_text = activity(transcript_path(session["sessionId"]))
        state = session.get("state")
        settled = state in ("failed", "blocked") or (tools and state in ("working", "done"))

        if settled or time.monotonic() >= deadline:
            report(session, tools, last_text)
            if state == "failed":
                return 1
            if state == "blocked":
                return 2
            return 0 if tools else 3

        time.sleep(POLL_SECONDS)


if __name__ == "__main__":
    sys.exit(main())
