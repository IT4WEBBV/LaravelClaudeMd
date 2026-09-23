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

// Repeated from pipeline.php (pipeline_legs, pipeline_next_leg) and dispatch.php (pipeline_loop_target,
// LegStatus::allowedFor, PIPELINE_LOOP_BOUND), which interactive mode uses. The smoke run in
// docs/superpowers/plans/2026-09-23-pipeline-auto-workflow.md (Task 6) is this script's test.
const LEGS = ['design', 'review-plan', 'handoff', 'implement', 'verify-ui', 'review-pr']
const STEPS = { 'review-plan': ['review', 'resolve'], 'review-pr': ['review', 'resolve'] }
const LOOP_TARGET = { 'review-plan': 'design', 'verify-ui': 'implement', 'review-pr': 'implement' }
const ALLOWED = {
  'design:run': ['continued', 'halted'],
  review: ['continued', 'halted', 'plan-insufficient'],
  resolve: ['continued', 'looped-back', 'halted'],
  'verify-ui:run': ['continued', 'looped-back', 'halted', 'plan-insufficient'],
  run: ['continued', 'halted', 'plan-insufficient'],
}
const BOUND = 2
const UNSATISFIABLE = { type: 'object', properties: {}, required: ['status'] } // the smoke run's thrown error

const loops = { 'review-plan': 0, 'verify-ui': 0, 'review-pr': 0, ...args.loops }
let ui = args.ui

function nextLeg(leg) {
  return LEGS.slice(LEGS.indexOf(leg) + 1).find(next => next !== 'verify-ui' || ui)
}

function halt(leg, reason) {
  return { action: 'halt', leg, reason: reason || `the ${leg} step halted without a reason` }
}

function briefCommand(leg, step) {
  return `php ${args.checks}/dispatch_cli.php brief ${args.manifest} ${leg} ${step}`
}

function schemaFor(leg, step) {
  const properties = {
    status: { type: 'string', enum: ALLOWED[`${leg}:${step}`] ?? ALLOWED[step] },
    reason: { type: 'string' },
  }
  if (leg === 'implement') properties.ui = { type: 'boolean' }
  return { type: 'object', properties, required: leg === 'implement' ? ['status', 'ui'] : ['status'] }
}

function stepPrompt(leg, step) {
  const diff = args.manifest.replace(/\.json$/, '.diff')
  const lines = [
    `You are the \`${leg}\` leg, \`${step}\` step, of a /pipeline autoflow run.`,
    `1. Run \`${briefCommand(leg, step)}\` and follow the brief it prints; it is complete. If it prints {"action":"halt",…} instead, return status \`halted\` with its reason.`,
    '2. You cannot start agents. Where a skill or the brief would dispatch one, do that work yourself; where that is impossible, return `halted` with the reason.',
    "3. Finish as the brief's `## Return` says.",
  ]
  if (leg === 'implement') {
    lines.push(`4. After the last commit, run \`git -C ${args.worktree} diff origin/<base>...HEAD > ${diff}\` (<base>: the PR's base branch), then \`php -r 'require $argv[1]; echo json_encode(pipeline_triggers(file_get_contents($argv[2]))["ui"]);' ${args.checks}/triggers.php ${diff}\`, and return what it prints as \`ui\`: copy it, do not judge it.`)
  }
  if (leg === 'review-pr' && step === 'resolve') {
    lines.push(`4. Run the proof page's \`open\` as \`PIPELINE_NO_OPEN=${args.noOpen ? 1 : 0} php ${args.checks}/proof_cli.php open …\`.`)
  }
  return lines.join('\n')
}

function stubPrompt(leg, step, returns) {
  return [
    `SMOKE TEST: you stand in for the \`${leg}\` leg, \`${step}\` step, of a /pipeline autoflow run. Do no real work.`,
    `1. Run \`${briefCommand(leg, step)}\`. If it prints {"action":"halt",…}, return {"status":"halted","reason":<its reason>} and stop.`,
    args.stub.prompt,
    JSON.stringify(returns),
  ].join('\n')
}

async function runStep(leg, step) {
  const returns = args.stub?.steps[`${leg}:${step}`]?.shift()
  if (args.stub && !returns) return { status: 'halted', reason: `the smoke run has no stub for ${leg}:${step}` }
  const model = returns ? 'haiku' : step === 'review' ? 'fable' : undefined
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

let leg = args.startLeg
let from = args.startStep
while (leg) {
  const all = STEPS[leg] ?? ['run']
  const steps = from ? all.slice(all.indexOf(from)) : all
  from = undefined
  let result
  for (const step of steps) {
    result = await runStep(leg, step)
    log(`${leg}:${step} ${result.status}${result.reason ? `: ${result.reason}` : ''}`)
    if (result.status !== 'continued') break
  }
  if (result.status === 'halted') return halt(leg, result.reason)
  if (result.ui !== undefined) ui = result.ui
  if (result.status === 'continued') {
    leg = nextLeg(leg)
    continue
  }

  const gap = result.status === 'plan-insufficient'
  const target = gap ? 'design' : LOOP_TARGET[leg]
  if (!target) return halt(leg, `no loop-back from ${leg}`)
  const counted = !(gap && args.size === 'Bounded') // a Bounded escalation is not a loop-back
  const gate = gap ? 'review-plan' : leg
  if (counted && ++loops[gate] > BOUND) return halt(leg, `${gate}: loop-back bound exhausted`)
  leg = target
}
return { action: 'done' }
