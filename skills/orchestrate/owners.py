#!/usr/bin/env python3
"""Name the live sessions that own a worktree.

Usage: claude agents --json --all | owners.py <worktree> [--projects-dir DIR] [--session-id ID]

A session owns a worktree when its state is working or blocked (the live states `claude agents`
reports) and its transcript, or one of its subagents' transcripts, has entries whose cwd lies inside
it. Every other state (done, failed, stopped, …) and the calling session are skipped. Grepping for
the path or the branch is not enough: slot directories are recycled, and every session that mapped a
worktree mentions it.

Prints one line per owner: name, id, state, matching entries (tab-separated).
No output and exit 0 means no live session owns it: the work is orphaned.

Fails closed: when a live session has no transcript, or its transcripts hold no cwd entries at all,
the lookup cannot tell whether that session owns the worktree (the transcript layout or format has
changed, or the session has not written its first entry yet). When that session's own cwd, from its
`claude agents` row, is inside the worktree, contains it (the primary checkout, ~), lies in the same
git repository (a slot's primary checkout), or is missing, it prints nothing, names the sessions on
stderr and exits 2. That is never "orphaned". An unreadable session rooted anywhere else has no path
to the worktree and is skipped.
"""
import argparse
import functools
import glob
import json
import os
import subprocess
import sys

LIVE_STATES = {"working", "blocked"}


def inside(cwd, worktree):
    return cwd == worktree or cwd.startswith(worktree + "/")


def above(cwd, worktree):
    return worktree.startswith(cwd.rstrip("/") + "/")


@functools.lru_cache(maxsize=None)
def repository(path):
    result = subprocess.run(
        ["git", "-C", path, "rev-parse", "--path-format=absolute", "--git-common-dir"],
        capture_output=True,
        text=True,
    )
    return result.stdout.strip() if result.returncode == 0 else None


def related(cwd, worktree):
    if not cwd:
        return True  # no cwd in the row: nothing rules the session out
    cwd = os.path.abspath(cwd)
    return inside(cwd, worktree) or above(cwd, worktree) or same_repository(cwd, worktree)


def same_repository(cwd, worktree):
    return repository(worktree) is not None and repository(cwd) == repository(worktree)


def transcripts(projects_dir, session_id):
    return glob.glob(os.path.join(projects_dir, "*", session_id + ".jsonl")) + glob.glob(
        os.path.join(projects_dir, "*", session_id, "subagents", "*.jsonl")
    )


def cwd_entries(paths):
    for path in paths:
        with open(path, errors="ignore") as transcript:
            for line in transcript:
                try:
                    cwd = json.loads(line).get("cwd")
                except (json.JSONDecodeError, AttributeError):
                    continue  # a line still being written, or not an entry
                if cwd:
                    yield cwd


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("worktree", help="absolute path of the worktree")
    parser.add_argument("--projects-dir", default=os.path.expanduser("~/.claude/projects"))
    parser.add_argument("--session-id", default=os.environ.get("CLAUDE_CODE_SESSION_ID", ""))
    args = parser.parse_args()
    worktree = os.path.abspath(args.worktree).rstrip("/")

    owners, unreadable = [], []
    for session in json.load(sys.stdin):
        session_id = session.get("sessionId")
        if session_id == args.session_id or session.get("state") not in LIVE_STATES:
            continue
        label = f"{session.get('name', '')} ({session.get('id', '')}, {session.get('state', '')})"
        cwds = list(cwd_entries(transcripts(args.projects_dir, session_id))) if session_id else []
        if not cwds:
            if related(session.get("cwd"), worktree):
                unreadable.append(label)
            continue
        count = sum(1 for cwd in cwds if inside(cwd, worktree))
        if count:
            owners.append(f"{session.get('name', '')}\t{session.get('id', '')}\t{session.get('state', '')}\t{count}")

    if unreadable:
        print(
            "owners.py: cannot tell whether these live sessions own the worktree, because no transcript "
            f"with cwd entries was found under {args.projects_dir}: " + "; ".join(unreadable)
            + ". Treat the worktree as owned and ask the owner.",
            file=sys.stderr,
        )
        sys.exit(2)

    for owner in owners:
        print(owner)


if __name__ == "__main__":
    main()
