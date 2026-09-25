// Replays ../../workflow/pipeline-autoflow.js outside the Workflow runtime: stdin is
// {script, args, returns}; agent() is faked, each call taking the next return scripted for its label and
// refusing a status its schema does not allow, as the runtime's StructuredOutput would. Prints
// {labels, result}: the agent labels in call order and what the script returned.
import { readFileSync } from 'node:fs'

const input = JSON.parse(readFileSync(0, 'utf8'))
const body = readFileSync(input.script, 'utf8').replace(/^export const meta\b/m, 'const meta')
const AsyncFunction = Object.getPrototypeOf(async function () {}).constructor
const labels = []

async function agent(prompt, opts) {
  labels.push(opts.label)
  const statuses = opts.schema.properties.status.enum
  if (statuses.length === 0) throw new Error('the schema is unsatisfiable')
  const returns = input.returns[opts.label]?.shift()
  if (!returns) throw new Error(`no return scripted for ${opts.label}`)
  if (!statuses.includes(returns.status)) throw new Error(`${opts.label} may not return ${returns.status}`)
  return returns
}

const result = await new AsyncFunction('args', 'agent', 'log', body)(input.args, agent, () => {})
process.stdout.write(JSON.stringify({ labels, result }) + '\n')
