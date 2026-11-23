import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { closeSync, existsSync, mkdirSync, openSync, readFileSync, unlinkSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, isAbsolute, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { parseArgs } from 'node:util';

// Queue the existing session; never launch a second agent or change its permissions.
const { values } = parseArgs({ options: {
    thread: { type: 'string' },
    codex: { type: 'string' },
    'dry-run': { type: 'boolean', default: false },
} });
if (!/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(values.thread ?? '')) {
    throw new Error('An explicit existing session UUID is required.');
}
if (!values.codex || !isAbsolute(values.codex) || !existsSync(values.codex) || !/codex\.exe$/i.test(values.codex)) {
    throw new Error('An existing absolute Codex executable path is required.');
}
const directory = dirname(fileURLToPath(import.meta.url));
const workspace = resolve(directory, '../..');
if (!existsSync(join(workspace, 'docs/PROJECT_PLAN.md'))) throw new Error('Project plan missing.');
const prompt = readFileSync(join(directory, 'continuation-prompt.txt'), 'utf8').trim();
if (!prompt || prompt.length > 16000) throw new Error('Invalid continuation prompt.');
const key = createHash('sha256').update(`${workspace}\n${values.thread.toLowerCase()}`).digest('hex').slice(0, 20);
const stateDirectory = join(tmpdir(), `harusi-codex-continuation-${key}`);
if (values['dry-run']) {
    process.stdout.write(JSON.stringify({ dry_run: true, workspace, interval_hours: 4, prompt_characters: prompt.length, state_directory: stateDirectory }) + '\n');
    process.exit(0);
}
mkdirSync(stateDirectory, { recursive: true });
const lockPath = join(stateDirectory, 'queue.lock');
const statusPath = join(stateDirectory, 'status.json');
let lock;
try {
    lock = openSync(lockPath, 'wx');
} catch (error) {
    if (error.code !== 'EEXIST') throw error;
    // Fail closed if a killed helper left a lock. Recovery requires inspecting the task.
    process.stderr.write('Continuation already locked; inspect the task and local lock before recovery.\n');
    process.exit(0);
}
try {
    let previous = {};
    try { previous = JSON.parse(readFileSync(statusPath, 'utf8')); } catch { /* First run or interrupted status write. */ }
    const elapsed = Date.now() - Date.parse(previous.last_accepted_at ?? '');
    if (elapsed >= 0 && elapsed < (4 * 60 - 5) * 60 * 1000) process.exitCode = 0;
    else {
        const output = execFileSync(values.codex, ['queue', '--thread', values.thread, '--message', prompt, '-C', workspace], {
            cwd: workspace, encoding: 'utf8', windowsHide: true, timeout: 90_000, maxBuffer: 16_384,
        });
        const accepted = /^Queued message ([0-9a-f-]+) for thread ([0-9a-f-]+)\.\s*$/i.exec(output.trim());
        if (!accepted || accepted[2].toLowerCase() !== values.thread.toLowerCase()) {
            throw new Error('Codex did not confirm queue acceptance.');
        }
        writeFileSync(statusPath, JSON.stringify({ last_attempt_at: new Date().toISOString(), last_accepted_at: new Date().toISOString(), status: 'queued' }, null, 2) + '\n');
        process.stdout.write('Continuation accepted by the existing session queue.\n');
    }
} catch (error) {
    // Keep one bounded status file. Do not record prompts, credentials or provider output.
    let previous = {};
    try { previous = JSON.parse(readFileSync(statusPath, 'utf8')); } catch { /* No previous success. */ }
    writeFileSync(statusPath, JSON.stringify({ last_attempt_at: new Date().toISOString(), last_accepted_at: previous.last_accepted_at ?? null, status: 'failed', error_code: String(error.code ?? error.status ?? 'QUEUE_NOT_CONFIRMED').slice(0, 80) }, null, 2) + '\n');
    process.stderr.write('Continuation queue failed; see the local status file. No agent was launched.\n');
    process.exitCode = 1;
} finally {
    closeSync(lock);
    unlinkSync(lockPath);
}
