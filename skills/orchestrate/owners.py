#!/usr/bin/env python3
"""Name the live sessions that own a worktree.

Usage: claude agents --json --all | owners.py <worktree> [--projects-dir DIR] [--session-id ID]

A session owns a worktree when its transcript, or one of its subagents' transcripts, has entries
whose cwd lies inside it. Finished sessions and the calling session are skipped. Grepping for the
path or the branch is not enough: slot directories are recycled, and every session that mapped a
worktree mentions it.

Prints one line per owner: name, id, state, matching entries (tab-separated).
No output means no live session owns it: the work is orphaned.
"""
import argparse
import glob
import json
import os
import sys


def inside(cwd, worktree):
    return cwd == worktree or cwd.startswith(worktree + "/")


def transcripts(projects_dir, session_id):
    return glob.glob(os.path.join(projects_dir, "*", session_id + ".jsonl")) + glob.glob(
        os.path.join(projects_dir, "*", session_id, "subagents", "*.jsonl")
    )


def entries_inside(paths, worktree):
    count = 0
    for path in paths:
        with open(path, errors="ignore") as transcript:
            for line in transcript:
                try:
                    cwd = json.loads(line).get("cwd")
                except (json.JSONDecodeError, AttributeError):
                    continue  # a line still being written, or not an entry
                if cwd and inside(cwd, worktree):
                    count += 1
    return count


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("worktree", help="absolute path of the worktree")
    parser.add_argument("--projects-dir", default=os.path.expanduser("~/.claude/projects"))
    parser.add_argument("--session-id", default=os.environ.get("CLAUDE_CODE_SESSION_ID", ""))
    args = parser.parse_args()
    worktree = os.path.abspath(args.worktree).rstrip("/")

    for session in json.load(sys.stdin):
        session_id = session.get("sessionId")
        if not session_id or session_id == args.session_id or session.get("state") == "done":
            continue
        count = entries_inside(transcripts(args.projects_dir, session_id), worktree)
        if count:
            print(f"{session.get('name', '')}\t{session.get('id', '')}\t{session.get('state', '')}\t{count}")


if __name__ == "__main__":
    main()
