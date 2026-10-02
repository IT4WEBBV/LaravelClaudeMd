// Replays ../../workflow/pipeline-autoflow.js outside the Workflow runtime: stdin is
// {script, args, returns, steps?}; agent() is faked, each call taking the next return scripted for its label
// and refusing a status its schema does not allow, as the runtime's StructuredOutput would. A scripted
// null is an agent that returns nothing. With `steps`, each call is a stub step against the real checks:
// it runs the brief command from its prompt and returns a halt it prints; otherwise it merges the
// return's `write` into the manifest (`cursor` one level down), writes its `diff` to the run's diff file,
// and returns the rest. A return with `record` (a list of flags) writes through the real command instead:
// its `review` and `actions` go to the run's two files and are passed by path, and a refusal comes back as a
// halt. A call whose schema has no `status` is the script's relay check: it returns `{head: input.relay}`
// (default: a clean head), `null` when `input.relay` is null, or throws `input.relay.throw`. Prints
// {labels, prompts, settings, relay?, result}: the step labels, prompts and `<model> <effort>` in call
// order, the check's call when it ran, and what the script returned.
import { execFileSync, execSync } from 'node:child_process'
import { readFileSync, writeFileSync } from 'node:fs'

const input = JSON.parse(readFileSync(0, 'utf8'))
const body = readFileSync(input.script, 'utf8').replace(/^export const meta\b/m, 'const meta')
const AsyncFunction = Object.getPrototypeOf(async function () {}).constructor
const labels = []
const prompts = []
const settings = []
const CLEAN_HEAD = '[Workflow harness — computed task] The t'
let relay

function brief(prompt) {
  const answer = execSync(prompt.match(/`(php \S+\/dispatch_cli\.php brief [^`]+)`/)[1], { encoding: 'utf8' })
  const halt = answer.startsWith('{') ? JSON.parse(answer) : null
  return halt?.action === 'halt' ? { status: 'halted', reason: halt.reason } : null
}

function play({ write = {}, diff, ...returns }) {
  const path = input.args.manifest
  const manifest = JSON.parse(readFileSync(path, 'utf8'))
  writeFileSync(path, JSON.stringify({ ...manifest, ...write, cursor: { ...manifest.cursor, ...write.cursor } }, null, 4) + '\n')
  if (diff !== undefined) writeFileSync(path.replace(/\.json$/, '.diff'), diff)
  return returns
}

function record(label, { record: flags, review, actions, diff, ...returns }) {
  const path = input.args.manifest
  const stem = path.replace(/\.json$/, '')
  const files = []
  if (review !== undefined) {
    writeFileSync(`${stem}.review.md`, review)
    files.push('--review-file', `${stem}.review.md`)
  }
  if (actions !== undefined) {
    writeFileSync(`${stem}.actions.json`, JSON.stringify(actions))
    files.push('--actions-file', `${stem}.actions.json`)
  }
  if (diff !== undefined) writeFileSync(`${stem}.diff`, diff)
  const [leg, step] = label.split(':')
  const reason = returns.reason ? ['--reason', returns.reason] : []
  try {
    execFileSync('php', [`${input.args.checks}/dispatch_cli.php`, 'record', path, leg, step, '--status', returns.status, ...reason, ...flags, ...files], { encoding: 'utf8' })
  } catch (error) {
    return { ...returns, status: 'halted', reason: JSON.parse(error.stdout).reason }
  }
  return returns
}

function check(prompt, opts) {
  relay = { prompt, label: opts.label, agentType: opts.agentType, schema: opts.schema, setting: `${opts.model} ${opts.effort}` }
  const head = 'relay' in input ? input.relay : CLEAN_HEAD
  if (head?.throw) throw new Error(head.throw)
  return head === null ? null : { head }
}

async function agent(prompt, opts) {
  if (!('status' in opts.schema.properties)) return check(prompt, opts)
  labels.push(opts.label)
  prompts.push(prompt)
  settings.push(`${opts.model} ${opts.effort}`)
  const statuses = opts.schema.properties.status.enum
  if (statuses.length === 0) throw new Error('the schema is unsatisfiable')
  const returns = input.returns[opts.label]?.shift()
  if (returns === undefined) throw new Error(`no return scripted for ${opts.label}`)
  const halted = input.steps ? brief(prompt) : null
  if (halted) return halted
  if (returns === null) return null
  if (!statuses.includes(returns.status)) throw new Error(`${opts.label} may not return ${returns.status}`)
  if (!input.steps) return returns
  return returns.record ? record(opts.label, returns) : play(returns)
}

const result = await new AsyncFunction('args', 'agent', 'log', body)(input.args, agent, () => {})
process.stdout.write(JSON.stringify({ labels, prompts, settings, ...(relay ? { relay } : {}), result }) + '\n')
