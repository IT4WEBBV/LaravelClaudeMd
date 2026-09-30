#!/usr/bin/env python3
"""Print the `needs input:` line for the PRs that wait on the owner's merge.

Usage: needs_input.py <repo> <pr>:<issue> [<pr>:<issue> ...]

One pair per PR whose merge watch is armed. Prints one line, which ends the orchestrator's message:

    needs input: merge PR #109 (#91) https://github.com/IT4WEBBV/LaravelClaudeMd/pull/109

No pair: prints nothing and exits 0, so it can run before every report. A repo that is not
`owner/name`, or a pair that is not `<digits>:<digits>`: usage on stderr, exit 2.

Why a script: the job list (Claude Code 2.1.285) classifies a session from its latest message, and
shows it as needing input when the last 800 characters hold a line matching

    (?:^|\\n)\\s*needs input\\s*[:—–-]\\s*(.{3,200}?)(?=\\n|$)     (case-insensitive)

outside a code fence. The text after the colon is 3 to 200 characters to the end of the line: a
longer line is no marker at all, and nothing says so. So the request is the first of these that fits
in LIMIT: every PR with its link; every PR without (links on all or on none, so the line's shape
does not vary per PR); as many linkless PRs as fit, then ` +<n> more`.

Pairs are compared as numbers, de-duplicated and ordered by PR number, so consecutive reports print
the same line. The line says nothing about CI: nothing re-checks it when the line is restated.
"""
import re
import sys

LIMIT = 200
PREFIX = "needs input: "
USAGE = "Usage: needs_input.py <repo> <pr>:<issue> [<pr>:<issue> ...]"
REPO = re.compile(r"[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+")
PAIR = re.compile(r"[0-9]+:[0-9]+")


def entry(repo, pr, issue, link):
    text = f"PR #{pr} (#{issue})"
    return f"{text} https://github.com/{repo}/pull/{pr}" if link else text


def merge(repo, pairs, link):
    return "merge " + ", ".join(entry(repo, pr, issue, link) for pr, issue in pairs)


def candidates(repo, pairs):
    yield merge(repo, pairs, True)
    yield merge(repo, pairs, False)
    for shown in range(len(pairs) - 1, -1, -1):
        yield f"{merge(repo, pairs[:shown], False).rstrip()} +{len(pairs) - shown} more"


def request(repo, pairs):
    return next(text for text in candidates(repo, sorted(set(pairs))) if len(text) <= LIMIT)


def main(arguments):
    if not arguments or not REPO.fullmatch(arguments[0]) or not all(PAIR.fullmatch(pair) for pair in arguments[1:]):
        print(USAGE, file=sys.stderr)
        return 2
    pairs = [tuple(int(number) for number in pair.split(":")) for pair in arguments[1:]]
    if pairs:
        print(PREFIX + request(arguments[0], pairs))
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
