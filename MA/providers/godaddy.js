function createGoDaddyProvider(deps) {
    const { cfg, typeHuman, clickSmart } = deps;

    return {
        name: 'godaddy',
        detect: async (page) => {
            const url = page.url();
            const isGoDaddyUrl = url.includes('sso.godaddy.com') || url.includes('secureserver.net') || url.includes('godaddy.com');
            const hasPasswordField = await page.$('#password').catch(() => null);
            if (hasPasswordField) {
                await hasPasswordField.dispose();
                return true;
            }
            return isGoDaddyUrl;
        },
        login: async ({ page, email, password, simulate }) => {
            if (simulate) {
                return { passwordAttempted: false, ok: true };
            }

            console.log('[GoDaddy] Waiting for password input (#password)...');
            try {
                const passwordInput = await page.waitForSelector('#password', { visible: true, timeout: 15000 });
                if (!passwordInput) throw new Error('Password input not found');

                console.log('[GoDaddy] Typing password...');
                await passwordInput.click({ clickCount: 3 });
                await typeHuman(page, '#password', password);
                
                console.log('[GoDaddy] Submitting...');
                await page.keyboard.press('Enter');
                
                // Fallback click if Enter doesn't work
                await new Promise(r => setTimeout(r, 1000));
                const submitBtn = await page.$('button[id="submitBtn"], button[type="submit"]');
                if (submitBtn) {
                     await submitBtn.click().catch(() => {});
                }

                // Wait for navigation OR error message OR challenge text (Race)
                console.log('[GoDaddy] Waiting for response...');
                try {
                    await Promise.race([
                        page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: cfg.navTimeoutMs }),
                        page.waitForFunction(() => {
                            const t = document.body.innerText;
                            return t.includes('Your browser is a bit unusual') || 
                                   t.includes('Verify it\'s you') ||
                                   document.querySelector('.error') ||
                                   document.querySelector('.alert-error');
                        }, { timeout: 15000 })
                    ]);
                } catch (e) {
                    console.log('[GoDaddy] Race timeout (or navigation happened).');
                }
                
                // Check state immediately
                const bodyText = await page.evaluate(() => document.body.innerText || '').catch(() => '');
                console.log(`[GoDaddy] Page text loaded (length: ${bodyText.length})`);
                
                if (bodyText.includes('Your browser is a bit unusual')) {
                     console.log('[GoDaddy] ⚠️  Detected: "Your browser is a bit unusual"');
                } else if (bodyText.includes('Verify it\'s you')) {
                     console.log('[GoDaddy] ⚠️  Detected: "Verify it\'s you"');
                } else if (bodyText.includes('Sign in')) {
                     console.log('[GoDaddy] Still on "Sign in" page (possible incorrect password)');
                } else {
                     console.log('[GoDaddy] Navigation complete. Checking login state...');
                }

                return { passwordAttempted: true, ok: true };
            } catch (e) {
                console.error('[GoDaddy] Login error:', e.message);
                return { passwordAttempted: true, ok: false };
            }
        }
    };
}

module.exports = { createGoDaddyProvider };
