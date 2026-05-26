function createMicrosoftProvider(deps) {
    const { cfg, typeHuman, clickAndWait, clickSmart, findPasswordFieldAcrossFrames, clickSubmitNearPasswordField } = deps;
    const { waitForAny, clearAndType } = require('./utils');

    // Expose this helper so consolidated.js can use it
    const checkWorkOrSchool = async (page) => {
        // console.log('[Microsoft] Checking for "Work or school" prompt...');
        const aadTile = await page.$('#aadTile').catch(() => null);
        if (aadTile) {
            console.log('[Microsoft] Found #aadTile, clicking...');
            // Race: It might redirect OR just show password field on same page
            const passwordSelector = '#i0118, input[type="password"]';
            const navPromise = page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 10000 }).catch(() => 'nav');
            const passPromise = page.waitForSelector(passwordSelector, { timeout: 10000 }).catch(() => 'pass');
            
            await aadTile.click().catch(() => {});
            await Promise.race([navPromise, passPromise]);
            console.log('[Microsoft] Clicked #aadTile and waited for outcome.');
            return true;
        }
        
        // Text-based fallback
        const clicked = await page.evaluate(() => {
            const textMatches = (el, needle) => {
                const t = (el && el.textContent ? el.textContent : '').trim().toLowerCase();
                return t.includes(needle);
            };
            const all = Array.from(document.querySelectorAll('button, a, div[role="button"], input[type="button"], input[type="submit"], div.table-row'));
            const target = all.find(el => textMatches(el, 'work or school'));
            if (!target) return false;
            target.click();
            return true;
        }).catch(() => false);
        
        if (clicked) {
            console.log('[Microsoft] Found "Work or school" text element, clicked.');
            await page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 10000 }).catch(() => {});
            return true;
        }
        return false;
    };

    const chooseWorkOrSchoolIfPrompted = async (page) => {
        return await checkWorkOrSchool(page);
    };

    const submitEmailIfPresent = async (page, email) => {
        console.log('[Microsoft] Looking for email input...');

        // Check if password input is already present (which means we are past email)
        const passwordField = await findPasswordFieldAcrossFrames(page);
        if (passwordField) {
            console.log('[Microsoft] Password input detected! Skipping email submission.');
            await passwordField.handle.dispose().catch(() => {});
            return true;
        }

        // Optimized: Parallel wait for email input
        const emailSelectors = ['input[type="email"]', 'input[name="loginfmt"]', '#i0116'];
        let found = null;
        try {
             // Use Promise.any if available, otherwise sequential fallback
             if (Promise.any) {
                 found = await Promise.any(emailSelectors.map(s => 
                    page.waitForSelector(s, { visible: true, timeout: 10000 })
                        .then(h => ({ selector: s, handle: h }))
                ));
             } else {
                 found = await waitForAny(page, emailSelectors, 10000);
             }
        } catch (e) { found = null; }

        if (!found) {
            console.log('[Microsoft] Email input NOT found');
            return false;
        }
        console.log(`[Microsoft] Email input found: ${found.selector}`);
        const selector = found.selector;
        await found.handle.dispose().catch(() => {});
        await clearAndType(page, selector, email, typeHuman);
        console.log('[Microsoft] Email typed, clicking submit...');
        
        // Smarter submission: Race between navigation (success) and error appearance (failure)
        const submitBtnSelector = 'input[type="submit"], #idSIButton9';
        const errorSelector = '#usernameError, .error, .alert-error';
        
        try {
            await Promise.race([
                clickAndWait(page, clickSmart(page, submitBtnSelector), cfg.navTimeoutMs),
                page.waitForSelector(errorSelector, { visible: true, timeout: 5000 })
            ]);
        } catch (e) {
            // Ignore timeout errors from the race losers
        }
        
        console.log('[Microsoft] Email submitted');

        // Check for error messages immediately
        const errorEl = await page.$(errorSelector);
        if (errorEl) {
            const errText = await page.evaluate(el => el.textContent, errorEl);
            console.log(`[Microsoft] ❌ Email error detected: ${errText}`);
            return false;
        }

        return true;
    };

    const enterPasswordAndSubmit = async (page, password) => {
        console.log('[Microsoft] Looking for password input...');
        let found = null;
        for (let i = 0; i < 8; i++) {
            found = await findPasswordFieldAcrossFrames(page);
            if (found) {
                console.log(`[Microsoft] Password input found on attempt ${i+1}`);
                break;
            }
            await new Promise(r => setTimeout(r, 750));
        }

        if (!found) {
            console.log('[Microsoft] Password input NOT found after attempts.');
            console.log('[Microsoft] STOPPING WORKER.');
            process.exit(1);
        }

        await new Promise(r => setTimeout(r, 300));

        try {
            console.log('[Microsoft] Typing password...');
            await found.handle.click({ clickCount: 3 }).catch(() => {});
            await found.handle.type(password, { delay: Math.floor(Math.random() * 20) + 10 });
        } catch (e) {
            console.log(`[Microsoft] Error typing password: ${e.message}, retrying...`);
            const msg = String(e && e.message ? e.message : e);
            if (msg.includes('detached Frame') || msg.includes('Execution context was destroyed')) {
                await new Promise(r => setTimeout(r, 1000));
                const refound = await findPasswordFieldAcrossFrames(page);
                if (!refound) return false;
                await found.handle.dispose().catch(() => {});
                found = refound;
                await found.handle.click({ clickCount: 3 }).catch(() => {});
                await found.handle.type(password, { delay: Math.floor(Math.random() * 20) + 10 });
            } else {
                throw e;
            }
        }

        // console.log('[Microsoft] Password typed, submitting...');
        if (await page.$('#idSIButton9')) {
            await clickAndWait(page, clickSmart(page, '#idSIButton9'), cfg.navTimeoutMs);
        } else {
            const clicked = await clickSubmitNearPasswordField(page, found);
            if (!clicked) {
                await clickAndWait(page, clickSmart(page, 'button[type="submit"], input[type="submit"]'), cfg.navTimeoutMs);
            }
        }
        
        // console.log('[Microsoft] Password submitted');
        await found.handle.dispose().catch(() => {});
        return true;
    };

    return {
        name: 'microsoft',
        checkWorkOrSchool, // Exposed method
        detect: async (page) => {
            const url = String(page.url() || '');
            if (url.includes('login.microsoftonline.com') || url.includes('login.live.com')) return true;
            const found = await page.$('input[name="loginfmt"], #i0116, #i0118, #idSIButton9').catch(() => null);
            if (found) {
                await found.dispose().catch(() => {});
                return true;
            }
            return false;
        },
        login: async ({ page, email, password, simulate }) => {
            console.log(`[Microsoft] Login called (simulate=${simulate})`);

            if (simulate) {
                await submitEmailIfPresent(page, email);
                // Check for prompt AFTER email submission
                await chooseWorkOrSchoolIfPrompted(page);
                
                console.log('[Microsoft] Simulate mode: finishing email phase');
                return { passwordAttempted: false, ok: true };
            }

            // Real mode (Password Phase)
            // Check for prompt in case we are stuck there
            await chooseWorkOrSchoolIfPrompted(page);

            // Check if we redirected to an external provider (like GoDaddy)
            const currentUrl = page.url().toLowerCase();
            if (currentUrl.includes('godaddy') || currentUrl.includes('secureserver')) {
                console.log(`[Microsoft] Redirected to external provider (${currentUrl}). Skipping Microsoft password entry.`);
                return { passwordAttempted: false, ok: true };
            }
            
            // Wait for password field (loop) instead of immediate check
            let passwordField = null;
            for (let i = 0; i < 20; i++) { // Wait up to 10 seconds
                // Constant check for redirect
                const u = page.url().toLowerCase();
                if (u.includes('godaddy') || u.includes('secureserver')) {
                    console.log('[Microsoft] Redirected to GoDaddy during password wait. Aborting Microsoft login.');
                    return { passwordAttempted: false, ok: true };
                }

                passwordField = await findPasswordFieldAcrossFrames(page);
                if (passwordField) break;
                await new Promise(r => setTimeout(r, 500));
            }

            if (!passwordField) {
                 console.log('[Microsoft] ❌ Password field NOT found in password phase (after wait).');
                 console.log('[Microsoft] Current URL:', page.url());
                 const bodyText = await page.evaluate(() => document.body.innerText).catch(() => '');
                 console.log('[Microsoft] Body text snippet:', bodyText.substring(0, 200).replace(/\n/g, ' '));
                 process.exit(1);
            } else {
                 // Verify we are still on a Microsoft domain before assuming this is the correct field
                 const u = page.url().toLowerCase();
                 if (u.includes('godaddy') || u.includes('secureserver')) {
                     console.log('[Microsoft] Detected GoDaddy URL after finding password field. Aborting Microsoft login.');
                     return { passwordAttempted: false, ok: true };
                 }
                 console.log('[Microsoft] Password field detected on Microsoft domain. Proceeding to password entry.');
            }

            // console.log('[Microsoft] Entering password phase...');
            const ok = await enterPasswordAndSubmit(page, password);
            return { passwordAttempted: true, ok };
        }
    };
}

module.exports = { createMicrosoftProvider };
