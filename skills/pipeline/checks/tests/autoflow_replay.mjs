// Replays ../../workflow/pipeline-autoflow.js outside the Workflow runtime: stdin is
// {script, args, returns, steps?}; agent() is faked, each call taking the next return scripted for its label
// and refusing a status its schema does not allow, as the runtime's StructuredOutput would. A scripted
// null is an agent that returns nothing. With `steps`, each call is a stub step against the real checks:
// it runs the brief command from its prompt and returns a halt it prints; otherwise it merges the
// return's `write` into the manifest (`cursor` one level down), writes its `diff` to the run's diff file,
// and returns the rest. Prints {labels, prompts, result}: the agent labels and prompts in call order and
// what the script returned.
import { execSync } from 'node:child_process'
import { readFileSync, writeFileSync } from 'node:fs'

const input = JSON.parse(readFileSync(0, 'utf8'))
const body = readFileSync(input.script, 'utf8').replace(/^export const meta\b/m, 'const meta')
const AsyncFunction = Object.getPrototypeOf(async function () {}).constructor
const labels = []
const prompts = []

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

async function agent(prompt, opts) {
  labels.push(opts.label)
  prompts.push(prompt)
  const statuses = opts.schema.properties.status.enum
  if (statuses.length === 0) throw new Error('the schema is unsatisfiable')
  const returns = input.returns[opts.label]?.shift()
  if (returns === undefined) throw new Error(`no return scripted for ${opts.label}`)
  const halted = input.steps ? brief(prompt) : null
  if (halted) return halted
  if (returns === null) return null
  if (!statuses.includes(returns.status)) throw new Error(`${opts.label} may not return ${returns.status}`)
  return input.steps ? play(returns) : returns
}

const result = await new AsyncFunction('args', 'agent', 'log', body)(input.args, agent, () => {})
process.stdout.write(JSON.stringify({ labels, prompts, result }) + '\n')
