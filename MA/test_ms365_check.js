const dns = require('dns').promises;

const CONSUMER_DOMAINS = new Set([
  'outlook.com','hotmail.com','live.com','msn.com','hotmail.co.uk','outlook.co'
]);

function validEmail(email) {
  const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
  return re.test(email);
}

function isConsumerDomain(domain) {
  return CONSUMER_DOMAINS.has(domain.toLowerCase());
}

async function domainHasMx(domain) {
  try {
    const records = await dns.resolveMx(domain);
    return (records && records.length > 0);
  } catch (e) {
    return false;
  }
}

async function looksLikeMicrosoft365(domain) {
  try {
    const mx = await dns.resolveMx(domain).catch(() => []);
    if (mx.some(r => r.exchange && r.exchange.includes('mail.protection.outlook.com'))) return true;
    const txt = await dns.resolveTxt(domain).catch(() => []);
    const joined = txt.map(t => t.join('')).join(' ');
    if (joined.includes('spf.protection.outlook.com') || joined.includes('protection.outlook.com')) return true;
  } catch (e) {}
  return false;
}

async function checkEmail(email) {
  if (!validEmail(email)) return { email, result: 'FAIL', reason: 'invalid_email_format' };

  const domain = email.split('@')[1].toLowerCase();

  if (isConsumerDomain(domain)) return { email, result: 'FAIL', reason: 'consumer_domain_blocked' };

  const hasMx = await domainHasMx(domain);
  if (!hasMx) return { email, result: 'FAIL', reason: 'no_mx_record' };

  const isMs365 = await looksLikeMicrosoft365(domain);
  if (!isMs365) return { email, result: 'FAIL', reason: 'not_microsoft_365_business' };

  return { email, result: 'PASS', reason: 'microsoft_365_business_domain' };
}

(async () => {
  const email = process.argv[2];
  if (!email) {
    console.log('Usage: node test_ms365_check.js <email>');
    process.exit(1);
  }
  console.log('\n=== Microsoft 365 Business Email Gate — Real DNS Test ===\n');
  const r = await checkEmail(email);
  const icon = r.result === 'PASS' ? '✅' : '❌';
  console.log(`${icon}  ${r.result.padEnd(4)}  ${r.email.padEnd(45)}  reason: ${r.reason}`);
  console.log('\n=== Done ===\n');
})();
