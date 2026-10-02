#!/usr/bin/env python3
"""Tear down the checkout of a merged PR, once every check holds.

Usage: teardown.py <checkout> <pr> [--repo <owner/name>] [--proof <page.html>] [--projects-dir DIR]

<checkout> is the working tree the PR's branch is checked out in: a slot, another linked worktree, or
the primary checkout. <pr> is the PR's number or URL, as gh takes it. In order, a line per stage:

1. read the PR (gh pr view, from the checkout, with -R <repo> when given);
2. mark its proof page merged or closed: --proof, else the run manifest's artifacts.proof
   (<checkout>/.claude/pipeline/<branch, / -> ->.json); a failed mark is printed and passed over;
3. stop when the PR is still open or was closed without merge;
4. print every check: clean, head (HEAD is the merged head), branch (the PR's), outside (this command
   does not run inside a worktree it would remove), owners (owners.py finds no other live session);
5. only when all hold, remove from the primary checkout: a slot (<Project>-<N> beside a primary that
   has scripts/worktree.sh) through `scripts/worktree.sh remove <N> --force-local-branch-removal`,
   any other linked worktree with `git worktree remove`, and the primary checkout back to its base
   with `git pull --ff-only origin <base>`; then `git branch -D` of the PR's branch.

The last line starts with `teardown: `. Exit 0 removed, 1 nothing removed, 2 usage, 3 stopped
part-way (the last line names the command that failed and what was already done).
"""
import argparse
import json
import os
import re
import subprocess
import sys
from dataclasses import dataclass

HERE = os.path.dirname(os.path.realpath(__file__))
PROOF_CLI = os.path.join(os.path.dirname(HERE), "pipeline", "checks", "proof_cli.php")
OWNERS = os.path.join(HERE, "owners.py")
PAGE_STATUS = {"MERGED": "merged", "CLOSED": "closed"}
RESTART_NOTE = "; not run: ./scripts/restart.sh (it reseeds the database)"


def run(command, cwd, stdin=None):
    return subprocess.run(command, cwd=cwd, input=stdin, capture_output=True, text=True)


def say(line):
    print(line, flush=True)


def end(line, code):
    say("teardown: " + line)
    sys.exit(code)


def inside(path, root):
    return path == root or path.startswith(root + "/")


class Stopped(Exception):
    def __init__(self, command, done):
        super().__init__(command)
        self.command, self.done = command, done


class Steps:
    """Removal commands, run in order from the primary checkout; the first that fails stops the rest."""

    def __init__(self, cwd):
        self.cwd, self.done = cwd, []

    def run(self, *command):
        line = " ".join(command)
        result = run(list(command), self.cwd)
        if result.returncode != 0:
            sys.stdout.write(result.stdout + result.stderr)
            sys.stdout.flush()
            raise Stopped(line, self.done)
        self.done.append(line)
        return result.stdout


@dataclass
class PullRequest:
    number: int
    state: str
    head_sha: str
    head: str
    base: str

    @classmethod
    def read(cls, pr, repo, cwd):
        command = ["gh", "pr", "view", pr, "--json", "number,state,headRefOid,headRefName,baseRefName"]
        result = run(command + (["-R", repo] if repo else []), cwd)
        if result.returncode != 0:
            sys.stderr.write(result.stderr)
            sys.stderr.flush()
            end(f"nothing removed: gh could not read PR {pr}", 1)
        view = json.loads(result.stdout)
        return cls(view["number"], view["state"], view["headRefOid"], view["headRefName"], view["baseRefName"])


class Checkout:
    def __init__(self, path, primary):
        self.path, self.primary = path, primary

    @staticmethod
    def at(path):
        primary = primary_checkout(path)
        if path == primary:
            return PrimaryCheckout(path, primary)
        number = slot_number(path, primary)
        return Slot(path, primary, number) if number else LinkedWorktree(path, primary)

    def git(self, *args):
        return run(["git", *args], self.path).stdout.strip()

    def outside(self, cwd):
        return not inside(cwd, self.path)


class LinkedWorktree(Checkout):
    def remove(self, pr, steps):
        steps.run("git", "worktree", "remove", self.path)
        steps.run("git", "branch", "-D", pr.head)
        return f"removed worktree {self.path} and branch {pr.head}"


class Slot(Checkout):
    def __init__(self, path, primary, number):
        super().__init__(path, primary)
        self.number = number

    def remove(self, pr, steps):
        steps.run("bash", "scripts/worktree.sh", "remove", self.number, "--force-local-branch-removal")
        if run(["git", "rev-parse", "--verify", "--quiet", "refs/heads/" + pr.head], self.primary).returncode == 0:
            steps.run("git", "branch", "-D", pr.head)
        return f"removed slot {self.number} ({self.path}), its stack and branch {pr.head}"


class PrimaryCheckout(Checkout):
    def outside(self, cwd):
        return True  # the primary checkout stays on disk

    def remove(self, pr, steps):
        steps.run("git", "switch", pr.base)
        steps.run("git", "pull", "--ff-only", "origin", pr.base)
        steps.run("git", "branch", "-D", pr.head)
        line = f"{self.path} back on {pr.base} at {self.git('rev-parse', '--short', 'HEAD')}; removed branch {pr.head}"
        return line + (RESTART_NOTE if os.path.isfile(os.path.join(self.path, "scripts", "restart.sh")) else "")


def primary_checkout(path):
    """git lists the main working tree first: the one whose git dir is the common dir."""
    listing = run(["git", "worktree", "list", "--porcelain"], path).stdout
    return os.path.realpath(listing.splitlines()[0][len("worktree "):])


def slot_number(path, primary):
    if not os.path.isfile(os.path.join(primary, "scripts", "worktree.sh")):
        return None
    if os.path.dirname(path) != os.path.dirname(primary):
        return None
    found = re.fullmatch(re.escape(os.path.basename(primary)) + r"-(\d+)", os.path.basename(path))
    return found.group(1) if found else None


def proof_page(given, checkout, pr):
    if given:
        return given
    manifest = os.path.join(checkout.path, ".claude", "pipeline", pr.head.replace("/", "-") + ".json")
    try:
        with open(manifest) as file:
            artifacts = json.load(file).get("artifacts")
    except (OSError, ValueError, AttributeError):
        return None
    return artifacts.get("proof") if isinstance(artifacts, dict) else None


def mark(page, pr):
    status = PAGE_STATUS.get(pr.state)
    if not page or not status:
        return
    result = run(["php", PROOF_CLI, "status", page, status], os.getcwd())
    say(f"page: marked {status}, {page}" if result.returncode == 0 else f"page: not marked: {result.stderr.strip()}")


def owners(path, projects_dir):
    """What owners.py reports: empty when no other live session works in the checkout."""
    agents = run(["claude", "agents", "--json", "--all"], path)
    if agents.returncode != 0:
        return "claude agents failed: " + agents.stderr.strip()
    command = [sys.executable, OWNERS, path] + (["--projects-dir", projects_dir] if projects_dir else [])
    result = run(command, path, stdin=agents.stdout)
    found = (result.stdout + result.stderr).strip()
    return found or ("" if result.returncode == 0 else f"owners.py exited {result.returncode}")


def checks(checkout, pr, projects_dir):
    changes = checkout.git("status", "--porcelain")
    head = checkout.git("rev-parse", "HEAD")
    branch = checkout.git("branch", "--show-current")
    owned = owners(checkout.path, projects_dir)
    return [
        ("clean", not changes, "; ".join(changes.splitlines())),
        ("head", head == pr.head_sha, f"HEAD is {head}, the merged head is {pr.head_sha}"),
        ("branch", branch == pr.head, f"on {branch or 'a detached HEAD'}, the PR's branch is {pr.head}"),
        ("outside", checkout.outside(os.path.realpath(os.getcwd())),
         f"this command runs inside {checkout.path}: run it from the primary checkout {checkout.primary}"
         " (a session that entered the worktree leaves it first: ExitWorktree, keep)"),
        ("owners", not owned, owned),
    ]


def arguments():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument("checkout", help="the working tree the PR's branch is checked out in")
    parser.add_argument("pr", help="the PR's number or URL")
    parser.add_argument("--repo", help="owner/name, passed to gh as -R")
    parser.add_argument("--proof", help="the proof page to mark (default: the run manifest's artifacts.proof)")
    parser.add_argument("--projects-dir", help="passed on to owners.py")
    args = parser.parse_args()
    args.checkout = os.path.realpath(args.checkout)
    top = run(["git", "rev-parse", "--show-toplevel"], args.checkout) if os.path.isdir(args.checkout) else None
    if top is None or top.returncode != 0 or os.path.realpath(top.stdout.strip()) != args.checkout:
        parser.error(f"{args.checkout} is not the top level of a git working tree")
    return args


def main():
    args = arguments()
    checkout = Checkout.at(args.checkout)
    pr = PullRequest.read(args.pr, args.repo, checkout.path)
    say(f"PR #{pr.number} {pr.state}: {pr.head} at {pr.head_sha[:7]} into {pr.base}")
    mark(proof_page(args.proof, checkout, pr), pr)
    if pr.state == "OPEN":
        end(f"nothing removed: PR #{pr.number} is still open", 1)
    if pr.state != "MERGED":
        end(f"nothing removed: PR #{pr.number} was closed without merge", 1)

    results = checks(checkout, pr, args.projects_dir)
    for name, ok, found in results:
        say(f"ok {name}" if ok else f"FAIL {name}: " + found.replace("\n", "\n  "))
    failed = [name for name, ok, _ in results if not ok]
    if failed:
        end("nothing removed: " + ", ".join(failed), 1)

    try:
        end(checkout.remove(pr, Steps(checkout.primary)), 0)
    except Stopped as stopped:
        done = "done: " + ", ".join(stopped.done) if stopped.done else "nothing done yet"
        end(f"stopped at {stopped.command}: {done}", 3)


if __name__ == "__main__":
    main()
