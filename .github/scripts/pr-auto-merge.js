// Auto Merge for PRs labeled "Auto Merge":
// 1) clean review → squash-merge
// 2) Claude findings with a ```suggestion``` fence → apply in Actions (commit + push)
// 3) remaining findings → Cursor Cloud Agent (max 3 loops)
// Invoked from .github/workflows/claude-code-review.yml after the review check.

const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');

const AUTO_MERGE_LABEL = 'Auto Merge';
// Cloud Agent rounds (each = one agent run that pushes commits).
const MAX_FIX_ATTEMPTS = 3;
// Every automated push (suggestion apply or agent) counts here — guards against a
// reviewer/fixer ping-pong that never converges.
const MAX_AUTOMATED_ROUNDS = 5;
const LOOP_MARKER = '<!-- auto-merge-fix-loop -->';
const SUGGESTION_MARKER = '<!-- auto-merge-apply-suggestions -->';
const CLAUDE_LOGIN = /claude/i;
const CURSOR_API = 'https://api.cursor.com/v1';
// Check runs produced by this workflow itself — never a merge gate for itself.
const OWN_CHECK_NAMES = new Set(['Run review', 'Claude Code Review', 'Auto Merge']);
const CHECKS_WAIT_MS = 18 * 60 * 1000;
const CHECKS_POLL_MS = 15000;

/**
 * @param {{ github: import('@octokit/rest').Octokit, context: import('@actions/github').Context, core: import('@actions/core') }} args
 */
module.exports = async function prAutoMerge({ github, context, core }) {
    const owner = context.repo.owner;
    const repo = context.repo.repo;
    const prNumber = context.payload.pull_request.number;
    const reviewResult = process.env.REVIEW_RESULT;
    const cursorApiKey = (process.env.CURSOR_API_KEY || '').trim();
    const inspectorToken = (process.env.MCP_INSPECTOR_TOKEN || '').trim();
    // Pushes made with GITHUB_TOKEN never trigger `pull_request: synchronize`, so an
    // applied suggestion would sit un-reviewed forever. Direct apply needs a PAT / App token.
    const pushToken = (process.env.AUTO_MERGE_PUSH_TOKEN || '').trim();
    const requiredChecks = (process.env.AUTO_MERGE_REQUIRED_CHECKS || '')
        .split(',')
        .map((name) => name.trim())
        .filter(Boolean);

    const { data: pr } = await github.rest.pulls.get({ owner, repo, pull_number: prNumber });

    if (pr.merged || pr.state !== 'open') {
        core.info('PR is not open — nothing to do.');
        return;
    }

    if (!pr.labels.some((label) => label.name === AUTO_MERGE_LABEL)) {
        core.info('No Auto Merge label — skip.');
        return;
    }

    if (pr.head.repo.full_name !== `${owner}/${repo}`) {
        await comment(github, owner, repo, prNumber, 'Auto Merge: fork PR — slučovat ani posílat Cloud Agent nebudu.');
        return;
    }

    if (reviewResult !== 'success') {
        await comment(
            github,
            owner,
            repo,
            prNumber,
            [
                'Auto Merge: Claude review (job Run review) nedoběhl — neslučuji ani nespouštím Cloud Agent.',
                '',
                'Až půjde review znovu spustit (nový push na větev PR), Auto Merge pokračuje sám.',
            ].join('\n'),
        );
        return;
    }

    // Claude re-runs on every push. Comments left from an older head are stale: GitHub often
    // keeps them non-outdated even after a fix. Close those, then only act on findings posted
    // against the current head (Claude re-comments when the issue is still real).
    const headSha = pr.head.sha;
    await resolveStaleClaudeThreads(github, core, owner, repo, prNumber, headSha);

    const findings = await currentClaudeFindings(github, owner, repo, prNumber, headSha);
    core.info(`Unresolved Claude findings on head ${headSha.slice(0, 7)}: ${findings.length}`);

    if (await claudeVerificationFailed(github, owner, repo, prNumber)) {
        await comment(
            github,
            owner,
            repo,
            prNumber,
            [
                'Auto Merge: Claude hlásí selhání produkční verifikace (Inspector) — neslučuji.',
                '',
                'Až půjde Inspector znovu a review doběhne čistě, Auto Merge pokračuje sám.',
            ].join('\n'),
        );
        core.setFailed('Claude review reported Inspector verification failure.');
        return;
    }

    // A human "Request changes" always outranks the bots — never merge or auto-fix over it.
    const blocker = await humanChangesRequested(github, owner, repo, prNumber);
    if (blocker) {
        core.info(`Human CHANGES_REQUESTED by ${blocker} — Auto Merge paused.`);
        await commentOnce(
            github,
            owner,
            repo,
            prNumber,
            `<!-- auto-merge-human-block:${headSha} -->`,
            `Auto Merge: @${blocker} má na PR „Request changes“ — neslučuji ani nespouštím opravy, dokud review nezmění.`,
        );
        return;
    }

    if (findings.length === 0) {
        await mergeWhenClean({ github, core, owner, repo, pr, prNumber, headSha, requiredChecks, runId: context.runId });
        return;
    }

    const comments = await github.paginate(github.rest.issues.listComments, {
        owner,
        repo,
        issue_number: prNumber,
    });
    const agentRounds = comments.filter((item) => (item.body ?? '').includes(LOOP_MARKER)).length;
    const suggestionRounds = comments.filter((item) => (item.body ?? '').includes(SUGGESTION_MARKER)).length;

    if (agentRounds + suggestionRounds >= MAX_AUTOMATED_ROUNDS) {
        await comment(
            github,
            owner,
            repo,
            prNumber,
            [
                `Auto Merge: ${MAX_AUTOMATED_ROUNDS} automatických kol (suggestion + Cloud Agent) a review pořád není čisté.`,
                'Zastavuji smyčku — potřebuje to člověka.',
            ].join('\n'),
        );
        core.info('Max automated rounds reached.');
        return;
    }

    const withSuggestion = findings.filter((finding) => finding.suggestion !== null);
    const withoutSuggestion = findings.filter((finding) => finding.suggestion === null);

    if (withSuggestion.length > 0 && !pushToken) {
        core.info('AUTO_MERGE_PUSH_TOKEN missing — suggestions go to the Cloud Agent instead of a direct push.');
    }

    if (withSuggestion.length > 0 && pushToken) {
        try {
            const applied = applyClaudeSuggestions({
                core,
                workspace: process.env.GITHUB_WORKSPACE,
                branch: process.env.PR_HEAD_REF || pr.head.ref,
                findings: withSuggestion,
            });
            await comment(
                github,
                owner,
                repo,
                prNumber,
                [
                    SUGGESTION_MARKER,
                    `Auto Merge: aplikoval jsem ${applied} Claude suggestion(s) přímo (bez Cloud Agentu).`,
                    '',
                    ...withSuggestion.map((finding) => `- \`${finding.path}:${finding.startLine}-${finding.line}\` — ${finding.html_url}`),
                    '',
                    withoutSuggestion.length === 0
                        ? 'Všechny námitky měly suggestion — čekám na nové review po pushi.'
                        : [
                            `Ještě ${withoutSuggestion.length} námitek bez suggestion.`,
                            'Ty pošlu Cloud Agentu až po novém review (push teď restartuje workflow).',
                        ].join(' '),
                ].join('\n'),
            );
            // Do not start the Cloud Agent in this same run: pushing suggestions fires
            // synchronize, and concurrency cancel-in-progress would kill this job mid-launch.
            // Remaining findings are re-posted on the new head if still valid.
            core.info('Suggestions pushed — waiting for re-review before any Cloud Agent.');
            return;
        } catch (error) {
            core.warning(`Apply suggestions failed: ${error.message}`);
            await comment(
                github,
                owner,
                repo,
                prNumber,
                [
                    SUGGESTION_MARKER,
                    'Auto Merge: přímá aplikace suggestion selhala — posílám všechny námitky Cloud Agentu.',
                    '',
                    `Důvod: \`${error.message}\``,
                ].join('\n'),
            );
            // Fall through — agent gets every finding; nothing was pushed.
        }
    }

    try {
        await launchFixAgent({
            github,
            core,
            owner,
            repo,
            pr,
            prNumber,
            findings,
            cursorApiKey,
            inspectorToken,
            attempts: agentRounds,
        });
    } catch (error) {
        await comment(
            github,
            owner,
            repo,
            prNumber,
            [
                'Auto Merge: nespustil se Cursor Cloud Agent.',
                '',
                `Důvod: \`${error.message}\``,
            ].join('\n'),
        );
        core.setFailed(error.message);
    }
};

/**
 * Same finding set as the Auto Merge job — used by the review check.
 * Does not resolve threads (check job is pull-requests:read only).
 * @param {{ github: import('@octokit/rest').Octokit, context: import('@actions/github').Context }} args
 */
module.exports.collectFindings = async function collectFindings({ github, context }) {
    const pr = context.payload.pull_request;
    return currentClaudeFindings(
        github,
        context.repo.owner,
        context.repo.repo,
        pr.number,
        pr.head.sha,
    );
};

/**
 * Findings Claude actually posted on the current head commit.
 * Older unresolved threads (often still !isOutdated after a fix) are ignored.
 */
async function currentClaudeFindings(github, owner, repo, prNumber, headSha) {
    const threads = await listReviewThreads(github, owner, repo, prNumber);

    return threads.flatMap((thread) => {
        if (thread.isResolved || thread.isOutdated) {
            return [];
        }
        const first = thread.comments?.nodes?.[0];
        const login = first?.author?.login ?? '';
        if (!CLAUDE_LOGIN.test(login)) {
            return [];
        }
        if (!isFindingOnHead(first, headSha)) {
            return [];
        }

        return [{
            path: first.path ?? '?',
            line: first.line ?? first.originalLine ?? null,
            startLine: first.startLine ?? first.originalStartLine ?? first.line ?? first.originalLine ?? null,
            body: first.body ?? '',
            html_url: first.url ?? '',
            suggestion: extractSuggestion(first.body ?? ''),
        }];
    });
}

/**
 * @param {string} body
 * @returns {string|null} replacement text (may be empty = delete lines), or null if no fence
 */
function extractSuggestion(body) {
    const match = /```suggestion\r?\n([\s\S]*?)```/.exec(body);
    if (!match) {
        return null;
    }

    return match[1];
}

/**
 * Apply GitHub ```suggestion``` fences to the checked-out PR head and push.
 * @returns {number} how many suggestions were written
 */
function applyClaudeSuggestions({ core, workspace, branch, findings }) {
    if (!workspace || !fs.existsSync(workspace)) {
        throw new Error('GITHUB_WORKSPACE missing — cannot apply suggestions.');
    }
    if (!branch) {
        throw new Error('PR head branch missing — cannot push suggestions.');
    }

    // Sort bottom-up so earlier line numbers stay valid after each edit.
    const ordered = [...findings].sort((a, b) => {
        if (a.path !== b.path) {
            return String(a.path).localeCompare(String(b.path));
        }
        return Number(b.startLine) - Number(a.startLine);
    });

    for (const finding of ordered) {
        applyOneSuggestion(workspace, finding);
        core.info(`Applied suggestion at ${finding.path}:${finding.startLine}-${finding.line}`);
    }

    git(workspace, ['config', 'user.name', 'github-actions[bot]']);
    git(workspace, ['config', 'user.email', '41898282+github-actions[bot]@users.noreply.github.com']);
    git(workspace, ['add', '-A']);

    const status = git(workspace, ['status', '--porcelain'], { stdio: 'pipe' }).toString().trim();
    if (!status) {
        throw new Error('Suggestions produced no file changes (paths/line ranges may be stale).');
    }

    const paths = [...new Set(ordered.map((finding) => finding.path))].join(', ');
    git(workspace, [
        'commit',
        '-m',
        `Apply Claude review suggestion(s)\n\nAuto-applied from PR review on: ${paths}`,
    ]);
    git(workspace, ['push', 'origin', `HEAD:${branch}`]);
    core.info(`Pushed ${ordered.length} suggestion(s) to ${branch}`);

    return ordered.length;
}

/**
 * @param {string} workspace
 * @param {{ path: string, line: number|null, startLine: number|null, suggestion: string }} finding
 */
function applyOneSuggestion(workspace, finding) {
    const rel = finding.path;
    if (!rel || rel === '?' || path.isAbsolute(rel) || rel.includes('..')) {
        throw new Error(`Refusing unsafe suggestion path: ${rel}`);
    }

    const filePath = path.join(workspace, rel);
    if (!fs.existsSync(filePath)) {
        throw new Error(`Suggestion target missing: ${rel}`);
    }

    const startLine = Number(finding.startLine);
    const endLine = Number(finding.line);
    if (!Number.isInteger(startLine) || !Number.isInteger(endLine) || startLine < 1 || endLine < startLine) {
        throw new Error(`Invalid line range for ${rel}: ${finding.startLine}-${finding.line}`);
    }

    const raw = fs.readFileSync(filePath, 'utf8');
    const nl = raw.includes('\r\n') ? '\r\n' : '\n';
    const endedWithNewline = /\r?\n$/.test(raw);
    const lines = raw.split(/\r?\n/);
    if (endedWithNewline && lines[lines.length - 1] === '') {
        lines.pop();
    }

    if (endLine > lines.length) {
        throw new Error(`Suggestion past EOF for ${rel}: end ${endLine}, file has ${lines.length} lines`);
    }

    let replacement = finding.suggestion.replace(/\r\n/g, '\n');
    if (replacement.endsWith('\n')) {
        replacement = replacement.slice(0, -1);
    }
    const newLines = replacement === '' ? [] : replacement.split('\n');

    const next = [
        ...lines.slice(0, startLine - 1),
        ...newLines,
        ...lines.slice(endLine),
    ];

    let out = next.join(nl);
    if (endedWithNewline || raw === '') {
        out += nl;
    }
    fs.writeFileSync(filePath, out);
}

function git(workspace, args, options = {}) {
    return execFileSync('git', args, {
        cwd: workspace,
        stdio: options.stdio ?? 'inherit',
        encoding: options.stdio === 'pipe' ? 'utf8' : undefined,
    });
}

/**
 * True when the latest Claude issue comment says production verification failed.
 * Inline findings alone are not enough — Claude posts only a summary when Inspector is down,
 * and Auto Merge would otherwise treat "0 findings" as clean and squash-merge.
 */
async function claudeVerificationFailed(github, owner, repo, prNumber) {
    const comments = await github.paginate(github.rest.issues.listComments, {
        owner,
        repo,
        issue_number: prNumber,
        per_page: 100,
    });

    const latest = [...comments].reverse().find((comment) => CLAUDE_LOGIN.test(comment.user?.login ?? ''));
    if (!latest) {
        return false;
    }

    return /Inspector unreachable|Verified failed/i.test(latest.body ?? '');
}

function isFindingOnHead(comment, headSha) {
    const originalOid = comment?.originalCommit?.oid ?? null;
    // Prefer originalCommit: GitHub rewrites commit.oid to the latest head while the
    // comment still belongs to the review that ran on an older SHA.
    if (originalOid) {
        return originalOid === headSha;
    }
    return (comment?.commit?.oid ?? null) === headSha;
}

async function listReviewThreads(github, owner, repo, number) {
    const threads = [];
    let cursor = null;
    let hasNext = true;

    while (hasNext) {
        const data = await github.graphql(
            `query($owner: String!, $repo: String!, $number: Int!, $cursor: String) {
                repository(owner: $owner, name: $repo) {
                    pullRequest(number: $number) {
                        reviewThreads(first: 50, after: $cursor) {
                            pageInfo { hasNextPage endCursor }
                            nodes {
                                id
                                isResolved
                                isOutdated
                                comments(first: 1) {
                                    nodes {
                                        author { login }
                                        body
                                        path
                                        url
                                        line
                                        startLine
                                        originalLine
                                        originalStartLine
                                        commit { oid }
                                        originalCommit { oid }
                                    }
                                }
                            }
                        }
                    }
                }
            }`,
            { owner, repo, number, cursor },
        );

        const connection = data.repository?.pullRequest?.reviewThreads;
        threads.push(...(connection?.nodes ?? []));
        hasNext = Boolean(connection?.pageInfo?.hasNextPage);
        cursor = connection?.pageInfo?.endCursor ?? null;
    }

    return threads;
}

/**
 * Review is clean → still gate on deterministic CI before touching master.
 * The repo has no branch protection (private, Free plan), so `pulls.merge` would otherwise
 * succeed with failing or still-running checks. This is the only place that enforces
 * "green CI before merge".
 */
async function mergeWhenClean({ github, core, owner, repo, pr, prNumber, headSha, requiredChecks, runId }) {
    try {
        await waitForGreenChecks({ github, core, owner, repo, headSha, requiredChecks, runId });
        await waitUntilMergeable(github, core, owner, repo, prNumber);
    } catch (error) {
        await comment(
            github,
            owner,
            repo,
            prNumber,
            [
                'Auto Merge: review je čisté, ale neslučuji.',
                '',
                `Důvod: \`${error.message}\``,
                '',
                'Oprav / re-runni check a pushni — Auto Merge pokračuje sám.',
            ].join('\n'),
        );
        core.setFailed(`Merge gate failed: ${error.message}`);
        return;
    }

    await resolveClaudeThreads(github, owner, repo, prNumber);

    try {
        await squashMerge(github, owner, repo, pr, prNumber);
        await maybeDispatchChatbotBuild(github, core, owner, repo, prNumber);
        await comment(github, owner, repo, prNumber, 'Auto Merge: review bez námitek, CI zelené — sloučeno (squash).');
        core.info('Squash-merged.');
    } catch (error) {
        await comment(
            github,
            owner,
            repo,
            prNumber,
            [
                'Auto Merge: review je čisté, ale GitHub teď nesloučí.',
                '',
                `Důvod: \`${error.message}\``,
            ].join('\n'),
        );
        core.setFailed(`Could not merge: ${error.message}`);
    }
}

/**
 * Wait until every check run on the head SHA (except this workflow's own) has finished
 * successfully. `requiredChecks` (repo var AUTO_MERGE_REQUIRED_CHECKS) must also be present —
 * a missing required check (workflow disabled, filtered out) is a failure, not a pass.
 */
async function waitForGreenChecks({ github, core, owner, repo, headSha, requiredChecks, runId }) {
    const deadline = Date.now() + CHECKS_WAIT_MS;
    const ownRunUrl = `/actions/runs/${runId}`;

    while (Date.now() < deadline) {
        const runs = await github.paginate(github.rest.checks.listForRef, {
            owner,
            repo,
            ref: headSha,
            per_page: 100,
        });

        const external = runs.filter(
            (run) => !OWN_CHECK_NAMES.has(run.name) && !(run.details_url ?? '').includes(ownRunUrl),
        );

        const failed = external.filter(
            (run) => run.status === 'completed' && !['success', 'neutral', 'skipped'].includes(run.conclusion ?? ''),
        );
        if (failed.length > 0) {
            throw new Error(`CI check(s) failed: ${failed.map((run) => `${run.name}=${run.conclusion}`).join(', ')}`);
        }

        const pending = external.filter((run) => run.status !== 'completed');
        const missingRequired = requiredChecks.filter(
            (name) => !external.some((run) => run.name === name),
        );

        if (pending.length === 0 && missingRequired.length === 0) {
            core.info(`CI green: ${external.length} external check(s) on ${headSha.slice(0, 7)}`);
            return;
        }

        core.info(
            `Waiting for CI — pending: [${pending.map((run) => run.name).join(', ')}]`
            + ` missing required: [${missingRequired.join(', ')}]`,
        );
        await sleep(CHECKS_POLL_MS);
    }

    throw new Error(`CI checky nedoběhly včas (${CHECKS_WAIT_MS / 60000} min) — required: ${requiredChecks.join(', ') || 'žádné'}.`);
}

/**
 * @returns {string|null} login of the human whose latest review is CHANGES_REQUESTED
 */
async function humanChangesRequested(github, owner, repo, prNumber) {
    const reviews = await github.paginate(github.rest.pulls.listReviews, {
        owner,
        repo,
        pull_number: prNumber,
        per_page: 100,
    });

    const latestByUser = new Map();
    for (const review of reviews) {
        const login = review.user?.login ?? '';
        if (!login || review.user?.type === 'Bot' || CLAUDE_LOGIN.test(login)) {
            continue;
        }
        if (!['APPROVED', 'CHANGES_REQUESTED', 'DISMISSED'].includes(review.state)) {
            continue;
        }
        latestByUser.set(login, review.state);
    }

    for (const [login, state] of latestByUser) {
        if (state === 'CHANGES_REQUESTED') {
            return login;
        }
    }

    return null;
}

function sleep(ms) {
    return new Promise((resolve) => setTimeout(resolve, ms));
}

async function squashMerge(github, owner, repo, pr, prNumber) {
    await github.rest.pulls.merge({
        owner,
        repo,
        pull_number: prNumber,
        merge_method: 'squash',
        commit_title: `${pr.title} (#${prNumber})`,
    });
}

/**
 * Merges via GITHUB_TOKEN do not fire path-filtered `push` workflows (GitHub security).
 * Gramína harvest lives in chat-agent — without an explicit dispatch the PHP harvester
 * ships alone and tickets stick on "unsupported" until someone remembers to rebuild.
 */
async function maybeDispatchChatbotBuild(github, core, owner, repo, prNumber) {
    const files = await github.paginate(github.rest.pulls.listFiles, {
        owner,
        repo,
        pull_number: prNumber,
        per_page: 100,
    });
    const needsChatbot = files.some((file) => {
        const path = file.filename;
        return (
            path.startsWith('chat-agent/') ||
            path.startsWith('ops/chat-agent/') ||
            path === 'docker/chat-agent.prod.dockerfile' ||
            path === '.github/workflows/build-chatbot.yml' ||
            path === '.github/workflows/deploy-chatbot.yml'
        );
    });
    if (!needsChatbot) {
        return;
    }

    await github.rest.actions.createWorkflowDispatch({
        owner,
        repo,
        workflow_id: 'build-chatbot.yml',
        ref: 'master',
    });
    core.info('Dispatched Build chatbot after Auto Merge (chat-agent paths changed).');
}

async function waitUntilMergeable(github, core, owner, repo, prNumber) {
    const deadline = Date.now() + 18 * 60 * 1000;

    while (Date.now() < deadline) {
        const { data } = await github.rest.pulls.get({ owner, repo, pull_number: prNumber });
        const state = data.mergeable_state;
        core.info(`mergeable_state=${state}`);

        if (data.merged) {
            return;
        }
        if (state === 'dirty') {
            throw new Error('PR má konflikty s masterem.');
        }
        // External CI is already gated by waitForGreenChecks. GitHub's `unstable` also
        // covers this workflow's own jobs: Auto Merge still running, a previous Auto Merge
        // failure on the same SHA, and cancelled runs from concurrency cancel-in-progress.
        // Treating that as red deadlocks a clean review. `behind` is fine for squash without
        // branch protection. Without protection GitHub would merge `unstable` anyway.
        if (
            data.mergeable
            && (state === 'clean' || state === 'has_hooks' || state === 'unstable' || state === 'behind')
        ) {
            return;
        }

        await new Promise((resolve) => setTimeout(resolve, 15000));
    }

    throw new Error('Povinné checky nedoběhly včas (18 min).');
}

async function resolveClaudeThreads(github, owner, repo, prNumber) {
    const threads = await listReviewThreads(github, owner, repo, prNumber);

    for (const thread of threads) {
        if (thread.isResolved) {
            continue;
        }
        const login = thread.comments?.nodes?.[0]?.author?.login ?? '';
        if (!CLAUDE_LOGIN.test(login)) {
            continue;
        }
        await resolveReviewThread(github, thread.id);
    }
}

/**
 * Close Claude threads that were filed on an older head. After a fix push + re-review,
 * Claude posts again only when the issue remains; leftover threads would otherwise
 * keep the Auto Merge fix loop alive forever.
 */
async function resolveStaleClaudeThreads(github, core, owner, repo, prNumber, headSha) {
    const threads = await listReviewThreads(github, owner, repo, prNumber);
    let resolved = 0;

    for (const thread of threads) {
        if (thread.isResolved) {
            continue;
        }
        const first = thread.comments?.nodes?.[0];
        const login = first?.author?.login ?? '';
        if (!CLAUDE_LOGIN.test(login)) {
            continue;
        }
        if (isFindingOnHead(first, headSha)) {
            continue;
        }
        await resolveReviewThread(github, thread.id);
        resolved += 1;
    }

    if (resolved > 0) {
        core.info(`Resolved ${resolved} stale Claude thread(s) from older commits.`);
    }
}

async function resolveReviewThread(github, threadId) {
    await github.graphql(
        `mutation($id: ID!) {
            resolveReviewThread(input: { threadId: $id }) { thread { isResolved } }
        }`,
        { id: threadId },
    );
}

async function launchFixAgent({
    github,
    core,
    owner,
    repo,
    pr,
    prNumber,
    findings,
    cursorApiKey,
    inspectorToken,
    attempts,
}) {
    if (!cursorApiKey) {
        await comment(
            github,
            owner,
            repo,
            prNumber,
            'Auto Merge: review má námitky, ale chybí secret `CURSOR_API_KEY` — Cloud Agent nespustím.',
        );
        core.setFailed('CURSOR_API_KEY is empty');
        return;
    }

    const comments = await github.paginate(github.rest.issues.listComments, {
        owner,
        repo,
        issue_number: prNumber,
    });

    if (attempts >= MAX_FIX_ATTEMPTS) {
        await comment(
            github,
            owner,
            repo,
            prNumber,
            [
                'Auto Merge: po 3 pokusech Cloud Agentu pořád zbývají námitky.',
                'Zastavuji smyčku — potřebuje to člověka. Štítek můžeš sundat, nebo opravit ručně a nechat review znovu doběhnout.',
            ].join('\n'),
        );
        core.info('Max fix attempts reached.');
        return;
    }

    const prUrl = pr.html_url;
    const repoUrl = `https://github.com/${owner}/${repo}`;
    const existing = await cursorGet(
        `${CURSOR_API}/agents?prUrl=${encodeURIComponent(prUrl)}&includeArchived=false`,
        cursorApiKey,
    );
    const agents = existing.items ?? [];

    const active = agents.find((agent) => agent.status === 'ACTIVE');
    if (active) {
        const alreadyNoted = comments.some((item) => (item.body ?? '').includes(active.url ?? active.id));
        if (!alreadyNoted) {
            await comment(
                github,
                owner,
                repo,
                prNumber,
                `Auto Merge: Cloud Agent už na tomhle PR běží: ${active.url}\nNespouštím dalšího.`,
            );
        }
        core.info(`Agent already ACTIVE: ${active.id}`);
        return;
    }

    const findingBlocks = findings.map((finding, index) => {
        const range = finding.startLine && finding.startLine !== finding.line
            ? `${finding.path}:${finding.startLine}-${finding.line}`
            : `${finding.path}:${finding.line}`;
        return [
            `### Finding ${index + 1} — \`${range}\``,
            finding.html_url,
            '',
            finding.body.trim(),
        ].join('\n');
    });

    const promptText = [
        'You are fixing review findings on an EXISTING pull request.',
        'Do not open a new pull request. Do not create a new branch. Commit and push on this PR head branch only.',
        '',
        `PR: ${prUrl}`,
        `Head branch: ${pr.head.ref}`,
        '',
        'Fix ONLY the findings below. Keep the original task scope.',
        'Follow repo rules in AGENTS.md and CLAUDE.md. Do not add tests unless a finding requires them.',
        'Do not merge the PR. Do not remove the Auto Merge label.',
        '',
        'If a finding includes a fenced ```suggestion``` block, apply that exact text as the',
        'replacement for the cited line range — do not reinvent the fix.',
        'Otherwise follow the Fix: line.',
        '',
        'Findings:',
        ...findingBlocks,
    ].join('\n');

    const mcpServers = inspectorToken
        ? [
            {
                name: 'inspector',
                type: 'http',
                url: 'https://www.gramodesky.cz/mcp/inspector',
                headers: { Authorization: `Bearer ${inspectorToken}` },
            },
        ]
        : undefined;

    const idle = agents.find((agent) => agent.status === 'IDLE');
    let agentUrl;
    let agentId;

    if (idle) {
        const followUp = { prompt: { text: promptText } };
        if (mcpServers) {
            followUp.mcpServers = mcpServers;
        }
        await cursorPost(`${CURSOR_API}/agents/${idle.id}/runs`, cursorApiKey, followUp);
        agentUrl = idle.url;
        agentId = idle.id;
        core.info(`Resumed idle agent ${idle.id}`);
    } else {
        const body = {
            prompt: { text: promptText },
            name: `Fix review on PR #${prNumber}`.slice(0, 100),
            repos: [{ url: repoUrl, prUrl }],
            workOnCurrentBranch: true,
            autoCreatePR: false,
            skipReviewerRequest: true,
        };
        if (mcpServers) {
            body.mcpServers = mcpServers;
        }

        const created = await cursorPost(`${CURSOR_API}/agents`, cursorApiKey, body);
        agentId = created.agent?.id;
        agentUrl = created.agent?.url ?? `https://cursor.com/agents/${agentId}`;
        core.info(`Started cloud agent ${agentId}`);
    }

    await comment(
        github,
        owner,
        repo,
        prNumber,
        [
            LOOP_MARKER,
            `Auto Merge: review má ${findings.length} námitek. Spouštím Cursor Cloud Agent (pokus ${attempts + 1}/${MAX_FIX_ATTEMPTS}).`,
            '',
            agentUrl,
            '',
            'Až agent pushne commity, review se spustí znovu. Když bude čisté, PR se sloučí.',
        ].join('\n'),
    );
}

async function comment(github, owner, repo, prNumber, body) {
    await github.rest.issues.createComment({
        owner,
        repo,
        issue_number: prNumber,
        body,
    });
}

/** Post once per marker — re-runs on the same head must not spam the PR. */
async function commentOnce(github, owner, repo, prNumber, marker, body) {
    const comments = await github.paginate(github.rest.issues.listComments, {
        owner,
        repo,
        issue_number: prNumber,
        per_page: 100,
    });
    if (comments.some((item) => (item.body ?? '').includes(marker))) {
        return;
    }
    await comment(github, owner, repo, prNumber, `${marker}\n${body}`);
}

async function cursorGet(url, apiKey) {
    return cursorFetch(url, apiKey, { method: 'GET' });
}

async function cursorPost(url, apiKey, body) {
    return cursorFetch(url, apiKey, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body),
    });
}

async function cursorFetch(url, apiKey, init) {
    const response = await fetch(url, {
        ...init,
        headers: {
            Authorization: `Basic ${Buffer.from(`${apiKey}:`).toString('base64')}`,
            ...(init.headers ?? {}),
        },
    });
    const text = await response.text();
    let data;
    try {
        data = text ? JSON.parse(text) : {};
    } catch {
        data = { raw: text };
    }
    if (!response.ok) {
        throw new Error(`Cursor API ${response.status}: ${text.slice(0, 500)}`);
    }
    return data;
}
