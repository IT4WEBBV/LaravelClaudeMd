#!/usr/bin/env python3
import json, sys, os, subprocess
from datetime import datetime

data = json.load(sys.stdin)

# Optional payload dump for debugging: CLAUDE_STATUSLINE_DEBUG=1 claude
# Session-scoped so parallel sessions don't clobber each other.
if os.environ.get('CLAUDE_STATUSLINE_DEBUG'):
    session_id = data.get('session_id', 'unknown')
    debug_dir = data.get('scratchpad_dir') or '/tmp'
    try:
        os.makedirs(debug_dir, exist_ok=True)
        with open(os.path.join(debug_dir, f'statusline-debug-{session_id}.json'), 'w') as f:
            json.dump(data, f, indent=2)
    except OSError:
        pass

GREEN, YELLOW, RED, RESET = '\033[32m', '\033[33m', '\033[31m', '\033[0m'


def by_threshold(pct):
    if pct < 50:
        return GREEN
    if pct < 75:
        return YELLOW
    return RED


# Extract current directory
cwd = data.get('workspace', {}).get('current_dir', '')

# Get short directory (last 2 components)
parts = cwd.rstrip('/').split('/')
if len(parts) <= 2:
    short_dir = cwd
else:
    short_dir = f".../{parts[-2]}/{parts[-1]}"

# Check if directory is writable
writable = "[RO] " if cwd and not os.access(cwd, os.W_OK) else ""

# Get git info
git_info = ""
try:
    subprocess.check_output(
        ['git', '-C', cwd, 'rev-parse', '--git-dir'],
        stderr=subprocess.DEVNULL
    )
    branch = subprocess.check_output(
        ['git', '-C', cwd, '--no-optional-locks', 'branch', '--show-current'],
        stderr=subprocess.DEVNULL, text=True
    ).strip() or "detached"
    commit = subprocess.check_output(
        ['git', '-C', cwd, '--no-optional-locks', 'rev-parse', '--short=6', 'HEAD'],
        stderr=subprocess.DEVNULL, text=True
    ).strip()

    dirty = ""
    try:
        subprocess.check_call(
            ['git', '-C', cwd, '--no-optional-locks', 'diff', '--quiet'],
            stderr=subprocess.DEVNULL, stdout=subprocess.DEVNULL
        )
        subprocess.check_call(
            ['git', '-C', cwd, '--no-optional-locks', 'diff', '--cached', '--quiet'],
            stderr=subprocess.DEVNULL, stdout=subprocess.DEVNULL
        )
    except subprocess.CalledProcessError:
        dirty = "*"

    git_info = f" on {branch}@{commit}{dirty}"
except (subprocess.CalledProcessError, FileNotFoundError):
    pass

# Model, extended-context marker and reasoning effort
model_name = (data.get('model', {}).get('display_name') or '').split(' (')[0]
if data.get('context_window', {}).get('context_window_size', 0) >= 1_000_000:
    model_name += "·1M"

effort = data.get('effort', {}).get('level') or ''
effort = {'medium': 'med'}.get(effort, effort)
if data.get('fast_mode'):
    effort = (effort + ' fast').strip()

model_seg = f" | {model_name} {effort}".rstrip() if model_name else ""

# Get per-turn token usage (current_usage is null before first API call)
current = data.get('context_window', {}).get('current_usage') or {}
input_tokens = current.get('input_tokens', 0) or 0
output_tokens = current.get('output_tokens', 0) or 0
cache_create = current.get('cache_creation_input_tokens', 0) or 0
cache_read = current.get('cache_read_input_tokens', 0) or 0
total_tokens = input_tokens + output_tokens + cache_create + cache_read


# Format token count
def format_tokens(n):
    if n >= 1_000_000:
        return f"{n / 1_000_000:.1f}M"
    elif n >= 1_000:
        return f"{n / 1_000:.1f}k"
    return str(n)


token_str = format_tokens(total_tokens)

# Get context window usage
used_pct = int(data.get('context_window', {}).get('used_percentage', 0) or 0)
ctx_seg = f"{token_str} tokens, {by_threshold(used_pct)}{used_pct}% ctx{RESET}"

# Rate limits. Shows the absolute reset clock time rather than a countdown,
# because the status line only re-runs on events and a countdown would go stale.
limits = data.get('rate_limits') or {}
windows = []
# The reset clock is only shown for the 5h window: it is the one that actually
# gates a working day, and a bare time-of-day for a window days out misleads.
for key, label, show_reset in (('five_hour', '5h', True),
                               ('seven_day', '7d', False),
                               ('spend_limit', '$', False)):
    window = limits.get(key) or {}
    pct = window.get('used_percentage')
    if pct is None:
        continue
    pct = int(pct)
    resets_at = window.get('resets_at')
    reset = f"→{datetime.fromtimestamp(resets_at).strftime('%H:%M')}" if show_reset and resets_at else ""
    windows.append(f"{label} {by_threshold(pct)}{pct}%{RESET}{reset}")

limit_seg = f" | {' '.join(windows)}" if windows else ""

# Get current time
time_str = datetime.now().strftime('%H:%M:%S')

# Output the status line
print(f"{writable}{short_dir}{git_info}{model_seg} | {ctx_seg}{limit_seg} | {time_str}", end='')
