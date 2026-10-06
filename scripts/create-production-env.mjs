import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const argv = process.argv.slice(2);
const args = new Set(argv);
const force = args.has('--force');
const activate = args.has('--activate');
const templateArg = argv.find((arg) => arg.startsWith('--template='));
const outputArg = argv.find((arg) => arg.startsWith('--output='));
const bootstrapSecretArg = argv.find((arg) => arg.startsWith('--bootstrap-secret-output='));
const template = path.resolve(root, templateArg ? templateArg.split('=').slice(1).join('=') : '.env.online.example');
const output = path.resolve(root, outputArg ? outputArg.split('=').slice(1).join('=') : '.env.generated');
const activeEnv = path.resolve(root, '.env');
const bootstrapSecretOutput = path.resolve(root, bootstrapSecretArg ? bootstrapSecretArg.split('=').slice(1).join('=') : `${path.relative(root, output)}.bootstrap-password.txt`);

const randomHex = (bytes = 24) => crypto.randomBytes(bytes).toString('hex');
const randomBase64Key = () => `base64:${crypto.randomBytes(32).toString('base64')}`;
const randomPassword = () => {
  const alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%_-';
  const bytes = crypto.randomBytes(28);
  return Array.from(bytes, (byte) => alphabet[byte % alphabet.length]).join('');
};

const unresolvedMarkers = [
  'GANTI_', 'GANTI-', 'ganti-', 'ganti_', 'domain-anda.com', 'nama_database', 'nama_user_database',
  '/home/username/', '/home/account/', 'hotel.example.com', 'local-gateway.example.net',
  'CHANGE_ME', 'NAMA HOTEL', 'property-01', 'PROPERTY01'
];
const validateReady = (text) => unresolvedMarkers.filter((marker) => text.includes(marker));

let content;
let generatedBootstrapPassword = null;
if (fs.existsSync(output) && activate && !force) {
  content = fs.readFileSync(output, 'utf8');
  console.log(`Menggunakan environment yang sudah ada: ${path.relative(root, output)}`);
} else {
  if (!fs.existsSync(template)) {
    console.error(`Template tidak ditemukan: ${template}`);
    process.exit(1);
  }
  if (fs.existsSync(output) && !force) {
    console.error(`File sudah ada: ${output}\nGunakan --activate untuk mengaktifkan file yang telah diedit, atau --force untuk membuat ulang.`);
    process.exit(1);
  }
  content = fs.readFileSync(template, 'utf8');
  generatedBootstrapPassword = randomPassword();
  const replacements = new Map([
    ['APP_ENCRYPTION_KEY', randomBase64Key()],
    ['APP_BOOTSTRAP_ADMIN_PASSWORD', generatedBootstrapPassword],
    ['CRON_SECRET', randomHex(32)],
    ['PUBLIC_SITE_RATE_LIMIT_SALT', randomHex(32)],
  ]);
  for (const [key, value] of replacements) {
    const pattern = new RegExp(`^${key}=.*$`, 'm');
    if (pattern.test(content)) content = content.replace(pattern, `${key}="${value}"`);
    else content += `\n${key}="${value}"\n`;
  }
  fs.mkdirSync(path.dirname(output), { recursive: true });
  fs.writeFileSync(output, content, { flag: force ? 'w' : 'wx', mode: 0o600 });
  try { fs.chmodSync(output, 0o600); } catch {}
  console.log(`Environment template dibuat: ${path.relative(root, output)}`);
  console.log('Rahasia runtime dibuat otomatis. Simpan file ini secara privat dan jangan commit ke Git.');
  fs.writeFileSync(bootstrapSecretOutput, generatedBootstrapPassword + '\n', { flag: force ? 'w' : 'wx', mode: 0o600 });
  try { fs.chmodSync(bootstrapSecretOutput, 0o600); } catch {}
  console.log(`Password bootstrap disimpan terpisah (0600): ${path.relative(root, bootstrapSecretOutput)}`);
  console.log('Pindahkan password tersebut ke password manager dan hapus file rahasia setelah bootstrap selesai.');
}

if (activate) {
  const remaining = validateReady(content);
  if (remaining.length > 0) {
    console.error(`Belum dapat mengaktifkan .env. Placeholder berikut masih ada: ${remaining.join(', ')}`);
    console.error(`Edit ${path.relative(root, output)}, lalu jalankan kembali: node scripts/create-production-env.mjs --activate`);
    process.exit(2);
  }
  if (fs.existsSync(activeEnv) && !force) {
    console.error('.env aktif sudah ada. Backup terlebih dahulu atau gunakan --force secara sadar.');
    process.exit(3);
  }
  fs.copyFileSync(output, activeEnv);
  try { fs.chmodSync(activeEnv, 0o600); } catch {}
  console.log(`Environment aktif dibuat: ${path.relative(root, activeEnv)}`);
}
