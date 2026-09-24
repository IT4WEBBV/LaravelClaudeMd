#!/usr/bin/env python3
"""Name the live sessions that own a worktree.

Usage: claude agents --json --all | owners.py <worktree> [--projects-dir DIR] [--session-id ID]

A session owns a worktree when its state is working or blocked (the live states `claude agents`
reports) and one of its transcripts (main, subagents/, or an autoflow workflow's steps under
subagents/workflows/wf_*/) has an entry that works in it: an entry whose cwd lies inside it, or one
that names it through the pipeline. An autoflow step runs from the launch directory, so it names
the worktree instead: a Bash command holding a `dispatch_cli.php <subcommand>
<worktree>/.claude/pipeline/…` call, a kickoff `ready` or launch `start` answer printed in a tool
result, or the Workflow call whose args are that answer. The command is matched as a string, so one
that only quotes the call (a grep over another run's transcripts) counts too: a spurious owner turns
resume into a question, never into a second run. Every other state (done, failed, stopped, …) and the calling session are skipped.
Grepping for the path or the branch is not enough: slot directories are recycled, and every session
that mapped a worktree mentions it.

Prints one line per owner: name, id, state, matching entries (tab-separated).
No output and exit 0 means no live session owns it: the work is orphaned.

Fails closed: when a live session that owns nothing has no transcript, or any one of its transcripts
holds no cwd entries, the lookup cannot tell whether that session owns the worktree (the transcript
layout or format has changed, or the session has not written its first entry yet). When that
session's own cwd, from its `claude agents` row, is inside the worktree, contains it (the primary
checkout, ~), lies in the same git repository (a slot's primary checkout), or is missing, it prints
nothing, names the sessions on stderr and exits 2. That is never "orphaned". An unreadable session
rooted anywhere else has no path to the worktree and is skipped.

This protects the cwd evidence only. A transcript that still has cwd entries but whose tool_use /
tool_result blocks moved is read as readable, and its pipeline evidence is silently lost: an
autoflow run that stops being found after a Claude Code update is a format change first and an
orphan second.
"""
import argparse
import functools
import glob
import json
import os
import re
import subprocess
import sys

LIVE_STATES = {"working", "blocked"}
ANSWERS = {"ready", "start"}  # kickoff's and launch's answers: the two that carry a worktree
DISPATCH_CALL = re.compile(r"dispatch_cli\.php['\"]?\s+[a-z-]+\s+['\"]?([^\s'\"]+)")
MANIFESTS = "/.claude/pipeline/"


def inside(cwd, worktree):
    return cwd == worktree or cwd.startswith(worktree + "/")


def above(cwd, worktree):
    return worktree.startswith(cwd.rstrip("/") + "/")


@functools.lru_cache(maxsize=None)
def repository(path):
    result = subprocess.run(
        ["git", "-C", path, "rev-parse", "--git-common-dir"],
        capture_output=True,
        text=True,
    )
    return os.path.realpath(os.path.join(path, result.stdout.strip())) if result.returncode == 0 else None


def related(cwd, worktree):
    if not cwd:
        return True  # no cwd in the row: nothing rules the session out
    cwd = os.path.abspath(cwd)
    return inside(cwd, worktree) or above(cwd, worktree) or same_repository(cwd, worktree)


def same_repository(cwd, worktree):
    return repository(worktree) is not None and repository(cwd) == repository(worktree)


def transcripts(projects_dir, session_id):
    session = os.path.join(projects_dir, "*", session_id)
    paths = (
        glob.glob(session + ".jsonl")
        + glob.glob(os.path.join(session, "subagents", "*.jsonl"))
        + glob.glob(os.path.join(session, "subagents", "workflows", "wf_*", "*.jsonl"))
    )
    return [path for path in paths if os.path.basename(path) != "journal.jsonl"]


def entries(path):
    with open(path, errors="ignore") as transcript:
        for line in transcript:
            try:
                entry = json.loads(line)
            except json.JSONDecodeError:
                continue  # a line still being written
            if isinstance(entry, dict):
                yield entry


def answer_worktree(value):
    if isinstance(value, dict) and value.get("action") in ANSWERS and isinstance(value.get("worktree"), str):
        return value["worktree"]
    return None


def result_text(block):
    content = block.get("content")
    if isinstance(content, list):
        return "\n".join(part.get("text", "") for part in content if isinstance(part, dict))
    return content if isinstance(content, str) else ""


def named_in_result(block):
    for line in result_text(block).splitlines():
        try:
            worktree = answer_worktree(json.loads(line))
        except json.JSONDecodeError:
            continue
        if worktree:
            yield worktree


def named_in_call(block):
    command = ((block.get("input") or {}).get("command") or "") if block.get("name") == "Bash" else ""
    for path in DISPATCH_CALL.findall(command):
        if MANIFESTS in path:
            yield path.partition(MANIFESTS)[0]
    if block.get("name") == "Workflow":
        worktree = answer_worktree((block.get("input") or {}).get("args"))
        if worktree:
            yield worktree


def named_worktrees(entry):
    content = (entry.get("message") or {}).get("content") if isinstance(entry.get("message"), dict) else None
    for block in content if isinstance(content, list) else []:
        if not isinstance(block, dict):
            continue
        if block.get("type") == "tool_use":
            yield from named_in_call(block)
        elif block.get("type") == "tool_result":
            yield from named_in_result(block)


def normalised(path):
    return os.path.abspath(path).rstrip("/")


def works_in(entry, worktree):
    cwd = entry.get("cwd")
    if isinstance(cwd, str) and cwd and inside(cwd, worktree):
        return True
    return any(normalised(named) == worktree for named in named_worktrees(entry))


def read(path, worktree):
    has_cwd, matches = False, 0
    for entry in entries(path):
        has_cwd = has_cwd or bool(entry.get("cwd"))
        matches += works_in(entry, worktree)
    return has_cwd, matches


def unreadable_transcripts(reads):
    return not reads or not all(has_cwd for has_cwd, _ in reads)


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("worktree", help="absolute path of the worktree")
    parser.add_argument("--projects-dir", default=os.path.expanduser("~/.claude/projects"))
    parser.add_argument("--session-id", default=os.environ.get("CLAUDE_CODE_SESSION_ID", ""))
    args = parser.parse_args()
    worktree = normalised(args.worktree)

    owners, unreadable = [], []
    for session in json.load(sys.stdin):
        session_id = session.get("sessionId")
        if session_id == args.session_id or session.get("state") not in LIVE_STATES:
            continue
        label = f"{session.get('name', '')} ({session.get('id', '')}, {session.get('state', '')})"
        reads = [read(path, worktree) for path in transcripts(args.projects_dir, session_id)] if session_id else []
        count = sum(matches for _, matches in reads)
        if count:
            owners.append(f"{session.get('name', '')}\t{session.get('id', '')}\t{session.get('state', '')}\t{count}")
        elif unreadable_transcripts(reads) and related(session.get("cwd"), worktree):
            unreadable.append(label)

    if unreadable:
        print(
            "owners.py: cannot tell whether these live sessions own the worktree, because they have no "
            f"transcript, or one without cwd entries, under {args.projects_dir}: " + "; ".join(unreadable)
            + ". Treat the worktree as owned and ask the owner.",
            file=sys.stderr,
        )
        sys.exit(2)

    for owner in owners:
        print(owner)


if __name__ == "__main__":
    main()
