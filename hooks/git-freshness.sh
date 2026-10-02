#!/usr/bin/env bash
#
# git-freshness.sh — warn when a stale checkout is about to cost you something.
#
# Wired into ~/.claude/settings.json. Prints Claude Code hook JSON on stdout.
#
#   session    SessionStart — bring the config repos up to date (see
#              sync_config_repos), then check the directory the session was
#              launched in. The only thing knowable before anything has been
#              touched.
#
#   edit       PostToolUse on Edit|Write — check the repo that owns the file
#              being written, once per repo per session. This is the one that
#              matters: it anchors on the repo actually being worked in, which
#              is not necessarily where the session was launched, and it fires
#              at the moment staleness starts costing something — right before
#              new work lands on an old base.
#
#   checkout   PostToolUse on `git checkout` — a branch switch changes the
#              answer, so drop this session's cached verdicts and stay silent.
#              The next edit re-checks.
#
# Why this exists: `git status` only compares HEAD against its *tracking* branch,
# so a feature branch perfectly in sync with its own remote reads as "up to date"
# even when origin/main has moved underneath it. That is the most common
# stale-checkout case, and it is invisible to `git status`.
#
# What it reports: a working branch behind its base gets one line with the
# count. A checked repo is a repo about to be worked in, and new work on a
# stale base is the cost, whether or not the branch has commits of its own yet.
# What makes that line actionable are the consequences under it: migrations
# your dev database is missing, lockfiles that moved, files you will have to
# merge by hand. A /pipeline run's own branch gets neither, because the run's
# code decides when it merges its base. A repo that is current says so in one
# quiet line of context and nothing on screen.
#
# The one thing it changes on its own is the local base branch: main/master is
# fast-forwarded to match origin so that a branch cut later starts from a current
# base. That is strictly a fast-forward, and only when it cannot cost anything —
# never with local commits on the base, never into a dirty or mid-operation
# checkout, and never by writing a ref out from under a checkout that is sitting
# on it (see worktree_holding, which is the whole reason that function exists).
#
# The config repos get one more: a skill that has no symlink in ~/.claude/skills
# yet is linked, and so is a skill's workflow script (skills/<skill>/workflow/*.js)
# that has none in ~/.claude/workflows, a skill's agent definition
# (skills/<skill>/agents/*.md) that has none in ~/.claude/agents, and the status
# line script when ~/.claude/statusline-command.sh does not exist; a skill's
# AppleScript app (skills/<skill>/apps/*.applescript) is compiled into
# ~/Applications when no app of that name is there. So each reaches every machine
# with its next session instead of waiting for a manual relink. An existing entry
# is never replaced.
#
# Beyond that it touches nothing: your branch, your index and your working tree
# are left alone, merges are predicted in a throwaway index, and deciding whether
# to rebase or merge *your* work is left to the human.
#
# Every path exits 0: a hook must never take a session down with it.

set -uo pipefail

mode="${1:-session}"

max_fetch_seconds=10    # hard cap on the network call
fetch_ttl_seconds=900   # skip the network entirely if we fetched within 15 min
max_listed_files=6      # the conflict list is a prompt, not an inventory

# The repos whose skills are symlinked into the skills dir, colon-separated.
# Both are overridable, and set to empty, by the tests.
config_repos="${GIT_FRESHNESS_CONFIG_REPOS-$HOME/GitProjects/LaravelClaudeMd/LaravelClaudeMd:$HOME/GitProjects/DevOps-Claude-Config/DevOps-Claude-Config}"
skills_dir="${GIT_FRESHNESS_SKILLS_DIR-$HOME/.claude/skills}"
workflows_dir="${GIT_FRESHNESS_WORKFLOWS_DIR-$HOME/.claude/workflows}"
agents_dir="${GIT_FRESHNESS_AGENTS_DIR-$HOME/.claude/agents}"
apps_dir="${GIT_FRESHNESS_APPS_DIR-$HOME/Applications}"
statusline="${GIT_FRESHNESS_STATUSLINE-$HOME/.claude/statusline-command.sh}"
config_fetch_seconds=5  # tighter than max_fetch_seconds: the session repo still has to fit in the hook timeout

config_notes=""
config_tags=""
repo_context=""
repo_summary=""

payload=""
[ -t 0 ] || payload=$(cat 2>/dev/null)

# Pull a simple string field out of the hook's JSON payload. Enough for the
# flat, known-shape fields we need; jq is not installed on this machine.
payload_field() {
    printf '%s' "$payload" \
        | sed -n "s/.*\"$1\"[[:space:]]*:[[:space:]]*\"\([^\"]*\)\".*/\1/p" \
        | head -1
}

# Undo a JSON string's escapes: \n and \t become a newline and a tab, any other
# escaped character becomes that character (\" \\ \/, but also \r as r and
# \uXXXX as uXXXX: no path we resolve needs those).
json_unescape() {
    awk '{
        out = ""
        for (i = 1; i <= length($0); i++) {
            c = substr($0, i, 1)
            if (c == "\\" && i < length($0)) {
                i++
                c = substr($0, i, 1)
                if (c == "n") c = "\n"
                else if (c == "t") c = "\t"
            }
            out = out c
        }
        print out
    }'
}

# A string field of the payload with its JSON escapes respected, which
# payload_field does not do: a Bash command routinely holds quotes.
payload_string() {
    printf '%s' "$payload" \
        | sed -nE 's/.*"'"$1"'"[[:space:]]*:[[:space:]]*"((\\.|[^"\\])*)".*/\1/p' \
        | head -1 \
        | json_unescape
}

# Each word that follows `cd` or `git -C` at a command boundary (line start,
# ; & | ( or whitespace), up to whitespace, ; & | or ), one per line, as
# written. awk's match() rather than grep -o, whose handling of a ^ inside an
# alternation differs between BSD and GNU.
bash_words() {
    awk '{
        line = " " $0
        while (match(line, /[;&|( \t](cd|git[ \t]+-C)[ \t]+[^ \t;&|)]+/)) {
            word = substr(line, RSTART + 1, RLENGTH - 1)
            sub(/^(cd|git[ \t]+-C)[ \t]+/, "", word)
            print word
            line = substr(line, RSTART + RLENGTH)
        }
    }'
}

# The absolute path a word from a Bash command names: surrounding quotes
# stripped, a leading ~ as $HOME, a relative path against directory $2. Status
# 1 for a word only the shell could resolve (a variable, a substitution).
resolve_word() {
    local word=$1

    word=${word#\"}
    word=${word#\'}
    word=${word%\"}
    word=${word%\'}
    case "$word" in
        '' | *'$'* | *'`'*) return 1 ;;
        '~')                word=$HOME ;;
        '~/'*)              word="$HOME/${word#'~/'}" ;;
    esac

    absolute_path "$word" "$2"
}

# The directories a Bash call works in: every existing one its command names
# with cd or git -C, else directory $1, the call's cwd. A word that does not
# resolve is a miss, visible as a missing freshness line.
bash_targets() {
    local cwd=$1 word dir found=""

    while IFS= read -r word; do
        [ -n "$word" ] || continue
        dir=$(resolve_word "$word" "$cwd") || continue
        [ -d "$dir" ] || continue
        printf '%s\n' "$dir"
        found=1
    done <<< "$(payload_string command | bash_words)"

    [ -n "$found" ] || printf '%s\n' "$cwd"
}

session_id=$(payload_field session_id)
cache_dir="${TMPDIR:-/tmp}/claude-git-freshness/${session_id:-nosession}"

mtime() {
    stat -f %m "$1" 2>/dev/null || stat -c %Y "$1" 2>/dev/null
}

human_age() {
    local s=$1
    if   [ "$s" -lt 60 ];    then echo "just now"
    elif [ "$s" -lt 3600 ];  then echo "$((s / 60))m ago"
    elif [ "$s" -lt 86400 ]; then echo "$((s / 3600))h ago"
    else                          echo "$((s / 86400))d ago"
    fi
}

# Escape a string for embedding in a JSON string literal.
json_escape() {
    printf '%s' "$1" \
        | sed -e 's/\\/\\\\/g' -e 's/"/\\"/g' \
        | awk 'BEGIN { ORS = "" } { print (NR > 1 ? "\\n" : "") $0 }'
}

count_lines() {
    printf '%s\n' "$1" | grep -c . 2>/dev/null || true
}

# Does any line of $1 match the extended regex $2?
#
# Deliberately `grep -c` and not `grep -q`. Under `set -o pipefail`, `grep -q`
# exits the moment it matches, the upstream `printf` takes EPIPE on its
# still-unwritten remainder, and the pipeline reports that failure — so a
# successful match reads as no match once the input outgrows the pipe buffer.
# It fails silently and only on large inputs, which is the worst way to be
# wrong. Counting drains the input, so the status is grep's own.
matches_path() {
    local n
    n=$(printf '%s\n' "$1" | grep -cE "$2" 2>/dev/null)
    [ "${n:-0}" -gt 0 ]
}

abs_git_path() {
    local d
    d=$(git rev-parse "$1" 2>/dev/null) || return
    case "$d" in
        /*) printf '%s' "$d" ;;
        *)  printf '%s/%s' "$PWD" "$d" ;;
    esac
}

# FETCH_HEAD is per-worktree: a fetch from inside a worktree writes
# .git/worktrees/<name>/FETCH_HEAD, leaving the common dir's copy untouched (and
# often hours stale). Take whichever is newer so both layouts read correctly.
newest_fetch_mtime() {
    local newest=0 dir m
    for dir in "$(abs_git_path --git-dir)" "$(abs_git_path --git-common-dir)"; do
        [ -n "$dir" ] || continue
        m=$(mtime "$dir/FETCH_HEAD"); m=${m:-0}
        [ "$m" -gt "$newest" ] && newest=$m
    done
    printf '%s' "$newest"
}

# Print this invocation's one hook JSON object: the repo reports collected in
# repo_context/repo_summary, plus whatever sync_config_repos found, because
# Claude Code reads a single object per hook run.
emit() {
    local event=$1 context=$2 summary=$3

    if [ -n "$config_notes" ]; then
        context="${context}${context:+

}Config repos:${config_notes}"
    fi
    if [ -n "$config_tags" ]; then
        summary="${summary}${summary:+ }Config repos: ${config_tags}."
    fi

    printf '{'
    printf '"hookSpecificOutput":{"hookEventName":"%s","additionalContext":"%s"}' \
        "$(json_escape "$event")" "$(json_escape "$context")"
    if [ -n "$summary" ]; then
        printf ',"systemMessage":"%s"' "$(json_escape "$summary")"
    else
        printf ',"suppressOutput":true'
    fi
    printf '}\n'
}

# Add one repo's report to this invocation's single emit(). Reports are joined
# by a blank line, summaries by a space.
add_report() {
    local context=$1 summary=$2

    repo_context="${repo_context}${repo_context:+

}${context}"
    [ -z "$summary" ] || repo_summary="${repo_summary}${repo_summary:+ }${summary}"
}

# Incoming changes whose arrival has a concrete local consequence. Anything that
# does not land in one of these buckets is, for our purposes, invisible: a
# rebase will absorb it without the human needing to know in advance.
#
# Appends to the globals `insights` (the detail) and `tags` (the headline).
classify_incoming() {
    local mb=$1 base=$2 files n

    files=$(git diff --name-only "$mb" "$base" 2>/dev/null)
    [ -n "$files" ] || return 0

    n=$(printf '%s\n' "$files" | grep -c 'database/migrations/')
    if [ "${n:-0}" -gt 0 ]; then
        insights="${insights}
  - $n new migration(s) on $base — your dev database is behind; migrate after catching up"
        tags="${tags}${tags:+, }$n migrations"
    fi

    n=$(printf '%s\n' "$files" | grep -cE 'database/(actions|operations)/')
    if [ "${n:-0}" -gt 0 ]; then
        insights="${insights}
  - $n new deploy operation(s) on $base — production-only, but review before rebasing onto them"
        tags="${tags}${tags:+, }$n operations"
    fi

    if matches_path "$files" 'composer\.lock'; then
        insights="${insights}
  - composer.lock moved on $base — run composer install after catching up"
        tags="${tags}${tags:+, }composer.lock"
    fi

    if matches_path "$files" 'package-lock\.json|yarn\.lock|pnpm-lock\.yaml'; then
        insights="${insights}
  - JS lockfile moved on $base — run npm install and rebuild assets after catching up"
        tags="${tags}${tags:+, }js lockfile"
    fi

    if matches_path "$files" '\.env\.example'; then
        insights="${insights}
  - .env.example changed on $base — new environment keys may be required"
        tags="${tags}${tags:+, }.env.example"
    fi

    return 0
}

# Which files a catch-up would actually make you merge by hand.
#
# Performed against a throwaway index so the real index and working tree are
# never touched. `merge-tree --write-tree` would be the clean way to do this but
# needs git >= 2.38; `read-tree -m --aggressive` works everywhere and costs about
# 35ms. It resolves only the trivial cases, so this over-reports slightly: a file
# both sides touched in non-overlapping regions still shows up. For a warning,
# that is the right direction to be wrong in.
predict_conflicts() {
    local mb=$1 base=$2 idx paths=""

    idx="${TMPDIR:-/tmp}/claude-freshness-idx.$$"
    rm -f "$idx"
    if GIT_INDEX_FILE="$idx" git read-tree -m --aggressive "$mb" HEAD "$base" >/dev/null 2>&1; then
        paths=$(GIT_INDEX_FILE="$idx" git ls-files -u 2>/dev/null | awk '{print $4}' | sort -u)
    fi
    rm -f "$idx"

    printf '%s' "$paths"
}

# Path of the worktree that currently has branch $1 checked out, or empty.
#
# This is load-bearing. Writing a branch ref that some checkout is sitting on
# desynchronises that checkout: the branch moves, its working tree does not, and
# its index then claims a staged revert of everything that just arrived —
# committing there would push away the commits we just brought in. git only
# guards the *current* worktree's branch (verified on 2.33); a branch checked
# out in a sibling worktree is rewritten without complaint. So: ask, never
# assume. With slots, the sibling case is the common one.
worktree_holding() {
    git worktree list --porcelain 2>/dev/null | awk -v want="refs/heads/$1" '
        $1 == "worktree" { path = substr($0, index($0, " ") + 1) }
        $1 == "branch" && $2 == want { print path; exit }
    '
}

# A checkout is safe to fast-forward only when there is nothing in it to lose
# and no operation half-finished on top of it.
worktree_is_clean() {
    local wt=$1 dirty gd state

    dirty=$(git -C "$wt" status --porcelain 2>/dev/null | grep -c . || true)
    [ "${dirty:-0}" -eq 0 ] || return 1

    gd=$(git -C "$wt" rev-parse --absolute-git-dir 2>/dev/null) || return 1
    for state in MERGE_HEAD CHERRY_PICK_HEAD REVERT_HEAD BISECT_LOG rebase-merge rebase-apply; do
        [ -e "$gd/$state" ] && return 1
    done

    return 0
}

# What a completed fast-forward costs locally.
#
# A ref-only update costs nothing: no checkout moved, so nothing is out of sync
# and there is nothing to rebuild. Moving a real working tree is different — the
# containers running against it are now behind the code in it, which is the one
# consequence worth interrupting for.
#
# Appends to `sync_notes` (always, so the record exists) and to `sync_tags`
# (only when there is something to act on, since tags are what reach the user).
report_sync() {
    local base=$1 n=$2 files=$3 wt=$4 needs=""

    if [ -z "$wt" ]; then
        sync_notes="${sync_notes}
  - fast-forwarded $base by $n commit(s) — ref only, no checkout has it out, nothing to rebuild"
        return 0
    fi

    matches_path "$files" 'composer\.lock' \
        && needs="${needs}${needs:+, }composer install"
    matches_path "$files" 'package-lock\.json|yarn\.lock|pnpm-lock\.yaml' \
        && needs="${needs}${needs:+, }npm install + asset rebuild"
    matches_path "$files" 'database/migrations/' \
        && needs="${needs}${needs:+, }migrate"
    matches_path "$files" '\.env\.example' \
        && needs="${needs}${needs:+, }check for new .env keys"

    if [ -z "$needs" ]; then
        sync_notes="${sync_notes}
  - fast-forwarded $base by $n commit(s) in $wt — application code only, nothing to rebuild"
        return 0
    fi

    sync_notes="${sync_notes}
  - fast-forwarded $base by $n commit(s) in $wt — $needs; that checkout is now
    ahead of the containers running against it, so run scripts/restart.sh there
    before using that stack"
    sync_tags="${sync_tags}${sync_tags:+, }$base +$n, restart needed"
}

# Bring the local base branch up to date with origin, but only where that is
# provably safe: nothing local to lose, and either nobody has the branch checked
# out (a pure ref write) or the checkout that does is clean (a --ff-only merge).
#
# Everything else is reported and left exactly as it was. This is the only place
# the script mutates local state, so every path here either acts on a verified
# safe condition or says why it did nothing.
sync_base_branch() {
    local base_ref=$1 base behind ahead files wt

    [ -n "$base_ref" ] || return 0
    base=${base_ref#origin/}
    [ -n "$base" ] || return 0
    git rev-parse --verify --quiet "refs/heads/$base" >/dev/null 2>&1 || return 0

    behind=$(git rev-list --count "$base..$base_ref" 2>/dev/null || echo 0)
    [ "${behind:-0}" -gt 0 ] || return 0

    # Local commits on the base branch are somebody's unpushed work. Never.
    ahead=$(git rev-list --count "$base_ref..$base" 2>/dev/null || echo 0)
    if [ "${ahead:-0}" -gt 0 ]; then
        sync_notes="${sync_notes}
  - $base is $behind behind $base_ref and has $ahead local commit(s) — not a
    fast-forward, left alone"
        sync_tags="${sync_tags}${sync_tags:+, }$base needs a manual merge"
        return 0
    fi

    files=$(git diff --name-only "$base" "$base_ref" 2>/dev/null)
    wt=$(worktree_holding "$base")

    # Nobody has it out: a ref write, no working tree involved. Fetching from
    # the local repo itself keeps git's own non-fast-forward rejection as a
    # second line of defence without another trip to the network.
    if [ -z "$wt" ]; then
        git fetch --quiet . "$base_ref:$base" >/dev/null 2>&1 \
            && report_sync "$base" "$behind" "$files" ""
        return 0
    fi

    if ! worktree_is_clean "$wt"; then
        sync_notes="${sync_notes}
  - $base is $behind behind $base_ref but its checkout at $wt has uncommitted
    changes or an operation in progress — left alone"
        sync_tags="${sync_tags}${sync_tags:+, }$base checkout dirty"
        return 0
    fi

    git -C "$wt" merge --ff-only "$base_ref" >/dev/null 2>&1 \
        && report_sync "$base" "$behind" "$files" "$wt"

    return 0
}

# Fetch origin in the current repo, unless it fetched within the TTL. The network
# call is capped at $1 seconds: a dead connection must not hang the session.
fetch_if_stale() {
    local cap=$1 now last fetch_pid ticks=0

    now=$(date +%s)
    last=$(newest_fetch_mtime)
    [ "$((now - last))" -ge "$fetch_ttl_seconds" ] || return 0

    GIT_TERMINAL_PROMPT=0 \
    GIT_SSH_COMMAND="${GIT_SSH_COMMAND:-ssh} -o BatchMode=yes" \
        git fetch --quiet origin >/dev/null 2>&1 &
    fetch_pid=$!
    while kill -0 "$fetch_pid" 2>/dev/null; do
        if [ "$ticks" -ge "$((cap * 4))" ]; then
            kill "$fetch_pid" 2>/dev/null
            break
        fi
        sleep 0.25
        ticks=$((ticks + 1))
    done
    wait "$fetch_pid" 2>/dev/null

    # Re-point origin/HEAD at the remote's real default branch. This symref
    # is cached at clone time and goes stale silently — a clone made when
    # `develop` was default still claims `develop` years after the repo
    # moved to `main`, which would have us measure against the wrong branch.
    git remote set-head origin --auto >/dev/null 2>&1
}

# The branch the current repo is measured against: origin/HEAD, which
# fetch_if_stale keeps honest, else whichever of main and master exists.
resolve_base_ref() {
    local ref candidate

    ref=$(git symbolic-ref -q --short refs/remotes/origin/HEAD 2>/dev/null || echo "")
    if [ -z "$ref" ]; then
        for candidate in origin/main origin/master; do
            if git rev-parse --verify --quiet "$candidate" >/dev/null 2>&1; then
                ref="$candidate"
                break
            fi
        done
    fi
    printf '%s' "$ref"
}

config_repo_list() {
    printf '%s\n' "$config_repos" | tr ':' '\n' | grep -v '^$'
}

# Link each skill in repo $1 that has no entry in the skills dir yet. An
# existing entry is never replaced: a name taken by the other repo is a
# collision to fix by renaming, not something to settle silently here.
link_new_skills() {
    local repo=$1 skill name

    # A skills dir that is itself a symlink points into one repo; linking into
    # it would write into that repo's working tree.
    [ -d "$skills_dir" ] && [ ! -L "$skills_dir" ] || return 0

    for skill in "$repo"/skills/*/; do
        [ -f "${skill}SKILL.md" ] || continue
        name=$(basename "$skill")
        { [ -e "$skills_dir/$name" ] || [ -L "$skills_dir/$name" ]; } && continue
        ln -s "${skill%/}" "$skills_dir/$name" 2>/dev/null \
            && config_tags="${config_tags}${config_tags:+, }linked new skill $name"
    done
}

# Link each file a skill in repo $1 ships under skills/<skill>/$2/*.$3 that has
# no entry in dir $4 yet, reported as "linked new $5 <name>": workflow scripts,
# so a saved workflow loads by name in every project, and agent definitions, so
# a workflow's agentType resolves. Same rules as skills, except that a missing
# dir is created: it is ours alone, where the skills dir is set up by hand once
# per machine.
link_new_skill_files() {
    local repo=$1 subdir=$2 ext=$3 dir=$4 word=$5 file name

    [ ! -L "$dir" ] && mkdir -p "$dir" 2>/dev/null || return 0

    for file in "$repo"/skills/*/"$subdir"/*."$ext"; do
        [ -f "$file" ] || continue
        name=$(basename "$file")
        { [ -e "$dir/$name" ] || [ -L "$dir/$name" ]; } && continue
        ln -s "$file" "$dir/$name" 2>/dev/null \
            && config_tags="${config_tags}${config_tags:+, }linked new $word ${name%.$ext}"
    done
}

# Compile each AppleScript a skill in repo $1 ships under skills/<skill>/apps/*.applescript
# into the apps dir as <name>.app when no app of that name is there yet, reported as
# "built new app <name>". A built app does not follow its source: an existing app is
# never replaced, whoever put it there, and a changed source reaches a machine by
# deleting the app (README §Proofs app). It compiles into a temp dir and moves the
# bundle in, so a source that fails to compile leaves nothing the skip rule would keep.
# Same dir rules as link_new_skill_files; without osacompile (not macOS) it does nothing.
build_new_skill_apps() {
    local repo=$1 script name tmp

    command -v osacompile >/dev/null 2>&1 || return 0
    [ ! -L "$apps_dir" ] && mkdir -p "$apps_dir" 2>/dev/null || return 0

    for script in "$repo"/skills/*/apps/*.applescript; do
        [ -f "$script" ] || continue
        name=$(basename "$script" .applescript)
        { [ -e "$apps_dir/$name.app" ] || [ -L "$apps_dir/$name.app" ]; } && continue
        tmp=$(mktemp -d "${TMPDIR:-/tmp}/git-freshness-app.XXXXXX") || continue
        osacompile -o "$tmp/$name.app" "$script" >/dev/null 2>&1 \
            && mv "$tmp/$name.app" "$apps_dir/$name.app" 2>/dev/null \
            && config_tags="${config_tags}${config_tags:+, }built new app $name"
        rm -rf "$tmp"
    done

    return 0
}

# Link the status line script a config repo ships (statusline/statusline-command.sh) when the
# machine has nothing at the status line path yet. An existing file or link is never replaced:
# swapping a machine-local copy for the link is the README's one-time setup step.
link_statusline() {
    local script="$1/statusline/statusline-command.sh"

    [ -f "$script" ] || return 0
    { [ -e "$statusline" ] || [ -L "$statusline" ]; } && return 0
    ln -s "$script" "$statusline" 2>/dev/null \
        && config_tags="${config_tags}${config_tags:+, }linked the status line"
}

# The config repos are where a stale checkout is invisible by design: their
# skills are symlinked into the skills dir, so a checkout left behind quietly
# runs old skills on this machine. Fetch them in parallel, fast-forward their
# base branch exactly as any other repo, flag a checkout that is not on its
# base branch (its skills run that branch), and link skills that are new.
#
# Appends to `config_notes` and `config_tags`, which emit() folds into the
# session's single hook output.
sync_config_repos() {
    local repo name base_ref branch pids=""

    while IFS= read -r repo; do
        git -C "$repo" rev-parse --git-dir >/dev/null 2>&1 || continue
        ( cd "$repo" && fetch_if_stale "$config_fetch_seconds" ) </dev/null &
        pids="$pids $!"
    done <<< "$(config_repo_list)"
    [ -z "$pids" ] || wait $pids 2>/dev/null

    while IFS= read -r repo; do
        [ -n "$repo" ] && cd "$repo" 2>/dev/null || continue
        git rev-parse --git-dir >/dev/null 2>&1 || continue

        # Every skill is a symlink into this checkout, so nearly every session
        # reads a file here. The session has just synced it: a skill read must
        # not check it again and tell a session that does not work here to
        # raise it and wait.
        claim_repo "$(git rev-parse --show-toplevel)" || :

        name=$(basename "$repo")
        base_ref=$(resolve_base_ref)

        sync_notes=""
        sync_tags=""
        sync_base_branch "$base_ref"
        [ -z "$sync_notes" ] || config_notes="${config_notes}
  $name:${sync_notes}"
        [ -z "$sync_tags" ] || config_tags="${config_tags}${config_tags:+, }$name $sync_tags"

        branch=$(git symbolic-ref --short -q HEAD 2>/dev/null || echo "detached HEAD")
        if [ -n "$base_ref" ] && [ "$branch" != "${base_ref#origin/}" ]; then
            config_notes="${config_notes}
  - $name is on '$branch', not ${base_ref#origin/}: the skills linked from it run that branch"
            config_tags="${config_tags}${config_tags:+, }$name skills from '$branch'"
        fi

        link_new_skills "$repo"
        link_new_skill_files "$repo" workflow js "$workflows_dir" workflow
        link_new_skill_files "$repo" agents md "$agents_dir" agent
        build_new_skill_apps "$repo"
        link_statusline "$repo"
    done <<< "$(config_repo_list)"
}

# The SecondBrain vault was retired on one machine (LaravelClaudeMd #64); this
# reminds the other one to clean up at its next session. Read-only: the session
# asks before touching anything. Delete this once both machines are clean.
remind_retired_vault() {
    local home="${GIT_FRESHNESS_VAULT_HOME-$HOME}" found="" n

    [ "$(grep -c '"basic-memory"' "$home/.claude.json" 2>/dev/null)" -gt 0 ] 2>/dev/null \
        && found="${found}, basic-memory MCP entry in ~/.claude.json"
    [ -d "$home/.basic-memory" ] && found="${found}, ~/.basic-memory"
    if [ -d "$home/GitProjects/SecondBrain" ]; then
        n=$(git -C "$home/GitProjects/SecondBrain/SecondBrain" status --porcelain 2>/dev/null | grep -c .)
        case "${n:-0}" in
            0) found="${found}, ~/GitProjects/SecondBrain" ;;
            1) found="${found}, ~/GitProjects/SecondBrain (1 uncommitted file)" ;;
            *) found="${found}, ~/GitProjects/SecondBrain ($n uncommitted files)" ;;
        esac
    fi
    [ -n "$found" ] || return 0

    config_notes="${config_notes}
  - SecondBrain leftovers on this machine: ${found#, }. The vault is retired (LaravelClaudeMd #64). Offer the cleanup with AskUserQuestion: \`claude mcp remove basic-memory -s user\`, \`uv tool uninstall basic-memory\`, \`rm -rf ~/.basic-memory\`, and grep ~/.claude/projects/*/memory for SecondBrain/basic-memory notes to delete. Ask separately before deleting ~/GitProjects/SecondBrain: uncommitted files there exist nowhere else (the GitHub repo is archived)."
    config_tags="${config_tags}${config_tags:+, }SecondBrain leftovers found"
}

# Path $1 as an absolute path, a relative one taken against directory $2.
absolute_path() {
    case "$1" in
        /*) printf '%s\n' "$1" ;;
        *)  printf '%s/%s\n' "$2" "$1" ;;
    esac
}

# The repo path $1 is in: its --show-toplevel. A path that is no directory is
# looked up by its dirname, so a file about to be written still finds its repo.
# Prints nothing, status 1, when the path does not exist or is in no repo. Each
# worktree, a slot included, is its own toplevel.
repo_toplevel() {
    local dir=$1

    [ -d "$dir" ] || dir=$(dirname "$dir")
    [ -d "$dir" ] || return 1
    git -C "$dir" rev-parse --show-toplevel 2>/dev/null
}

# Claim repo $1 for this session: true the first time only. mkdir is atomic, so
# of two tool calls racing into one repo exactly one wins, and a marker left as
# a file by an older edit mode blocks it as well. The claim comes before the
# check, so a repo that fails it (no origin, a dead network) is not retried.
claim_repo() {
    mkdir -p "$cache_dir" 2>/dev/null || return 1
    mkdir "$cache_dir/$(printf '%s' "$1" | tr -c 'A-Za-z0-9._-' '_')" 2>/dev/null
}

# The paths the payload's tool call acts on, one per line. Tools that carry no
# path (WebFetch, MCP tools, Task tools) name none.
touch_targets() {
    local cwd path

    cwd=$(payload_field cwd)
    case "$(payload_field tool_name)" in
        Read | Edit | Write | MultiEdit) path=$(payload_field file_path) ;;
        NotebookEdit)                    path=$(payload_field notebook_path) ;;
        Glob | Grep)                     path=$(payload_field path); path=${path:-$cwd} ;;
        Bash)                            bash_targets "$cwd"; return 0 ;;
        *)                               return 0 ;;
    esac

    [ -z "$path" ] || absolute_path "$path" "$cwd"
}

# Check each repo among paths $2 (one per line) that this session has not
# claimed yet, then print one $1 hook object for all of them, or nothing.
check_first_touches() {
    local event=$1 target toplevel

    while IFS= read -r target; do
        [ -n "$target" ] || continue
        toplevel=$(repo_toplevel "$target") || continue
        [ -n "$toplevel" ] || continue
        claim_repo "$toplevel" || continue
        check_repo "$toplevel" </dev/null
    done <<< "$2"

    [ -z "$repo_context" ] || emit "$event" "$repo_context" "$repo_summary"
}

# Is branch $1 a /pipeline run's own branch? Its manifest sits in the worktree at
# the path manifest_path() in skills/pipeline/checks/manifest.php builds, and the
# run's own code decides whether a step merges the base (pipeline engine.md
# §Catching up with the base): telling every step agent to raise it and wait on
# its first Read would contradict the brief it runs on.
runs_pipeline() {
    local toplevel

    toplevel=$(git rev-parse --show-toplevel 2>/dev/null) || return 1
    [ -f "$toplevel/.claude/pipeline/$(printf '%s' "$1" | tr '/' '-').json" ]
}

# What catching up with base $1 costs the local work: migrations, lockfiles,
# files to merge by hand. Only work to protect earns it: a branch with no local
# commits and a clean tree catches up as a plain fast-forward.
report_consequences() {
    local base_ref=$1 ahead dirty mb conflicts n_conf shown

    ahead=$(git rev-list --count "$base_ref..HEAD" 2>/dev/null || echo 0)
    dirty=$(git status --porcelain 2>/dev/null | grep -c . || true)
    [ "${ahead:-0}" -gt 0 ] || [ "${dirty:-0}" -gt 0 ] || return 0

    mb=$(git merge-base HEAD "$base_ref" 2>/dev/null)
    [ -n "$mb" ] || return 0
    classify_incoming "$mb" "$base_ref"

    # No local commits means no divergence, so nothing can conflict.
    [ "${ahead:-0}" -gt 0 ] || return 0
    conflicts=$(predict_conflicts "$mb" "$base_ref")
    n_conf=$(count_lines "$conflicts")
    [ "${n_conf:-0}" -gt 0 ] || return 0

    shown=$(printf '%s\n' "$conflicts" | head -"$max_listed_files" | sed 's/^/      /')
    insights="${insights}
  - catching up would need manual merging in ${n_conf} file(s):
${shown}"
    if [ "$n_conf" -gt "$max_listed_files" ]; then
        insights="${insights}
      (+$((n_conf - max_listed_files)) more)"
    fi
    tags="${tags}${tags:+, }${n_conf} to merge by hand"
}

# The working branch against base $3, after the base sync. A branch behind it
# gets the headline with its count, then its consequences; a detached HEAD
# ($2 empty) names no branch to bring up, so it gets the consequences only; a
# /pipeline run's own branch gets neither.
report_behind_base() {
    local name=$1 branch=$2 base_ref=$3 behind

    [ -n "$base_ref" ] || return 0
    if [ -n "$branch" ] && runs_pipeline "$branch"; then
        return 0
    fi

    behind=$(git rev-list --count "HEAD..$base_ref" 2>/dev/null || echo 0)
    [ "${behind:-0}" -gt 0 ] || return 0

    if [ -n "$branch" ]; then
        headline="Stale checkout: $name on '$branch' is $behind commit(s) behind $base_ref."
        tags="${tags}${tags:+, }$behind behind $base_ref"
    fi
    report_consequences "$base_ref"
}

# Someone pushed to *this* branch: another machine, or another slot, is ahead
# of this checkout. Always worth knowing and always actionable.
report_pushed_elsewhere() {
    local upstream=$1 behind

    [ -n "$upstream" ] || return 0
    behind=$(git rev-list --count "HEAD..$upstream" 2>/dev/null || echo 0)
    [ "${behind:-0}" -gt 0 ] || return 0

    insights="${insights}
  - $upstream has $behind commit(s) not in this checkout — another machine or slot pushed to this branch; pull before continuing"
    tags="${tags}${tags:+, }branch pushed elsewhere"
}

# Fold this repo's findings into one report: the headline (or the consequences'
# header) with the raise-and-wait instruction, then the notes that ask for no
# decision. A repo with nothing to say gets one quiet line of context.
add_repo_report() {
    local name=$1 branch=$2 age=$3 context="" summary=""

    if [ -n "$headline$insights" ]; then
        context="${headline:-Stale checkout with consequences: $name on '$branch'}${insights}
Last fetch: $age.

Do NOT pull, rebase, or merge on your own initiative. Raise this with the user
before working in this repo and wait for their decision: bring the branch up to
date, or deliberately continue on the current base."
    fi

    # A sync that changed nothing anyone has to act on is still recorded, so
    # the reason main moved is never a mystery; it just earns no line on screen.
    if [ -n "$sync_notes" ]; then
        context="${context}${context:+

}Base branch sync: $name${sync_notes}"
    fi

    if [ -z "$context" ]; then
        add_report "git freshness: $name on '$branch' — nothing incoming that affects this work (fetched $age)." ""
        return 0
    fi

    [ -z "$tags" ] || summary="$name '$branch': $tags."
    if [ -n "$sync_tags" ] && [ -n "$summary" ]; then
        summary="$summary Also: $sync_tags."
    elif [ -n "$sync_tags" ]; then
        summary="$name: $sync_tags."
    fi

    add_report "$context" "$summary"
}

# Report on the repo containing $1 into repo_context/repo_summary, which the
# mode emits. Adds nothing when the path is not a git repo with an origin.
check_repo() {
    local target=$1

    cd "$target" 2>/dev/null || return 0
    git rev-parse --git-dir >/dev/null 2>&1 || return 0
    git remote get-url origin >/dev/null 2>&1 || return 0

    fetch_if_stale "$max_fetch_seconds"

    local name age branch upstream base_ref
    name=$(basename "$target")
    age=$(human_age $(( $(date +%s) - $(newest_fetch_mtime) )))
    branch=$(git symbolic-ref --short -q HEAD 2>/dev/null || echo "")
    upstream=$(git rev-parse --abbrev-ref --symbolic-full-name '@{u}' 2>/dev/null || echo "")
    base_ref=$(resolve_base_ref)

    headline=""
    insights=""
    tags=""
    sync_notes=""
    sync_tags=""

    # Catch the local base branch up first, so the count below, and a branch
    # cut later in this session, measure against the right base.
    sync_base_branch "$base_ref"
    report_behind_base "$name" "$branch" "$base_ref"

    # A branch whose upstream is the base already got the behind line.
    [ "$upstream" = "$base_ref" ] || report_pushed_elsewhere "$upstream"

    add_repo_report "$name" "${branch:-(detached HEAD)}" "$age"
}

# Sourcing this file with GIT_FRESHNESS_LIB=1 defines the functions above
# without running the hook. Used by hooks/tests/git-freshness-sync.test.sh.
if [ "${GIT_FRESHNESS_LIB:-}" = "1" ]; then
    return 0 2>/dev/null || exit 0
fi

case "$mode" in
    checkout)
        # A branch switch invalidates every verdict cached for this session.
        [ -n "$session_id" ] && rm -rf "$cache_dir" 2>/dev/null
        exit 0
        ;;

    touch)
        check_first_touches PreToolUse "$(touch_targets)"
        ;;

    edit)
        # The legacy PostToolUse wiring: the same check on the written file's
        # repo, sharing touch's markers, so with both wired it is a no-op. It
        # reads file_path without tool_name, as it always has.
        check_first_touches PostToolUse "$(payload_field file_path)"
        ;;

    session)
        # Nothing has been edited yet, so the session's own cwd is all we have.
        repo=$(payload_field cwd)
        [ -n "$repo" ] && [ -d "$repo" ] || repo="$PWD"

        sync_config_repos
        remind_retired_vault

        # Mark the launch repo as touched, so its first tool call does not
        # report it again. A resumed or cleared session checks regardless.
        toplevel=$(repo_toplevel "$repo") && claim_repo "$toplevel"
        check_repo "${toplevel:-$repo}"

        # check_repo adds nothing outside a git repo; config news still gets out.
        [ -z "$repo_context$config_notes$config_tags" ] \
            || emit SessionStart "$repo_context" "$repo_summary"
        ;;

    *)
        exit 0
        ;;
esac

exit 0
