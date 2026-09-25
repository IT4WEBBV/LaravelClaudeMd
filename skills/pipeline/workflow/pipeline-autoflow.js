export const meta = {
  name: 'pipeline-autoflow',
  description: 'Drive a /pipeline autoflow run from its cursor: one fresh agent per step; the order, loop-backs, bounds and halts in code',
  whenToUse: 'Started by the pipeline skill (and orchestrate) for an autoflow run, with the JSON that dispatch_cli.php launch printed as args',
  phases: [
    { title: 'design' },
    { title: 'review-plan' },
    { title: 'handoff' },
    { title: 'implement' },
    { title: 'verify-ui' },
    { title: 'review-pr' },
  ],
}

// The routing tables are launch's: `tables` in its start answer, built by pipeline_routing_tables() from
// the functions interactive mode uses. meta.phases repeats the legs as labels only (meta must be a pure
// literal). AutoflowScriptTest replays this script on launch's answer with agent() faked.
const COPIED = { design: { size: { type: 'string', enum: ['Bounded', 'Architectural'] } }, implement: { ui: { type: 'boolean' } } }
const UNSATISFIABLE = { type: 'object', properties: { status: { type: 'string', enum: [] } }, required: ['status'] } // invalid: agent() throws before starting an agent — the smoke run's thrown error

function complete(tables) {
  const { legs, steps, loopTarget, allowed, bound } = tables ?? {}
  const filled = list => Array.isArray(list) && list.length > 0
  return filled(legs)
    && legs.every(leg => filled(steps?.[leg]) && steps[leg].every(step => filled(allowed?.[`${leg}:${step}`])))
    && typeof loopTarget === 'object' && loopTarget !== null && Object.entries(loopTarget).every(([from, to]) => legs.includes(from) && legs.includes(to))
    && Number.isInteger(bound)
}

function nextLeg(leg) {
  return legs.slice(legs.indexOf(leg) + 1).find(next => next !== 'verify-ui' || ui)
}

function halt(leg, reason) {
  return { action: 'halt', leg, reason: reason || `the ${leg} step halted without a reason` }
}

// The previous step's return as brief checks it (`--after`); the first step of a run gets none. A retry
// reuses its prompt, and so the flags of the step before it.
function briefCommand(leg, step) {
  const flags = last ? [` --after ${last.leg}:${last.step} --status ${last.status}`, ...Object.keys(COPIED[last.leg] ?? {}).map(key => ` --${key} ${last[key]}`)] : []
  return `php ${args.checks}/dispatch_cli.php brief ${args.manifest} ${leg} ${step}${flags.join('')}`
}

function schemaFor(leg, step) {
  const copied = COPIED[leg] ?? {}
  const properties = {
    status: { type: 'string', enum: allowed[`${leg}:${step}`] },
    reason: { type: 'string' },
    ...copied,
  }
  return { type: 'object', properties, required: ['status', ...Object.keys(copied)] }
}

function stepPrompt(leg, step) {
  const diff = args.manifest.replace(/\.json$/, '.diff')
  const lines = [
    `You are the \`${leg}\` leg, \`${step}\` step, of a /pipeline autoflow run.`,
    `1. Run \`${briefCommand(leg, step)}\` and follow the brief it prints; it is complete. If it prints {"action":"halt",…} instead, return status \`halted\` with its reason.`,
    '2. You cannot start agents. Where a skill or the brief would dispatch one, do that work yourself; where that is impossible, return `halted` with the reason.',
    "3. Finish as the brief's `## Return` says.",
  ]
  if (leg === 'design') {
    lines.push(`4. After the last commit, run \`php ${args.checks}/dispatch_cli.php size ${args.manifest}\` and return what it prints as \`size\`: copy it, do not judge it. Every return carries \`size\`; on a halt its value is ignored.`)
  }
  if (leg === 'implement') {
    lines.push(`4. Before returning (after the last commit, when there is one), run \`git -C ${args.worktree} diff origin/<base>...HEAD > ${diff}\` with <base> the PR's base branch (\`gh pr view <pr> --json baseRefName --jq .baseRefName\`, <pr> being \`artifacts.pr\` in ${args.manifest}), then \`php ${args.checks}/dispatch_cli.php ui ${diff}\`, and return what it prints as \`ui\`: copy it, do not judge it. Every return carries \`ui\`; on a halt its value is ignored.`)
  }
  if (leg === 'review-pr' && step === 'resolve') {
    lines.push(`4. Run the proof page's \`open\` as \`PIPELINE_NO_OPEN=${args.noOpen ? 1 : 0} php ${args.checks}/proof_cli.php open …\`.`)
  }
  return lines.join('\n')
}

function stubPrompt(leg, step, returns) {
  return [
    `SMOKE TEST: you stand in for the \`${leg}\` leg, \`${step}\` step, of a /pipeline autoflow run. Do no real work.`,
    `Your status is \`${returns.status}\`${returns.reason ? ` with reason "${returns.reason}"` : ''}; your structured result is exactly ${JSON.stringify(returns)}.`,
    `1. Run \`${briefCommand(leg, step)}\`. If it prints {"action":"halt",…}, return {"status":"halted","reason":<its reason>} and stop.`,
    args.stub.prompt,
    JSON.stringify(returns),
  ].join('\n')
}

async function runStep(leg, step) {
  const returns = args.stub?.steps[`${leg}:${step}`]?.shift()
  if (args.stub && !returns) return { status: 'halted', reason: `the smoke run has no stub for ${leg}:${step}` }
  const model = returns ? 'sonnet' : step === 'review' ? 'fable' : undefined
  const opts = {
    label: `${leg}:${step}`,
    phase: leg,
    schema: returns?.throw ? UNSATISFIABLE : schemaFor(leg, step),
    ...(model ? { model } : {}),
    ...(leg === 'handoff' ? { effort: 'low' } : {}),
  }
  const prompt = returns ? stubPrompt(leg, step, returns) : stepPrompt(leg, step)
  try {
    const result = await agent(prompt, opts)
    const retried = result === null && model === 'fable' ? await agent(prompt, { ...opts, model: 'opus' }) : result
    return retried ?? { status: 'halted', reason: 'the agent returned nothing' }
  } catch (error) {
    return { status: 'halted', reason: `the agent failed: ${error?.message ?? error}` }
  }
}

if (args.action !== 'start') return halt(args.startLeg ?? 'launch', 'args are not a launch start answer')
if (!complete(args.tables)) return halt(args.startLeg ?? 'launch', 'args carry no complete tables: re-run launch from checks that have pipeline_routing_tables()')
const { legs, steps, loopTarget, allowed, bound } = args.tables
if (!legs.includes(args.startLeg)) return halt(args.startLeg ?? 'launch', 'args are not a launch start answer')
if (args.startStep && !steps[args.startLeg].includes(args.startStep)) return halt(args.startLeg, `${args.startLeg} has no ${args.startStep} step`)

const loops = { ...Object.fromEntries(Object.keys(loopTarget).map(gate => [gate, 0])), ...args.loops }
let ui = args.ui
let size = args.size
let exempted = false
let leg = args.startLeg
let from = args.startStep
let last
while (leg) {
  const all = steps[leg]
  const remaining = from ? all.slice(all.indexOf(from)) : all
  from = undefined
  let result
  for (const step of remaining) {
    result = await runStep(leg, step)
    last = { ...result, leg, step }
    log(`${leg}:${step} ${result.status}${result.reason ? `: ${result.reason}` : ''}`)
    if (result.status !== 'continued') break
  }
  if (result.status === 'halted') return halt(leg, result.reason)
  if (leg === 'implement') ui = result.ui
  if (leg === 'design') size = result.size
  if (result.status === 'continued') {
    leg = nextLeg(leg)
    continue
  }

  const gap = result.status === 'plan-insufficient'
  if (!gap && result.status !== 'looped-back') return halt(leg, `unknown status ${result.status}`)
  const target = gap ? 'design' : loopTarget[leg]
  if (!target) return halt(leg, `no loop-back from ${leg}`)
  const counted = !(gap && size === 'Bounded' && !exempted) // a Bounded escalation is not a loop-back; escalation is one-way, so once per run
  if (!counted) exempted = true
  const gate = gap ? 'review-plan' : leg
  if (counted && ++loops[gate] > bound) return halt(leg, `${gate}: loop-back bound exhausted`)
  leg = target
}
return { action: 'done' }
