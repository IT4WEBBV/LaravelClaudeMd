---
name: experiment
description: Use when user says "experiment" or invokes /experiment, or when user expresses doubt about whether a proposed library/framework feature actually works
---

# Experiment

Validate technical feasibility with throwaway code before committing to an approach.

**Core principle:** Don't trust claims about libraries/frameworks — prove they work with throwaway code before building on them.

## The Iron Law

```
NO EXPERIMENT WITHOUT USER CONFIRMATION FIRST
```

State what you'll test. Wait for approval. Then proceed.

**Violating the letter of this rule is violating its spirit.**

**"Can you experiment with X?" is NOT approval to proceed.** It's a request to propose an experiment. You still must:
1. State what you'll test
2. State what throwaway artifact you'll create
3. Wait for explicit "yes" / "go ahead" / "proceed"

Only then write code.

## Triggers

**Explicit:** User says "experiment" or invokes `/experiment`

**Implicit:** User expresses doubt ("are you sure that works?", "will that actually work?")

For implicit triggers, ask: "Would you like me to run a quick experiment to validate this?" — do NOT assume.

## Workflow

```
1. CONFIRM → 2. IMPLEMENT → 3. REPORT → 4. CLEANUP
```

### 1. CONFIRM (mandatory, never skip)

Before writing ANY code, state:
- What specific capability will be tested
- What throwaway artifact will be created
- What success looks like

**Wait for user approval.** Do not proceed without it.

### 2. IMPLEMENT

- Create minimal throwaway code that proves the claim
- Actually run it — reading docs or code is NOT an experiment
- Keep scope tight — only test the uncertain part

### 3. REPORT

**On success:**
```
## Experiment Result: PASS

### What was tested
[Specific capability validated]

### Throwaway code
[Show the code that proved it works]

### Design implications
[What we learned, any caveats discovered]
```

**On failure:**
```
## Experiment Result: FAIL

### What was tested
[Specific capability attempted]

### What went wrong
[Why it didn't work]

### Throwaway code
[Show what was tried]

### Alternatives
1. [Alternative A] — [trade-off]
2. [Alternative B] — [trade-off]

### Should we abandon this direction?
[Honest assessment: dead end, or just needs different approach?]
```

### 4. CLEANUP

Delete all throwaway code. Confirm deletion to user.

Exception: User explicitly asks to keep it as reference.

## Common Rationalizations

| Excuse | Reality |
|--------|---------|
| "User asked me to experiment, so I have permission" | "Experiment with X" = propose an experiment. Still need explicit approval before writing code. |
| "I can answer by reading the config/code" | Reading is not proving. Create throwaway code that runs. |
| "Let me analyze the technical details" | Analysis is not experiment. Write code, run it, show results. |
| "I'm confident this works" | Confidence is not proof. Training data may be wrong. |
| "The docs say it works" | Docs can be wrong or misremembered. Prove it. |
| "This is too simple to need an experiment" | Simple claims can be wrong. 5-min experiment beats 2-hour dead end. |
| "I've used this before" | In THIS project? With THIS version? Prove it. |

## Red Flags — STOP

If you catch yourself:
- Interpreting "experiment with X" as permission to start coding
- Answering a feasibility question by reading existing code
- Saying "let me experiment" but then just discussing/analyzing
- Starting to write throwaway code without confirming first
- Skipping cleanup because "it's just test code"

**STOP. Follow the workflow from step 1.**

## What an Experiment is NOT

- Reading documentation
- Reading existing code in the project
- Analyzing how something should work theoretically
- Discussing technical nuances
- Saying "I'll experiment" and then explaining instead

**An experiment IS:** Throwaway code you write, run, and show the results of.
